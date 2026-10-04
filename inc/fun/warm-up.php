<?php
/**
 * 缓存预热（Cache Warm-up）。
 *
 * 解决「冷态尖刺」：整页缓存 / 对象缓存只在首个真实访客请求时重建（首页冷态约 80+ SQL，
 * 重建耗时 1 秒级），之后访客命中热态（个位数 SQL）。预热把这些重建成本分摊到
 * 后台主动爬取请求，使真实访客永远命中热缓存。
 *
 * 设计约束：
 *  - 默认关闭，由站长在「性能中心 → 缓存预热」显式开启（自发外请求是敏感操作，不应静默默认开）。
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

/* 收集预热 URL：核心关键页（自动）+ 站长自定义附加。 */
if ( ! function_exists( 'jinyu_warmup_get_urls' ) ) {
	function jinyu_warmup_get_urls(): array {
		$urls = [];

		// 1) 首页 + 列表前 2 页（分页上限由「阅读→每页文章数」决定）
		$urls[] = home_url( '/' );
		$pp     = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$counts = wp_count_posts( 'post' );
		$pub    = is_object( $counts ) ? (int) ( $counts->publish ?? 0 ) : 0;
		$pages  = min( 2, (int) ceil( $pub / $pp ) );
		for ( $i = 2; $i <= $pages; $i++ ) {
			$urls[] = home_url( '/page/' . $i . '/' );
		}

		// 2) 分类归档前 2 页（最多 8 个非空分类，避免列表爆炸）
		$cats = get_terms(
			[
				'taxonomy'   => 'category',
				'fields'     => 'ids',
				'number'     => 8,
				'hide_empty' => true,
			]
		);
		if ( ! is_wp_error( $cats ) && is_array( $cats ) ) {
			foreach ( $cats as $cid ) {
				$link = get_category_link( $cid );
				$urls[] = $link;
				$term = get_term( $cid, 'category' );
				$count = is_object( $term ) ? (int) ( $term->count ?? 0 ) : 0;
				// 仅当该分类文章数超过一页时才预热第 2 页，避免空分页 404
				if ( $count > $pp ) {
					$urls[] = trailingslashit( $link ) . 'page/2/';
				}
			}
		}

		// 3) 近期文章前 10 篇（直接命中单篇整页缓存 + 对象缓存）
		$recent = get_posts(
			[
				'posts_per_page' => 10,
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'fields'         => 'ids',
			]
		);
		foreach ( $recent as $pid ) {
			$urls[] = get_permalink( $pid );
		}

		// 4) 热门文章前 10 篇（按浏览量，若插件提供该聚合）
		if ( function_exists( 'jinyu_get_popular_post_ids' ) ) {
			foreach ( (array) jinyu_get_popular_post_ids( 10 ) as $pid ) {
				$permalink = get_permalink( $pid );
				if ( $permalink ) {
					$urls[] = $permalink;
				}
			}
		}

		// 5) 自定义附加 URL（设置面板填写，每行一个；仅本域名或显式 http(s)）
		$extra = function_exists( 'jinyu_perf_get_options' ) ? ( jinyu_perf_get_options()['warmup_urls'] ?? '' ) : '';
		if ( is_string( $extra ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $extra ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$line = esc_url_raw( $line );
				if ( '' !== $line ) {
					$urls[] = $line;
				}
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}
}

/* 执行一次预热：依次请求各 URL，触发整页 / 对象缓存重建。返回结果快照。 */
if ( ! function_exists( 'jinyu_warmup_run' ) ) {
	function jinyu_warmup_run( bool $verbose = false ): array {
		$started = microtime( true );
		$urls    = jinyu_warmup_get_urls();
		$ok      = 0;
		$fail    = 0;
		$failed  = [];

		foreach ( $urls as $url ) {
			/*
			 * 关键：请求 URL 必须与真实访客逐字节一致，绝不能追加任何 query 参数。
			 * 整页缓存的 key 含完整 URI（本机 nginx 为 fastcgi_cache_key "$scheme$host$request_uri"，
			 * PHP 落盘层为 path+query 的 md5），给预热请求拼上 ?xxx 只会暖到「另一个 key」，
			 * 规范 URL 依旧冷 —— 预热等于空转（实测：预热后 / 仍是 MISS，只有 /?jinyu_warmup=1 被缓存）。
			 * 预热标记一律走请求头 X-Jinyu-Warmup，不进 URL。
			 */
			$resp = wp_remote_get(
				$url,
				[
					'timeout'     => 8,
					'redirection' => 0,
					'blocking'    => true,
					'sslverify'   => false,
					'headers'     => [ 'X-Jinyu-Warmup' => '1' ],
				]
			);
			if ( is_wp_error( $resp ) ) {
				++$fail;
				if ( $verbose ) {
					$failed[] = $url . ' :: ' . $resp->get_error_message();
				}
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code >= 200 && $code < 400 ) {
				++$ok;
			} else {
				++$fail;
				if ( $verbose ) {
					$failed[] = $url . ' :: HTTP ' . $code;
				}
			}
		}

		$result = [
			'total'   => count( $urls ),
			'ok'      => $ok,
			'fail'    => $fail,
			'elapsed' => round( microtime( true ) - $started, 2 ),
			'failed'  => $failed,
			'at'      => time(),
		];
		update_option( 'jinyu_warmup_last', $result, false );
		return $result;
	}
}

/* cron 事件回调：仅在开启时执行（关闭后事件已被注销，此处双保险）。 */
if ( ! function_exists( 'jinyu_warmup_cron_event' ) ) {
	function jinyu_warmup_cron_event(): void {
		if ( jinyu_warmup_is_enabled() ) {
			jinyu_warmup_run();
		}
	}
	add_action( 'jinyu_warmup_cron', 'jinyu_warmup_cron_event' );
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

/* 按 interval 重挂 cron（保存设置后调用）。 */
if ( ! function_exists( 'jinyu_warmup_reschedule' ) ) {
	function jinyu_warmup_reschedule(): void {
		/*
		 * 用公开 API wp_clear_scheduled_hook：它以 isset 守卫遍历 cron 数组，
		 * 对「顶层混入标量键（如历史遗留的 times / version 之外的键）的站点」安全。
		 * wp_unschedule_hook 会对这些标量下标无条件 unset，PHP 8 下直接抛 Error —— 本站真实踩到过
		 * （cron option 里残留 times / wp_maybe_next_update，保存设置即 500）。
		 */
		wp_clear_scheduled_hook( 'jinyu_warmup_cron' );
		if ( ! jinyu_warmup_is_enabled() ) {
			return;
		}
		$interval = function_exists( 'jinyu_perf_get_options' ) ? ( jinyu_perf_get_options()['warmup_interval'] ?? 'manual' ) : 'manual';
		if ( 'manual' === $interval ) {
			return;
		}
		$map = [
			'15min' => 'jinyu_quarter_hour',
			'hourly' => 'hourly',
			'daily'  => 'daily',
		];
		$hook = $map[ $interval ] ?? 'daily';
		if ( ! wp_get_scheduled_event( 'jinyu_warmup_cron' ) ) {
			wp_schedule_event( time() + 60, $hook, 'jinyu_warmup_cron' );
		}
	}
}

/* 防抖队列：同一次请求内多次 flush / 升级只预热一次，延后到 shutdown 执行不阻塞当前请求。 */
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
		add_action(
			'shutdown',
			static function () {
				jinyu_warmup_run();
			},
			99
		);
	}
}

/* 自动触发点 1：清缓存后（perf-center 在 jinyu_perf_flush_caches 末尾 do_action 本钩子）。 */
add_action( 'jinyu_warmup_trigger', 'jinyu_warmup_schedule_once' );

/* 自动触发点 2：主题 / 插件 / 核心升级完成后。 */
add_action( 'upgrader_process_complete', 'jinyu_warmup_schedule_once', 10 );

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
		if ( isset( $_POST['urls'] ) ) {
			$opts['warmup_urls'] = sanitize_textarea_field( wp_unslash( $_POST['urls'] ) );
		}
		if ( function_exists( 'jinyu_perf_get_options' ) ) {
			update_option( 'jinyu_perf_options_v2', $opts, false );
			jinyu_perf_get_options( true );
		}
		jinyu_warmup_reschedule();

		$run    = ! empty( $_POST['run'] );
		$result = $run ? jinyu_warmup_run( true ) : null;
		wp_send_json_success(
			[
				'msg'    => $run ? __( '预热完成', 'jinyu-theme-companion' ) : __( '设置已保存', 'jinyu-theme-companion' ),
				'result' => $result,
			]
		);
	}
}
