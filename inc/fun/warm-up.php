<?php
/**
 * 缓存预热（Cache Warm-up）。
 *
 * 解决「冷态尖刺」：整页缓存 / 对象缓存只在首个真实访客请求时重建（首页冷态约 80+ SQL，
 * 重建耗时 1 秒级），之后访客命中热态（个位数 SQL）。预热把这些重建成本分摊到
 * 后台主动爬取请求，使真实访客永远命中热缓存。
 *
 * 设计约束：
 *  - 默认关闭，由站长在「前台加速 → 缓存预热」显式开启（自发外请求是敏感操作，不应静默默认开）。
 *  - 严格只在配套插件内，不进主题代码（wp.org 禁止主题自发包外请求预热缓存，属越权 + 资源滥用）。
 *  - 只预热本站关键 URL（首页 / 列表 / 分类 / 近期 / 热门 + 站长自定义），不外发第三方。
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── 预热频率枚举（仅开启自动预热时挂 cron 生效；manual = 只手动 / 清缓存后触发） ── */
if ( ! function_exists( 'jinyu_warmup_intervals' ) ) {
	function jinyu_warmup_intervals(): array {
		return [
			'manual' => __( '仅手动 / 清缓存后触发', 'jinyu-theme-companion' ),
			'15min'  => __( '每 15 分钟', 'jinyu-theme-companion' ),
			'hourly' => __( '每小时', 'jinyu-theme-companion' ),
			'daily'  => __( '每天', 'jinyu-theme-companion' ),
		];
	}
}

/* 总开关：复用性能中心开关表 jinyu_perf_options_v2（与优化开关同一真源）。 */
if ( ! function_exists( 'jinyu_warmup_is_enabled' ) ) {
	function jinyu_warmup_is_enabled(): bool {
		$opts = function_exists( 'jinyu_perf_get_options' ) ? jinyu_perf_get_options() : [];
		return ! empty( $opts['warmup_enable'] );
	}
}

/*
 * 默认热门文章数据源（插件自带，不依赖主题 / 第三方浏览量插件，不影响主题提交规范）。
 * 用 WP 原生 comment_count 排序代理「热门」，并限定近 180 天避免去暖老旧文章。
 * 主题 / 私有插件若想用更精准的浏览量数据，挂 add_filter('jinyu_warmup_popular_post_ids', fn) 即可覆盖，插件零改动。 */
if ( ! function_exists( 'jinyu_warmup_default_popular_ids' ) ) {
	function jinyu_warmup_default_popular_ids( int $limit = 50 ): array {
		$limit = max( 1, (int) $limit );
		$ids   = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'orderby'        => 'comment_count',
				'order'          => 'DESC',
				'date_query'     => array(
					array(
						'after'     => gmdate( 'Y-m-d', strtotime( '-180 days' ) ),
						'column'    => 'post_date_gmt',
						'inclusive' => true,
					),
				),
			)
		);
		return is_array( $ids ) ? $ids : array();
	}
}

/*
 构建预热队列：价值加权 + 全量枚举（不封顶 8 分类 / 10 近期），返回按权重降序去重的 URL 列表。
 * 权重越高越先暖，保证「暖的 frontier」永远覆盖要害页；slug 与真实访客逐字节一致。 */
