<?php
/**
 * 边缘缓存命中统计：解析 Web 服务器写出的 $upstream_cache_status 日志。
 *
 * 背景：边缘模式下页面由 Nginx/Apache 直接吐出，PHP 完全不参与，插件自采的
 * hit/miss 计数恒为 0，状态卡的「命中率」只能显示「—」。真值只能来自服务器：
 *  - Nginx：片段里的 log_format jinyu_edge + access_log 把 $upstream_cache_status 写成
 *    「时间|状态|HTTP码|URI」四段（状态为 HIT / MISS / BYPASS / EXPIRED / STALE …）。
 *  - Apache：片段里的 LogFormat + CustomLog 把响应头 X-Cache 写成同样的四段
 *    （mod_cache 输出形如「HIT from host」，解析时取首个词归一）。
 * 两种来源结构一致，本模块增量解析同一份日志并聚合。
 *
 * 设计要点（目标：后台打开即最新，且不拖慢后台）：
 *  - 增量解析：记录已读字节偏移，只处理新增行。
 *  - 轮转容错：文件变小（logrotate copytruncate）时偏移归零重读。
 *  - 读取上限：单次最多 JINYU_EDGE_STATS_MAX_READ 字节，超出只取尾部。
 *  - 磁盘兜底：先解析、再把超过 JINYU_EDGE_STATS_MAX_SIZE 的日志就地截断（nginx 与 apache 的
 *    access_log 都以 O_APPEND 打开，截断后写入从头开始、不产生稀疏空洞），不依赖系统 logrotate。
 *  - 定时维护：init 上按 JINYU_EDGE_STATS_MAINT_INTERVAL 兜底跑一次，无后台访问也能封顶。
 *  - 节流：距上次解析不足 JINYU_EDGE_STATS_THROTTLE 秒时直接返回缓存值。
 *  - 不写服务器配置：插件不改任何服务器配置文件；只读本模块日志，仅在超阈值时就地截断。
 *
 * @package jinyu-theme-companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'JINYU_EDGE_STATS_OPT' ) ) {
	define( 'JINYU_EDGE_STATS_OPT', 'jinyu_edge_cache_stats' );
	define( 'JINYU_EDGE_STATS_OFFSET', 'jinyu_edge_stats_offset' );
	define( 'JINYU_EDGE_STATS_AT', 'jinyu_edge_stats_at' );
	define( 'JINYU_EDGE_STATS_MAX_READ', 2097152 ); // 单次最多解析 2MB。
	define( 'JINYU_EDGE_STATS_THROTTLE', 20 );      // 秒。
	define( 'JINYU_EDGE_STATS_MAX_SIZE', 8388608 );   // 日志自截断阈值 8MB（兜底无 logrotate 的环境）。
	define( 'JINYU_EDGE_STATS_MAINT_INTERVAL', 600 ); // 后台维护（解析 + 截断）最短间隔，秒。
	define( 'JINYU_EDGE_STATS_MAINT_AT', 'jinyu_edge_stats_maint_at' );
}

/**
 * 统计日志路径（必须与 nginx 片段里的 access_log 完全一致）。
 *
 * 与边缘缓存目录同级放在 wp-content 下：nginx 与 php-fpm 命名空间同源可见，
 * 且已被片段的 deny 规则挡住直接访问。
 */
function jinyu_edge_stats_log_path(): string {
	// 与边缘缓存目录同级：缓存目录位于 php-fpm 可见的命名空间，日志用同一基准
	// 才能保证「Web 服务器写的路径」与「插件读的路径」指向同一个文件。
	$base = function_exists( 'jinyu_page_cache_edge_path' )
		? dirname( jinyu_page_cache_edge_path() )
		: rtrim( WP_CONTENT_DIR, '/' ) . '/cache/jinyu';
	return rtrim( $base, '/' ) . '/edge-access.log';
}

/**
 * 统计是否可用：边缘模式且日志存在可读。
 */
