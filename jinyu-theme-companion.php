<?php
/**
 * Plugin Name: Jinyu Theme Companion
 * Plugin URI:  https://www.qicaiyun.top
 * Description: Official companion plugin for the Jinyu WordPress theme. After the theme was split into a
 *              three-part structure, this plugin takes over all functional capabilities (SEO, structured
 *              data, social, related posts, shortcodes, cache, anti-spam, index ping, and more) so the
 *              theme stays a pure presentation layer. All outbound features are off by default.
 * Version:     1.0.4
 * Author:      金玉主题作者
 * Author URI:  https://www.qicaiyun.top
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jinyu-theme-companion
 * Domain Path: /languages
 * Requires at least: 6.2
 * Tested up to: 6.8
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
	define( 'JINYU_CUR_VER', '1.0.2' );
}

/* 插件自有路径常量：模块（shortcode-ui / poster 等）一律引用插件自身资源，
 * 不再依赖主题的 JINYU_ABS_DIR / JINYU_ABS_URI（主题缺席时未定义常量会直接抛 Error）。 */
if ( ! defined( 'JINYU_COMPANION_DIR' ) ) {
	define( 'JINYU_COMPANION_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'JINYU_COMPANION_URL' ) ) {
	define( 'JINYU_COMPANION_URL', plugin_dir_url( __FILE__ ) );
}

/* 文本域兜底：社交登录等从私有插件迁入的模块沿用 JINYU 常量作为 __() 文本域，
 * 此处统一指向配套插件自身文本域，保证翻译可被 load_plugin_textdomain 加载。 */
if ( ! defined( 'JINYU' ) ) {
	define( 'JINYU', 'jinyu-theme-companion' );
}

/* 第三方登录（社交登录）模块：从私有增强插件迁入，使配套插件可独立提供该能力。
 * 必须在顶层加载（先于主题 functions.php），因为主题 user.php 用 if(!function_exists('jinyu_oauth_*'))
 * 提供降级桩，本模块须在主题运行前定义真实现，否则桩被采用、真实登录失效。
 * 与历史私有插件 wordpress-plugin-jinyu 互斥：双方均在 require 处用 function_exists 守卫，
 * 无论加载顺序如何，仅有一方定义 jinyu_oauth_enabled 等符号（PHP 8.5 编译期早绑定要求互斥置于 require 处）。 */
if ( ! function_exists( 'jinyu_oauth_enabled' ) ) {
	require_once __DIR__ . '/inc/fun/social-login/loader.php';
}

/* 激活即建表：通知表（jinyu_notify）/ 统计表不再只靠 after_switch_theme + footer 兜底，
 * 避免插件激活后首个请求内表缺失导致写入失败。 */
register_activation_hook( __FILE__, static function (): void {
	require_once __DIR__ . '/inc/fun/companion-options.php';
	require_once __DIR__ . '/inc/fun/social.php';
	require_once __DIR__ . '/inc/fun/stats.php';
	if ( function_exists( 'jinyu_notify_install' ) ) {
		jinyu_notify_install();
	}
	if ( function_exists( 'jinyu_stats_install' ) ) {
		jinyu_stats_install();
	}
	// storage 任务表：原先拖到首次 AJAX 才建，批处理前必有一次空跑
	require_once __DIR__ . '/inc/fun/storage.php';
	if ( function_exists( 'jinyu_storage_install_table' ) ) {
		jinyu_storage_install_table();
	}
	// 标记下次 init 刷新重写规则：moments/series 等自定义 rewrite 在全新安装后
	// 不 flush 会 404，直到手动保存固定链接。此刻 CPT 尚未注册，只能延后到 init(99)。
	update_option( 'jinyu_companion_flush_rewrite', 1, false );
} );

/* 激活后首个请求：CPT 已注册（after_setup_theme 先于 init），此时 flush 才有效 */
add_action( 'init', static function (): void {
	if ( get_option( 'jinyu_companion_flush_rewrite' ) ) {
		flush_rewrite_rules();
		delete_option( 'jinyu_companion_flush_rewrite' );
	}
}, 99 );

/* 翻译加载：.org 审查硬性要求（languages/ 目录已随包发布） */
add_action( 'init', static function (): void {
	load_plugin_textdomain( 'jinyu-theme-companion', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/* --------------------------------------------------------------------------
 * 模块加载：下列文件原属主题，拆为独立外发插件；各文件顶部自行注册钩子。
 * WordPress 加载顺序是「插件先于主题」，故必须延后到 after_setup_theme 再 require，
 * 确保主题函数（若启用）已就绪。插件不依赖主题：所有跨主题调用经 inc/fun/theme-shims.php
 * 兼容层 function_exists 守卫兜底，主题缺席时优雅降级，站点不会白屏 / 致命。
 * ------------------------------------------------------------------------ */
add_action( 'after_setup_theme', static function (): void {
	// 不再要求金玉主题在场：插件可独立运行。所有对主题原语的调用统一经
	// inc/fun/theme-shims.php 兼容层兜底（function_exists 守卫，主题在场优先用主题版）。

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

	// 选项 helper + 主题兼容层（必须在各功能模块之前加载）
	require_once __DIR__ . '/inc/fun/companion-options.php';
	require_once __DIR__ . '/inc/fun/theme-shims.php';

	// 加密：companion 自有实现（唯一命名，不与主题 jinyu_encrypt/decrypt 冲突），
	// storage 的 Secret 加密入库 + 一次性迁移解密主题历史密文均依赖此文件，须先于 storage.php 加载。
	require_once __DIR__ . '/inc/fun/crypto.php';

	// 对象存储引擎（又拍云 / 阿里云 OSS / 腾讯云 COS / 七牛 / S3）：
	// 从主题拆出迁入本插件，提供 jinyu_is_storage_enabled / jinyu_storage_config / Jinyu_Storage_Factory，
	// 主题 media.php 经 function_exists 守卫自动接管。与历史私有插件 wordpress-plugin-jinyu 的互斥
	// 由其主文件 require 处守卫保证（PHP 8.5 编译期早绑定使文件级 return 守卫不可用，本文件禁用之）。
	// 配置存本插件独立选项 jinyu_companion_settings（首次运行自动从主题 JINYU_OPT 平移），不依赖主题函数。
	require_once __DIR__ . '/inc/fun/storage.php';

	// SEO / 结构化数据 / 索引推送
	require_once __DIR__ . '/inc/seo.php';
	require_once __DIR__ . '/inc/seo-jsonld.php';
	require_once __DIR__ . '/inc/fun/category-seo.php';
	require_once __DIR__ . '/inc/fun/post-seo.php';
	require_once __DIR__ . '/inc/fun/llms.php';
	require_once __DIR__ . '/inc/fun/indexnow.php';
	require_once __DIR__ . '/inc/fun/baidu-push.php';
	require_once __DIR__ . '/inc/fun/no-category.php';
	// 站点验证元标签（Google/Bing/Baidu/Yandex/360）+ 图片 alt 补全
	require_once __DIR__ . '/inc/fun/verification.php';
	require_once __DIR__ . '/inc/fun/img-alt.php';

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
	require_once __DIR__ . '/inc/fun/speculation.php';
	require_once __DIR__ . '/inc/fun/page-cache.php';
	require_once __DIR__ . '/inc/fun/db-optimize.php';
	// 性能优化中心（自主题 perf.php 迁入）：OPcache/Memcached 看板、
	// 性能开关、缓存清理、一键优化。渲染挂在设置面板「性能中心」分区。
	require_once __DIR__ . '/inc/fun/perf-center.php';
	require_once __DIR__ . '/inc/fun/stats.php';
	require_once __DIR__ . '/inc/fun/email.php';
	require_once __DIR__ . '/inc/ajax/poster.php';
require_once __DIR__ . '/inc/admin/settings.php';
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
