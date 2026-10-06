<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 站点监控磁贴（概览页「站点监控」分组）。
 *
 * 采集站点级运行指标并展示于概览页，与 cron / file-integrity 互不耦合：
 *  - 数据源：WP 核心 + 自有服务器可读信息（磁盘 / 负载 / 内存 / 证书 / DB），不依赖主题；
 *  - 快照存独立 option（jinyu_monitor_snapshot），有效期 1 小时，过期或手动巡检时重新采集；
 *  - 每小时定时刷新（jinyu_monitor_hourly），保证后台不打开也有近实时数据；
 *  - 文件完整性仅读取 file-integrity 模块已存状态（不自跑扫描、不触发告警邮件）；
 *  - 与主题的关联一律走 function_exists 守卫，缺失功能优雅降级为「未启用」。
 */

if ( ! defined( 'JINYU_MONITOR_OPT' ) ) {
	define( 'JINYU_MONITOR_OPT', 'jinyu_monitor_snapshot' );
}
if ( ! defined( 'JINYU_MONITOR_TTL' ) ) {
	define( 'JINYU_MONITOR_TTL', HOUR_IN_SECONDS );
}

/**
 * 取快照：未过期直接返回缓存；否则重新采集并落盘。
 *
 * @param bool $force 强制重新采集。
 * @return array
 */
function jinyu_monitor_snapshot( bool $force = false ): array {
	$prev = get_option( JINYU_MONITOR_OPT );
	if ( ! is_array( $prev ) ) {
		$prev = array();
	}
	$stale = empty( $prev['built'] ) || ( time() - (int) $prev['built'] ) > JINYU_MONITOR_TTL;
	if ( $force || $stale ) {
		$snap = jinyu_monitor_collect( $prev );
		update_option( JINYU_MONITOR_OPT, $snap );
		return $snap;
	}
	return $prev;
}

/**
 * 采集全部指标。
 *
 * @param array $prev 上一次快照（用于延续 sparkline 历史）。
 * @return array
 */
function jinyu_monitor_collect( array $prev = array() ): array {
	$uptime    = jinyu_monitor_uptime();
	$integrity = jinyu_monitor_integrity();
	$php       = jinyu_monitor_php_errors();
	$server    = jinyu_monitor_server();
	$ssl       = jinyu_monitor_ssl();
	$db        = jinyu_monitor_db();
	$cron      = jinyu_monitor_cron();
	$penv      = jinyu_monitor_phpenv();

	// 延续 TTFB 历史（最近 7 次），驱动 sparkline。
	$history = isset( $prev['uptime']['sparkline'] ) && is_array( $prev['uptime']['sparkline'] )
		? $prev['uptime']['sparkline']
		: array();
	if ( null !== $uptime['ttfb'] ) {
		$history[] = $uptime['ttfb'];
		$history   = array_slice( $history, -7 );
	}
	$uptime['sparkline'] = $history;

	return array(
		'built'     => time(),
		'uptime'    => $uptime,
		'integrity' => $integrity,
		'php'       => $php,
		'server'    => $server,
		'ssl'       => $ssl,
		'db'        => $db,
		'cron'      => $cron,
		'penv'      => $penv,
	);
}

/**
 * 站点存活 + TTFB（自请求首页，近似首字节耗时）。
 *
 * @return array{ok:bool,ttfb:int|null,code:int,down:string,sparkline:array}
 */