function jinyu_edge_stats_available(): bool {
	if ( ! function_exists( 'jinyu_page_cache_mode' ) || 'edge' !== jinyu_page_cache_mode() ) {
		return false;
	}
	$f = jinyu_edge_stats_log_path();
	return is_file( $f ) && is_readable( $f );
}

/**
 * 读取已聚合的统计（不触发解析，供渲染与 AJAX 复用）。
 *
 * @return array<string,int>
 */
function jinyu_edge_stats_get(): array {
	$d = get_option( JINYU_EDGE_STATS_OPT );
	$d = is_array( $d ) ? $d : array();
	$s = array(
		'hits'    => (int) ( $d['hits'] ?? 0 ),
		'miss'    => (int) ( $d['miss'] ?? 0 ),
		'bypass'  => (int) ( $d['bypass'] ?? 0 ),
		'expired' => (int) ( $d['expired'] ?? 0 ),
		'stale'   => (int) ( $d['stale'] ?? 0 ),
		'other'   => (int) ( $d['other'] ?? 0 ),
		'at'      => (int) ( $d['at'] ?? 0 ),
	);
	$s['total'] = $s['hits'] + $s['miss'] + $s['bypass'] + $s['expired'] + $s['stale'] + $s['other'];
	return $s;
}

/**
 * 命中率（%）。
 *
 * 分子含 HIT 与 STALE（陈旧但仍可用），分母排除 BYPASS —— 登录用户 / 后台 /
 * REST 属于主动绕过缓存，计入分母会无端拉低真实命中率。样本为零时返回 null。
 *
 * @param array<string,int> $s 统计数组。
 */
function jinyu_edge_stats_rate( array $s ): ?float {
	$den = (int) $s['hits'] + (int) $s['miss'] + (int) $s['expired'] + (int) $s['stale'];
	if ( $den <= 0 ) {
		return null;
	}
	return round( ( (int) $s['hits'] + (int) $s['stale'] ) / $den * 100, 1 );
}

/**
 * 增量解析日志并聚合，返回最新统计。
 *
 * @param bool $force 忽略节流强制解析（用户点「刷新」时）。
 */