if ( ! function_exists( 'jinyu_warmup_get_urls' ) ) {
	function jinyu_warmup_get_urls(): array {
		$opts     = function_exists( 'jinyu_perf_get_options' ) ? jinyu_perf_get_options() : array();
		$max_posts = (int) ( $opts['warmup_max'] ?? 300 );
		if ( $max_posts < 0 ) {
			$max_posts = 0;
		}

		// 输入指纹：任一变化都需重建队列，否则直接返回缓存，避免 cron 每 tick 重算 892 条 permalink。
		// 含 get_lastpostmodified 以覆盖「仅改 slug 不改计数」这类结构未变但 URL 已变的场景。
		$counts = wp_count_posts( 'post' );
		$pub    = is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
		$lastmod = function_exists( 'get_lastpostmodified' ) ? get_lastpostmodified( 'gmt' ) : '';
		$cat_ids = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'fields'     => 'ids',
			)
		);
		$cat_sig = is_array( $cat_ids ) ? implode( ',', $cat_ids ) : '';
		$extra   = is_string( $opts['warmup_urls'] ?? '' ) ? $opts['warmup_urls'] : '';
		// 热门锚点：用已审核评论总数做廉价指纹。评论暴涨时触发队列重建（热门随之重算），平时不查热门、不查 892 次 permalink。
		$popular_sig = function_exists( 'wp_count_comments' ) ? (int) ( wp_count_comments()->approved ?? 0 ) : 0;
		$sig = md5( $pub . '|' . $lastmod . '|' . $cat_sig . '|' . $max_posts . '|' . $extra . '|' . $popular_sig );

		$cached_sig = get_option( 'jinyu_warmup_queue_sig', '' );
		$cached     = get_option( 'jinyu_warmup_queue', false );
		if ( $cached_sig === $sig && is_array( $cached ) ) {
			return $cached;
		}

		$items = array(); // [ 'url' => string, 'score' => int ]

		// 1) 首页 + 列表前 3 页（最高权重，冷态重建成本最大）
		$items[] = array(
			'url' => home_url( '/' ),
			'score' => 100,
		);
		$pp      = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$home_pages = min( 3, (int) ceil( $pub / $pp ) );
		for ( $i = 2; $i <= $home_pages; $i++ ) {
			$items[] = array(
				'url' => home_url( '/page/' . $i . '/' ),
				'score' => 85,
			);
		}

		// 2) 全部分类归档（不封顶 8），按文章数给权重
		$cats = get_terms(
            array(
				'taxonomy' => 'category',
				'hide_empty' => true,
            )
        );
		if ( ! is_wp_error( $cats ) && is_array( $cats ) ) {
			foreach ( $cats as $t ) {
				$cnt   = (int) ( $t->count ?? 0 );
				$score = min( 80, 45 + (int) ( $cnt / 40 ) );
				$link  = get_category_link( $t->term_id );
				$items[] = array(
					'url' => $link,
					'score' => $score,
				);
				if ( $cnt > $pp ) {
					$items[] = array(
						'url' => trailingslashit( $link ) . 'page/2/',
						'score' => $score - 10,
					);
				}
			}
		}

		// 3) 热门文章 Top-N：插件自带默认数据源（comment_count 代理），主题 / 私有插件经 add_filter('jinyu_warmup_popular_post_ids', fn) 覆盖即可。
		// 彻底去掉「插件硬认知主题函数名」的写法，满足主题 / 插件解耦硬规则。
		$popular_ids = apply_filters( 'jinyu_warmup_popular_post_ids', jinyu_warmup_default_popular_ids( 50 ), 50 );
		foreach ( (array) $popular_ids as $pid ) {
			$p = get_permalink( $pid );
			if ( $p ) {
				$items[] = array(
					'url' => $p,
					'score' => 75,
				);
			}
		}

		// 4) 全量已发布文章，按修改时间降序（新文优先），封顶 warmup_max
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $max_posts > 0 ? $max_posts : -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$n = count( $posts );
		foreach ( $posts as $idx => $pid ) {
			$p = get_permalink( $pid );
			if ( ! $p ) {
				continue;
			}
			// 越新权重越高：50 → 20 线性衰减
			$score   = $n > 1 ? (int) ( 50 - ( $idx / ( $n - 1 ) ) * 30 ) : 50;
			$items[] = array(
				'url' => $p,
				'score' => max( 20, $score ),
			);
		}

		// 5) 自定义附加 URL（站长显式指定，高权重）
		if ( '' !== $extra ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $extra ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$line = esc_url_raw( $line );
				if ( '' !== $line ) {
					$items[] = array(
						'url' => $line,
						'score' => 90,
					);
				}
			}
		}

		// 按权重降序，URL 去重（保留最高权重）
		usort(
			$items,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		$seen = array();
		$out  = array();
		foreach ( $items as $it ) {
			if ( isset( $seen[ $it['url'] ] ) ) {
				continue;
			}
			$seen[ $it['url'] ] = 1;
			$out[]              = $it['url'];
		}

		update_option( 'jinyu_warmup_queue', $out, false );
		update_option( 'jinyu_warmup_queue_sig', $sig, false );
		return $out;
	}
}

