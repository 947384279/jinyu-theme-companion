<?php
/**
 * Plugin Name: Jinyu Theme Companion
 * Plugin URI:  https://www.qicaiyun.top/4698.html
 * Description: Functional layer for the Jinyu theme: SEO, structured data, social, related posts, shortcodes, cache and anti-spam. Works on any theme.
 * Version:     1.2.8
 * Author:      金玉
 * Author URI:  https://www.qicaiyun.top
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jinyu-theme-companion
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.0
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
--------------------------------------------------------------------------
 * 版本常量：本插件自有，常量名一律用 JINYU_COMPANION_VER。
 * 不可用 JINYU_CUR_VER —— 该常量由主题定义（取自 style.css 的 Version），
 * 插件先于主题载入，抢先定义会让主题读到的版本号变成插件版本，造成版本漂移。
 * ------------------------------------------------------------------------ */
if ( ! defined( 'JINYU_COMPANION_VER' ) ) {
	define( 'JINYU_COMPANION_VER', '1.2.8' );
}

/*
插件自有路径常量：模块（shortcode-ui / poster 等）一律引用插件自身资源，
 * 不再依赖主题的 JINYU_ABS_DIR / JINYU_ABS_URI（主题缺席时未定义常量会直接抛 Error）。 */
if ( ! defined( 'JINYU_COMPANION_DIR' ) ) {
	define( 'JINYU_COMPANION_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'JINYU_COMPANION_URL' ) ) {
	define( 'JINYU_COMPANION_URL', plugin_dir_url( __FILE__ ) );
}

/*
文本域兜底：社交登录等模块沿用 JINYU 常量作为 __() 文本域，
 * 此处统一指向配套插件自身文本域，保证翻译可被 load_plugin_textdomain 加载。 */
if ( ! defined( 'JINYU' ) ) {
	define( 'JINYU', 'jinyu-theme-companion' );
}

/*
第三方登录（社交登录）模块：配套插件自持该能力，不依赖任何其它插件在场。
 * 必须在顶层加载（先于主题 functions.php），因为主题 user.php 用 if(!function_exists('jinyu_oauth_*'))
 * 提供降级桩，本模块须在主题运行前定义真实现，否则桩被采用、真实登录失效。
 * 互斥：本模块定义 jinyu_oauth_enabled 等全局符号，与任何同源的第三方实现互斥。
 * 双方均在各自主文件的 require 处用 function_exists 守卫，加载顺序无论先后，
 * 都只有一方定义这些符号（PHP 8.5 编译期早绑定要求互斥必须置于 require 处，
 * 文件顶部的 return 守卫无效——那会让本文件第 6 行之后的所有钩子静默失效）。
 *
 * PHP 版本守卫：本模块使用 PHP 8.0 联合类型语法（string|WP_Error 等），低版本下是编译期
 * fatal 而非运行期错误。wp.org 按「Requires PHP: 8.0」拦截低版本安装；此处的运行时守卫
 * 兜底手动上传 / 降级包等不受 wp.org 管控的场景：PHP 7.x 下跳过加载并提示，其余模块照常运行。
 */
if ( PHP_VERSION_ID >= 80000 ) {
	if ( ! function_exists( 'jinyu_oauth_enabled' ) ) {
		require_once __DIR__ . '/inc/fun/social-login/loader.php';
	}
} else {
	add_action(
        'admin_notices',
        static function (): void {
			if ( ! current_user_can( 'update_core' ) ) {
				return;
			}
			printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__( '「金玉主题配套插件」的第三方登录模块需要 PHP 8.0 及以上版本，当前 PHP 版本过低，该模块已停用；其余功能不受影响。', 'jinyu-theme-companion' )
			);
		}
    );
}

/*
激活即建表：通知表（jinyu_notify）/ 统计表不再只靠 after_switch_theme + footer 兜底，
 * 避免插件激活后首个请求内表缺失导致写入失败。 */
register_activation_hook(
    __FILE__,
    static function (): void {
		require_once __DIR__ . '/inc/fun/companion-options.php';
		require_once __DIR__ . '/inc/fun/social.php';
		require_once __DIR__ . '/inc/fun/stats.php';
		if ( function_exists( 'jinyu_notify_install' ) ) {
			jinyu_notify_install();
		}
		if ( function_exists( 'jinyu_stats_install' ) ) {
			jinyu_stats_install();
		}
		// storage 任务表：原先拖到首次 AJAX 才建，批处理前必有一次空跑。
		// 互斥：storage 模块与任何同源实现共享全局符号，此处先探测——
		// 若对方已加载（先激活），本插件跳过，共享函数由先加载方提供。
		if ( ! function_exists( 'jinyu_is_storage_enabled' ) && ! class_exists( 'Jinyu_Storage_Factory' ) ) {
			require_once __DIR__ . '/inc/fun/storage.php';
		}
		if ( function_exists( 'jinyu_storage_install_table' ) ) {
			jinyu_storage_install_table();
		}
		// 标记下次 init 刷新重写规则：moments/series 等自定义 rewrite 在全新安装后
		// 不 flush 会 404，直到手动保存固定链接。此刻 CPT 尚未注册，只能延后到 init(99)。
		update_option( 'jinyu_companion_flush_rewrite', 1, false );
	}
);

