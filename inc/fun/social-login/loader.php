<?php
/**
 * 金玉主题配套插件 — 第三方登录（社交登录）模块加载器
 *
 * 本模块从私有增强插件 wordpress-plugin-jinyu 迁入，使配套插件可独立提供
 * 第三方登录能力（满足 WordPress.org 主题/插件职责分离要求）。
 * 模块完全自包含：自带配置选项（JINYU_SL_OPT）与 AES 加密，不依赖主题函数。
 * 主题侧仅保留 jinyu_oauth_* 的降级空实现（function_exists 守卫），本模块加载后自动接管。
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'JINYU_SL_OPT' ) ) {
	define( 'JINYU_SL_OPT', 'jinyu_social_login' );
}
if ( ! defined( 'JINYU_SL_COOKIE' ) ) {
	define( 'JINYU_SL_COOKIE', 'jinyu_sl_state' );
}

if ( ! function_exists( 'jinyu_sl_icon_markup' ) ) {
	/**
	 * 平台徽标输出：以 `<svg` 开头的为内置可信 SVG（simple-icons 路径），原样输出；
	 * 其余（历史纯文本）按普通文本转义，避免把 HTML 当内容回显。
	 */
	function jinyu_sl_icon_markup( string $icon ): string {
		return 0 === strpos( $icon, '<svg' ) ? $icon : esc_html( $icon );
	}
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/class-base.php';
require_once __DIR__ . '/class-github.php';
require_once __DIR__ . '/class-gitee.php';
require_once __DIR__ . '/class-qq.php';
require_once __DIR__ . '/class-apple.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/settings.php';

// 首次启用（或升级到插件接管）时，从主题旧配置迁移一次（幂等：仅当 JINYU_SL_OPT 为空才写入）。
// 用 init 而非 plugins_loaded：本文件在插件加载期被 require，彼时 plugins_loaded 已在进行中，
// 再向该钩子挂回调不会在本次请求触发，故改用必然触发的 init。
add_action( 'init', 'jinyu_sl_maybe_migrate' );