/* ── 预热引擎：价值加权队列 + cron 分批 + 跳过已暖（超越 WP Super Cache 的盲爬） ── */

/* 单 URL 抓取，触发整页 / 对象缓存重建；返回 HTTP 状态码，失败返回错误信息串。 */
if ( ! function_exists( 'jinyu_warmup_fetch_one' ) ) {
	function jinyu_warmup_fetch_one( string $url ) {
		/*
		 * 请求 URL 必须与真实访客逐字节一致，绝不追加 query 参数（缓存 key 含完整 URI，
		 * 拼 ?xxx 只会暖到另一个 key，规范 URL 依旧冷）。预热标记走请求头 X-Jinyu-Warmup。
		 */
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'blocking'    => true,
				'sslverify'   => false,
				'headers'     => array( 'X-Jinyu-Warmup' => '1' ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}
		return (int) wp_remote_retrieve_response_code( $resp );
	}
}

/* 批量并发抓取：curl_multi 单批内多路并行，超越 WPSC 的严格串行；无 curl 扩展时回退顺序。 */
if ( ! function_exists( 'jinyu_warmup_fetch_batch' ) ) {
	function jinyu_warmup_fetch_batch( array $urls ): array {
		$out = array();
		if ( empty( $urls ) ) {
			return $out;
		}
		$parallel = (int) apply_filters( 'jinyu_warmup_parallel', 5 );
		$parallel = $parallel > 0 ? $parallel : 5;

		if ( ! function_exists( 'curl_multi_init' ) ) {
			foreach ( $urls as $url ) {
				$out[ $url ] = jinyu_warmup_fetch_one( $url );
			}
			return $out;
		}

		// WP HTTP API 仅支持阻塞请求，无法单批内并行；并发预热必须直接用 curl_multi（有意例外）。
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init,WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_exec,WordPress.WP.AlternativeFunctions.curl_curl_multi_select,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle,WordPress.WP.AlternativeFunctions.curl_curl_close,WordPress.WP.AlternativeFunctions.curl_curl_multi_close
		foreach ( array_chunk( $urls, $parallel ) as $chunk ) {
			$mh   = curl_multi_init();
			$jobs = array();
			foreach ( $chunk as $url ) {
				$ch = curl_init( $url );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $ch, CURLOPT_HEADER, false );
				curl_setopt( $ch, CURLOPT_TIMEOUT, 8 );
				curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
				curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
				curl_setopt(
					$ch,
					CURLOPT_HTTPHEADER,
					array( 'X-Jinyu-Warmup: 1', 'User-Agent: Jinyu-Warmup/1.0' )
				);
				curl_multi_add_handle( $mh, $ch );
				$jobs[] = array(
					'ch'  => $ch,
					'url' => $url,
				);
			}
			$active = null;
			do {
				$status = curl_multi_exec( $mh, $active );
				if ( $active ) {
					curl_multi_select( $mh, 1.0 );
				}
			} while ( $active && CURLM_OK === $status );
			foreach ( $jobs as $job ) {
				$code = (int) curl_getinfo( $job['ch'], CURLINFO_HTTP_CODE );
				$err  = curl_error( $job['ch'] );
				$out[ $job['url'] ] = ( $code >= 200 && $code < 400 ) ? $code : ( '' !== $err ? $err : ( 'HTTP ' . $code ) );
				curl_multi_remove_handle( $mh, $job['ch'] );
			}
			curl_multi_close( $mh );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_multi_init,WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_exec,WordPress.WP.AlternativeFunctions.curl_curl_multi_select,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle,WordPress.WP.AlternativeFunctions.curl_curl_close,WordPress.WP.AlternativeFunctions.curl_curl_multi_close
		return $out;
	}
}

