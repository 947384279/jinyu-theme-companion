<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 全量补推：把存量已发布文章/页面批量提交给 IndexNow 与百度，
 * 解决「仅在发布/更新时推送、历史文章从未提交」的收录盲区。
 */

add_action( 'wp_ajax_jinyu_bulk_push', 'jinyu_bulk_push_ajax' );

function jinyu_bulk_push_ajax(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '权限不足' );
	}
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );

	$result = jinyu_bulk_push_run();

	// 留痕：无论成功失败都记一条，后台「推送记录」卡片可回溯每次补推的结果。
	if ( function_exists( 'jinyu_push_log_add' ) ) {
		$data = is_array( $result['data'] ) ? $result['data'] : [];
		if ( $result['ok'] ) {
			foreach ( [ 'indexnow' => 'IndexNow', 'baidu' => '百度主动推送' ] as $key => $label ) {
				$st = isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : null;
				if ( ! $st || empty( $st['enabled'] ) ) {
					continue;
				}
				jinyu_push_log_add(
					$label,
					__( '全量补推', 'jinyu-theme-companion' ),
					(int) $st['sent'],
					$st['ok'] ? 'ok' : 'fail',
					sprintf( /* translators: %s: HTTP response code */ __( '接口返回 %s', 'jinyu-theme-companion' ), (string) ( $st['code'] ?? '-' ) )
				);
			}
		} else {
			jinyu_push_log_add( '—', __( '全量补推', 'jinyu-theme-companion' ), 0, 'fail', is_string( $result['data'] ) ? $result['data'] : '' );
		}
	}

	if ( $result['ok'] ) {
		wp_send_json_success( $result['data'] );
	}
	wp_send_json_error( $result['data'] );
}

/**
 * 收集已发布文章/页面的永久链接，分别批量提交到已启用的渠道。
 * IndexNow 支持文章与页面；百度仅收文章类型。
 *
 * @return array{ok:bool,data:mixed}
 */
function jinyu_bulk_push_run(): array {
	$posts = get_posts(
		[
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]
	);

	$post_urls = []; // 仅文章（百度）
	$all_urls  = []; // 文章 + 页面（IndexNow）

	foreach ( $posts as $pid ) {
		$u = get_permalink( $pid );
		if ( ! $u ) {
			continue;
		}
		$all_urls[] = $u;
		if ( get_post_type( $pid ) === 'post' ) {
			$post_urls[] = $u;
		}
	}

	$indexnow = [ 'enabled' => false, 'total' => count( $all_urls ), 'sent' => 0, 'ok' => 0 ];
	$baidu    = [ 'enabled' => false, 'total' => count( $post_urls ), 'sent' => 0, 'ok' => 0 ];

	// IndexNow：单次请求最多 10000 条，全量一份直发。
	if ( jinyu_companion_is_checked( 'indexnow_enable', false ) ) {
		$key = (string) get_option( 'jinyu_indexnow_key' );
		if ( $key && $all_urls ) {
			$indexnow['enabled'] = true;
			$host                = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$payload             = wp_json_encode(
				[
					'host'        => $host,
					'key'         => $key,
					'keyLocation' => home_url( '/' . $key . '.txt' ),
					'urlList'     => $all_urls,
				],
				JSON_UNESCAPED_SLASHES
			);
			$resp                = wp_remote_post(
				'https://api.indexnow.org/indexnow',
				[
					'headers'   => [ 'Content-Type' => 'application/json; charset=utf-8' ],
					'body'      => $payload,
					'timeout'   => 20,
					'blocking'  => true,
					'sslverify' => true,
				]
			);
			$indexnow['sent']    = count( $all_urls );
			$code                = wp_remote_retrieve_response_code( $resp );
			$indexnow['code']    = (int) $code;
			$indexnow['ok']      = ( 200 === $code || 202 === $code ) ? 1 : 0;
		}
	}

	// 百度：单请求上限 2000 条，按 1000 条分块发送。
	$token = (string) jinyu_companion_get_option( 'baidu_submit_token', '' );
	if ( $token && $post_urls ) {
		$baidu['enabled'] = true;
		$baidu_ok         = true;
		$baidu_code       = 0;
		foreach ( array_chunk( $post_urls, 1000 ) as $chunk ) {
			$resp         = wp_remote_post(
				$token,
				[
					'timeout'   => 20,
					'blocking'  => true,
					'headers'   => [ 'Content-Type' => 'text/plain' ],
					'body'      => implode( "\n", $chunk ),
					'sslverify' => (bool) apply_filters( 'jinyu_baidu_push_ssl_verify', true ),
				]
			);
			$baidu['sent'] += count( $chunk );
			$code           = wp_remote_retrieve_response_code( $resp );
			$baidu_code     = (int) $code;
			if ( 200 !== $code ) {
				$baidu_ok = false;
			}
		}
		$baidu['ok']   = $baidu_ok ? 1 : 0;
		$baidu['code'] = $baidu_code;
	}

	if ( ! $indexnow['enabled'] && ! $baidu['enabled'] ) {
		return [ 'ok' => false, 'data' => '未开启 IndexNow 且未填写百度接口，暂无可推送渠道。' ];
	}

	return [
		'ok'   => true,
		'data' => [ 'indexnow' => $indexnow, 'baidu' => $baidu ],
	];
}