function jinyu_edge_stats_collect( bool $force = false ): array {
	$s    = jinyu_edge_stats_get();
	$path = jinyu_edge_stats_log_path();

	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return $s;
	}

	$at = (int) get_option( JINYU_EDGE_STATS_AT, 0 );
	if ( ! $force && $at > 0 && ( time() - $at ) < JINYU_EDGE_STATS_THROTTLE ) {
		return $s;
	}

	clearstatcache( true, $path );
	$size = (int) @filesize( $path );
	$off  = (int) get_option( JINYU_EDGE_STATS_OFFSET, 0 );
	if ( $off > $size ) {
		$off = 0; // 轮转或截断（logrotate copytruncate / 本模块自截断）：从新文件头重读。
	}
	if ( $size - $off > JINYU_EDGE_STATS_MAX_READ ) {
		$off = $size - JINYU_EDGE_STATS_MAX_READ; // 异常膨胀时只取尾部，保证后台不被拖慢。
	}

	$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 大日志需 seek/逐行增量读取，WP_Filesystem 无此语义。
	if ( false === $fh ) {
		return $s;
	}
	if ( 0 !== fseek( $fh, $off ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 与上方 fopen 同理，大日志 seek 增量读取 WP_Filesystem 无此语义
		return $s;
	}

	$pos = $off;
	while ( ! feof( $fh ) ) {
		$line = fgets( $fh );
		if ( false === $line ) {
			break;
		}
		// 末行可能只写了一半（buffer 未 flush 完）：留到下次再解析，避免算错。
		if ( "\n" !== substr( $line, -1 ) ) {
			break;
		}
		$pos = (int) ftell( $fh );

		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = explode( '|', $line, 4 );
		if ( count( $parts ) < 3 ) {
			continue;
		}
		// 状态词取「首个空白分词」：Nginx 直接给「HIT」，Apache 经 X-Cache 头给「HIT from host」，
		// 归一后两种来源共用同一张映射表；X-Cache 缺失时该字段为空，落 default 记入 other（不进分母）。
		$word  = preg_split( '/\s+/', trim( (string) $parts[1] ) );
		$token = strtoupper( (string) ( $word[0] ?? '' ) );
		switch ( $token ) {
			case 'HIT':
				++$s['hits'];
				break;
			case 'MISS':
				++$s['miss'];
				break;
			case 'BYPASS':
				++$s['bypass'];
				break;
			case 'EXPIRED':
				++$s['expired'];
				break;
			case 'STALE':
			case 'UPDATING':
			case 'REVALIDATED':
			case 'REVALIDATE': // Apache：陈旧但成功重验证后从缓存返回，语义同 Nginx 的 REVALIDATED。
				++$s['stale'];
				break;
			default:
				++$s['other'];
				break;
		}

		if ( $pos - $off >= JINYU_EDGE_STATS_MAX_READ ) {
			break;
		}
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 与上方 fopen 同理，大日志 seek 增量读取 WP_Filesystem 无此语义

	$s['total'] = $s['hits'] + $s['miss'] + $s['bypass'] + $s['expired'] + $s['stale'] + $s['other'];
	$s['at']    = time();

	update_option( JINYU_EDGE_STATS_OPT, $s, false );
	update_option( JINYU_EDGE_STATS_OFFSET, $pos, false );
	update_option( JINYU_EDGE_STATS_AT, $s['at'], false );

	// 磁盘兜底：先把新增行解析入账，再把超限日志就地截断——既不丢已解析数据，也不依赖系统 logrotate。
	// nginx / apache 的 access_log 都以 O_APPEND 打开（nginx 已实测；apache 源码 mod_log_config.c
	// 的 xfer_flags 含 APR_APPEND），截断后新写入从头开始、不产生稀疏空洞，故截断后偏移归零。
	clearstatcache( true, $path );
	if ( (int) @filesize( $path ) > JINYU_EDGE_STATS_MAX_SIZE ) {
		$fh = @fopen( $path, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 需就地截断，WP_Filesystem 无 ftruncate 语义。
		if ( false !== $fh ) {
			@ftruncate( $fh, 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 截断失败仅不生效，不影响正常统计。
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 与上方 fopen 同理，就地 ftruncate 截断 WP_Filesystem 无此语义
			update_option( JINYU_EDGE_STATS_OFFSET, 0, false );
		}
	}

	return $s;
}

/**
 * 清零统计：偏移推进到当前文件末尾，此后只统计新增请求。
 */
function jinyu_edge_stats_reset(): void {
	delete_option( JINYU_EDGE_STATS_OPT );
	$path = jinyu_edge_stats_log_path();
	clearstatcache( true, $path );
	$size = is_file( $path ) ? (int) @filesize( $path ) : 0;
	update_option( JINYU_EDGE_STATS_OFFSET, $size, false );
	update_option( JINYU_EDGE_STATS_AT, time(), false );
}

/**
 * 定时维护：按 MAINT_INTERVAL 兜底解析并封顶日志大小。
 *
 * 挂在 init 上（与 page-cache 的 GC 同套路）：即便管理员长期不进后台，只要有前台流量
 * 就会被触发，从而保证统计不至于长期停滞、日志也不至于无限增长。节流与真正的解析成本
 * 都在 jinyu_edge_stats_collect() 内部（单次最多读 2MB）。
 */
function jinyu_edge_stats_maintain(): void {
	if ( ! jinyu_edge_stats_available() ) {
		return;
	}
	$last = (int) get_option( JINYU_EDGE_STATS_MAINT_AT, 0 );
	if ( $last > 0 && ( time() - $last ) < JINYU_EDGE_STATS_MAINT_INTERVAL ) {
		return;
	}
	update_option( JINYU_EDGE_STATS_MAINT_AT, time(), false );
	jinyu_edge_stats_collect( true );
}
add_action( 'init', 'jinyu_edge_stats_maintain', 99 );