/* 是否已暖：回热窗口内抓过的 URL 视为仍暖，跳过重建（缓存 TTL 内不重复抓，复跑 I/O 大幅削减）。 */
if ( ! function_exists( 'jinyu_warmup_is_hot' ) ) {
	function jinyu_warmup_is_hot( string $url ): bool {
		$reheat = (int) apply_filters( 'jinyu_warmup_reheat_window', 3600 );
		if ( $reheat <= 0 ) {
			return false;
		}
		$map = get_option( 'jinyu_warmup_warmed_at', array() );
		return is_array( $map ) && isset( $map[ $url ] ) && ( time() - (int) $map[ $url ] ) < $reheat;
	}
}

/* Tier1（SLO 保障页）：首页 + 前 3 页 + Top5 分类，每轮忽略回热窗口强制重暖，保证关键页零冷态。 */
if ( ! function_exists( 'jinyu_warmup_tier1_urls' ) ) {
	function jinyu_warmup_tier1_urls(): array {
		$urls      = array( home_url( '/' ) );
		$pp        = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$counts    = wp_count_posts( 'post' );
		$pub       = is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
		$home_pages = min( 3, (int) ceil( $pub / $pp ) );
		for ( $i = 2; $i <= $home_pages; $i++ ) {
			$urls[] = home_url( '/page/' . $i . '/' );
		}
		$cats = get_terms(
			array(
				'taxonomy' => 'category',
				'hide_empty' => true,
				'number' => 5,
				'orderby' => 'count',
				'order' => 'DESC',
			)
		);
		if ( ! is_wp_error( $cats ) && is_array( $cats ) ) {
			foreach ( $cats as $t ) {
				$urls[] = get_category_link( $t->term_id );
			}
		}
		return array_values( array_unique( $urls ) );
	}
}

/* 批大小 / 批间隔 / 负载护栏（均可经过滤器调参；批大小在中载时自适应减半）。 */
if ( ! function_exists( 'jinyu_warmup_batch_size' ) ) {
	function jinyu_warmup_batch_size(): int {
		$n = (int) apply_filters( 'jinyu_warmup_batch_size', 25 );
		// 自适应：系统负载升到阈值 60% 以上时减半，避免雪上加霜（阈值见 jinyu_warmup_load_high）。
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = sys_getloadavg();
			$avg  = is_array( $load ) ? (float) ( $load[0] ?? 0 ) : 0;
			$th   = (float) apply_filters( 'jinyu_warmup_load_threshold', 4.0 );
			if ( $avg > $th * 0.6 ) {
				$n = (int) ( $n / 2 );
			}
		}
		return $n > 0 ? $n : 5;
	}
}
if ( ! function_exists( 'jinyu_warmup_tick_interval' ) ) {
	function jinyu_warmup_tick_interval(): int {
		$n = (int) apply_filters( 'jinyu_warmup_tick_interval', 30 );
		return $n > 0 ? $n : 30;
	}
}
if ( ! function_exists( 'jinyu_warmup_load_high' ) ) {
	function jinyu_warmup_load_high(): bool {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return false;
		}
		$load = sys_getloadavg();
		$avg  = is_array( $load ) ? (float) ( $load[0] ?? 0 ) : 0;
		return $avg > (float) apply_filters( 'jinyu_warmup_load_threshold', 4.0 );
	}
}

/* 分批状态机读写。 */
if ( ! function_exists( 'jinyu_warmup_state_get' ) ) {
	function jinyu_warmup_state_get(): array {
		$def = array(
			'position' => 0,
			'total' => 0,
			'warmed' => 0,
			'running' => false,
			'last_run' => 0,
			'done_at' => 0,
		);
		$s   = get_option( 'jinyu_warmup_state', array() );
		return is_array( $s ) ? array_merge( $def, $s ) : $def;
	}
}
if ( ! function_exists( 'jinyu_warmup_state_set' ) ) {
	function jinyu_warmup_state_set( array $s ): void {
		update_option( 'jinyu_warmup_state', $s, false );
	}
}

/*
 执行一轮预热（手动 / cron 续跑共用）：在「时间预算」内跑若干批，超出或负载高则交 cron 续跑。
 * $fresh=true 时从头开始整轮（手动点击 / 周期重置用）。 */
