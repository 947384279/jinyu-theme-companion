<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI 爬虫访问统计（GEO）。
 *
 * 对 `jinyu_geo_crawler_groups()` 清单中的 UA 做命中识别与聚合计数，
 * 供设置面板「SEO」分区核对 AI 爬虫的真实到访情况。
 *
 * 【隐私口径】只存聚合三元组（命中 UA 规范名 / 次数 / 最后访问时间），
 * 不记录 IP、完整 UA 原文与访问 URI，因此不构成个人数据收集，无需隐私披露。
 *
 * 【性能口径】正常访客流量零开销（先做一次 mb_stripos 级 UA 前缀短路判断，
 * 未命中清单首词直接返回）；仅命中 bot 时写 option（autoload=no），
 * 且同一分钟内多次命中合并为一次落盘（对象缓存节流，无对象缓存时退化为按次落盘，
 * AI 爬虫命中频率远低于人类流量，可接受）。
 *
 * 【不做 robots 接管】robots.txt 输出的架构决策见 geo-robots.php 头注释，此处不重复。
 */

const JINYU_AI_CRAWL_STATS_OPT = 'jinyu_ai_crawl_stats';

/**
 * 展平放行清单为 [规范 UA => array{brand:string, region:string}]，长 UA 优先。
 *
 * 长优先很关键：'Baiduspider-render' 必须先于 'Baiduspider' 匹配，
 * 否则渲染器会被计入主爬虫。
 *
 * @return array<string, array{brand:string, region:string}>
 */