/* 激活后首个请求：CPT 已注册（after_setup_theme 先于 init），此时 flush 才有效 */
add_action(
    'init',
    static function (): void {
		if ( get_option( 'jinyu_companion_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'jinyu_companion_flush_rewrite' );
		}
	},
    99
);

/*
翻译加载：自 WP 4.6 起，托管在 WordPress.org 的插件由核心自动按 slug 加载语言包，
 * 无需再调用 load_plugin_textdomain（wp.org 审查明确要求移除）。 */

/*
--------------------------------------------------------------------------
 * 模块加载：下列文件原属主题，拆为独立外发插件；各文件顶部自行注册钩子。
 * WordPress 加载顺序是「插件先于主题」。这里延后到 after_setup_theme 只是为了等 WP 环境就绪，
 * 与主题无关：插件内部一律调 jinyu_companion_* 自持原语（inc/fun/primitives.php），
 * 既不调用主题函数，也不读主题私有数据模型，主题是否在场都不影响插件行为。
 * ------------------------------------------------------------------------ */
add_action(
    'after_setup_theme',
    static function (): void {

		// 头部冗余输出清理（plugin-territory）：原属主题的 clean_wp_head 优化项，
		// 迁出到配套插件，使主题通过 .org 审查；线上行为保持不变。
		add_action(
            'init',
            static function () {
				remove_action( 'wp_head', 'rsd_link' );
				remove_action( 'wp_head', 'wp_generator' );
				remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
				remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
				remove_action( 'wp_head', 'feed_links_extra', 3 );
				remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
            },
            99
		);

		// 基础设施：SMTP 配置类（被 email.php 依赖，须先加载）
		require_once __DIR__ . '/inc/classes/Mail/Jinyu_SmtpConfig.php';

		// 选项 helper + 插件自持原语（必须在各功能模块之前加载）
		require_once __DIR__ . '/inc/fun/companion-options.php';
		require_once __DIR__ . '/inc/fun/primitives.php';
		// 从主题迁出的「插件领地」兼容层：安全加固 / 浏览量采集 / 客户端 IP / IP 限流。
		// 主题侧仅保留委托壳，本文件在插件启用时提供真实实现；未启用主题时优雅降级。
		require_once __DIR__ . '/inc/fun/theme-compat.php';

		// 加密：companion 自有实现（唯一命名，不与主题 jinyu_encrypt/decrypt 冲突），
		// storage 的 Secret 加密入库 + 一次性迁移解密主题历史密文均依赖此文件，须先于 storage.php 加载。
		require_once __DIR__ . '/inc/fun/crypto.php';

		// 对象存储引擎（又拍云 / 阿里云 OSS / 腾讯云 COS / 七牛 / S3）：
		// 提供 jinyu_is_storage_enabled / jinyu_storage_config / Jinyu_Storage_Factory，
		// 主题 media.php 经 function_exists 守卫自动接管。
		// 互斥：storage 模块与任何同源实现共享全局符号，此处的守卫是**反向**那一半——
		// 对方先加载时本插件跳过，避免 redeclare fatal；对方若也在其主文件 require 处
		// 做了对称守卫，则本插件先加载时对方跳过。两个方向都覆盖，缺任一半都会在
		// 两种插件同时激活时产生致命错误，因此互斥必须成对实现。
		// 独有函数仅 storage.php 内部使用，共享函数签名一致，先加载方生效即安全。
		// 配置存本插件独立选项 jinyu_companion_settings（首次运行自动从主题配置平移），不依赖主题函数。
		if ( ! function_exists( 'jinyu_is_storage_enabled' ) && ! class_exists( 'Jinyu_Storage_Factory' ) ) {
			require_once __DIR__ . '/inc/fun/storage.php';
		}

		// SEO / 结构化数据 / 索引推送
		require_once __DIR__ . '/inc/seo.php';
		// 微信 JS-SDK 分享：复用 seo.php 已输出的 og:* meta 作为分享数据，零冗余。
		require_once __DIR__ . '/inc/fun/wechat-share.php';
		require_once __DIR__ . '/inc/seo-jsonld.php';
		require_once __DIR__ . '/inc/seo-sitemap.php';
		require_once __DIR__ . '/inc/fun/category-seo.php';
		require_once __DIR__ . '/inc/fun/post-seo.php';
		require_once __DIR__ . '/inc/fun/llms.php';
		require_once __DIR__ . '/inc/fun/geo-robots.php';
		require_once __DIR__ . '/inc/fun/ai-crawl-stats.php';
		require_once __DIR__ . '/inc/fun/privacy.php';
		// 推送记录：IndexNow / 百度每次提交的留痕，供后台「推送记录」卡片回溯
		require_once __DIR__ . '/inc/fun/push-log.php';
		require_once __DIR__ . '/inc/fun/indexnow.php';
		require_once __DIR__ . '/inc/fun/baidu-push.php';
		require_once __DIR__ . '/inc/fun/bulk-push.php';
		require_once __DIR__ . '/inc/fun/no-category.php';
		// 站点验证元标签（Google/Bing/Baidu/Yandex/360）+ 图片 SEO（alt/尺寸补全、体检）
		require_once __DIR__ . '/inc/fun/verification.php';
		require_once __DIR__ . '/inc/fun/img-alt.php';

		// 图片水印引擎（上传时自动打 + 面板批量 / 媒体库自选），默认关闭，主题无关
		// 用 file_exists 包裹：这两份文件属于较新的增量，尚未随主文件一起部署到存量站点时，
		// 裸 require 会「Failed opening required」直接整站 500；缺文件时静默跳过即可。
		if ( file_exists( __DIR__ . '/inc/fun/watermark.php' ) ) {
			require_once __DIR__ . '/inc/fun/watermark.php';
		}
		if ( file_exists( __DIR__ . '/inc/ajax/media-batch.php' ) ) {
			require_once __DIR__ . '/inc/ajax/media-batch.php';
		}

		// 社交：关注 / 取关 / 消息 / 未读（主题调用 jinyu_follow_user 等）
		require_once __DIR__ . '/inc/fun/social.php';
		// 验证码 + 登录失败计数（主题登录/评论调用 jinyu_captcha_*）
		require_once __DIR__ . '/inc/fun/captcha.php';
		// 主题扩展插槽应答：把社交 / 验证码 / 登录防暴破接入主题的 jinyu_ext_* 契约。
		// 须在 social.php + captcha.php 之后（回调里要用到它们的实现）。
		require_once __DIR__ . '/inc/fun/ext-slots.php';
		require_once __DIR__ . '/inc/fun/ext-slots-captcha.php';

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
		require_once __DIR__ . '/inc/fun/comment-cleanup.php';

		// 性能 / 系统
		require_once __DIR__ . '/inc/fun/speculation.php';
		require_once __DIR__ . '/inc/fun/page-cache.php';
		// HTTP 传输层体检（压缩 / 静态资源缓存 / HTML 缓存头 / HTTP3）：只在点击体检时发请求。
		require_once __DIR__ . '/inc/fun/transport-check.php';
		require_once __DIR__ . '/inc/fun/db-optimize.php';
		// 性能优化中心（自主题 perf.php 迁入）：OPcache/Memcached 看板、
		// 性能开关、缓存清理、一键优化。渲染挂在设置面板「性能中心」分区。
		require_once __DIR__ . '/inc/fun/perf-center.php';
		require_once __DIR__ . '/inc/fun/stats.php';
		require_once __DIR__ . '/inc/fun/email.php';
		require_once __DIR__ . '/inc/ajax/poster.php';
		// 设置面板：概览磁贴数据层（依赖 perf-center / stats，须在其后加载）
		require_once __DIR__ . '/inc/admin/overview.php';
		// 设置导入 / 导出：在 settings.php 之前加载（面板与保存入口均调用其 jinyu_companion_io_* / _import_*）。
		require_once __DIR__ . '/inc/admin/import-export.php';
		require_once __DIR__ . '/inc/admin/settings.php';
	}
);

/*
 * 兼容性：本插件可独立运行，未启用金玉主题时各模块走 function_exists 守卫优雅降级，
 * 不会白屏。此处**刻意不弹**「建议搭配本主题」的后台通知 —— 那属于在 WordPress 后台
 * 推销特定产品，WordPress.org 插件目录明确禁止；且「是否搭配」是站点自己的选择，
 * 插件无权替它做市场宣传。主题关联的说明放在 readme 的 FAQ 里，那才是合适的位置。
 */
