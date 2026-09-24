<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Web Vitals 接收端点（同源 AJAX，来自前端原生 PerformanceObserver 采集）。
 * 仅本地聚合，供后台「性能优化中心」展示。无私有后端依赖。 */

/**
 * 本地聚合真实用户指标，供后台「性能优化中心」展示。
 *
 * 设计：滚动 7 天窗口，仅维护 sum / n / max / 热门路径，不存逐条样本，
 * 写入成本恒定（一次 option 读改写），可承受每访客一次的上报频率。
 *
 * @param array $m 单条上报（lcp/inp/cls/fcp/ttfb 数值 + path）
 */
function jinyu_web_vitals_record( array $m ): void {
	$key = 'jinyu_web_vitals_stats';
	$agg = get_option( $key );
	if ( ! is_array( $agg ) ) {
		$agg = [ 'ts' => 0, 'n' => 0, 'sum' => [], 'max' => [], 'paths' => [] ];
	}
	// 7 天窗口过期则重置，避免历史样本长期稀释当前均值
	if ( empty( $agg['ts'] ) || ( time() - (int) $agg['ts'] ) > WEEK_IN_SECONDS ) {
		$agg = [ 'ts' => time(), 'n' => 0, 'sum' => [], 'max' => [], 'paths' => [] ];
	}

	$agg['ts'] = time();
	$agg['n']  = (int) ( $agg['n'] ?? 0 ) + 1;
	foreach ( [ 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ] as $k ) {
		$v            = isset( $m[ $k ] ) ? (float) $m[ $k ] : 0;
		$agg['sum'][ $k ] = (float) ( $agg['sum'][ $k ] ?? 0 ) + $v;
		$agg['max'][ $k ] = max( (float) ( $agg['max'][ $k ] ?? 0 ), $v );
	}
	if ( ! empty( $m['path'] ) ) {
		$p                 = substr( (string) $m['path'], 0, 255 );
		$agg['paths'][ $p ] = (int) ( $agg['paths'][ $p ] ?? 0 ) + 1;
		if ( count( $agg['paths'] ) > 50 ) {
			arsort( $agg['paths'] );
			$agg['paths'] = array_slice( $agg['paths'], 0, 50, true );
		}
		// 逐路径 LCP 聚合（按最差 LCP 排序即「最慢路径」），上限 50 条路径
		if ( ! isset( $agg['path_metrics'][ $p ] ) || ! is_array( $agg['path_metrics'][ $p ] ) ) {
			$agg['path_metrics'][ $p ] = [ 'n' => 0, 'lcp_sum' => 0.0, 'lcp_max' => 0.0, 'cls_max' => 0.0 ];
		}
		$pm              = &$agg['path_metrics'][ $p ];
		$pm['n']         += 1;
		$pm['lcp_sum']   += (float) ( $m['lcp'] ?? 0 );
		$pm['lcp_max']   = max( (float) ( $pm['lcp_max'] ?? 0 ), (float) ( $m['lcp'] ?? 0 ) );
		$pm['cls_max']   = max( (float) ( $pm['cls_max'] ?? 0 ), (float) ( $m['cls'] ?? 0 ) );
		unset( $pm );
		if ( count( $agg['path_metrics'] ) > 50 ) {
			uasort( $agg['path_metrics'], static function ( $a, $b ) {
				return ( (float) ( $b['lcp_max'] ?? 0 ) ) <=> ( (float) ( $a['lcp_max'] ?? 0 ) );
			} );
			$agg['path_metrics'] = array_slice( $agg['path_metrics'], 0, 50, true );
		}
	}

	update_option( $key, $agg, false );
}

function jinyu_ajax_web_vitals() {
	// 仅接受 POST
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		wp_send_json_error( 'method', 405 );
	}

	// 匿名端点防滥用：每 IP 每小时最多 30 次上报，防恶意刷库放大攻击（一次上报=一次 option 读改写）
	if ( ! jinyu_rate_limit_check( 'web_vitals', 30, HOUR_IN_SECONDS ) ) {
		wp_send_json_error( 'rate_limited', 429 );
	}

	$raw = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		wp_send_json_error( 'bad_payload' );
	}

	$metrics = [];
	foreach ( [ 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ] as $k ) {
		if ( isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ) {
			$v = floatval( $data[ $k ] );
			// 合理性校验：指标毫秒/无单位数均不可能为负或超过 10 分钟，过滤脏数据污染统计
			if ( $v >= 0 && $v < 600000 ) {
				$metrics[ $k ] = $v;
			}
		}
	}
	if ( empty( $metrics ) ) {
		wp_send_json_error( 'empty_metrics' );
	}
	if ( ! empty( $data['path'] ) ) {
		$metrics['path'] = substr( esc_url_raw( $data['path'] ), 0, 255 );
	}

	// 本地落库（真实用户指标供性能中心展示）
	jinyu_web_vitals_record( $metrics );

	wp_send_json_success();
}
add_action( 'wp_ajax_nopriv_jinyu_web_vitals', 'jinyu_ajax_web_vitals' );
add_action( 'wp_ajax_jinyu_web_vitals', 'jinyu_ajax_web_vitals' );