function jinyu_monitor_uptime(): array {
	$url   = home_url( '/' );
	$start = microtime( true );
	$resp  = wp_remote_get(
		$url,
		array(
			'timeout'     => 5,
			'sslverify'   => false,
			'redirection' => 0,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		)
	);
	if ( is_wp_error( $resp ) ) {
		return array(
			'ok'       => false,
			'ttfb'     => null,
			'code'     => 0,
			'down'     => wp_strip_all_tags( $resp->get_error_message() ),
			'sparkline' => array(),
		);
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	return array(
		'ok'       => $code >= 200 && $code < 400,
		'ttfb'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
		'code'     => $code,
		'down'     => '',
		'sparkline' => array(),
	);
}

/**
 * 文件完整性状态（读取 file-integrity 模块已存结果，不自跑扫描）。
 *
 * @return array{status:string,last_run:int,enabled:bool}
 */
function jinyu_monitor_integrity(): array {
	if ( ! function_exists( 'jinyu_integrity_settings' ) ) {
		return array(
			'status' => 'none',
			'last_run' => 0,
			'enabled' => false,
		);
	}
	$st = jinyu_integrity_settings();
	return array(
		'status'    => is_string( $st['last_status'] ?? '' ) ? $st['last_status'] : 'none',
		'last_run'  => (int) ( $st['last_run'] ?? 0 ),
		'enabled'   => ! empty( $st['enable'] ),
	);
}

/**
 * PHP 错误计数（读取 WP 调试日志近期条目；无日志则回退 0）。
 *
 * @return array{errors:int,fatal:int,sample:string[],source:string}
 */
function jinyu_monitor_php_errors(): array {
	$log = '';
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		$log = WP_CONTENT_DIR . '/debug.log';
	} else {
		$log = (string) ini_get( 'error_log' );
	}
	if ( ! $log || ! is_file( $log ) || ! is_readable( $log ) ) {
		return array(
			'errors' => 0,
			'fatal' => 0,
			'sample' => array(),
			'source' => 'none',
		);
	}
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( false === $lines ) {
		$lines = array();
	}
	$pat   = '/^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2} [^\]]*\] (PHP (?:Fatal error|Parse error|Error|Warning|Notice|Deprecated))/i';
	$count = 0;
	$fatal = 0;
	$sample = array();
	foreach ( array_reverse( $lines ) as $ln ) {
		if ( ! preg_match( $pat, $ln, $m ) ) {
			continue;
		}
		++$count;
		if ( preg_match( '/Fatal|Parse|Error/i', $m[2] ) ) {
			++$fatal;
		}
		if ( count( $sample ) < 6 ) {
			$sample[] = jinyu_monitor_trim_error( $ln );
		}
	}
	return array(
		'errors' => $count,
		'fatal' => $fatal,
		'sample' => $sample,
		'source' => 'debug.log',
	);
}

/**
 * 单行错误日志截断为卡片展示用的短文本。
 *
 * @param string $ln 原始日志行。
 * @return string
 */
function jinyu_monitor_trim_error( string $ln ): string {
	$ln = preg_replace( '/^\[\d{2}-[A-Za-z]{3}-\d{4}[^\]]*\] /', '', $ln );
	$ln = wp_strip_all_tags( $ln );
	if ( mb_strlen( $ln ) > 150 ) {
		$ln = mb_substr( $ln, 0, 147 ) . '…';
	}
	return $ln;
}

/**
 * 服务器资源：磁盘 / 负载 / 内存。
 *
 * @return array{disk_free:float,disk_total:float,disk_pct:int,load:array|null,mem_used:float,mem_total:float}
 */
