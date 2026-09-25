<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 推送记录
 *
 * 记录 IndexNow / 百度主动推送每次提交的渠道、条数与结果，最多滚动保留 20 条。
 * 解决「发布时推送与全量补推都是发完即忘、后台看不到是否推成功」的盲区。
 *
 * 说明：单篇发布/更新的推送是非阻塞请求（不拖慢后台保存），无法读取响应码，
 * 因此状态记为「已提交（异步）」；全量补推是阻塞请求，可记录真实成功/失败与 HTTP 码。
 *
 * 存储：站点选项 jinyu_companion_push_log（autoload=no，不在每次请求加载）。
 */

/** 推送记录选项名。 */
function jinyu_push_log_option(): string {
	return 'jinyu_companion_push_log';
}

/** 单条记录上限（滚动覆盖最旧的）。 */
function jinyu_push_log_max(): int {
	return 20;
}

/**
 * 追加一条推送记录。
 *
 * @param string $channel 渠道名，如 IndexNow / 百度主动推送。
 * @param string $action  动作名，如 发布推送 / 全量补推。
 * @param int    $count   本次提交的 URL 条数。
 * @param string $status  ok / fail / sent。
 * @param string $note    备注，如 HTTP 响应码。
 */
function jinyu_push_log_add( string $channel, string $action, int $count, string $status, string $note = '' ): void {
	$log = get_option( jinyu_push_log_option(), [] );
	if ( ! is_array( $log ) ) {
		$log = [];
	}

	array_unshift(
		$log,
		[
			'time'    => time(),
			'channel' => sanitize_text_field( $channel ),
			'action'  => sanitize_text_field( $action ),
			'count'   => max( 0, $count ),
			'status'  => in_array( $status, [ 'ok', 'fail', 'sent' ], true ) ? $status : 'sent',
			'note'    => sanitize_text_field( $note ),
		]
	);

	$log = array_slice( $log, 0, jinyu_push_log_max() );
	update_option( jinyu_push_log_option(), $log, false );
}

/**
 * 读取推送记录（最新在前）。
 *
 * @param int $limit 返回条数。
 * @return array<int,array{time:int,channel:string,action:string,count:int,status:string,note:string}>
 */
function jinyu_push_log_get( int $limit = 8 ): array {
	$log = get_option( jinyu_push_log_option(), [] );
	if ( ! is_array( $log ) || ! $log ) {
		return [];
	}
	return array_slice( array_values( $log ), 0, max( 1, $limit ) );
}

/** 清空推送记录。 */
function jinyu_push_log_clear(): void {
	delete_option( jinyu_push_log_option() );
}

/**
 * 渲染推送记录表格（服务端唯一渲染源：面板首屏与 AJAX 刷新共用）。
 *
 * @param int $limit 展示条数。
 */
function jinyu_push_log_render( int $limit = 8 ): void {
	$rows = jinyu_push_log_get( $limit );

	if ( ! $rows ) {
		echo '<div class="jyc-plog-empty">' . esc_html__( '暂无推送记录。开启 IndexNow 或填写百度接口后，发布文章与执行全量补推都会在这里留痕。', 'jinyu-theme-companion' ) . '</div>';
		return;
	}

	$labels = [
		'ok'   => __( '成功', 'jinyu-theme-companion' ),
		'fail' => __( '失败', 'jinyu-theme-companion' ),
		'sent' => __( '已提交（异步）', 'jinyu-theme-companion' ),
	];

	echo '<table class="jyc-plog"><thead><tr>'
		. '<th>' . esc_html__( '时间', 'jinyu-theme-companion' ) . '</th>'
		. '<th>' . esc_html__( '渠道', 'jinyu-theme-companion' ) . '</th>'
		. '<th>' . esc_html__( '动作', 'jinyu-theme-companion' ) . '</th>'
		. '<th class="jyc-plog-num">' . esc_html__( '条数', 'jinyu-theme-companion' ) . '</th>'
		. '<th>' . esc_html__( '结果', 'jinyu-theme-companion' ) . '</th>'
		. '</tr></thead><tbody>';

	foreach ( $rows as $r ) {
		$status = isset( $labels[ $r['status'] ] ) ? $r['status'] : 'sent';
		$icon   = 'ok' === $status ? '✓' : ( 'fail' === $status ? '✗' : '↗' );
		echo '<tr>'
			. '<td class="jyc-plog-time">' . esc_html( wp_date( 'm-d H:i', (int) $r['time'] ) ) . '</td>'
			. '<td>' . esc_html( $r['channel'] ) . '</td>'
			. '<td>' . esc_html( $r['action'] )
			. ( $r['note'] ? '<span class="jyc-plog-note">' . esc_html( $r['note'] ) . '</span>' : '' )
			. '</td>'
			. '<td class="jyc-plog-num">' . esc_html( number_format_i18n( (int) $r['count'] ) ) . '</td>'
			. '<td><span class="jyc-pill is-' . esc_attr( $status ) . '">' . esc_html( $icon . ' ' . $labels[ $status ] ) . '</span></td>'
			. '</tr>';
	}

	echo '</tbody></table>';
}

/* ---------------- AJAX：刷新 / 清空 ---------------- */

add_action( 'wp_ajax_jinyu_push_log_refresh', 'jinyu_push_log_ajax_refresh' );

function jinyu_push_log_ajax_refresh(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '权限不足' );
	}
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );

	ob_start();
	jinyu_push_log_render( 8 );
	wp_send_json_success( [ 'html' => (string) ob_get_clean() ] );
}

add_action( 'wp_ajax_jinyu_push_log_clear', 'jinyu_push_log_ajax_clear' );

function jinyu_push_log_ajax_clear(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( '权限不足' );
	}
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );

	jinyu_push_log_clear();

	ob_start();
	jinyu_push_log_render( 8 );
	wp_send_json_success(
		[
			'msg'  => __( '推送记录已清空', 'jinyu-theme-companion' ),
			'html' => (string) ob_get_clean(),
		]
	);
}
