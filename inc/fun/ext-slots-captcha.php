<?php
/**
 * 主题扩展插槽的应答实现（验证码 + 社交登录入口 + 登录防暴破计数）。
 *
 * 主题是纯呈现层，通过 jinyu_ext_markup() / jinyu_ext_value() / do_action() 广播需求，
 * 本文件负责应答。双方互不引用对方符号。
 *
 * 覆盖插槽：
 *   标记名（HTML）  jinyu_ext_markup_captcha   验证码控件（场景参数透传）
 *                  jinyu_ext_markup_oauth_login 登录弹窗的第三方登录按钮组
 *   值             jinyu_ext_value_captcha_verify  服务端校验（true 放行 / WP_Error 拒绝）
 *   动作           jinyu_login_failed   登录失败计数（防暴破窗口）
 *                  jinyu_login_succeeded 登录成功清零
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── 验证码 ─────────────────────────────────────────────────────────── */

add_filter( 'jinyu_ext_markup_captcha', 'jinyu_captcha_slot_markup', 10, 2 );
/**
 * 渲染指定场景的验证码控件。
 *
 * @param string $html  主题默认值（空串）。
 * @param string $scene 场景标识（login / register / reset / comment / flink_apply）。
 * @return string
 */
function jinyu_captcha_slot_markup( $html, $scene = '' ): string {
	$scene = sanitize_key( (string) $scene );
	if ( '' === $scene || ! jinyu_captcha_required( $scene ) ) {
		return '';
	}
	return (string) jinyu_captcha_markup( $scene );
}

add_filter( 'jinyu_ext_value_captcha_verify', 'jinyu_captcha_slot_verify', 10, 3 );
/**
 * 服务端校验：主题发问，本插件判定。
 *
 * @param mixed  $default 主题默认值（true = 放行）。
 * @param string $scene   场景标识。
 * @param string $input   用户输入。
 * @return true|WP_Error
 */
function jinyu_captcha_slot_verify( $default, $scene = '', $input = '' ) {
	return jinyu_captcha_verify( sanitize_key( (string) $scene ), (string) $input );
}

/* ── 第三方登录入口（登录 / 注册弹窗） ──────────────────────────────── */

add_filter( 'jinyu_ext_markup_oauth_login', 'jinyu_oauth_login_slot_markup' );
/**
 * 渲染登录弹窗底部的第三方登录按钮组。
 *
 * @param string $html 主题默认值（空串）。
 * @return string 未启用 / 已登录 / 无平台时返回空串。
 */
function jinyu_oauth_login_slot_markup( $html ): string {
	return (string) jinyu_oauth_shortcode();
}

/* ── 登录防暴破计数 ─────────────────────────────────────────────────── */

add_action( 'jinyu_login_failed', 'jinyu_login_failure_incr' );
add_action( 'jinyu_login_succeeded', 'jinyu_login_failure_reset' );