if ( ! function_exists( 'jinyu_warmup_run' ) ) {
	// 轻量互斥锁：update_option 抢占 + TTL 兜底，避免 AJAX 手动跑与 cron 续跑（或双点击）并发推进同一 state 导致进度互相覆盖 / 重复暖。
	if ( ! function_exists( 'jinyu_warmup_lock_acquire' ) ) {
		function jinyu_warmup_lock_acquire( string $key, int $ttl ): bool {
			$held = get_option( $key, false );
			if ( false !== $held && ( time() - (int) $held ) < $ttl ) {
				return false;
			}
			update_option( $key, time(), 'no' );
			return true;
		}
	}
	if ( ! function_exists( 'jinyu_warmup_lock_release' ) ) {
		function jinyu_warmup_lock_release( string $key ): void {
			delete_option( $key );
		}
	}

	function jinyu_warmup_run( bool $verbose = false, bool $fresh = false ): array {
		$lock_key = 'jinyu_warmup_lock';
		$lock_ttl = 40;
		if ( ! jinyu_warmup_lock_acquire( $lock_key, $lock_ttl ) ) {
			// 已有进程在跑 → 不重复启动，返回上一轮结果，交给前端轮询 / cron 续跑。
			$last = get_option( 'jinyu_warmup_last', array() );
			return is_array( $last ) ? $last : array( 'running' => true );
		}
		try {
			return jinyu_warmup_run_inner( $verbose, $fresh );
		} finally {
			jinyu_warmup_lock_release( $lock_key );
		}
	}

	function jinyu_warmup_run_inner( bool $verbose = false, bool $fresh = false ): array {
		if ( ! jinyu_warmup_is_enabled() ) {
			$s = jinyu_warmup_state_get();
			$s['running'] = false;
			jinyu_warmup_state_set( $s );
			return array(
				'total' => 0,
				'ok' => 0,
				'fail' => 0,
				'hit' => 0,
				'elapsed' => 0,
				'failed' => array(),
				'at' => time(),
				'done' => true,
				'position' => 0,
			);
		}

		$budget     = (float) apply_filters( 'jinyu_warmup_time_budget', 20 );
		$queue      = jinyu_warmup_get_urls();
		$total      = count( $queue );
		$queue_keys = array_flip( $queue );
		$tier1      = jinyu_warmup_tier1_urls();
		$state      = jinyu_warmup_state_get();

		// 整轮重置：队列结构变化（文章数变）或首次，或显式 fresh
		if ( $fresh || (int) ( $state['total'] ?? -1 ) !== $total ) {
			$state = array(
				'position' => 0,
				'total' => $total,
				'warmed' => 0,
				'running' => false,
				'last_run' => 0,
				'done_at' => 0,
			);
		}
		$pos = (int) ( $state['position'] ?? 0 );

		$started = microtime( true );
		$ok      = (int) ( $state['warmed'] ?? 0 );
		$fail    = 0;
		$failed  = array();
		$map     = is_array( get_option( 'jinyu_warmup_warmed_at', array() ) ) ? get_option( 'jinyu_warmup_warmed_at', array() ) : array();
		$hit     = 0;

		// 变更感知：先暖高优 pending（新/改文章），绕过回热窗口，避免「编辑后访客仍踩冷态」。
		// 必须放在「已完成提前返回」之前：否则整轮跑完后新增文章会因 pos>=total 被直接 return 而漏暖。
		$pending = is_array( get_option( 'jinyu_warmup_pending', array() ) ) ? get_option( 'jinyu_warmup_pending', array() ) : array();
		if ( ! empty( $pending ) ) {
			$purls = array_keys( $pending );
			$pres  = jinyu_warmup_fetch_batch( $purls );
			foreach ( $purls as $url ) {
				$code = $pres[ $url ] ?? 0;
				if ( is_int( $code ) && $code >= 200 && $code < 400 ) {
					$map[ $url ] = time();
					if ( ! isset( $queue_keys[ $url ] ) ) {
						++$ok;
					}
				} else {
					++$fail;
					if ( $verbose ) {
						$failed[] = $url . ' :: ' . ( is_int( $code ) ? 'HTTP ' . $code : $code );
					}
				}
			}
			delete_option( 'jinyu_warmup_pending' );
			update_option( 'jinyu_warmup_warmed_at', $map, false );
			$state['warmed'] = $ok;
			jinyu_warmup_state_set( $state );
		}

		// 已完成且未变（且无待暖 pending）→ 直接返回，不抹掉进度
		if ( ! $fresh && $total > 0 && $pos >= $total ) {
			$state['running'] = false;
			jinyu_warmup_state_set( $state );
			$last = get_option( 'jinyu_warmup_last', array() );
			return is_array( $last )
				? $last
				: array(
					'total' => $total,
					'ok' => 0,
					'fail' => 0,
					'hit' => 0,
					'elapsed' => 0,
					'failed' => array(),
					'at' => time(),
					'done' => true,
					'position' => $pos,
				);
		}

		while ( $pos < $total ) {
			if ( jinyu_warmup_load_high() ) { // 负载高，本批让出，交 cron
				break;
			}
			$batch = (int) jinyu_warmup_batch_size();
			$end   = min( $pos + $batch, $total );
			$to_fetch = array();
			for ( $i = $pos; $i < $end; $i++ ) {
				$url = $queue[ $i ];
				// Tier1 关键页忽略回热窗口，每轮强制重暖（SLO 保障零冷态）
				if ( jinyu_warmup_is_hot( $url ) && ! in_array( $url, $tier1, true ) ) {
					++$ok;
					++$hit;
					continue;
				}
				$to_fetch[] = $url;
			}
			if ( ! empty( $to_fetch ) ) {
				$res = jinyu_warmup_fetch_batch( $to_fetch );
				foreach ( $to_fetch as $url ) {
					$code = $res[ $url ] ?? 0;
					if ( is_int( $code ) && $code >= 200 && $code < 400 ) {
						++$ok;
						$map[ $url ] = time();
					} else {
						++$fail;
						if ( $verbose ) {
							$failed[] = $url . ' :: ' . ( is_int( $code ) ? 'HTTP ' . $code : $code );
						}
					}
				}
			}
			$pos               = $end;
			$state['position'] = $pos;
			$state['warmed']   = $ok;
			$state['running']  = true;
			$state['last_run'] = time();
			jinyu_warmup_state_set( $state );
			update_option( 'jinyu_warmup_warmed_at', $map, false );

			if ( ( microtime( true ) - $started ) >= $budget ) {
				break;
			}
		}

		$done = $pos >= $total;
		if ( $done ) {
			$state['running'] = false;
			$state['done_at'] = time();
			jinyu_warmup_state_set( $state );
		}
		if ( ! $done && ! wp_next_scheduled( 'jinyu_warmup_cron' ) ) {
			// 续跑：确保只有一个待执行事件
			wp_schedule_single_event( time() + (int) jinyu_warmup_tick_interval(), 'jinyu_warmup_cron' );
		}

		$result = array(
			'total'   => $total,
			'ok'      => $ok,
			'fail'    => $fail,
			'hit'     => $hit,
			'elapsed' => round( microtime( true ) - $started, 2 ),
			'failed'  => $failed,
			'at'      => time(),
			'done'    => $done,
			'position' => $pos,
		);
		update_option( 'jinyu_warmup_last', $result, false );
		return $result;
	}
}

