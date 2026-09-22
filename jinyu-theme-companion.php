<?php
/**
 * Plugin Name: Jinyu Theme Companion
 * Plugin URI:  https://www.qicaiyun.top
 * Description: Official companion plugin for the Jinyu WordPress theme. After the theme was split into a
 *              three-part structure, this plugin takes over all functional capabilities (SEO, structured
 *              data, social, related posts, shortcodes, cache, anti-spam, index ping, and more) so the
 *              theme stays a pure presentation layer. All outbound features are off by default.
 * Version:     1.0.1
 * Author:      金玉主题作者
 * Author URI:  https://www.qicaiyun.top
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jinyu-theme-companion
 * Domain Path: /languages
 * Requires at least: 6.2
 * Tested up to: 6.6
 * Requires PHP: 7.4
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --------------------------------------------------------------------------
 * 版本常量兜底：插件各模块对主题的调用均经 function_exists() 守卫，
 * 主题缺失时优雅降级，不会白屏。文本域统一使用字面量 'jinyu-theme-companion'。
 * ------------------------------------------------------------------------ */
if ( ! defined( 'JINYU_CUR_VER' ) ) {
	define( 'JINYU_CUR_VER', '1.0.1' );
}

/* --------------------------------------------------------------------------
 * 模块加载：下列文件原属主题，拆为独立外发插件；各文件顶部自行注册钩子。
 * 关键：这些模块在顶层调用主题的 jinyu_is_checked()/jinyu_get_option() 等函数，
 * 而 WordPress 加载顺序是「插件先于主题」，故必须延后到 after_setup_theme
 * （主题 functions.php 已包含、主题函数已就绪）再 require，否则加载期会因调用
 * 未定义函数而致命。主题未启用时跳过加载，仅保留下方兼容性提示，站点不会白屏。
 * ------------------------------------------------------------------------ */
add_action( 'after_setup_theme', static function (): void {
	if ( ! function_exists( 'jinyu_is_checked' ) ) {
		return; // 金玉主题未启用：不加载功能模块，避免调用未定义函数致命
	}

	// 头部冗余输出清理（plugin-territory）：原属主题的 clean_wp_head 优化项，
	// 迁出到配套插件，使主题通过 .org 审查；线上行为保持不变。
	add_action( 'init', static function () {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
	}, 99 );

	// 基础设施：SMTP 配置类（被 email.php 依赖，须先加载）
	require_once __DIR__ . '/inc/classes/Mail/Jinyu_SmtpConfig.php';

	// SEO / 结构化数据 / 索引推送
	require_once __DIR__ . '/inc/seo.php';
	require_once __DIR__ . '/inc/seo-jsonld.php';
	require_once __DIR__ . '/inc/fun/category-seo.php';
	require_once __DIR__ . '/inc/fun/llms.php';
	require_once __DIR__ . '/inc/fun/indexnow.php';
	require_once __DIR__ . '/inc/fun/baidu-push.php';
	require_once __DIR__ . '/inc/fun/no-category.php';

	// 社交：关注 / 取关 / 消息 / 未读（主题调用 jinyu_follow_user 等）
	require_once __DIR__ . '/inc/fun/social.php';
	// 验证码 + 登录失败计数（主题登录/评论调用 jinyu_captcha_*）
	require_once __DIR__ . '/inc/fun/captcha.php';

	// 内容增强
	require_once __DIR__ . '/inc/fun/related.php';
	require_once __DIR__ . '/inc/fun/series.php';
	require_once __DIR__ . '/inc/fun/short-code.php';
	require_once __DIR__ . '/inc/fun/shortcode-ui.php';
	require_once __DIR__ . '/inc/ext/moments.php';
	require_once __DIR__ . '/inc/fun/auto-link.php';
	require_once __DIR__ . '/inc/fun/web-vitals.php';

	// 评论与互动
	require_once __DIR__ . '/inc/fun/comment-notify.php';
	require_once __DIR__ . '/inc/fun/anti-spam.php';

	// 性能 / 系统
	require_once __DIR__ . '/inc/fun/page-cache.php';
	require_once __DIR__ . '/inc/fun/db-optimize.php';
	require_once __DIR__ . '/inc/fun/stats.php';
	require_once __DIR__ . '/inc/fun/email.php';
	require_once __DIR__ . '/inc/ajax/poster.php';
} );

/* --------------------------------------------------------------------------
 * 兼容性提示：本插件设计为与「金玉」主题搭配。未启用主题时仅作软提示（可忽略），
 * 各功能已做降级处理，不会导致站点白屏。
 * ------------------------------------------------------------------------ */
add_action( 'admin_notices', function (): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && 'plugins' !== $screen->id && 'dashboard' !== $screen->id ) {
		return;
	}
	$theme = wp_get_theme();
	if ( 'jinyu' === strtolower( $theme->get( 'TextDomain' ) ?: '' ) || 'jinyu' === strtolower( $theme->get_template() ) ) {
		return; // 金玉主题已启用，无需提示
	}
	$dismissed = get_user_meta( get_current_user_id(), 'jinyu_companion_theme_notice_dismissed', true );
	if ( $dismissed ) {
		return;
	}
	$url = admin_url( 'themes.php' );
	echo '<div class="notice notice-info is-dismissible" id="jinyu-companion-theme-notice">'
		. '<p>' . esc_html__( '「金玉主题配套插件」建议与金玉（jinyu）主题搭配使用以获得完整体验；当前未检测到金玉主题，部分功能将自动降级。', 'jinyu-theme-companion') . '</p>'
		. '<p><a href="' . esc_url( $url ) . '">' . esc_html__( '前往主题管理', 'jinyu-theme-companion') . '</a></p>'
		. '</div>';
} );

add_action( 'wp_ajax_jinyu_companion_dismiss_theme_notice', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die();
	}
	update_user_meta( get_current_user_id(), 'jinyu_companion_theme_notice_dismissed', 1 );
	wp_die();
} );

add_action( 'admin_footer', function (): void {
	?>
	<script>
		(function () {
			var n = document.getElementById('jinyu-companion-theme-notice');
			if (!n) return;
			n.querySelector('.notice-dismiss').addEventListener('click', function () {
				var x = new XMLHttpRequest();
				x.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
				x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				x.send('action=jinyu_companion_dismiss_theme_notice');
			});
		})();
	</script>
	<?php
} );