function jinyu_monitor_server(): array {
	$disk_free  = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 空间读取可能受限，降级而非 fatal
	$disk_total = function_exists( 'disk_total_space' ) ? @disk_total_space( ABSPATH ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$disk_pct   = 0;
	if ( $disk_free && $disk_total ) {
		$disk_pct = (int) round( ( 1 - (float) $disk_free / (float) $disk_total ) * 100 );
	}
	$load = function_exists( 'sys_getloadavg' ) ? array_map(
		static function ( $v ) {
			return round( (float) $v, 2 );
		},
		sys_getloadavg()
	) : null;

	$mem_used  = null;
	$mem_total = null;
	if ( is_readable( '/proc/meminfo' ) ) {
		$raw = @file_get_contents( '/proc/meminfo' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 只读系统文件
		if ( is_string( $raw ) ) {
			$mem_total = jinyu_monitor_meminfo( $raw, 'MemTotal' );
			$avail     = jinyu_monitor_meminfo( $raw, 'MemAvailable' );
			if ( null !== $mem_total && null !== $avail ) {
				$mem_used = $mem_total - $avail;
			}
		}
	}

	return array(
		'disk_free'  => $disk_free ? (float) $disk_free : 0.0,
		'disk_total' => $disk_total ? (float) $disk_total : 0.0,
		'disk_pct'   => $disk_pct,
		'load'       => $load,
		'mem_used'   => null === $mem_used ? 0.0 : (float) $mem_used,
		'mem_total'  => null === $mem_total ? 0.0 : (float) $mem_total,
	);
}

/**
 * 从 /proc/meminfo 解析某字段（kB → 字节）。
 *
 * @param string $raw 文件内容。
 * @param string $key 字段名。
 * @return float|null
 */
function jinyu_monitor_meminfo( string $raw, string $key ): ?float {
	if ( preg_match( '/^' . preg_quote( $key, '/' ) . ':\s+(\d+)\s*kB/m', $raw, $m ) ) {
		return (float) $m[1] * 1024;
	}
	return null;
}

/**
 * SSL 证书到期（读取首页域名 443 对等证书）。
 *
 * @return array{valid:bool,days_left:int|null,issuer:string,expires:int|null,checked:int}
 */
function jinyu_monitor_ssl(): array {
	$empty = array(
		'valid' => false,
		'days_left' => null,
		'issuer' => '',
		'expires' => null,
		'checked' => time(),
	);
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( '' === $host || ! function_exists( 'stream_socket_client' ) || ! function_exists( 'openssl_x509_parse' ) ) {
		return $empty;
	}
	$ctx  = stream_context_create(
		array(
			'ssl' => array(
				'capture_peer_cert' => true,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
			),
		)
	);
	$sock = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $sock ) {
		return $empty;
	}
	$params = stream_context_get_params( $ctx );
	$cert   = $params['options']['ssl']['peer_certificate'] ?? null;
	fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	if ( ! $cert ) {
		return $empty;
	}
	$info = openssl_x509_parse( $cert );
	if ( empty( $info ) || empty( $info['validTo_time_t'] ) ) {
		return $empty;
	}
	$expires  = (int) $info['validTo_time_t'];
	$issuer   = isset( $info['issuer']['O'] ) ? $info['issuer']['O'] : ( $info['issuer']['CN'] ?? '' );
	$days_left = max( 0, (int) round( ( $expires - time() ) / DAY_IN_SECONDS ) );
	return array(
		'valid'     => true,
		'days_left' => $days_left,
		'issuer'    => is_string( $issuer ) ? $issuer : '',
		'expires'   => $expires,
		'checked'   => time(),
	);
}

/**
 * 数据库概况：表数 / 总体积 / autoload 体积。
 *
 * @return array{tables:int,size:float,autoload:float}
 */
function jinyu_monitor_db(): array {
	global $wpdb;
	$tables = 0;
	$size   = 0;
	$res    = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- 只读管理查询，无注入点
	if ( is_array( $res ) ) {
		foreach ( $res as $r ) {
			++$tables;
			$size += (int) ( $r['Data_length'] ?? 0 ) + (int) ( $r['Index_length'] ?? 0 );
		}
	}
	$autoload = (float) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = %s", 'yes' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名来自 WP 核心常量
	);
	return array(
		'tables'   => $tables,
		'size'     => (float) $size,
		'autoload' => $autoload,
	);
}

/**
 * 计划任务概况：事件总数 / 下次执行。
 *
 * 兼容旧 WP：wp_get_scheduled_events() 需 5.1+，旧版本退回 _get_cron_array()。
 *
 * @return array{events:int,next:int}
 */
function jinyu_monitor_cron(): array {
	$count = 0;
	$next  = 0;
	if ( function_exists( 'wp_get_scheduled_events' ) ) {
		foreach ( wp_get_scheduled_events() as $instances ) {
			$batch = is_array( $instances ) ? $instances : array( $instances );
			$count += count( $batch );
			foreach ( $batch as $inst ) {
				$t = isset( $inst->timestamp ) ? (int) $inst->timestamp : 0;
				if ( $t && ( 0 === $next || $t < $next ) ) {
					$next = $t;
				}
			}
		}
		return array(
			'events' => $count,
			'next' => $next,
		);
	}
	// 旧版本兜底：_get_cron_array() 返回 [ timestamp => [ hook => [ key => [schedule,args,interval] ] ] ]。
	$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
	foreach ( $crons as $timestamp => $hooks ) {
		if ( ! is_array( $hooks ) ) {
			continue;
		}
		$count += count( $hooks );
		$ts = (int) $timestamp;
		if ( $ts && ( 0 === $next || $ts < $next ) ) {
			$next = $ts;
		}
	}
	return array(
		'events' => $count,
		'next' => $next,
	);
}

/**
 * PHP 运行环境：版本 / 内存限制 / 最大执行时间。
 *
 * @return array{version:string,mem:string,max_exec:string}
 */