/* cron 续跑回调：从状态机断点继续（关闭后事件已被注销，此处双保险）。 */
if ( ! function_exists( 'jinyu_warmup_cron_event' ) ) {
	function jinyu_warmup_cron_event(): void {
		if ( jinyu_warmup_is_enabled() ) {
			jinyu_warmup_run();
		}
	}
	add_action( 'jinyu_warmup_cron', 'jinyu_warmup_cron_event' );
}

/* 整轮回调：重置游标后跑完整队列（周期定时 / 手动整轮用），失败 URL 会在本轮重试。 */
if ( ! function_exists( 'jinyu_warmup_cycle_event' ) ) {
	function jinyu_warmup_cycle_event(): void {
		if ( jinyu_warmup_is_enabled() ) {
			jinyu_warmup_run( false, true );
		}
	}
	add_action( 'jinyu_warmup_cycle', 'jinyu_warmup_cycle_event' );
}

/* 注册 15 分钟周期（WP 无内建，需自定义）。 */
add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		$schedules['jinyu_quarter_hour'] = [
			'interval' => 900,
			'display'  => __( '每 15 分钟', 'jinyu-theme-companion' ),
		];
		return $schedules;
	}
);

/* 按 interval 重挂 cron（保存设置后调用）。周期事件走 jinyu_warmup_cycle（整轮重置）。 */
if ( ! function_exists( 'jinyu_warmup_reschedule' ) ) {
	function jinyu_warmup_reschedule(): void {
		/*
		 * 用公开 API wp_clear_scheduled_hook：它以 isset 守卫遍历 cron 数组，
		 * 对「顶层混入标量键（如历史遗留的 times / version 之外的键）的站点」安全。
		 * wp_unschedule_hook 会对这些标量下标无条件 unset，PHP 8 下直接抛 Error —— 本站真实踩到过
		 * （cron option 里残留 times / wp_maybe_next_update，保存设置即 500）。
		 */
		wp_clear_scheduled_hook( 'jinyu_warmup_cron' );
		wp_clear_scheduled_hook( 'jinyu_warmup_cycle' );
		if ( ! jinyu_warmup_is_enabled() ) {
			return;
		}
		$interval = function_exists( 'jinyu_perf_get_options' ) ? ( jinyu_perf_get_options()['warmup_interval'] ?? 'manual' ) : 'manual';
		if ( 'manual' === $interval ) {
			return;
		}
		$map = array(
			'15min' => 'jinyu_quarter_hour',
			'hourly' => 'hourly',
			'daily'  => 'daily',
		);
		$hook = $map[ $interval ] ?? 'daily';
		if ( ! wp_get_scheduled_event( 'jinyu_warmup_cycle' ) ) {
			wp_schedule_event( time() + 60, $hook, 'jinyu_warmup_cycle' );
		}
	}
}