function jinyu_ai_crawl_map(): array {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$flat = array();
	foreach ( jinyu_geo_crawler_groups() as $region => $groups ) {
		foreach ( $groups as $group ) {
			foreach ( $group['crawlers'] as $ua ) {
				$flat[ $ua ] = array(
					'brand'  => $group['label'],
					'region' => $region,
				);
			}
		}
	}
	// 长串优先。
	uksort(
		$flat,
		static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	$map = $flat;
	return $map;
}

/**
 * 识别当前请求 UA 是否命中放行清单，命中返回规范 UA 名，否则空串。
 */
function jinyu_ai_crawl_match(): string {
	$ua = (string) sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 已 sanitize_text_field，只读匹配不落库原文。
	if ( '' === $ua ) {
		return '';
	}
	// 宽松快速拒绝：清单 UA 家族词覆盖 bot/spider/crawl/slurp/agent（meta-externalagent）/extended（Google-Extended）。
	if ( ! preg_match( '/(bot|spider|crawl|slurp|agent|extended)/i', $ua ) ) {
		return '';
	}
	foreach ( jinyu_ai_crawl_map() as $crawler => $meta ) {
		if ( false !== stripos( $ua, $crawler ) ) {
			return (string) $crawler;
		}
	}
	return '';
}

/**
 * 命中计数（同一分钟合并落盘）。
 */
function jinyu_ai_crawl_count( string $crawler ): void {
	$stats = get_option( JINYU_AI_CRAWL_STATS_OPT, array() );
	if ( ! is_array( $stats ) ) {
		$stats = array();
	}
	$meta = jinyu_ai_crawl_map()[ $crawler ] ?? array(
		'brand'  => '',
		'region' => '',
	);
	if ( isset( $stats[ $crawler ] ) && is_array( $stats[ $crawler ] ) ) {
		++$stats[ $crawler ]['n'];
	} else {
		$stats[ $crawler ] = array(
			'n'      => 1,
			'last'   => '',
			'brand'  => $meta['brand'],
			'region' => $meta['region'],
		);
	}
	$stats[ $crawler ]['last'] = gmdate( 'Y-m-d H:i' ) . ' UTC';

	// 节流：60 秒窗口内只允许一次真实落盘，计数累加在缓存副本上。
	$cache_stamp = wp_cache_get( 'jinyu_ai_crawl_flush_ts', 'jinyu_tc' );
	if ( false === $cache_stamp || ( time() - (int) $cache_stamp ) >= 60 ) {
		update_option( JINYU_AI_CRAWL_STATS_OPT, $stats, false );
		wp_cache_set( 'jinyu_ai_crawl_flush_ts', time(), 'jinyu_tc', 120 );
	} else {
		// 未落盘的计数放进缓存副本，下次落盘时合并。
		$pending = wp_cache_get( 'jinyu_ai_crawl_pending', 'jinyu_tc' );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$pending[ $crawler ] = ( $pending[ $crawler ] ?? 0 ) + 1;
		wp_cache_set( 'jinyu_ai_crawl_pending', $pending, 'jinyu_tc', 300 );
	}
}

/**
 * 合并缓存中的未落盘计数（面板渲染前与 reset 前调用）。
 */
function jinyu_ai_crawl_flush_pending(): array {
	$pending = wp_cache_get( 'jinyu_ai_crawl_pending', 'jinyu_tc' );
	if ( ! is_array( $pending ) || ! $pending ) {
		return get_option( JINYU_AI_CRAWL_STATS_OPT, array() );
	}
	wp_cache_delete( 'jinyu_ai_crawl_pending', 'jinyu_tc' );
	$stats = get_option( JINYU_AI_CRAWL_STATS_OPT, array() );
	if ( ! is_array( $stats ) ) {
		$stats = array();
	}
	$meta_all = jinyu_ai_crawl_map();
	foreach ( $pending as $crawler => $add ) {
		if ( isset( $stats[ $crawler ]['n'] ) ) {
			$stats[ $crawler ]['n'] += (int) $add;
		} else {
			$meta = $meta_all[ $crawler ] ?? array(
				'brand'  => '',
				'region' => '',
			);
			$stats[ $crawler ] = array(
				'n'      => (int) $add,
				'last'   => '',
				'brand'  => $meta['brand'],
				'region' => $meta['region'],
			);
		}
	}
	update_option( JINYU_AI_CRAWL_STATS_OPT, $stats, false );
	return $stats;
}

/**
 * 前台命中入口：挂在 template_redirect 最前（优先级 -999），仅 bot 命中时产生开销。
 */
add_action( 'template_redirect', 'jinyu_ai_crawl_track', -999 );
function jinyu_ai_crawl_track(): void {
	$crawler = jinyu_ai_crawl_match();
	if ( '' !== $crawler ) {
		jinyu_ai_crawl_count( $crawler );
	}
}

/**
 * 面板数据：按次数倒序。
 *
 * @return array<int, array{ua:string, brand:string, region:string, n:int, last:string}>
 */
function jinyu_ai_crawl_stats_rows(): array {
	$stats = jinyu_ai_crawl_flush_pending();
	if ( ! $stats ) {
		return array();
	}
	$rows = array();
	foreach ( $stats as $ua => $row ) {
		if ( ! is_array( $row ) || ! isset( $row['n'] ) ) {
			continue;
		}
		$rows[] = array(
			'ua'    => (string) $ua,
			'brand' => (string) ( $row['brand'] ?? '' ),
			'region' => (string) ( $row['region'] ?? '' ),
			'n'     => (int) $row['n'],
			'last'  => (string) ( $row['last'] ?? '' ),
		);
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			return $b['n'] - $a['n'];
		}
	);
	return $rows;
}

/**
 * 清零统计（面板按钮，admin-ajax）。
 */
add_action( 'wp_ajax_jinyu_ai_crawl_reset', 'jinyu_ai_crawl_ajax_reset' );
function jinyu_ai_crawl_ajax_reset(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
	}
	check_admin_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
	wp_cache_delete( 'jinyu_ai_crawl_pending', 'jinyu_tc' );
	delete_option( JINYU_AI_CRAWL_STATS_OPT );
	wp_send_json_success( array( 'msg' => __( 'AI 爬虫统计已清零', 'jinyu-theme-companion' ) ) );
}