function jinyu_monitor_phpenv(): array {
	$mem   = (string) ini_get( 'memory_limit' );
	$exec  = (string) ini_get( 'max_execution_time' );
	return array(
		'version'  => PHP_VERSION,
		'mem'      => '' !== $mem ? $mem : '—',
		'max_exec' => '' !== $exec ? $exec . 's' : '—',
	);
}

/**
 * 字节格式化。
 *
 * @param float $b 字节数。
 * @return string
 */
function jinyu_monitor_format_bytes( float $b ): string {
	$b = (float) $b;
	if ( $b >= 1073741824 ) {
		return round( $b / 1073741824, 1 ) . ' GB';
	}
	if ( $b >= 1048576 ) {
		return round( $b / 1048576, 1 ) . ' MB';
	}
	if ( $b >= 1024 ) {
		return round( $b / 1024 ) . ' KB';
	}
	return (int) $b . ' B';
}

/**
 * TTFB 迷你 sparkline（内联 SVG）。
 *
 * @param array $data 历史 TTFB 毫秒。
 * @return string
 */
function jinyu_monitor_sparkline( array $data ): string {
	if ( count( $data ) < 2 ) {
		return '';
	}
	$max   = max( $data );
	$min   = min( $data );
	$range = ( $max - $min ) ?: 1;
	$n     = count( $data );
	$w     = 130;
	$h     = 26;
	$pts   = array();
	foreach ( $data as $i => $v ) {
		$x = ( $n > 1 ) ? ( $i / ( $n - 1 ) ) * $w : 0;
		$y = $h - ( ( (float) $v - $min ) / $range ) * ( $h - 4 ) - 2;
		$pts[] = round( $x, 1 ) . ',' . round( $y, 1 );
	}
	$d = 'M' . implode( ' L', $pts );
	return '<svg class="jyc-spark" viewBox="0 0 ' . $w . ' ' . $h
		. '" preserveAspectRatio="none" aria-hidden="true"><path d="' . $d
		. '" fill="none" stroke="var(--brand)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/**
 * 渲染监控区单个紧凑状态项（状态条用，替代原大磁贴，提升密度）。
 *
 * @param string $dot  状态点颜色（CSS 颜色值）。
 * @param string $name 指标名（可翻译字符串）。
 * @param string $val  主值。
 * @param string $sub  次级说明（已转义的安全字符串，可为空）。
 * @return string
 */
function jinyu_monitor_stat( string $dot, string $name, string $val, string $sub = '' ): string {
	$sub_html = '' !== $sub ? '<div class="jyc-ss">' . $sub . '</div>' : '';
	return '<div class="jyc-stat">'
		. '<span class="jyc-dot" style="background:' . $dot . '"></span>'
		. '<div class="jyc-stat-body"><div class="jyc-sk">' . esc_html( $name ) . '</div>'
		. '<div class="jyc-sv">' . esc_html( $val ) . '</div>' . $sub_html . '</div>'
		. '</div>';
}

/**
 * 渲染概览页「站点监控」分组（磁贴 + 巡检按钮 + 隐藏 nonce）。
 *
 * @return string
 */
function jinyu_monitor_render_section(): string {
	$snap = jinyu_monitor_snapshot();
	$nonce = wp_create_nonce( 'jinyu_monitor_inspect' );

	$up_ok  = ! empty( $snap['uptime']['ok'] );
	$up_dot = $up_ok ? 'var(--ok)' : 'var(--danger)';
	$up_tt  = null === $snap['uptime']['ttfb'] ? '—' : (int) $snap['uptime']['ttfb'];
	$up_sub = $up_ok
		/* translators: %d: 站点 HTTP 状态码。 */
		? sprintf( esc_html__( '在线 · HTTP %d', 'jinyu-theme-companion' ), (int) $snap['uptime']['code'] )
		: esc_html__( '离线：', 'jinyu-theme-companion' ) . esc_html( $snap['uptime']['down'] );

	$ig = $snap['integrity'];
	$ig_map = array(
		'clean' => array( 'var(--ok)', esc_html__( '正常', 'jinyu-theme-companion' ) ),
		'alert' => array( 'var(--danger)', esc_html__( '异常', 'jinyu-theme-companion' ) ),
		'none'  => array( 'var(--warn)', esc_html__( '未启用', 'jinyu-theme-companion' ) ),
	);
	$ig_info = $ig_map[ $ig['status'] ] ?? $ig_map['none'];
	$ig_sub  = $ig['last_run']
		/* translators: %s: 距上次扫描的时长，如「5 分钟前」。 */
		? sprintf( esc_html__( '上次扫描 %s', 'jinyu-theme-companion' ), esc_html( human_time_diff( $ig['last_run'] ) . '前' ) )
		: esc_html__( '尚未扫描', 'jinyu-theme-companion' );

	$php    = $snap['php'];
	$php_dot = $php['fatal'] > 0 ? 'var(--danger)' : ( $php['errors'] > 0 ? 'var(--warn)' : 'var(--ok)' );
	$php_v   = $php['errors'] > 0 ? (int) $php['errors'] : '0';
	$php_sub = $php['fatal'] > 0
		/* translators: 1: 致命错误数 2: 警告数 */
		? sprintf( esc_html__( '致命 %1$d · 警告 %2$d', 'jinyu-theme-companion' ), (int) $php['fatal'], (int) ( $php['errors'] - $php['fatal'] ) )
		/* translators: %d: 近期 PHP 错误条数 */
		: ( $php['errors'] > 0 ? sprintf( esc_html__( '警告 %d 条', 'jinyu-theme-companion' ), (int) $php['errors'] ) : esc_html__( '无近期错误', 'jinyu-theme-companion' ) );

	$srv        = $snap['server'];
	$srv_pct    = $srv['disk_pct'];
	$srv_dot    = $srv_pct >= 85 ? 'var(--danger)' : ( $srv_pct >= 70 ? 'var(--warn)' : 'var(--ok)' );
	$mem_pct    = ( $srv['mem_total'] > 0 ) ? (int) round( $srv['mem_used'] / $srv['mem_total'] * 100 ) : 0;
	$load_txt   = is_array( $srv['load'] ) ? $srv['load'][0] : '—';

	$ssl     = $snap['ssl'];
	$ssl_dot = ( ! $ssl['valid'] ) ? 'var(--warn)'
		: ( ( null !== $ssl['days_left'] && $ssl['days_left'] < 15 ) ? 'var(--danger)' : 'var(--ok)' );
	$ssl_v   = $ssl['valid'] && null !== $ssl['days_left'] ? (int) $ssl['days_left'] : '—';
	$ssl_sub = $ssl['valid']
		? ( null !== $ssl['days_left'] && $ssl['days_left'] < 15 ? esc_html__( '即将到期', 'jinyu-theme-companion' ) : esc_html__( '有效', 'jinyu-theme-companion' ) )
		: esc_html__( '无法检测', 'jinyu-theme-companion' );

	$db     = $snap['db'];
	$db_v   = jinyu_monitor_format_bytes( $db['size'] );
	/* translators: 1: 数据表数量 2: 自动加载体积 */
	$db_sub = sprintf( esc_html__( '%1$d 张表 · 自动加载 %2$s', 'jinyu-theme-companion' ), (int) $db['tables'], jinyu_monitor_format_bytes( $db['autoload'] ) );

	$updated = $snap['built'] ? wp_date( 'Y-m-d H:i', $snap['built'] ) : '—';

	$stats  = '';
	// 站点存活
	$stats .= jinyu_monitor_stat( $up_dot, __( '站点存活', 'jinyu-theme-companion' ), $up_ok ? __( '正常', 'jinyu-theme-companion' ) : __( '离线', 'jinyu-theme-companion' ), $up_sub );
	// 文件完整性
	$stats .= jinyu_monitor_stat( $ig_info[0], __( '文件完整性', 'jinyu-theme-companion' ), $ig_info[1], $ig_sub );
	// PHP 错误
	$stats .= jinyu_monitor_stat( $php_dot, __( 'PHP 错误', 'jinyu-theme-companion' ), (string) $php_v, $php_sub );
	// 服务器资源
	/* translators: 1: 磁盘占用百分比 2: 内存占用百分比 3: 系统负载 */
	$stats .= jinyu_monitor_stat( $srv_dot, __( '服务器资源', 'jinyu-theme-companion' ), $srv_pct . '%', sprintf( __( '磁盘 %1$d%% · 内存 %2$d%% · 负载 %3$s', 'jinyu-theme-companion' ), $srv_pct, $mem_pct, $load_txt ) );
	// SSL 证书
	$stats .= jinyu_monitor_stat( $ssl_dot, __( 'SSL 证书', 'jinyu-theme-companion' ), (string) $ssl_v, $ssl_sub . ( $ssl['issuer'] ? ' · ' . esc_html( $ssl['issuer'] ) : '' ) );
	// 数据库
	$stats .= jinyu_monitor_stat( 'var(--brand-2)', __( '数据库', 'jinyu-theme-companion' ), $db_v, $db_sub );
	// 计划任务（旧快照缺键时回退默认，避免 Undefined offset 告警）
	$cron  = isset( $snap['cron'] ) && is_array( $snap['cron'] ) ? $snap['cron'] : array(
		'events' => 0,
		'next' => 0,
	);
	/* translators: %s: 距下次执行的时间差。 */
	$nextv = $cron['next'] ? sprintf( esc_html__( '%s后执行', 'jinyu-theme-companion' ), human_time_diff( $cron['next'] ) ) : esc_html__( '无排队事件', 'jinyu-theme-companion' );
	$stats .= jinyu_monitor_stat( 'var(--brand-2)', __( '计划任务', 'jinyu-theme-companion' ), (string) (int) $cron['events'], __( '排队 · ', 'jinyu-theme-companion' ) . $nextv );
	// PHP 环境（旧快照缺键时回退当前值）
	$penv = isset( $snap['penv'] ) && is_array( $snap['penv'] ) ? $snap['penv'] : jinyu_monitor_phpenv();
	/* translators: 1: PHP 内存限制 2: 最大执行时间 */
	$stats .= jinyu_monitor_stat( 'var(--brand-2)', __( 'PHP 环境', 'jinyu-theme-companion' ), $penv['version'], sprintf( __( '内存限制 %1$s · 超时 %2$s', 'jinyu-theme-companion' ), $penv['mem'], $penv['max_exec'] ) );

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部构造受控 HTML，nonce 为隐藏 input
	return '<div class="jyc-monitor-section" id="jyc-monitor-section">'
		. '<div class="jyc-sec-head">'
		. '<div class="jyc-sec-title"><h2>' . esc_html__( '站点监控', 'jinyu-theme-companion' ) . '</h2>'
		/* translators: %s: 快照生成时间。 */
		. '<span class="jyc-sec-upd" id="jyc-monitor-upd">' . sprintf( esc_html__( '更新于 %s', 'jinyu-theme-companion' ), $updated ) . '</span></div>'
		. '<button type="button" class="jyc-btn jyc-btn-soft jyc-btn-xs" id="jyc-monitor-refresh">' . esc_html__( '立即巡检', 'jinyu-theme-companion' ) . '</button>'
		. '</div>'
		. '<input type="hidden" id="jyc-monitor-nonce" value="' . $nonce . '">'
		. '<div class="jyc-strip" id="jyc-monitor-strip">' . $stats . '</div>'
		. '</div>';
	// phpcs:enable
}

/**
 * AJAX：立即巡检，返回新磁贴 HTML。
 */
add_action( 'wp_ajax_jinyu_monitor_inspect', 'jinyu_monitor_ajax' );
function jinyu_monitor_ajax(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json(
            array(
				'success' => false,
				'msg' => __( '权限不足', 'jinyu-theme-companion' ),
            )
        );
	}
	if ( empty( $_POST['jinyu_monitor_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jinyu_monitor_nonce'] ) ), 'jinyu_monitor_inspect' ) ) {
		wp_send_json(
            array(
				'success' => false,
				'msg' => __( '安全校验失败', 'jinyu-theme-companion' ),
            )
        );
	}
	$snap = jinyu_monitor_collect();
	update_option( JINYU_MONITOR_OPT, $snap );
	// 复用渲染函数（强制读刚写入的快照）。
	wp_send_json(
		array(
			'success' => true,
			'html'    => jinyu_monitor_render_section(),
		)
	);
}

/**
 * 每小时定时刷新快照（后台不打开也有近实时数据）。
 */
add_action( 'jinyu_monitor_hourly', 'jinyu_monitor_hourly_refresh' );
function jinyu_monitor_hourly_refresh(): void {
	update_option( JINYU_MONITOR_OPT, jinyu_monitor_collect() );
}
add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( 'jinyu_monitor_hourly' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'jinyu_monitor_hourly' );
		}
	},
	30
);