/* 防抖触发：清缓存 / 升级后排程一次整轮预热（fire-and-forget，不阻塞当前请求）。 */
if ( ! function_exists( 'jinyu_warmup_schedule_once' ) ) {
	function jinyu_warmup_schedule_once(): void {
		if ( ! jinyu_warmup_is_enabled() ) {
			return;
		}
		// 预热请求自身（带 X-Jinyu-Warmup 头）不得再排程，杜绝「预热触发预热」的递归。
		if ( ! empty( $_SERVER['HTTP_X_JINYU_WARMUP'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 仅做存在性判断，不落库、不输出。
			return;
		}
		static $queued = false;
		if ( $queued ) {
			return;
		}
		$queued = true;
		// 缓存刚清，全部转冷 → 整轮重置重暖（而非续跑断点）。
		if ( ! wp_next_scheduled( 'jinyu_warmup_cycle' ) ) {
			wp_schedule_single_event( time() + 5, 'jinyu_warmup_cycle' );
		}
	}
}

/* 自动触发点 1：清缓存后（perf-center 在 jinyu_perf_flush_caches 末尾 do_action 本钩子）。 */
add_action( 'jinyu_warmup_trigger', 'jinyu_warmup_schedule_once' );

/* 自动触发点 2：主题 / 插件 / 核心升级完成后。 */
add_action( 'upgrader_process_complete', 'jinyu_warmup_schedule_once', 10 );

/* 自动触发点 3：发布 / 更新已发布文章时，把其 URL 排进高优 pending，3s 后 cron 优先暖。 */
if ( ! function_exists( 'jinyu_warmup_on_save_post' ) ) {
	function jinyu_warmup_on_save_post( int $post_id, WP_Post $post, bool $update ): void {
		if ( ! jinyu_warmup_is_enabled() ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		// 预热请求自身不得再排程，杜绝递归。
		if ( ! empty( $_SERVER['HTTP_X_JINYU_WARMUP'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 仅做存在性判断，不落库、不输出。
			return;
		}
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return;
		}
		$pending = get_option( 'jinyu_warmup_pending', array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$pending[ $url ] = time();
		update_option( 'jinyu_warmup_pending', $pending, false );
		if ( ! wp_next_scheduled( 'jinyu_warmup_cron' ) ) {
			wp_schedule_single_event( time() + 3, 'jinyu_warmup_cron' );
		}
	}
	add_action( 'save_post', 'jinyu_warmup_on_save_post', 20, 3 );
}

/* ── AJAX：保存预热设置（interval / urls），可选立即触发 ── */
add_action( 'wp_ajax_jinyu_perf_warmup', 'jinyu_perf_ajax_warmup' );
if ( ! function_exists( 'jinyu_perf_ajax_warmup' ) ) {
	function jinyu_perf_ajax_warmup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'msg' => __( '权限不足', 'jinyu-theme-companion' ) ] );
		}
		if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'jinyu_perf_center' ) ) {
			wp_send_json_error( [ 'msg' => __( '安全校验失败，请刷新页面后重试', 'jinyu-theme-companion' ) ] );
		}

		$opts = function_exists( 'jinyu_perf_get_options' ) ? jinyu_perf_get_options() : [];
		$int  = isset( $_POST['interval'] ) ? sanitize_key( wp_unslash( $_POST['interval'] ) ) : 'manual';
		$int  = array_key_exists( $int, jinyu_warmup_intervals() ) ? $int : 'manual';
		$opts['warmup_interval'] = $int;
		// 启用总开关：前台加速卡片经独立 AJAX 落库（与性能中心开关同一 option 真源）。
		$opts['warmup_enable'] = ! empty( $_POST['enable'] ) ? 1 : 0;
		if ( isset( $_POST['urls'] ) ) {
			$opts['warmup_urls'] = sanitize_textarea_field( wp_unslash( $_POST['urls'] ) );
		}
		if ( isset( $_POST['max'] ) ) {
			$opts['warmup_max'] = max( 0, (int) $_POST['max'] );
		}
		if ( function_exists( 'jinyu_perf_get_options' ) ) {
			update_option( 'jinyu_perf_options_v2', $opts, false );
			jinyu_perf_get_options( true );
		}
		jinyu_warmup_reschedule();

		$run    = ! empty( $_POST['run'] ) && ! empty( $_POST['enable'] );
		// 已在跑则续跑（fresh=false，不从头重置），否则整轮重置起步。避免点击打断后台 cron / 变更感知任务。
		$already_running = function_exists( 'jinyu_warmup_state_get' ) ? (bool) ( jinyu_warmup_state_get()['running'] ?? false ) : false;
		$result          = $run ? jinyu_warmup_run( true, ! $already_running ) : null;
		wp_send_json_success(
			[
				'msg'    => $run ? __( '已启动预热', 'jinyu-theme-companion' ) : __( '设置已保存', 'jinyu-theme-companion' ),
				'result' => $result,
			]
		);
	}
}

/* AJAX：轮询预热进度（面板进度条用）。 */
add_action( 'wp_ajax_jinyu_perf_warmup_status', 'jinyu_perf_ajax_warmup_status' );
if ( ! function_exists( 'jinyu_perf_ajax_warmup_status' ) ) {
	function jinyu_perf_ajax_warmup_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'msg' => __( '权限不足', 'jinyu-theme-companion' ) ] );
		}
		if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'jinyu_perf_center' ) ) {
			wp_send_json_error( [ 'msg' => __( '安全校验失败，请刷新页面后重试', 'jinyu-theme-companion' ) ] );
		}
		$state = function_exists( 'jinyu_warmup_state_get' ) ? jinyu_warmup_state_get() : array();
		$last  = get_option( 'jinyu_warmup_last', array() );
		wp_send_json_success(
			[
				'enabled' => function_exists( 'jinyu_warmup_is_enabled' ) ? jinyu_warmup_is_enabled() : false,
				'state'   => $state,
				'last'    => is_array( $last ) ? $last : array(),
			]
		);
	}
}
