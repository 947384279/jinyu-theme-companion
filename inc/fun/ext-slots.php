<?php
/**
 * 主题扩展插槽的应答实现（social + messaging 子系统）。
 *
 * 主题是纯呈现层，通过 jinyu_ext_markup() / jinyu_ext_value() / do_action() 广播需求，
 * 本文件负责应答。双方互不引用对方符号：
 *   - 主题不认识本文件任何函数；
 *   - 本文件不认识主题任何函数（连function_exists 探测都没有）。
 *
 * 覆盖插槽：
 *   标记名（HTML）  jinyu_ext_markup_oauth_bindings  第三方账号绑定整块
 *   标记名（值）    jinyu_ext_value_unread_count     未读消息数
 *                  jinyu_ext_value_following_users  关注的用户列表
 *                  jinyu_ext_value_following_terms  关注的系列列表
 *                  jinyu_ext_value_notifications_page      消息列表页数据
 *                  jinyu_ext_value_mark_notifications_read 标记已读 → 返回最新未读数
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── 数据类插槽 ─────────────────────────────────────────────────────── */

add_filter(
	'jinyu_ext_value_unread_count',
	function ( $default, $uid = 0 ) {
		$uid = (int) $uid;
		return $uid > 0 ? jinyu_get_unread_count( $uid ) : (int) $default;
	},
	10,
	2
);

add_filter(
	'jinyu_ext_value_following_users',
	function ( $default, $uid = 0 ) {
		$uid = (int) $uid;
		return $uid > 0 ? jinyu_get_following_users( $uid ) : (array) $default;
	},
	10,
	2
);

add_filter(
	'jinyu_ext_value_following_terms',
	function ( $default, $uid = 0 ) {
		$uid = (int) $uid;
		return $uid > 0 ? jinyu_get_following_terms( $uid ) : (array) $default;
	},
	10,
	2
);

/**
 * 消息列表页数据：把通知行渲染成主题列表所需的 HTML，一并回传未读数与翻页标记。
 *
 * @param mixed $default 主题默认值（null = 未启用）。
 * @param int   $uid     当前用户 ID。
 * @param int   $page    页码。
 * @return array{html:string,unread:int,has_more:bool}|null
 */
function jinyu_notify_page_payload( $default, $uid = 0, $page = 1 ): ?array {
	$uid = (int) $uid;
	if ( $uid <= 0 ) {
		return null;
	}
	$per = 20;
	$list = jinyu_get_notifications( $uid, max( 1, (int) $page ), $per );

	$html = '';
	foreach ( $list as $n ) {
		$n       = (array) $n;
		$is_read = ! empty( $n['is_read'] );
		$link    = ! empty( $n['link'] ) ? $n['link'] : '';
		$html   .= '<li class="jinyu-notif-item' . ( $is_read ? ' is-read' : '' ) . '" data-notif-id="' . (int) ( $n['id'] ?? 0 ) . '">';
		if ( '' !== $link ) {
			$html .= '<a class="jinyu-notif-link" href="' . esc_url( $link ) . '">';
		}
		$html .= '<div class="jinyu-notif-body">';
		$html .= '<p class="jinyu-notif-title">' . esc_html( $n['title'] ?? '' ) . '</p>';
		if ( ! empty( $n['content'] ) ) {
			$html .= '<p class="jinyu-notif-content">' . esc_html( $n['content'] ) . '</p>';
		}
		$html .= '<p class="jinyu-notif-time">' . esc_html( mysql2date( 'Y-m-d H:i', $n['created_at'] ?? '' ) ) . '</p>';
		$html .= '</div>';
		if ( '' !== $link ) {
			$html .= '</a>';
		}
		$html .= '</li>';
	}

	return [
		'html'     => '' !== $html ? $html : '<li class="jinyu-empty">' . esc_html__( 'No messages', 'jinyu' ) . '</li>',
		'unread'   => jinyu_get_unread_count( $uid ),
		'has_more' => count( $list ) === $per,
	];
}
add_filter( 'jinyu_ext_value_notifications_page', 'jinyu_notify_page_payload', 10, 3 );

add_filter(
	'jinyu_ext_value_mark_notifications_read',
	function ( $default, $uid = 0, $ids = [] ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return null;
		}
		jinyu_mark_read( $uid, array_map( 'absint', (array) $ids ) );
		return jinyu_get_unread_count( $uid );
	},
	10,
	3
);

/* ── HTML 插槽：第三方账号绑定整块 ──────────────────────────────────── */

add_filter( 'jinyu_ext_markup_oauth_bindings', 'jinyu_sl_bindings_markup', 10, 3 );
/**
 * 渲染用户中心的「第三方账号绑定」整块。
 *
 * @param string $html  主题默认值（空串）。
 * @param int    $uid   当前用户 ID。
 * @param string $uc_url 绑定完成后的回跳地址。
 * @return string HTML；未启用或未登录时返回空串。
 */
function jinyu_sl_bindings_markup( $html, $uid = 0, $uc_url = '' ): string {
	$uid = (int) $uid;
	if ( $uid <= 0 || ! jinyu_oauth_enabled() ) {
		return '';
	}
	$platforms = jinyu_oauth_platforms();
	if ( empty( $platforms ) ) {
		return '';
	}
	$bindings = jinyu_oauth_bindings( $uid );
	$uc_url   = '' !== (string) $uc_url ? (string) $uc_url : home_url();

	$out  = '<h3 class="jinyu-user-subtitle">' . esc_html__( '第三方账号绑定', 'jinyu' ) . '</h3>';
	$out .= '<ul class="jinyu-bind-list">';
	foreach ( $platforms as $p => $info ) {
		$icon = (string) ( $info['icon'] ?? '' );
		$out .= '<li>';
		$out .= '<span class="jinyu-bind-ico jinyu-bind-ico-' . esc_attr( $p ) . '" aria-hidden="true">'
			. ( str_starts_with( $icon, '<svg' ) ? $icon : esc_html( $icon ) ) . '</span>';
		$out .= esc_html( $info['label'] ?? $p );
		if ( ! empty( $bindings[ $p ] ) ) {
			/* translators: %s: 第三方平台名称。 */
			$label = sprintf( __( '确定解除与「%s」的绑定吗？解绑后将无法再使用该平台一键登录。', 'jinyu' ), $info['label'] ?? $p );
			$out  .= '<button type="button" class="jinyu-bind-off"'
				. ' data-jinyu-unbind="' . esc_attr( $p ) . '"'
				. ' data-nonce="' . esc_attr( wp_create_nonce( 'jinyu_sl_unbind' ) ) . '"'
				. ' data-bind-url="' . esc_url( jinyu_oauth_bind_url( $p, $uc_url ) ) . '"'
				. ' data-bind-label="' . esc_attr( $label ) . '">'
				. esc_html__( '解除绑定', 'jinyu' ) . '</button>';
		} else {
			$out .= '<a class="jinyu-bind-go" href="' . esc_url( jinyu_oauth_bind_url( $p, $uc_url ) ) . '">'
				. esc_html__( '去绑定', 'jinyu' ) . '</a>';
		}
		$out .= '</li>';
	}
	$out .= '</ul>';
	$out .= '<p class="jinyu-field-hint">' . esc_html__( '绑定后可使用该平台一键登录，并关联到当前账号。', 'jinyu' ) . '</p>';

	return $out;
}
