<?php
/**
 * 金玉配套插件 · 性能优化中心
 * --------------------------------------------------------------------------
 * 自主题 inc/fun/perf.php 迁入（plugin-territory：性能开关 / 缓存看板 / 清理动作
 * 均非主题呈现层职责，.org 上架要求此类能力由配套插件承载）。
 * 函数统一改用 jinyu_perf_ 前缀，避免与主题历史实现发生编译期早绑定冲突；
 * 开关选项存于 jinyu_perf_options_v2（新键；首次读取时自动接管 jinyu_perf_options 旧键的历史配置）。
 *
 * 一个自包含的后台工具，提供：
 *   1) 状态看板：OPcache 字节码缓存（命中率/内存/脚本数/重启次数）与
 *      Memcached 对象缓存（命中率/内存占用/条目数）的实时指标，
 *      以及 autoload 体积、过期 transient、表碎片等总览。
 *   2) 可逆开关：关闭心跳、禁用仪表盘新闻、禁用 emoji / wp-embed、限制文章修订、
 *      短接实时小工具外部地理查询、禁用 XML-RPC、清理 wp_head 冗余、禁用 pingback、
 *      前台隐藏 admin bar。每项带用途与副作用说明（注释式 UI）。
 *   3) 缓存管理：单独或一键重置 OPcache / 清空 Memcached / 清整页缓存。
 *   4) 一键优化：清理过期 transient + 优化碎片表 + 刷新全部缓存 + 套用开关，
 *      返回优化前后对比，并自动刷新状态看板。
 *
 * 设计原则：
 *   - 所有操作均可逆（开关存于 jinyu_perf_options_v2，清理不删有效数据）。
 *   - 仅管理员可用（manage_options），全部 AJAX 走 nonce 校验。
 *   - 页面输出不使用任何 emoji / 图片：一律用 CSS / 内联 SVG 绘制，
 *     规避 WP emoji 脚本把字符替换为 s.w.org 远程图片、国内加载失败出现裂图的问题。
 *   - 不引入构建产物：JS / CSS 内联，不进 gulp 流程。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ───────────────────────── 选项与开关定义 ───────────────────────── */

/**
 * 推荐开关组合（单一来源）：既是全新站点的默认值，也是「一键打开推荐开关」的目标状态。
 * 「按需开启」类（前台 admin bar 隐藏 / 评论懒加载 / 游客 REST 限制）依赖站点具体环境，
 * 不进推荐组合，保持默认关。
 *
 * @return array key => 0|1
 */
function jinyu_perf_recommended_options(): array {
	return [
		'disable_heartbeat'      => 1, // 关闭后台心跳轮询（单管理员站点推荐）
		'disable_dashboard_news' => 1, // 移除仪表盘「动态与新闻」
		'disable_emoji'          => 1, // 移除 WP emoji 检测/替换脚本（国内 s.w.org 不可达）
		'disable_embed'          => 1, // 移除 wp-embed 前端脚本
		'limit_revisions'        => 1, // 每篇文章最多保留 5 个修订
		'disable_live_geo'       => 1, // 短接 whois.pconline.com.cn 地理查询
		'disable_xmlrpc'         => 1, // 禁用 XML-RPC（减小攻击面）
		'clean_wp_head'          => 1, // 清理 wp_head 冗余输出（RSD/wlwmanifest/版本号等）
		'disable_pingback'       => 1, // 禁用 pingback 自引用
		'disable_wp_org_api'     => 1, // 屏蔽 WordPress.org 外部 API（更新/翻译/主题检查，国内极慢）
		'hide_admin_bar_front'   => 0, // 前台对非管理员隐藏 admin bar（按需开启）
		'dns_preconnect'         => 1, // 关键域名 DNS 预连接（CDN 域名，加速首屏建连）
		'comment_lazyload'       => 0, // 评论懒加载（按需加载更多，减少长文首屏 DOM）
		'iframe_lazy'            => 1, // iframe 懒加载（视频/嵌入延后到视口）
		'restrict_guest_rest'    => 0, // 限制游客 REST API（拦截用户枚举等敏感路由）
		'html_minify'            => 1, // 前台 HTML 压缩（主题经 jinyu_perf_options 过滤器取本键的值）
	];
}

/**
 * 读取性能开关（单例缓存，避免一次请求内重复查询 options 表）。
 *
 * @param bool $flush 强制丢弃缓存并重新读库。写完 option 后必须传 true，
 *                     否则同一次请求内后续调用仍拿到写入前的旧快照。
 * @return array key => 0|1
 */
function jinyu_perf_get_options( bool $flush = false ): array {
	static $cache = null;
	if ( $flush ) {
		$cache = null;
	}
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$defaults = jinyu_perf_recommended_options();
	$saved = get_option( 'jinyu_perf_options_v2' );
	if ( ! is_array( $saved ) ) {
		// 兼容 1.2.7 及更早的旧键 jinyu_perf_options：新键从未保存时接管其历史配置。
		$saved = get_option( 'jinyu_perf_options' );
	}
	$cache = wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
	return $cache;
}

/**
 * 一次性迁移：旧键 jinyu_perf_options → 新键 jinyu_perf_options_v2。
 *
 * 为什么换键：旧键 jinyu_perf_options 与本文件对外广播的过滤器名同名（见下方 add_filter），
 * 同一字符串既当 option 键又当 filter 名，读代码时无法分辨它指的是配置存储还是契约通道。
 * 键名加 _v2 后缀后，**配置存储**与**对外契约**两个用途在字符串层面分开，
 * 且仍满足项目 jinyu_ 前缀约束。注意过滤器名保持 jinyu_perf_options 不变——它是已发布的
 * 对外契约（主题 apply_filters 广播需求，本插件 add_filter 响应），改名等于破坏契约。
 *
 * 为什么用标记而非永久 fallback：永久 fallback 会让旧键永远留在 wp_options 里，
 * 用户在后台改的是新键、旧键却纹丝不动，两份配置长期不一致且无人察觉。
 * 搬一次并删旧键，配置只有单一真源。
 *
 * 幂等：由 jinyu_perf_options_v2_migrated 标记，仅执行一次。
 *
 * ⚠️ 挂载时机必须是 init 而非 after_setup_theme：本文件是在插件主文件那个
 * after_setup_theme 回调「内部」被 require 的，而 after_setup_theme 在 wp-load.php
 * 里整个请求只触发一次（did_action = 1）。钩子注册时该动作早已跑完，
 * 挂上去的回调永远等不到下一轮 → 迁移每个请求都不执行（实测线上如此）。
 * init 在 after_setup_theme 之后触发且尚未跑过，是此处唯一正确的时机。
 */
if ( ! function_exists( 'jinyu_perf_maybe_migrate_options' ) ) {
	function jinyu_perf_maybe_migrate_options(): void {
		if ( get_option( 'jinyu_perf_options_v2_migrated' ) ) {
			return;
		}
		// 无论有无旧数据都落标记：新键已存在即说明是升级后的正常状态，无需再搬。
		$legacy = get_option( 'jinyu_perf_options' );
		if ( is_array( $legacy ) && ! get_option( 'jinyu_perf_options_v2' ) ) {
			update_option( 'jinyu_perf_options_v2', $legacy, false );
		}
		delete_option( 'jinyu_perf_options' );
		// 历史中间版本曾用过 jyc_ 前缀的键（违反项目 jinyu_ 约束，已废弃），一并清掉避免留脏数据。
		delete_option( 'jyc_perf_options' );
		update_option( 'jinyu_perf_options_v2_migrated', 1, false );
		// 搬完立刻失效静态缓存：本次请求里 jinyu_perf_get_options() 可能已被
		// jinyu_perf_apply()（init:1 之前就跑完了？不，apply 在 init:5，见下）填过旧快照。
		// 不清的话，同一请求内后续所有读到的仍是删除前的旧值——迁移等于没生效。
		jinyu_perf_get_options( true );
	}
}
// ⚠️ 优先级必须是 1：jinyu_perf_apply() 挂在 init:5，它会先读一遍开关表并据此摘/挂钩子。
// 迁移若晚于它，本次请求应用的将是删除前的旧键数据，且 jinyu_perf_get_options() 的静态缓存
// 已被填成旧快照，迁移后当请求再也拿不到新值（静态缓存同请求内永不自动失效）。
// 1 是 init 上第一个可用优先级，确保「先搬后用」。
add_action( 'init', 'jinyu_perf_maybe_migrate_options', 1 );

/**
 * 开关清单：key => [标签, 说明（用途 + 副作用）]。说明展示在每个开关下方，属「注释」UI。
 *
 * @return array
 */
function jinyu_perf_toggle_meta(): array {
	return [
		'disable_heartbeat'      => [
			'label' => __( '关闭心跳（Heartbeat）轮询', 'jinyu-theme-companion' ),

			'group' => 'admin',
			'desc'  => __( '后台默认每 15~60 秒向 admin-ajax.php 发一次心跳请求（协同编辑提示、自动保存增量、锁状态都靠它）。关闭后后台明显安静，但代价：编辑文章时不显示「他人正在编辑」锁定提示，自动保存间隔变得不可靠。单管理员站点建议开。', 'jinyu-theme-companion' ),

		],
		'disable_dashboard_news' => [
			'label' => __( '禁用仪表盘「WordPress 动态与新闻」', 'jinyu-theme-companion' ),

			'group' => 'admin',
			'desc'  => __( '该小工具每次打开仪表盘都会同步请求 api.wordpress.org 拉取资讯与活动，海外网络波动时可拖慢仪表盘 1~3 秒。移除后仅影响这一个官方小工具，其余小工具不受影响。', 'jinyu-theme-companion' ),

		],
		'disable_emoji'          => [
			'label' => __( '移除 WP Emoji 脚本（推荐）', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( 'WP 会在前台/后台注入 emoji 检测脚本，并在浏览器不支持时把部分 emoji 字符替换成 WordPress.org 的 emoji 图片 CDN——国内不可达时就会看到「裂开的图」。移除后 emoji 恢复为系统原生渲染（显示效果不变），只是不再转图片。本页面已全程改用 CSS 绘制图标，不再依赖 emoji。', 'jinyu-theme-companion' ),

		],
		'disable_embed'          => [
			'label' => __( '移除 wp-embed 脚本', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( 'wp-embed.js 用于把其他 WordPress 文章以卡片形式嵌入到内容里（oEmbed）。绝大多数站点从不使用。移除后正常文章显示完全不变；仅在「嵌入别人的 WP 文章卡片」这一场景失效。', 'jinyu-theme-companion' ),

		],
		'limit_revisions'        => [
			'label' => __( '限制文章修订（每篇最多 5 个）', 'jinyu-theme-companion' ),

			'group' => 'admin',
			'desc'  => __( 'WP 默认无限保存修订版本，编辑频繁时 wp_posts 表会持续膨胀、拖慢文章查询。开启后每篇文章最多保留 5 个修订。注意：只限制新增，不删除历史修订，可用性不受影响。', 'jinyu-theme-companion' ),

		],
		'disable_live_geo'       => [
			'label' => __( '短接实时小工具的外部地理查询', 'jinyu-theme-companion' ),

			'group' => 'admin',
			'desc'  => __( '实时小工具会同步请求 whois.pconline.com.cn 查询访客 IP 归属地（超时上限 3 秒）。该外链一旦变慢，会拖慢每一个带实时小工具的页面。开启后请求被本地直接拒绝，归属地显示为空，其余实时数据不受影响。', 'jinyu-theme-companion' ),

		],
		'hide_wp_footer'        => [
			'label' => __( '移除 WordPress 后台页脚文案', 'jinyu-theme-companion' ),

			'group' => 'admin',
			'desc'  => __( '开启后隐藏后台底部的「感谢使用 WordPress 进行创作。」与 WordPress 版本号。仅后台可见，不影响前台访客。', 'jinyu-theme-companion' ),

		],
		'disable_xmlrpc'         => [
			'label' => __( '禁用 XML-RPC 接口', 'jinyu-theme-companion' ),

			'group' => 'security',
			'desc'  => __( 'XML-RPC 用于旧版编辑器与部分第三方客户端的远程调用，也是暴力破解与 pingback 攻击的常见入口。关闭后手机原生 App、Jetpack 的部分远程功能可能失效；普通站点无影响。', 'jinyu-theme-companion' ),

		],
		'clean_wp_head'          => [
			'label' => __( '清理 wp_head 冗余输出', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( '移除 wp_head 中的 wlwmanifest（Windows Live Writer）链接，少一处可被探测的痕迹，不影响任何功能。', 'jinyu-theme-companion' ),

		],
		'disable_pingback'       => [
			'label' => __( '禁用 pingback 自引用', 'jinyu-theme-companion' ),

			'group' => 'security',
			'desc'  => __( '摘掉 XML-RPC 的 pingback.ping 方法并关闭新文章默认 pingback，减少外链回推请求与攻击面。已发布文章的既有 pingback 不受影响。', 'jinyu-theme-companion' ),

		],
		'disable_wp_org_api'     => [
			'label' => __( '屏蔽 WordPress.org 外部 API 请求（推荐）', 'jinyu-theme-companion' ),

			'group' => 'security',
			'desc'  => __( '仅拦截发往 *.wordpress.org 的「插件详情 / 插件安装弹窗 / 主题详情 / 主题安装弹窗」等请求（路径 /plugins/、/themes/），打开这些弹窗不再卡数秒。核心版本探测、更新检查、语言包与升级包下载整条链路照常放行，后台「有新版本」提示与一键升级不受影响。仅拦 WP 官方源，站点自身接口（CDN / ajax 等）与国内头像源（Cravatar 等）完全不受影响。', 'jinyu-theme-companion' ),

		],
		'hide_admin_bar_front'   => [
			'label' => __( '前台对非管理员隐藏 admin bar', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( '未登录 / 非管理员访问前台时不再显示 WordPress 管理条，减少一处前台 CSS/JS 注入。管理员在后台与前台均不受影响。', 'jinyu-theme-companion' ),

		],
		'dns_preconnect'        => [
			'label' => __( '关键域名 DNS 预连接（Preconnect）', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( '在 <head> 最前面为静态资源 CDN 域名提前建好 DNS+TCP+TLS 连接（并附 dns-prefetch 兼容老浏览器）。首屏图片/脚本命中该域名时省去建连往返，TTFB 与 LCP 略降。仅作用于已配置的「静态资源 CDN 域名」（主题设置→资源），未配置 CDN 时不发任何多余请求；不影响其它域名。还可通过 jinyu_perf_preconnect_hosts 过滤器追加字体/统计等源。', 'jinyu-theme-companion' ),

		],
		'iframe_lazy'           => [
			'label' => __( 'iframe 懒加载', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( '自动给正文/小工具里的 <iframe>（视频嵌入、第三方组件等）补上 loading="lazy"，延迟到进入视口才加载，减少首屏请求与带宽。主题 [jinyu_video] 的 B站 嵌入已内置该属性，此处对其它来源的 iframe 兜底。已带 loading 的不会被重复添加。现代浏览器对 iframe 本就默认懒加载，此开关为显式保险，默认开。', 'jinyu-theme-companion' ),

		],
		'restrict_guest_rest'   => [
			'label' => __( '限制游客 REST API 访问（加固）', 'jinyu-theme-companion' ),

			'group' => 'security',
			'desc'  => __( '未登录访客仅能访问公开内容类 REST 路由（文章/页面/评论/分类/标签/媒体/搜索等），其余内部/管理类路由（用户 /wp/v2/users、设置、插件、主题、区块、菜单、小工具、模板、全局样式、类型/分类法/状态枚举等）一律返回 403。主要阻断「通过 /wp/v2/users 枚举作者用户名」这类常见探测与 API 滥用。登录用户不受影响（后台区块编辑器等照常）。注意：若站内插件在前台依赖被拦截的路由，相关功能会受影响——默认关，确认无依赖后再开。', 'jinyu-theme-companion' ),

		],
		'html_minify'           => [
			'label' => __( '前台 HTML 压缩', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( '输出前压缩整页 HTML：去掉标签间多余空白与注释（pre / textarea / script / style / noscript 内容原样保护），减小传输体积。压缩会去掉源码里的缩进换行，日后查看页面源代码会变成一整行；页面结构与样式不受影响。默认开，如需查看源码或依赖 HTML 空白排版（如部分邮件模板、打印样式）请关闭。', 'jinyu-theme-companion' ),

		],
		'comment_lazyload'      => [
			'label' => __( '评论懒加载（加载更多）', 'jinyu-theme-companion' ),

			'group' => 'front',
			'desc'  => __( '评论数多的文章，首屏只渲染第一页评论，底部出现「加载更多评论」按钮，点击后通过 admin-ajax 增量拉取后续评论并追加（复用主题评论回调与嵌套结构，锚点/SEO 不受影响）。长文评论区 DOM 量大幅下降。关闭开关即恢复原生评论分页。默认关，按需开启。', 'jinyu-theme-companion' ),

		],
	];
}

/* ───────────────────────── 开关落地（前台 + 后台全站生效） ───────────────────────── */

/*
 * 广播给主题：主题是纯呈现层，只按需「问」开关值，不认识本插件的 option 键。
 * 契约方向：主题 apply_filters( 'jinyu_perf_options', [] ) → 本插件 add_filter 注入开关表。
 * 主题缺席 / 本插件缺席时，另一侧都按各自默认值降级，互不致命。
 */
add_filter(
	'jinyu_perf_options',
	function ( $opts ) {
		return array_merge( is_array( $opts ) ? $opts : [], jinyu_perf_get_options() );
	}
);

/*
 * 单个安全开关的专用广播：主题侧的 REST 路由白名单逻辑在 theme-compat.php，
 * 它不该为了读一个布尔值就依赖 jinyu_perf_get_options()（那是整表读取 + 静态缓存）。
 * 单开一个过滤器，语义单一、默认值明确（关），也不必让对方知道 option 键名。
 */
add_filter(
	'jinyu_restrict_guest_rest',
	function ( $enabled ) {
		$o = jinyu_perf_get_options();
		return ! empty( $o['restrict_guest_rest'] );
	}
);

/**
 * 套用全部开关。挂在 init:5 —— 开关保存后于「下一个请求」生效
 * （本请求内改的选项，钩子在本请求早已执行完，不回捞，逻辑简单可靠）。
 */
add_action( 'init', 'jinyu_perf_apply', 5 );

function jinyu_perf_apply(): void {
	$o = jinyu_perf_get_options();

	// 1) 关闭心跳：在脚本加载阶段（enqueue）注销 heartbeat。
	// 注意 admin-ajax.php 上下文不走 admin_enqueue_scripts，但心跳本来
	// 只在普通页面由 JS 发起，页面不发请求即达到目的。
	if ( ! empty( $o['disable_heartbeat'] ) ) {
		$kill_heartbeat = static function () {
			if ( wp_script_is( 'heartbeat', 'registered' ) ) {
				wp_deregister_script( 'heartbeat' );
			}
		};
		add_action( 'admin_enqueue_scripts', $kill_heartbeat, 999 );
		add_action( 'wp_enqueue_scripts', $kill_heartbeat, 999 );
	}

	// 2) 移除仪表盘「动态与新闻」：在自己的 wp_dashboard_setup 回调里
	// （优先级 1，先于小工具注册）摘掉官方注册函数。
	if ( ! empty( $o['disable_dashboard_news'] ) ) {
		add_action(
            'wp_dashboard_setup',
            static function () {
				remove_action( 'wp_dashboard_setup', 'wp_dashboard_events_news' );
			},
            1
        );
	}

	// 3) 移除 emoji 检测/替换脚本：不注入 JS，也就不会出现 s.w.org 裂图。
	// 完整接管原 optimize.php 的 emoji 移除（含 feed / 邮件过滤器），避免重复实现与开关失效。
	if ( ! empty( $o['disable_emoji'] ) ) {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_head', 'print_emoji_detection_script' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'emoji_svg_url', '__return_false' );
		add_filter(
            'tiny_mce_plugins',
            static function ( $plugins ) {
				return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : $plugins;
			}
        );
	}

	// 4) 移除 wp-embed 前端脚本（oEmbed 嵌入卡片）。
	if ( ! empty( $o['disable_embed'] ) ) {
		add_action(
            'wp_enqueue_scripts',
            static function () {
				if ( wp_script_is( 'wp-embed', 'registered' ) ) {
					wp_deregister_script( 'wp-embed' );
				}
			},
            999
        );
	}

	// 5) 限制修订数量：WP 核心过滤器，返回 5 即每篇最多 5 个修订。
	if ( ! empty( $o['limit_revisions'] ) ) {
		add_filter(
            'wp_revisions_to_keep',
            static function () {
				return 5;
			},
            999
        );
	}

	// 6) 短接实时小工具的外部地理查询。
	// pre_http_request 签名是 ($preempt, $args, $url) —— 必须接满 3 参，
	// 否则 $url 拿到的是 $args 数组，PHP 8 下 strpos() 直接 TypeError。
	if ( ! empty( $o['disable_live_geo'] ) ) {
		add_filter(
            'pre_http_request',
            static function ( $preempt, $args, $url ) {
				if ( is_string( $url ) && strpos( $url, 'whois.pconline.com.cn' ) !== false ) {
					return new WP_Error(
                        'jinyu_perf_geo_disabled',
                        __( '地理查询已被性能优化开关关闭', 'jinyu-theme-companion' )
					);
				}
				return $preempt;
			},
            10,
            3
        );
	}

	// 6.5) 移除 WP 后台页脚文案：清空 admin_footer_text 与 update_footer（高优先级覆盖核心默认文案）。
	// 仅影响后台页脚渲染，不影响前台访客；与「后台减负」分组语义一致。
	if ( ! empty( $o['hide_wp_footer'] ) ) {
		add_filter( 'admin_footer_text', '__return_empty_string', 99 );
		add_filter( 'update_footer', '__return_empty_string', 99 );
	}

	// 7) 禁用 XML-RPC：直接关闭远程调用接口（含 pingback 攻击面）。
	if ( ! empty( $o['disable_xmlrpc'] ) ) {
		add_filter( 'xmlrpc_enabled', '__return_false' );
	}

	// 8) 清理 wp_head：移除 wlwmanifest（Windows Live Writer）链接，少一处可被探测痕迹，不影响功能。
	if ( ! empty( $o['clean_wp_head'] ) ) {
		remove_action( 'wp_head', 'wlwmanifest_link' );
	}

	// 9) 禁用 pingback 自引用：摘掉 XML-RPC 的 pingback 方法，并关闭新文章默认 pingback。
	if ( ! empty( $o['disable_pingback'] ) ) {
		add_filter(
            'xmlrpc_methods',
            static function ( $methods ) {
				if ( is_array( $methods ) ) {
					unset( $methods['pingback.ping'], $methods['pingback.ext_pingbacks'] );
				}
				return $methods;
			}
        );
		add_filter( 'pre_option_default_ping_status', '__return_zero' );
	}

	// 10) 前台对非管理员隐藏 admin bar：减少一处前台 CSS/JS 注入。
	if ( ! empty( $o['hide_admin_bar_front'] ) ) {
		add_action(
            'init',
            static function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					show_admin_bar( false );
				}
			}
        );
	}

	// 11) 屏蔽 WordPress.org 外部 API 请求：国内访问 *.wordpress.org 极慢/超时，
	// 每次后台更新检查 / 翻译拉取 / 版本探测都同步卡数秒。pre_http_request
	// 直接返回 WP_Error，WP 更新检查立即失败跳过、不再阻塞后台。
	// 仅拦截 wordpress.org 主机，站点自身接口与国内头像源不受影响。
	//
	// 【只拦「详情 / 安装类」路径】这里用拦截清单，不用放行清单：放行清单必然随 WP
	// 版本演进被新的更新链路端点击穿 —— 2026-10-02 一天内连续漏掉 /core/version-check、
	// release/ 升级包、/translations/ 语言包索引与本体，表现为「后台看不到更新提示」
	// 「点升级后下载失败」「更新翻译失败」，而报错文案正是本插件抛的，极难定位。
	// 收敛后只拦真正拖慢后台的两类：
	// plugins/  插件详情、插件安装弹窗
	// themes/   主题详情、主题安装弹窗
	// 其余一律放行：核心版本探测 /core/version-check、更新检查 /core/update-check、
	// 语言包索引 /translations/、升级包 downloads.wordpress.org/release/ 与
	// translation/ 等整条更新链路。屏蔽的仍是慢请求，收益不变；
	// 更新通道不会因 WP 新增端点而被自己掐死。
	if ( ! empty( $o['disable_wp_org_api'] ) ) {
		add_filter(
            'pre_http_request',
            static function ( $preempt, $args, $url ) {
				if ( ! is_string( $url ) ) {
					return $preempt;
				}
				$host = wp_parse_url( $url, PHP_URL_HOST );
				if ( ! $host || ! preg_match( '/(\.|^)WordPress\.org$/i', $host ) ) {
					return $preempt;
				}
				$path = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( str_starts_with( $path, '/plugins/' ) || str_starts_with( $path, '/themes/' ) ) {
					return new WP_Error(
                        'jinyu_perf_wp_org_blocked',
                        __( 'WordPress.org 外部 API 已被性能优化开关屏蔽', 'jinyu-theme-companion' )
					);
				}
				return $preempt;
			},
            10,
            3
        );
	}

	// 12) 关键域名 DNS 预连接：在 <head> 最前为 CDN 域名提前建连。
	// 预连接域名只来自本插件接管的存储加速域名（storage_domain）。
	// 静态资源 CDN 域名这类站点自有配置，由站点经 jinyu_companion_preconnect_hosts 过滤器追加 ——
	// 插件不读任何主题的配置项，换主题后预连接不失效。
	if ( ! empty( $o['dns_preconnect'] ) ) {
		add_action(
            'wp_head',
            static function () {
				$hosts  = [];
				$scheme = 'https';
				// 图片常托管在存储加速域名；只预连接站点域名会导致图床域名零预连接，
				// 浏览器无法提前建连，首屏多一个 RTT。
				$sd = trim( (string) jinyu_companion_get_option( 'storage_domain', '' ) );
				if ( $sd ) {
					if ( preg_match( '#^[a-z]+://#i', $sd, $mm ) ) {
						$scheme = rtrim( $mm[0], ':' );
					}
					$h = wp_parse_url( $sd, PHP_URL_HOST );
					if ( $h && ! in_array( $h, $hosts, true ) ) {
						$hosts[] = $h;
					}
				}
				// 允许外部追加更多需预连接的域名（如字体/统计源）
				foreach ( (array) apply_filters( 'jinyu_perf_preconnect_hosts', [] ) as $extra ) {
					if ( is_string( $extra ) && ! in_array( $extra, $hosts, true ) ) {
						$hosts[] = $extra;
					}
				}
				foreach ( $hosts as $h ) {
					echo "\n<link rel=\"preconnect\" href=\"" . esc_attr( $scheme ) . '://' . esc_attr( $h ) . '">';
					echo "\n<link rel=\"dns-prefetch\" href=\"" . esc_attr( $scheme ) . '://' . esc_attr( $h ) . '">';
				}
			},
            1
        );
	}

	// 13) 评论懒加载：隐藏原生评论分页，改由「加载更多」按钮 + admin-ajax 增量拉取。
	if ( ! empty( $o['comment_lazyload'] ) ) {
		// 隐藏原生分页（由前端按钮替代）
		add_filter(
            'the_comments_pagination',
            static function ( $html ) {
				return is_singular() ? '' : $html;
			}
        );
		// 仅在单篇且开放评论时输出增量加载脚本
		add_action(
            'wp_footer',
            static function () {
				if ( ! is_singular() || ! comments_open() ) {
					return;
				}
				$opt = jinyu_perf_get_options();
				if ( empty( $opt['comment_lazyload'] ) ) {
					return;
				}
				ob_start();
				?>
			(function(){
				var btn = document.querySelector('.jinyu-comments-more');
				if(!btn) return;
				var list = document.querySelector('.jinyu-comment-list');
				if(!list) return;
				var busy = false;
				btn.addEventListener('click', function(){
					if(busy) return; busy=true;
					var page = parseInt(btn.getAttribute('data-page')||'2',10);
					var max  = parseInt(btn.getAttribute('data-max')||'1',10);
					var txt  = btn.querySelector('.jinyu-comments-more-txt');
					var old  = txt ? txt.textContent : '<?php echo esc_js( __( '加载更多评论', 'jinyu-theme-companion' ) ); ?>';
					if(txt) txt.textContent='<?php echo esc_js( __( '加载中…', 'jinyu-theme-companion' ) ); ?>';
					var url = (btn.getAttribute('data-ajax')||'') + '?action=jinyu_load_comments&post_id=' + encodeURIComponent(btn.getAttribute('data-post-id')||'') + '&page=' + page;
					fetch(url).then(function(r){return r.json();}).then(function(j){
						if(j && j.success && j.data && j.data.html){
							list.insertAdjacentHTML('beforeend', j.data.html);
							page++;
							btn.setAttribute('data-page', page);
							if(page>max && btn.parentNode){ btn.parentNode.removeChild(btn); }
							else if(txt){ txt.textContent=old; }
						} else if(txt){ txt.textContent=old; }
						busy=false;
					}).catch(function(){ if(txt) txt.textContent=old; busy=false; });
				});
			})();
				<?php
				wp_print_inline_script_tag( (string) ob_get_clean() );
			}
        );
	}

	// 14) iframe 懒加载：正文/小工具里缺 loading 属性的 iframe 自动补上 lazy。
	if ( ! empty( $o['iframe_lazy'] ) ) {
		$add_lazy = static function ( $content ) {
			if ( ! is_string( $content ) || false === strpos( $content, '<iframe' ) ) {
				return $content;
			}
			return preg_replace_callback(
				'#<iframe\b([^>]*)>#i',
				static function ( $m ) {
					if ( preg_match( '#\bloading\s*=#i', $m[1] ) ) {
						return $m[0];
					}
					return '<iframe loading="lazy"' . $m[1] . '>';
				},
				$content
			);
		};
		add_filter( 'the_content', $add_lazy, 99 );
		add_filter( 'widget_text_content', $add_lazy, 99 );
		add_filter( 'widget_block_content', $add_lazy, 99 );
	}

	// 15) 限制游客 REST API：未登录仅放行公开内容类路由，拦截内部/管理类路由。
	if ( ! empty( $o['restrict_guest_rest'] ) ) {
		add_filter(
            'rest_pre_dispatch',
            static function ( $result, $server, $request ) {
				if ( is_user_logged_in() ) {
					return $result;
				}
				$route = ltrim( (string) $request->get_route(), '/' );
				// 默认放行：公开内容类（文章/页面/媒体/评论/分类/标签/搜索/状态）
				// 拦截：用户枚举、设置、插件/主题、区块、菜单、小工具、模板、全局样式、类型/分类法枚举
				$deny = (array) apply_filters(
                    'jinyu_perf_rest_guest_deny',
                    [
						'#^wp/v2/users#m',
						'#^wp/v2/settings#m',
						'#^wp/v2/plugins#m',
						'#^wp/v2/themes#m',
						'#^wp/v2/blocks#m',
						'#^wp/v2/block-patterns#m',
						'#^wp/v2/block-types#m',
						'#^wp/v2/block-renderer#m',
						'#^wp/v2/menu-items#m',
						'#^wp/v2/menus#m',
						'#^wp/v2/widget-types#m',
						'#^wp/v2/widgets#m',
						'#^wp/v2/template-parts#m',
						'#^wp/v2/templates#m',
						'#^wp/v2/global-styles#m',
						'#^wp/v2/types#m',
						'#^wp/v2/taxonomies#m',
						'#^wp/v2/statuses#m',
					]
				);
				foreach ( $deny as $re ) {
					if ( is_string( $re ) && preg_match( $re, $route ) ) {
						return new WP_Error(
                            'jinyu_rest_forbidden',
                            __( '游客无权访问该 REST 接口', 'jinyu-theme-companion' ),
                            [ 'status' => 403 ]
						);
					}
				}
				return $result;
			},
            10,
            3
        );
	}
}

/* ───────────────────────── 状态采集：总览 ───────────────────────── */

/**
 * 总览指标（轻量，两次 SQL，可每页加载）。
 *
 * @return array
 */
function jinyu_perf_status(): array {
	global $wpdb;

	$autoload = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// WP 6.6+ autoload 列为 on/off/auto/auto-on/auto-off，须用兼容 IN 列表（复用 db-optimize 的 helper），
		// 写死 'yes' 会把「Autoload 体积」指标统计成 0。
		'SELECT SUM(LENGTH(option_value)) FROM ' . $wpdb->options . ' WHERE '
		. ( function_exists( 'jinyu_autoload_sql_in' ) ? jinyu_autoload_sql_in() : "autoload IN ('yes','on','auto','auto-on')" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 面板实时诊断需最新数据，优化动作本身不可缓存
	$transient_total = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 面板实时诊断需最新数据，优化动作本身不可缓存
	$transient_expired = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->options}
		 WHERE (option_name LIKE '_transient_timeout_%' OR option_name LIKE '_site_transient_timeout_%')
		 AND option_value < UNIX_TIMESTAMP()"
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 面板实时诊断需最新数据，优化动作本身不可缓存
	$overhead = (float) $wpdb->get_var(
		'SELECT COALESCE(SUM(Data_free),0) FROM information_schema.TABLES
		 WHERE TABLE_SCHEMA = DATABASE() AND Data_free > 0'
	);

	$active_plugins = (array) get_option( 'active_plugins', [] );

	// 心跳状态直接由开关推导：admin-ajax 上下文里 wp_script_is 不可靠，
	// 用「用户意志」而非「运行时碰巧的状态」来展示，逻辑才自洽。
	$o         = jinyu_perf_get_options();
	$opcache   = jinyu_perf_opcache_stats();
	$memcached = jinyu_perf_memcached_stats();

	return [
		'opcache'           => $opcache ? (bool) $opcache['enabled'] : false,
		'object_cache'      => (bool) ( $memcached['reachable'] ?? wp_using_ext_object_cache() ),
		'autoload_bytes'    => $autoload,
		'transient_total'   => $transient_total,
		'transient_expired' => $transient_expired,
		'table_overhead'    => $overhead,
		'active_plugins'    => count( $active_plugins ),
		'heartbeat'         => empty( $o['disable_heartbeat'] ),
	];
}

/**
 * 渲染层专用的状态快照：同一请求内多次读取只查一次（概览磁贴与性能中心分区共用）。
 *
 * jinyu_perf_status() 本身不缓存 —— 「一键优化」需要在同一请求里取前后对比（before/after），
 * 缓存会让对比结果恒等而失去意义。
 *
 * @return array
 */
function jinyu_perf_status_snapshot(): array {
	static $cache = null;
	if ( null === $cache ) {
		$cache = jinyu_perf_status();
	}
	return $cache;
}

/* ───────────────────────── 状态采集：OPcache 看板 ───────────────────────── */

/**
 * 读取 OPcache 运行指标。
 * 注意：CLI 下 opcache.enable_cli 默认关，返回 enabled=false 属正常，
 * 看板以 web（php-fpm）上下文为准——本函数由后台页面/AJAX 触发，天然是 web 上下文。
 *
 * @return array|null null=扩展未安装
 */
function jinyu_perf_opcache_stats(): ?array {
	if ( ! function_exists( 'opcache_get_status' ) ) {
		return null;
	}
	$st = @opcache_get_status( false );
	if ( ! is_array( $st ) ) {
		// 扩展装了但当前 SAPI 未启用（如 CLI）
		return [ 'enabled' => false ];
	}
	$stat = $st['opcache_statistics'] ?? [];

	// 内存键名兼容：PHP <=8.0 平铺在顶层（memory_used/free/wasted），
	// PHP 8.1+ 收进 memory_usage 子数组（used_memory/free_memory/wasted_memory）。
	$mu   = $st['memory_usage'] ?? [];
	$used = (float) ( $mu['used_memory'] ?? $st['memory_used'] ?? 0 );
	$free = (float) ( $mu['free_memory'] ?? $st['memory_free'] ?? 0 );
	$wasted = (float) ( $mu['wasted_memory'] ?? $st['memory_wasted'] ?? 0 );
	$total  = $used + $free;

	return [
		'enabled'        => ! empty( $st['opcache_enabled'] ),
		'cached_scripts' => (int) ( $stat['num_cached_scripts'] ?? 0 ),
		'hit_rate'       => round( (float) ( $stat['opcache_hit_rate'] ?? 0 ), 1 ),
		'hits'           => (int) ( $stat['num_hits'] ?? 0 ),
		'misses'         => (int) ( $stat['num_misses'] ?? 0 ),
		'memory_used'    => $used,
		'memory_total'   => $total,
		'mem_pct'        => $total > 0 ? round( $used / $total * 100, 1 ) : 0,
		'wasted'         => $wasted,
		'oom_restarts'   => (int) ( $stat['oom_restarts'] ?? 0 ),
		'last_restart'   => (int) ( $stat['last_restart_time'] ?? 0 ),
	];
}

/* ───────────────────────── 状态采集：Memcached 看板 ───────────────────────── */

/**
 * 读取 Memcached 服务器实时指标（getStats，等价于 stats 命令）。
 * 使用持久连接池，避免每次看板刷新都重建 TCP 连接。
 *
 * @return array|null null=PECL memcached 扩展不可用
 */
function jinyu_perf_memcached_stats(): ?array {
	if ( ! class_exists( 'Memcached' ) ) {
		return null;
	}

	// 服务器列表可通过过滤器覆盖（默认本机 11211，与 object-cache.php drop-in 一致）
	$servers = apply_filters( 'jinyu_perf_memcached_servers', [ [ '127.0.0.1', 11211 ] ] );

	// 持久池：同池复用连接；仅首次给池加服务器
	$m = new Memcached( 'jyc-perf-board' );
	if ( ! $m->getServerList() ) {
		$m->addServers( $servers );
	}
	$all = $m->getStats();
	if ( empty( $all ) ) {
		return [ 'reachable' => false ];
	}
	$s = reset( $all );

	$hits   = (int) ( $s['get_hits'] ?? 0 );
	$misses = (int) ( $s['get_misses'] ?? 0 );
	$total  = $hits + $misses;
	$limit  = (int) ( $s['limit_maxbytes'] ?? 0 );
	$bytes  = (int) ( $s['bytes'] ?? 0 );

	return [
		'reachable'   => true,
		'version'     => (string) ( $s['version'] ?? '' ),
		'hit_rate'    => $total > 0 ? round( $hits / $total * 100, 1 ) : null,
		'hits'        => $hits,
		'misses'      => $misses,
		'curr_items'  => (int) ( $s['curr_items'] ?? 0 ),
		'bytes'       => $bytes,
		'limit'       => $limit,
		'mem_pct'     => $limit > 0 ? round( $bytes / $limit * 100, 1 ) : 0,
		'uptime'      => (int) ( $s['uptime'] ?? 0 ),
		'connections' => (int) ( $s['curr_connections'] ?? 0 ),
	];
}

/* ───────────────────────── 状态采集：真实用户 Web Vitals ───────────────────────── */

/**
 * 读取本地聚合的真实用户指标（由前端 web-vitals 采集后写入）。
 *
 * @return array|null null=近 7 天无样本
 */
function jinyu_perf_web_vitals_stats(): ?array {
	$agg = get_option( 'jinyu_web_vitals_stats' );
	if ( ! is_array( $agg ) || empty( $agg['n'] ) ) {
		return null;
	}
	$n   = (int) $agg['n'];
	$out = [
		'n'     => $n,
		'avg'   => [],
		'max'   => [],
		'paths' => [],
		'fresh' => ! empty( $agg['ts'] ) && ( time() - (int) $agg['ts'] ) < WEEK_IN_SECONDS,
	];
	foreach ( [ 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ] as $k ) {
		$sum         = (float) ( $agg['sum'][ $k ] ?? 0 );
		$out['avg'][ $k ] = $n > 0 ? round( $sum / $n, 'cls' === $k ? 3 : 0 ) : 0;
		$out['max'][ $k ] = round( (float) ( $agg['max'][ $k ] ?? 0 ), 'cls' === $k ? 3 : 0 );
	}
	// 热门路径：按样本数降序取前 5（与下方「最慢路径」互补：一个看热度，一个看最慢）。
	if ( ! empty( $agg['paths'] ) && is_array( $agg['paths'] ) ) {
		$hot = $agg['paths'];
		arsort( $hot );
		foreach ( array_slice( $hot, 0, 5, true ) as $p => $pn ) {
			$out['paths'][] = [
				'path' => (string) $p,
				'n'    => (int) $pn,
			];
		}
	}
	if ( ! empty( $agg['path_metrics'] ) && is_array( $agg['path_metrics'] ) ) {
		$rows = [];
		foreach ( $agg['path_metrics'] as $p => $pm ) {
			if ( ! is_array( $pm ) || empty( $pm['n'] ) ) {
				continue;
			}
			$rows[] = [
				'path'    => $p,
				'n'       => (int) $pm['n'],
				'lcp_avg' => $pm['n'] > 0 ? (int) round( (float) $pm['lcp_sum'] / $pm['n'] ) : 0,
				'lcp_max' => (int) round( (float) ( $pm['lcp_max'] ?? 0 ) ),
			];
		}
		// 按最差 LCP 降序，取前 5 条路径 = 最慢路径
		usort(
            $rows,
            static function ( $a, $b ) {
				return $b['lcp_max'] <=> $a['lcp_max'];
			}
        );
		$out['slowest'] = array_slice( $rows, 0, 5 );
	}
	return $out;
}

/**
 * Web Vitals 指标元数据：标签 / 中文名 / 单位 / 良好与较差阈值（Core Web Vitals 标准）。
 *
 * @return array
 */
function jinyu_perf_wv_meta(): array {
	return [
		'lcp'  => [
			'label' => 'LCP',
			'name' => __( '最大内容绘制', 'jinyu-theme-companion' ),
			'ms' => true,
			'good' => 2500,
			'poor' => 4000,
			'tip' => __( '视口内最大元素（图片/标题/区块）渲染完成的时间。≤2.5s 良好，≥4s 较差。', 'jinyu-theme-companion' ),

			'optimize' => __( '压缩首屏大图并转 WebP，预加载关键资源，非首屏图片懒加载。', 'jinyu-theme-companion' ),
		],
		'inp'  => [
			'label' => 'INP',
			'name' => __( '交互延迟', 'jinyu-theme-companion' ),
			'ms' => true,
			'good' => 200,
			'poor' => 500,
			'tip' => __( '用户点击/输入到页面响应的延迟，反映整体交互流畅度。≤200ms 良好，≥500ms 较差。', 'jinyu-theme-companion' ),

			'optimize' => __( '拆分长任务、精简第三方脚本，交互回调避免强制同步布局。', 'jinyu-theme-companion' ),
		],
		'cls'  => [
			'label' => 'CLS',
			'name' => __( '累计布局位移', 'jinyu-theme-companion' ),
			'ms' => false,
			'good' => 0.1,
			'poor' => 0.25,
			'tip' => __( '页面加载中元素意外位移的幅度，衡量视觉稳定性。≤0.1 良好，≥0.25 较差。', 'jinyu-theme-companion' ),

			'optimize' => __( '为图片/视频/广告预留宽高比，禁止插入内容引发位移。', 'jinyu-theme-companion' ),
		],
		'fcp'  => [
			'label' => 'FCP',
			'name' => __( '首次内容绘制', 'jinyu-theme-companion' ),
			'ms' => true,
			'good' => 1800,
			'poor' => 3000,
			'tip' => __( '浏览器首次画出任意文本/图片的时间，首屏出图的快慢。≤1.8s 良好。', 'jinyu-theme-companion' ),

			'optimize' => __( '内联关键 CSS，移除阻塞渲染的脚本，启用对象缓存。', 'jinyu-theme-companion' ),
		],
		'ttfb' => [
			'label' => 'TTFB',
			'name' => __( '首字节时间', 'jinyu-theme-companion' ),
			'ms' => true,
			'good' => 800,
			'poor' => 1800,
			'tip' => __( '从请求到收到服务器第一个字节的耗时，反映后端与网络。≤0.8s 良好。', 'jinyu-theme-companion' ),

			'optimize' => __( '开启页面缓存与对象缓存，优化数据库查询，启用 CDN。', 'jinyu-theme-companion' ),
		],
	];
}

/**
 * 按 Core Web Vitals 阈值给均值评级：good / mid / poor。
 *
 * @param float $v   指标均值
 * @param array $meta jinyu_perf_wv_meta() 的单项
 * @return string
 */
function jinyu_perf_wv_rate( float $v, array $meta ): string {
	if ( $v <= (float) $meta['good'] ) {
		return 'good';
	}
	if ( $v >= (float) $meta['poor'] ) {
		return 'poor';
	}
	return 'mid';
}

/**
 * 由 5 项指标聚合出 0-100 综合体验评分（Field Performance Score）。
 * 单项分：≤good 记 100，≥poor 记 0，区间内线性插值；加权平均，并按木桶取评级。
 *
 * @param array $wv  jinyu_perf_web_vitals_stats() 返回值
 * @param array $wvm jinyu_perf_wv_meta() 返回值
 * @return array [ 'score' => int, 'rate' => 'good'|'mid'|'poor' ]
 */
function jinyu_perf_wv_score( array $wv, array $wvm ): array {
	$weights = [
		'lcp' => 0.25,
		'inp' => 0.25,
		'cls' => 0.15,
		'fcp' => 0.15,
		'ttfb' => 0.20,
	];
	$sum = 0;
	$w = 0;
	$worst = 'good';
	foreach ( $weights as $k => $wt ) {
		if ( ! isset( $wv['avg'][ $k ] ) ) {
			continue;
		}
		$v    = (float) $wv['avg'][ $k ];
		$meta = $wvm[ $k ];
		$good = (float) $meta['good'];
		$poor = (float) $meta['poor'];
		if ( $v <= $good ) {
			$s = 100;
		} elseif ( $v >= $poor ) {
			$s = 0;
		} else {
			$s = round( 100 * ( $poor - $v ) / ( $poor - $good ) );
		}
		$sum += $s * $wt;
		$w   += $wt;
		$r    = jinyu_perf_wv_rate( $v, $meta );
		if ( 'poor' === $r ) {
			$worst = 'poor';
		} elseif ( 'mid' === $r && 'poor' !== $worst ) {
			$worst = 'mid';
		}
	}
	$score = $w > 0 ? (int) round( $sum / $w ) : 0;
	// 木桶：任一核心项较差则整体不超过「需优化」上限。
	if ( 'poor' === $worst && $score > 49 ) {
		$score = 49;
	}
	return [
		'score' => $score,
		'rate'  => $score >= 90 ? 'good' : ( $score >= 50 ? 'mid' : 'poor' ),
	];
}

/* ───────────────────────── 优化动作 ───────────────────────── */

/**
 * 清理过期 transient。
 * 只删「已过期」的 timeout 计时行（option_value < 当前时间戳），
 * 对应值行由 WP 惰性回收（读到过期 timeout 会自动当作不存在），
 * 有效期内的 transient 一条不碰。
 *
 * @return int 删除行数
 */
function jinyu_perf_clean_transients(): int {
	global $wpdb;
	$sql = "DELETE FROM {$wpdb->options}
			WHERE (option_name LIKE '_transient_timeout_%' OR option_name LIKE '_site_transient_timeout_%')
			AND option_value < UNIX_TIMESTAMP()";
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 维护类批量操作，无法用 API 替代
	$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB, WordPress.DB.PreparedSQL.NotPrepared
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->rows_affected;
}

/**
 * 维护有碎片的表（information_schema 里 Data_free > 0 的表）。
 * 改用 ANALYZE TABLE 更新统计信息：InnoDB 下 OPTIMIZE 会重建整表并锁表，
 * 大表/高流量站点点「优化」会卡死前台；ANALYZE 只刷新统计，几乎不锁表。
 * 仅手动触发，绝不挂在任何自动钩子上。
 *
 * @return array{optimized:int, list:string[]}
 */
function jinyu_perf_optimize_tables(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 面板实时诊断需最新数据，优化动作本身不可缓存
	$tables = $wpdb->get_results(
		'SELECT TABLE_NAME AS t, Data_free AS f FROM information_schema.TABLES
		 WHERE TABLE_SCHEMA = DATABASE() AND Data_free > 0 ORDER BY Data_free DESC'
	);
	$optimized = 0;
	$list      = [];
	foreach ( (array) $tables as $row ) {
		// 表名来自 information_schema（系统目录），非用户输入，无注入面
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		$res = $wpdb->query( $wpdb->prepare( 'ANALYZE TABLE %i', $row->t ) );
		if ( false !== $res ) {
			++$optimized;
			$list[] = $row->t . '(' . jinyu_perf_human( (float) $row->f ) . ')';
		}
	}
	return [
		'optimized' => $optimized,
		'list' => $list,
	];
}

/* ───────────────────────── 缓存刷新 ───────────────────────── */

/**
 * 重置 OPcache（整池字节码缓存，所有 fpm worker 共享）。
 * 必须在 web 上下文调用才生效；CLI 下返回 false 属预期，结果里如实提示。
 *
 * @return string 人类可读结果
 */
function jinyu_perf_reset_opcache(): string {
	if ( ! function_exists( 'opcache_reset' ) ) {
		return __( 'OPcache 未安装，无需重置', 'jinyu-theme-companion' );
	}
	$ok = @opcache_reset();
	return $ok
		? __( 'OPcache 已重置', 'jinyu-theme-companion' )
		: __( 'OPcache 重置未生效（当前可能是 CLI 上下文，请在后台页面点击）', 'jinyu-theme-companion' );
}

/**
 * 清空对象缓存。
 * 有 drop-in 时走 wp_cache_flush()：本插件的 drop-in 用「推进本站缓存代际」实现，
 * 只失效本安装的键，不会像 Memcached::flush() 那样清空整台服务器上其它站点的数据。
 * 没有 drop-in 时降级为直连缓存服务清空（那是服务器级操作，结果里如实标注）。
 *
 * @return string 人类可读结果
 */
function jinyu_perf_flush_memcached(): string {
	if ( function_exists( 'wp_cache_flush' ) && wp_using_ext_object_cache() ) {
		$ok = wp_cache_flush();
		return $ok ? __( '对象缓存已清空（仅本站，不影响同机其它站点）', 'jinyu-theme-companion' ) : __( '对象缓存清空失败', 'jinyu-theme-companion' );
	}
	// 降级路径：没有 drop-in 但扩展可用时直连清空（服务器级，会波及同机其它应用）
	if ( class_exists( 'Memcached' ) ) {
		$m = new Memcached( 'jyc-perf-flush' );
		if ( ! $m->getServerList() ) {
			$m->addServers( apply_filters( 'jinyu_perf_memcached_servers', [ [ '127.0.0.1', 11211 ] ] ) );
		}
		return $m->flush() ? __( '已直连清空 Memcached（服务器级，同机其它站点也会被清）', 'jinyu-theme-companion' ) : __( 'Memcached 清空失败', 'jinyu-theme-companion' );
	}
	return __( '对象缓存未启用（无 drop-in 也无 Memcached 扩展）', 'jinyu-theme-companion' );
}

/* ───────────────── 对象缓存 drop-in 部署 / 回滚 ───────────────── */

const JINYU_OC_DROPIN_MARKER = 'JINYU_DROPIN_MARKER:jinyu-memcached-object-cache';

/**
 * 读取 wp-content/object-cache.php 状态。
 *
 * @return array{deployed:string|false, ext:bool, reachable:bool, can_deploy:bool, wp_using_ext:bool}
 *               deployed: 'jinyu'=本插件部署 | 'foreign'=外部部署 | false=无
 */
function jinyu_perf_object_cache_state(): array {
	$target = WP_CONTENT_DIR . '/object-cache.php';
	$exists = file_exists( $target );

	$deployed = $exists
		? ( jinyu_perf_object_cache_is_jinyu( $target ) ? 'jinyu' : 'foreign' )
		: false;

	$ext       = class_exists( 'Memcached' );
	$reachable = false;
	if ( $ext ) {
		$m = new Memcached( 'jyc-perf-probe' );
		if ( ! $m->getServerList() ) {
			$m->addServers( apply_filters( 'jinyu_perf_memcached_servers', [ [ '127.0.0.1', 11211 ] ] ) );
		}
		$stats     = $m->getStats();
		$reachable = ! empty( $stats );
	}

	return [
		'deployed'      => $deployed,
		'ext'           => $ext,
		'reachable'     => $reachable,
		'can_deploy'    => $ext && $reachable,
		'wp_using_ext'  => wp_using_ext_object_cache(),
	];
}

/** 该 object-cache.php 是否由本插件部署（靠文件头标记判定）。 */
function jinyu_perf_object_cache_is_jinyu( string $file ): bool {
	$head = (string) file_get_contents( $file, false, null, 0, 2048 );
	return false !== strpos( $head, JINYU_OC_DROPIN_MARKER );
}

/**
 * 能力对比：扫描现有外部 drop-in，识别金玉版可额外提供、而对方缺失的能力。
 *
 * 各能力用「仅在生效代码中出现」的特征串判定（避开注释误判），金玉版模板恒包含全部。
 *
 * @return array{exists:bool, missing:array<string,string>, recommend:bool}
 */
function jinyu_perf_object_cache_analyze_external(): array {
	$caps = [
		'local_cache'  => [
			'label' => __( '请求内本地缓存（避免同请求重复查询 Memcached）', 'jinyu-theme-companion' ),
			'token' => 'add_to_internal(',
		],
		'get_multiple' => [
			'label' => __( '批量获取（wp_cache_get_multiple / getMulti）', 'jinyu-theme-companion' ),
			'token' => 'getMulti(',
		],
		'cas'          => [
			'label' => __( 'CAS 乐观锁（高并发计数防竞争）', 'jinyu-theme-companion' ),
			'token' => 'get_with_cas(',
		],
		'libketama'    => [
			'label' => __( '一致性哈希（多客户端分布一致）', 'jinyu-theme-companion' ),
			'token' => 'OPT_LIBKETAMA_COMPATIBLE',
		],
		'suspend'      => [
			'label' => __( '缓存加法挂起（wp_suspend_cache_addition 兼容）', 'jinyu-theme-companion' ),
			'token' => 'wp_suspend_cache_addition(',
		],
		'graceful'     => [
			'label' => __( 'Memcached 扩展缺失时优雅降级（回退核心缓存，不白屏）', 'jinyu-theme-companion' ),
			'token' => "class_exists( 'Memcached', false )",
		],
		'get_stats'    => [
			'label' => __( '统计接口（getStats）', 'jinyu-theme-companion' ),
			'token' => 'getStats(',
		],
		'counters'     => [
			'label' => __( '命中 / 未命中计数', 'jinyu-theme-companion' ),
			'token' => '++$this->cache_hits',
		],
	];

	$target = WP_CONTENT_DIR . '/object-cache.php';
	if ( ! file_exists( $target ) || jinyu_perf_object_cache_is_jinyu( $target ) ) {
		return [
			'exists' => false,
			'missing' => [],
			'recommend' => false,
		];
	}

	$content = (string) file_get_contents( $target );
	$missing = [];
	foreach ( $caps as $k => $c ) {
		if ( false === strpos( $content, $c['token'] ) ) {
			$missing[ $k ] = $c['label'];
		}
	}
	return [
		'exists' => true,
		'missing' => $missing,
		'recommend' => count( $missing ) > 0,
	];
}

/** 备份现有 object-cache.php 为 object-cache.php.bak-<时间戳>，仅保留最近 3 份。成功返回备份路径，失败返回 false。 */
function jinyu_perf_object_cache_backup( string $target ) {
	$dir = dirname( $target );
	$bak = $target . '.bak-' . gmdate( 'Ymd-His' );
	if ( ! @copy( $target, $bak ) ) {
		return false;
	}
	$globs = glob( $dir . '/object-cache.php.bak-*' ) ?: [];
	if ( count( $globs ) > 3 ) {
		usort(
            $globs,
            static function ( $a, $b ) {
				return filemtime( $a ) <=> filemtime( $b );
			}
        );
		foreach ( array_slice( $globs, 0, count( $globs ) - 3 ) as $old ) {
			@wp_delete_file( $old );
		}
	}
	return $bak;
}

/**
 * 部署：写入 wp-content/object-cache.php。
 *
 * @param bool $force  允许覆盖外部 drop-in（仅升级替换流程使用）。
 * @param bool $backup 覆盖前是否先备份原文件。
 */
function jinyu_perf_deploy_object_cache( $force = false, $backup = false ): array {
	$msg_bak = ''; // 备份提示（无旧文件/无备份时保持空串，避免未初始化变量告警）
	$tpl = __DIR__ . '/object-cache-dropin.tpl';
	if ( ! is_readable( $tpl ) ) {
		return [
			'ok' => false,
			'msg' => __( '部署模板缺失（object-cache-dropin.tpl）', 'jinyu-theme-companion' ),
		];
	}
	if ( ! class_exists( 'Memcached' ) ) {
		return [
			'ok' => false,
			'msg' => __( 'PECL Memcached 扩展不可用，无法部署', 'jinyu-theme-companion' ),
		];
	}
	// 用 WP_CONTENT_DIR 而不是 ABSPATH . 'wp-content'：后者的路径不保证存在（自定义 wp-content 目录会失效）。
	$target = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/object-cache.php';
	if ( file_exists( $target ) ) {
		if ( jinyu_perf_object_cache_is_jinyu( $target ) ) {
			return [
				'ok' => true,
				'msg' => __( '对象缓存已部署，无需重复', 'jinyu-theme-companion' ),
			];
		}
		if ( ! $force ) {
			return [
				'ok' => false,
				'msg' => __( '已存在外部 object-cache.php，未覆盖以免破坏现有缓存', 'jinyu-theme-companion' ),
			];
		}
		if ( $backup ) {
			$bak = jinyu_perf_object_cache_backup( $target );
			if ( false === $bak ) {
				return [
					'ok' => false,
					'msg' => __( '备份原文件失败，已中止替换以确保安全', 'jinyu-theme-companion' ),
				];
			}
			$msg_bak = '（已备份原文件：' . basename( $bak ) . '）';
		} else {
			$msg_bak = '';
		}
	}

	$code = (string) file_get_contents( $tpl );
	// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- 缓存类插件的对象缓存 drop-in 只能落在 WP_CONTENT_DIR 根目录，WP 不识别其他位置；wp.org 指南对缓存插件有此豁免。
	if ( false === @file_put_contents( $target, $code, LOCK_EX ) ) {
		return [
			'ok' => false,
			'msg' => __( '写入 wp-content/object-cache.php 失败，请检查目录写权限', 'jinyu-theme-companion' ),
		];
	}
	return [
		'ok' => true,
		'msg' => __( '已部署 object-cache.php，下次请求起 WordPress 启用 Memcached 对象缓存', 'jinyu-theme-companion' ) . $msg_bak,
	];
}

/**
 * 把已部署的 object-cache.php 同步成当前模板。
 *
 * 为什么需要：object-cache.php 是「由插件分发、落在 wp-content 根目录」的代码副本，唯一来源是同目录的
 * object-cache-dropin.tpl。模板随插件升级修好后，已经部署过的站点不会自己更新 —— 老站会一直跑旧副本，
 * drop-in 的任何缺陷「修了也到不了用户手里」。这里按内容比对做幂等同步。
 *
 * 只覆盖「确认是本插件部署的」drop-in；外部 drop-in 一律不碰。写入用「临时文件 + rename」：
 * drop-in 每个请求都会被执行，半截文件会让整站 fatal，绝不能直接覆盖写。
 *
 * @return bool true = 已无差异（或无事可做），false = 写入失败需下次重试。
 */
function jinyu_perf_sync_object_cache_dropin(): bool {
	$target = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/object-cache.php';
	if ( ! file_exists( $target ) || ! jinyu_perf_object_cache_is_jinyu( $target ) ) {
		return true; // 没部署过 / 不是我们部署的：无事可做，不重试。
	}
	$tpl = __DIR__ . '/object-cache-dropin.tpl';
	if ( ! is_readable( $tpl ) ) {
		return false;
	}
	$want = (string) file_get_contents( $tpl );
	$have = (string) file_get_contents( $target );
	if ( '' === $want || $want === $have ) {
		return true;
	}

	$tmp = $target . '.' . str_replace( '.', '', uniqid( '', true ) ) . '.tmp';
	// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- 详见 jinyu_perf_deploy_object_cache()：缓存插件的 drop-in 只能落在 WP_CONTENT_DIR 根目录。
	if ( false === @file_put_contents( $tmp, $want, LOCK_EX ) ) {
		return false;
	}
	// rename 在同一文件系统内是原子的：要么旧文件、要么新文件，不存在半截状态。
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- drop-in 只能落在 WP_CONTENT_DIR 根目录；同盘 rename 的原子替换是避免半截 drop-in 导致整站致命错误的唯一手段（WP_Filesystem::move 无原子保证）。
	if ( ! @rename( $tmp, $target ) ) {
		@wp_delete_file( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 清理临时文件失败不影响结果，无可替代 WP API
		return false;
	}
	return true;
}

/**
 * 插件版本变化时触发一次 drop-in 同步。
 *
 * 挂在 admin_init：此时 object-cache.php 已加载完毕（改写它不影响当前请求），且只在后台访问时做，
 * 前台零额外开销。同步失败就不记录版本，下次后台访问自动重试。
 */
function jinyu_perf_maybe_sync_object_cache_dropin(): void {
	if ( (string) get_option( 'jinyu_oc_dropin_synced_ver', '' ) === JINYU_COMPANION_VER ) {
		return;
	}
	if ( ! jinyu_perf_sync_object_cache_dropin() ) {
		return;
	}
	// 不参与 autoload：只在后台读一次，没必要让前台每个请求都带上它。
	update_option( 'jinyu_oc_dropin_synced_ver', JINYU_COMPANION_VER, false );
}
add_action( 'admin_init', 'jinyu_perf_maybe_sync_object_cache_dropin' );

/** 回滚：仅删除本插件部署的 drop-in。 */
function jinyu_perf_remove_object_cache(): array {
	$target = WP_CONTENT_DIR . '/object-cache.php';
	if ( ! file_exists( $target ) ) {
		return [
			'ok' => true,
			'msg' => __( '未部署对象缓存', 'jinyu-theme-companion' ),
		];
	}
	if ( ! jinyu_perf_object_cache_is_jinyu( $target ) ) {
		return [
			'ok' => false,
			'msg' => __( '该 object-cache.php 非本插件部署，未删除', 'jinyu-theme-companion' ),
		];
	}
	if ( ! @wp_delete_file( $target ) ) {
		return [
			'ok' => false,
			'msg' => __( '删除 object-cache.php 失败，请检查目录权限', 'jinyu-theme-companion' ),
		];
	}
	return [
		'ok' => true,
		'msg' => __( '已移除 object-cache.php，下次请求起恢复默认数据库缓存', 'jinyu-theme-companion' ),
	];
}

/** 渲染 Memcached 面板内的部署控件（随状态看板一起刷新）。 */
function jinyu_perf_object_cache_control_html(): string {
	$st   = jinyu_perf_object_cache_state();
	$html = '<div class="jperf-oc">';

	if ( 'jinyu' === $st['deployed'] ) {
		$html .= '<div class="jperf-oc-row">'
			. '<span class="jperf-oc-badge on">' . esc_html__( '已部署（本插件管理）', 'jinyu-theme-companion' ) . '</span>'
			. '<button type="button" class="jperf-btn jperf-btn-danger jperf-btn-sm" data-deploy="0">'
			. '<svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>'
			. '<span>' . esc_html__( '移除 / 停用', 'jinyu-theme-companion' ) . '</span></button></div>';
		$html .= '<p class="jperf-oc-note">' . esc_html__( 'WordPress 当前使用 Memcached 对象缓存。移除后下次请求恢复默认数据库缓存。', 'jinyu-theme-companion' ) . '</p>';
	} elseif ( 'foreign' === $st['deployed'] ) {
		$an    = jinyu_perf_object_cache_analyze_external();
		$html .= '<div class="jperf-oc-row"><span class="jperf-oc-badge">' . esc_html__( '已存在外部 object-cache.php', 'jinyu-theme-companion' ) . '</span></div>';
		if ( ! empty( $an['missing'] ) ) {
			$html .= '<p class="jperf-oc-note">' . esc_html__( '对比发现：金玉版可额外提供以下能力，当前外部文件未包含：', 'jinyu-theme-companion' ) . '</p>';
			$html .= '<ul class="jperf-oc-caps">';
			foreach ( $an['missing'] as $label ) {
				$html .= '<li>' . esc_html( $label ) . '</li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<p class="jperf-oc-note">' . esc_html__( '功能与金玉版相当。替换为金玉版后可由本插件统一管理（支持一键回滚）。', 'jinyu-theme-companion' ) . '</p>';
		}
		$html .= '<div class="jperf-oc-row">'
			. '<button type="button" class="jperf-btn jperf-btn-hero jperf-btn-sm" data-upgrade="1">'
			. '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>'
			. '<span>' . esc_html__( '升级替换为金玉版', 'jinyu-theme-companion' ) . '</span></button></div>';
		$html .= '<div class="jperf-oc-confirm" hidden>'
			. '<p class="jperf-oc-confirm-tip">' . esc_html__( '将用金玉版 object-cache.php 替换现有文件。替换前建议备份原文件以便随时还原。', 'jinyu-theme-companion' ) . '</p>'
			. '<label class="jperf-oc-backup"><input type="checkbox" data-backup="1" checked> ' . esc_html__( '替换前备份原文件（推荐）', 'jinyu-theme-companion' ) . '</label>'
			. '<div class="jperf-oc-confirm-btns">'
			. '<button type="button" class="jperf-btn jperf-btn-hero jperf-btn-sm" data-upgrade-confirm="1">' . esc_html__( '确认升级', 'jinyu-theme-companion' ) . '</button>'
			. '<button type="button" class="jperf-btn jperf-btn-sm" data-upgrade-cancel="1">' . esc_html__( '取消', 'jinyu-theme-companion' ) . '</button>'
			. '</div></div>';
	} elseif ( $st['can_deploy'] ) {
		$html .= '<div class="jperf-oc-row"><button type="button" class="jperf-btn jperf-btn-hero jperf-btn-sm" data-deploy="1">'
			. '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>'
			. '<span>' . esc_html__( '部署对象缓存', 'jinyu-theme-companion' ) . '</span></button></div>';
		$html .= '<p class="jperf-oc-note">' . esc_html__( '一键写入 object-cache.php，让 WordPress 直接使用 Memcached，无需手动配置。', 'jinyu-theme-companion' ) . '</p>';
	} else {
		$reason = $st['ext']
			? esc_html__( '无法连接 Memcached 守护进程（127.0.0.1:11211）', 'jinyu-theme-companion' )
			: esc_html__( '服务器未安装 PECL Memcached 扩展', 'jinyu-theme-companion' );
		$html .= '<div class="jperf-oc-row"><span class="jperf-oc-badge off">' . esc_html__( '不支持部署', 'jinyu-theme-companion' ) . '</span></div>';
		$html .= '<p class="jperf-oc-note">' . $reason . '</p>';
	}

	$html .= '</div>';
	return $html;
}

/**
 * 清第三方整页缓存（WP Super Cache / W3 Total Cache 等，若有）。
 *
 * @return string 空串表示本站没有可清的整页缓存
 */
function jinyu_perf_flush_page_cache(): string {
	$msgs = [];

	// 本插件整页缓存（文件静态页）：epoch 版本号翻转即全量失效。
	if ( function_exists( 'jinyu_page_cache_flush' ) ) {
		jinyu_page_cache_flush();

		// 顺带回报后端可用性：缓存目录不可用时「已清空」只是清了个空目录，
		// 不说清楚的话用户会以为缓存生效了，实际每次访问都在重跑 WordPress。
		$pc = function_exists( 'jinyu_page_cache_status' ) ? jinyu_page_cache_status() : [];
		if ( ! empty( $pc['enabled'] ) && empty( $pc['ready'] ) ) {
			$msgs[] = __( '整页缓存已清空（但缓存目录不可用，缓存未生效）', 'jinyu-theme-companion' );
		} else {
			$msgs[] = __( '整页缓存已清空', 'jinyu-theme-companion' );
		}
	}

	// 片段缓存（transient / 对象缓存组）：走插件独占失效入口，主题定义的同义函数无法劫持，
	// 否则点「清除整页缓存」只清了第三方插件、本插件片段缓存原样留存，造成「点了没反应」的假象。
	if ( function_exists( 'jinyu_companion_cache_flush' ) ) {
		$n = jinyu_companion_cache_flush();
		// translators: Placeholder values are substituted at runtime.
		$msgs[] = sprintf( __( '片段缓存已清空（%d 项）', 'jinyu-theme-companion' ), $n );
	}

	// 第三方整页缓存插件
	if ( function_exists( 'wp_cache_clean_cache' ) ) {       // WP Super Cache
		wp_cache_clean_cache( true );
		$msgs[] = __( '整页缓存已清空', 'jinyu-theme-companion' );
	} elseif ( function_exists( 'wp_cache_clear_cache' ) ) { // W3 Total Cache
		wp_cache_clear_cache();
		$msgs[] = __( '整页缓存已清空', 'jinyu-theme-companion' );
	}

	return implode( __( '；', 'jinyu-theme-companion' ), $msgs );
}

/**
 * 组合刷新：OPcache + Memcached + 整页缓存（供一键优化 / 清除全部使用）。
 *
 * @return string[] 每条结果的人类可读消息（整页缓存不存在时返回空串，调用方过滤）
 */
function jinyu_perf_flush_caches(): array {
	$res = [
		jinyu_perf_reset_opcache(),
		jinyu_perf_flush_memcached(),
		jinyu_perf_flush_page_cache(),
	];
	// 清缓存后若开启预热，自动暖关键页（延后到 shutdown，不阻塞本次请求）
	if ( function_exists( 'jinyu_warmup_schedule_once' ) ) {
		jinyu_warmup_schedule_once();
	}
	return $res;
}

/**
 * 组合刷新（同 jinyu_perf_flush_caches）但**不触发自动重暖**。
 * 供「缓存预热」卡内「清除全部缓存」按钮使用：只清缓存、不重暖，
 * 重暖由用户显式点「立即预热」或下一周期自行发生。
 *
 * @return string[] 每条结果的人类可读消息
 */
/**
 * 清除「预热产生的缓存」：仅精确删除预热队列中各 URL 的整页缓存（及边缘缓存），
 * 不动 OPcache / Memcached / 全站其他页。并重置预热簿记，让卡片立即显示「未暖」。
 * 与 jinyu_perf_flush_caches()（全站三层全清）区别：范围仅限预热 URL，且不自动重暖。
 *
 * @return string[] 每条结果的人类可读消息
 */
function jinyu_perf_flush_warmed_cache(): array {
	$items = function_exists( 'jinyu_warmup_get_urls' ) ? jinyu_warmup_get_urls() : array();
	$count = 0;
	foreach ( $items as $item ) {
		$u = is_array( $item ) ? ( $item['url'] ?? '' ) : (string) $item;
		if ( ! $u ) {
			continue;
		}
		if ( function_exists( 'jinyu_page_cache_delete_uri' ) ) {
			jinyu_page_cache_delete_uri( $u );
		}
		if ( function_exists( 'jinyu_edge_purge_url' ) ) {
			jinyu_edge_purge_url( $u );
		}
		++$count;
	}
	// 同步重置预热簿记：让卡片立即回到「未暖」，下一次预热不再被 is_hot() 跳过、全量重暖。
	delete_option( 'jinyu_warmup_warmed_at' );
	if ( function_exists( 'jinyu_warmup_state_set' ) ) {
		jinyu_warmup_state_set(
			array(
				'position' => 0,
				'total'    => 0,
				'warmed'   => 0,
				'running'  => false,
				'last_run' => 0,
				'done_at'  => 0,
			)
		);
	}
	$res = array();
	if ( $count > 0 ) {
		/* translators: %d: 被清除整页缓存的预热 URL 数量 */
		$res[] = sprintf( __( '已清除 %d 个预热 URL 的整页缓存', 'jinyu-theme-companion' ), $count );
	} else {
		$res[] = __( '当前没有可清除的预热缓存', 'jinyu-theme-companion' );
	}
	$res[] = __( '预热簿记已重置（标记未暖）', 'jinyu-theme-companion' );
	return $res;
}

/* ───────────────────────── 工具 ───────────────────────── */

/** 字节数转人类可读（KB/MB/GB），全站看板统一入口。 */
function jinyu_perf_human( float $bytes ): string {
	if ( $bytes >= 1073741824 ) {
		return round( $bytes / 1073741824, 2 ) . ' GB';
	}
	if ( $bytes >= 1048576 ) {
		return round( $bytes / 1048576, 2 ) . ' MB';
	}
	if ( $bytes >= 1024 ) {
		return round( $bytes / 1024, 1 ) . ' KB';
	}
	return (int) $bytes . ' B';
}

/** 秒数转「x 天 x 小时」人类可读。 */
function jinyu_perf_human_uptime( int $s ): string {
	$d = intdiv( $s, 86400 );
	$h = intdiv( $s % 86400, 3600 );
	return $d > 0 ? $d . __( ' 天 ', 'jinyu-theme-companion' ) . $h . __( ' 小时', 'jinyu-theme-companion' ) : ( $h > 0 ? $h . __( ' 小时', 'jinyu-theme-companion' ) : $s . __( ' 秒', 'jinyu-theme-companion' ) );
}

/* ───────────────────────── 渲染：状态看板（指标条 + 双看板） ───────────────────────── */

/**
 * 渲染整个状态区（指标条 + OPcache 看板 + Memcached 看板）。
 * 页面初次加载与 AJAX 刷新共用这一个函数，保证两处 HTML 结构永远一致
 * （单一数据源，不会出现「初始渲染和刷新后长得不一样」的漂移）。
 *
 * @return string HTML 片段
 */
/** 缓存操作按钮（清 OPcache / Memcached / 整页 / 全部）：嵌进对应状态面板，统一 data-flush 由事件委托处理。 */
function jinyu_perf_flush_btn_html( string $target, string $label, string $cls = '' ): string {
	$icon = array(
		'opcache'  => '<path d="M12 2a10 10 0 109 6M12 6v6l4 2"/>',
		'memcached' => '<path d="M4 7h16M4 12h16M4 17h10"/>',
		'page'     => '<path d="M4 4h16v16H4zM4 9h16"/>',
		'all'      => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
	);
	$path = $icon[ $target ] ?? $icon['all'];
	// title/aria-label：窄屏只显示图标时（文字 span 被隐藏），仍能通过悬停/读屏得知按钮用途。
	return '<button type="button" class="jperf-btn ' . $cls . '" data-flush="' . esc_attr( $target ) . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">'
		. '<svg viewBox="0 0 24 24">' . $path . '</svg>'
		. '<span>' . esc_html( $label ) . '</span></button>';
}

function jinyu_perf_render_status_html(): string {
	$s = jinyu_perf_status_snapshot();
	$o = jinyu_perf_opcache_stats();
	$m = jinyu_perf_memcached_stats();

	// 指标条单元格；传入 $count 时数值包入 [data-count] 供前端 count-up 缓动。
	$stat = static function ( string $k, string $v, string $d, $count = null, string $suffix = '' ): string {
		$num = ( null !== $count )
			? '<span class="jperf-num" data-count="' . $count . '">0</span>' . $suffix
			: $v;
		return '<div class="jperf-stat"><div class="jperf-k">' . $k . '</div>'
			. '<div class="jperf-v">' . $num . '</div>'
			. '<div class="jperf-d">' . $d . '</div></div>';
	};

	// 细描边环形图：data-pct 交给前端 JS 做绘制动画；data-null 时显示「—」且不绘制。
	$ring = static function ( $pct, string $stroke, string $sub ): string {
		$is_null = ( null === $pct );
		$val     = $is_null ? 0 : (float) $pct;
		$ctr     = $is_null ? '—' : '0<u>%</u>';
		$attr    = $is_null ? ' data-null="1"' : '';
		return '<div class="jperf-ring"><svg width="108" height="108" viewBox="0 0 108 108">'
			. '<circle class="jperf-track" cx="54" cy="54" r="46"/>'
			. '<circle class="jperf-bar" cx="54" cy="54" r="46" data-pct="' . $val . '"' . $attr
			. ' style="stroke:' . $stroke . '"/></svg>'
			. '<div class="jperf-ctr"><div class="jperf-pct" data-pct="' . $val . '"' . $attr . '>' . $ctr . '</div>'
			. '<div class="jperf-rlbl">' . $sub . '</div></div></div>';
	};

	// 统计格：标签在上、数值在下（2×2 网格）；传入 $meter（百分比字符串）时格内附内存占用细进度条。
	$kv = static function ( string $name, string $val, string $meter = '' ): string {
		$bar = '' !== $meter ? '<div class="jperf-meter"><i style="width:' . $meter . '"></i></div>' : '';
		return '<div class="jperf-cell"><span class="jperf-clabel">' . $name . '</span>'
			. '<span class="jperf-cval">' . $val . '</span>' . $bar . '</div>';
	};

	// SAPI 未启用 opcache 时 jinyu_perf_opcache_stats() 只回 ['enabled'=>false]，键不存在。
	$op_hit = $o ? ( $o['hit_rate'] ?? null ) : null;
	$mc_ok  = ( $m && ! empty( $m['reachable'] ) );
	$mc_hit = $mc_ok ? ( $m['hit_rate'] ?? null ) : null;
	$mver   = $mc_ok ? (string) ( $m['version'] ?? '' ) : '';
	$mtag   = '' !== $mver ? 'v' . $mver : ( null === $m ? __( '未安装', 'jinyu-theme-companion' ) : __( '不可达', 'jinyu-theme-companion' ) );

	$html = '<div class="jperf-status">';

	// —— 指标条（分隔线，非卡片堆叠）——
	$html .= '<div class="jperf-stats">';
	$html .= $stat(
        __( 'OPcache 命中率', 'jinyu-theme-companion' ),
        '—',
        __( '字节码缓存有效', 'jinyu-theme-companion' ),
        null === $op_hit ? null : $op_hit,
        null === $op_hit ? '' : '<small>%</small>'
    );
	$html .= $stat(
        __( 'Memcached 命中率', 'jinyu-theme-companion' ),
        '—',
        __( '对象缓存有效', 'jinyu-theme-companion' ),
        null === $mc_hit ? null : $mc_hit,
        null === $mc_hit ? '' : '<small>%</small>'
    );
	$html .= $stat(
        __( '待清理 Transient', 'jinyu-theme-companion' ),
        (string) (int) $s['transient_expired'],
        __( '已过期待回收', 'jinyu-theme-companion' ),
        (int) $s['transient_expired'],
        ''
    );
	$html .= $stat(
        __( '活跃插件', 'jinyu-theme-companion' ),
        (string) (int) $s['active_plugins'],
        __( '含主题内置模块', 'jinyu-theme-companion' ),
        (int) $s['active_plugins'],
        ''
    );
	$html .= '</div>';

	// —— 监控双栏 ——
	$html .= '<p class="jperf-eyebrow jperf-eyebrow-mt">' . esc_html__( '运行时监控', 'jinyu-theme-companion' ) . '</p>';
	$html .= '<div class="jperf-panels">';

	// OPcache 面板：清除按钮进标题行，紧贴右侧「PHP x.y」版本标签的左边。
	$html .= '<div class="jperf-panel"><div class="jperf-panel-head">'
		. '<span class="jperf-panel-ico"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h7l-1 8 10-12h-7z"/></svg></span>'
		. '<h3>' . esc_html__( 'OPcache 字节码缓存', 'jinyu-theme-companion' ) . '</h3>'
		. '<span class="jperf-panel-act">' . jinyu_perf_flush_btn_html( 'opcache', __( '清除 OPcache', 'jinyu-theme-companion' ) ) . '</span>'
		. '<span class="jperf-tag">PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '</span></div>';
	$html .= '<div class="jperf-gauge">';
	$html .= $ring( $op_hit, 'var(--j-accent)', __( '命中率', 'jinyu-theme-companion' ) );
	$html .= '<div class="jperf-kv">';
	if ( null === $o ) {
		$html .= '<div class="jperf-cell jperf-cell-wide"><span class="jperf-clabel">' . esc_html__( '状态', 'jinyu-theme-companion' ) . '</span><span class="jperf-cval">' . esc_html__( '未安装扩展', 'jinyu-theme-companion' ) . '</span></div>';
	} elseif ( empty( $o['enabled'] ) ) {
		$html .= '<div class="jperf-cell jperf-cell-wide"><span class="jperf-clabel">' . esc_html__( '状态', 'jinyu-theme-companion' ) . '</span><span class="jperf-cval">' . esc_html__( '当前上下文未启用', 'jinyu-theme-companion' ) . '</span></div>';
	} else {
		$html .= $kv( __( '缓存脚本', 'jinyu-theme-companion' ), number_format_i18n( $o['cached_scripts'] ) );
		$html .= $kv( __( '内存占用', 'jinyu-theme-companion' ), jinyu_perf_human( $o['memory_used'] ) . ' / ' . jinyu_perf_human( $o['memory_total'] ) );
		$html .= $kv( __( '内存使用率', 'jinyu-theme-companion' ), $o['mem_pct'] . '%', $o['mem_pct'] . '%' );
		$html .= $kv( __( '浪费内存', 'jinyu-theme-companion' ), jinyu_perf_human( $o['wasted'] ) );
	}
	// 底部数据栏：横向补充运行细节，不改动上方仪表区布局。
	if ( $o && ! empty( $o['enabled'] ) ) {
		$extra  = '<div class="jperf-extra">';
		$extra .= '<span><i>' . esc_html__( '剩余内存', 'jinyu-theme-companion' ) . '</i><b>' . esc_html( jinyu_perf_human( max( 0, $o['memory_total'] - $o['memory_used'] ) ) ) . '</b></span>';
		$extra .= '<span><i>' . esc_html__( '累计命中', 'jinyu-theme-companion' ) . '</i><b>' . esc_html( number_format_i18n( $o['hits'] ) ) . '</b></span>';
		$extra .= '<span><i>' . esc_html__( 'OOM 重启', 'jinyu-theme-companion' ) . '</i><b>' . esc_html( number_format_i18n( $o['oom_restarts'] ) ) . '</b></span>';
		$extra .= '<span><i>' . esc_html__( '上次重置', 'jinyu-theme-companion' ) . '</i><b>' . esc_html( $o['last_restart'] > 0 ? sprintf( /* translators: %s: 相对时间 */ __( '%s前', 'jinyu-theme-companion' ), human_time_diff( $o['last_restart'], time() ) ) : __( '从未', 'jinyu-theme-companion' ) ) . '</b></span>';
		$extra .= '</div>';
		$html .= '</div></div>' . $extra;
		$html .= '</div>'; // 闭合 .jperf-panel（清除按钮在标题行，不再占卡片底部）
	} else {
		$html .= '</div></div>';
		$html .= '</div>'; // 依次闭合 .jperf-gauge、.jperf-panel
	}

	// Memcached 面板
	$html .= '<div class="jperf-panel"><div class="jperf-panel-head">'
		. '<span class="jperf-panel-ico"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg></span>'
		. '<h3>' . esc_html__( 'Memcached 对象缓存', 'jinyu-theme-companion' ) . '</h3>'
		. '<span class="jperf-panel-act">' . jinyu_perf_flush_btn_html( 'memcached', __( '清除 Memcached', 'jinyu-theme-companion' ) ) . '</span>'
		. '<span class="jperf-tag">' . esc_html( $mtag ) . '</span></div>';
	$html .= '<div class="jperf-gauge">';
	$html .= $ring( $mc_hit, 'var(--j-accent)', __( '命中率', 'jinyu-theme-companion' ) );
	$html .= '<div class="jperf-kv">';
	if ( null === $m ) {
		$html .= '<div class="jperf-cell jperf-cell-wide"><span class="jperf-clabel">' . esc_html__( '状态', 'jinyu-theme-companion' ) . '</span><span class="jperf-cval">' . esc_html__( '未安装扩展', 'jinyu-theme-companion' ) . '</span></div>';
	} elseif ( empty( $m['reachable'] ) ) {
		$ext = (bool) wp_using_ext_object_cache();
		$msg = $ext ? __( '未连接 Memcached（当前使用其他对象缓存后端）', 'jinyu-theme-companion' ) : __( '无法连接 127.0.0.1:11211', 'jinyu-theme-companion' );
		$html .= '<div class="jperf-cell jperf-cell-wide"><span class="jperf-clabel">' . esc_html__( '状态', 'jinyu-theme-companion' ) . '</span><span class="jperf-cval">' . esc_html( $msg ) . '</span></div>';
	} else {
		$html .= $kv( __( '缓存条目', 'jinyu-theme-companion' ), number_format_i18n( $m['curr_items'] ) );
		$html .= $kv( __( '内存占用', 'jinyu-theme-companion' ), jinyu_perf_human( (float) $m['bytes'] ) . ' / ' . jinyu_perf_human( (float) $m['limit'] ) );
		$html .= $kv( __( '内存使用率', 'jinyu-theme-companion' ), $m['mem_pct'] . '%', $m['mem_pct'] . '%' );
		$html .= $kv( __( '已运行', 'jinyu-theme-companion' ), jinyu_perf_human_uptime( $m['uptime'] ) );
	}
	$html .= '</div></div>'; // 闭合 .jperf-kv 与 .jperf-gauge：部署控件横贯整卡宽度，不进统计格
	$html .= jinyu_perf_object_cache_control_html();
	$html .= '</div>';
	$html .= '</div>'; // 闭合 .jperf-panels：Web Vitals 区是全宽板块，不进监控双栏

	// —— 真实用户 Web Vitals（近 7 天聚合）——
	$wv  = jinyu_perf_web_vitals_stats();
	$wvm = jinyu_perf_wv_meta();
	$wv_score = ( null !== $wv ) ? jinyu_perf_wv_score( $wv, $wvm ) : null;
	// 体验数据操作按钮：默认挂在「最慢路径」标题行最右端（与数据同卡同视区）；
	// 若该卡不存在（无样本 / 无路径聚合），退回板块底部，保证入口始终可用。
	$wv_reset_btn    = '<button type="button" class="jperf-btn jperf-btn-danger" id="jperf-reset-wv">'
		. '<svg viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>'
		. '<span>' . esc_html__( '清空体验数据', 'jinyu-theme-companion' ) . '</span></button>';
	$wv_reset_placed = false;
	$html .= '<p class="jperf-eyebrow jperf-eyebrow-mt">' . esc_html__( '真实用户体验（近 7 天）', 'jinyu-theme-companion' ) . '</p>';
	$html .= '<div class="jperf-wv">';
	if ( null === $wv ) {
		$html .= '<div class="jperf-wv-empty">' . esc_html__( '暂无样本。前端已采集 LCP / INP / CLS / FCP / TTFB，访客浏览后这里会出现真实均值。', 'jinyu-theme-companion' ) . '</div>';
	} else {
		// translators: Placeholder values are substituted at runtime.
		$foot = sprintf( __( '基于 %s 次真实访问', 'jinyu-theme-companion' ), number_format_i18n( $wv['n'] ) );
		if ( empty( $wv['fresh'] ) ) {
			$foot .= __( '（窗口已过期，等待新样本）', 'jinyu-theme-companion' );
		}
		// 报告总评 hero 卡：置于 5 项指标之前，圆环分数 + 评级 + 指标分布，一眼给结论。
		if ( null !== $wv_score ) {
			$sr     = $wv_score['rate'];
			$sbadge = 'good' === $sr ? __( '优秀', 'jinyu-theme-companion' ) : ( 'poor' === $sr ? __( '待提升', 'jinyu-theme-companion' ) : __( '一般', 'jinyu-theme-companion' ) );
			$dist   = array(
				'good' => 0,
				'mid' => 0,
				'poor' => 0,
			);
			foreach ( $wvm as $k => $meta ) {
				++$dist[ jinyu_perf_wv_rate( (float) $wv['avg'][ $k ], $meta ) ];
			}
			$n_all = count( $wvm );
			$seg   = '';
			foreach ( $dist as $r => $n ) {
				if ( $n > 0 ) {
					$seg .= '<i class="seg ' . $r . '" style="width:' . round( $n / $n_all * 100, 1 ) . '%"></i>';
				}
			}
			// 最短板：与「良好阈值」偏离最大的一项（全项达标时取相对最接近阈值的那项）。
			$weak_k = '';
			$weak_r = -INF;
			foreach ( $wvm as $k => $meta ) {
				$r = ( (float) $wv['avg'][ $k ] ) / (float) $meta['good'];
				if ( $r > $weak_r ) {
					$weak_r = $r;
					$weak_k = $k;
				}
			}
			$wv_val = $wvm[ $weak_k ]['ms']
				? number_format_i18n( (int) $wv['avg'][ $weak_k ] ) . ' ms'
				: (string) $wv['avg'][ $weak_k ];
			$concl = ( $dist['mid'] + $dist['poor'] ) > 0
				/* translators: 1: 达标项数, 2: 总项数, 3: 指标标签, 4: 当前均值, 5: 良好阈值 */
				? sprintf( __( '%1$d / %2$d 项达标 · 最短板 %3$s（%4$s，良好线 %5$s）', 'jinyu-theme-companion' ), $dist['good'], $n_all, $wvm[ $weak_k ]['label'], $wv_val, $wvm[ $weak_k ]['good'] . ( $wvm[ $weak_k ]['ms'] ? ' ms' : '' ) )
			// translators: Placeholder values are substituted at runtime.
				: sprintf( __( '%d 项全部达标 · 真实用户访问体验处于良好区间', 'jinyu-theme-companion' ), $n_all );
			$html .= '<div class="jperf-wv-score rate-' . $sr . '">'
				. '<div class="jperf-score-ring"><div class="jperf-ring"><svg viewBox="0 0 108 108">'
				. '<circle class="jperf-track" cx="54" cy="54" r="46"/>'
				. '<circle class="jperf-bar" cx="54" cy="54" r="46" data-pct="' . (float) $wv_score['score'] . '"/></svg>'
				. '<div class="jperf-ctr"><div class="jperf-score-num" data-count="' . (int) $wv_score['score'] . '">0</div>'
				. '<div class="jperf-rlbl">' . esc_html__( '满分 100', 'jinyu-theme-companion' ) . '</div></div></div></div>'
				. '<div class="jperf-score-body">'
				. '<div class="jperf-score-head"><span class="jperf-score-tt">' . esc_html__( '综合体验评分', 'jinyu-theme-companion' ) . '</span>'
				. '<span class="jperf-wv-badge">' . esc_html( $sbadge ) . '</span></div>'
				. '<div class="jperf-score-concl">' . esc_html( $concl ) . '</div>'
				. '<div class="jperf-score-distrow"><div class="jperf-score-dist">' . $seg . '</div>'
				. '<span class="jperf-score-dl">' . sprintf(
					/* translators: 1: 良好项数, 2: 需优化项数, 3: 较差项数 */
					__( '良好 %1$d / 需优化 %2$d / 较差 %3$d', 'jinyu-theme-companion' ),
					$dist['good'],
					$dist['mid'],
					$dist['poor']
				) . '</span></div>'
				. '<div class="jperf-score-sub">Field Performance Score · ' . esc_html( $foot ) . '</div>'
				. '<div class="jperf-score-meta">' . sprintf(
					/* translators: %d: 指标总数 */
					__( '%d 项加权（LCP / INP 各 25%%、TTFB 20%%、CLS / FCP 各 15%%）· 近 7 天滚动均值', 'jinyu-theme-companion' ),
					$n_all
				) . '</div>'
				. '</div></div>';
		}
		foreach ( $wvm as $k => $meta ) {
			$avg   = (float) $wv['avg'][ $k ];
			$max   = (float) $wv['max'][ $k ];
			$rate  = jinyu_perf_wv_rate( $avg, $meta );
			$val   = $meta['ms'] ? number_format_i18n( (int) $avg ) . ' ms' : $avg;
			$worst = $meta['ms'] ? number_format_i18n( (int) $max ) . ' ms' : $max;
			$badge = 'good' === $rate ? __( '良好', 'jinyu-theme-companion' ) : ( 'poor' === $rate ? __( '较差', 'jinyu-theme-companion' ) : __( '需优化', 'jinyu-theme-companion' ) );
			$html .= '<div class="jperf-wv-chip rate-' . $rate . '">'
				. '<div class="jperf-wv-top"><span class="jperf-wv-lab">' . $meta['label'] . '</span>'
				. '<span class="jperf-wv-right"><span class="jperf-wv-badge">' . $badge . '</span>'
				. '<button type="button" class="jperf-help" aria-expanded="false" aria-label="' . esc_attr( $meta['name'] . __( ' 的说明', 'jinyu-theme-companion' ) ) . '"><svg viewBox="0 0 24 24"><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2.4-3 4"/><circle cx="12" cy="17.3" r=".6"/></svg></button></span></div>'
				. '<div class="jperf-wv-val">' . $val . '</div>'
				. '<div class="jperf-wv-name">' . $meta['name'] . '</div>'
				. '<div class="jperf-wv-worst">' . esc_html__( '最差 ', 'jinyu-theme-companion' ) . $worst . '</div>'
				. '<div class="jperf-tip" role="tooltip" hidden><div>' . esc_html( $meta['tip'] ) . '</div><div><b>' . esc_html__( '优化', 'jinyu-theme-companion' ) . '</b> · ' . esc_html( $meta['optimize'] ) . '</div></div></div>';
		}
		// 热门页面 TOP5：占据指标 grid 末格，补上「内容维度」的数据视角。
		if ( ! empty( $wv['paths'] ) ) {
			$max_n = 0;
			foreach ( $wv['paths'] as $prow ) {
				$max_n = max( $max_n, (int) $prow['n'] );
			}
			$html .= '<div class="jperf-wv-chip jperf-wv-hot">'
				. '<div class="jperf-wv-top"><span class="jperf-wv-lab">' . esc_html__( '热门页面', 'jinyu-theme-companion' ) . '</span>'
				. '<span class="jperf-wv-badge jperf-hot-badge">' . esc_html__( '样本 TOP 5', 'jinyu-theme-companion' ) . '</span></div>'
				. '<div class="jperf-hot-list">';
			foreach ( $wv['paths'] as $prow ) {
				$pdisp = urldecode( (string) $prow['path'] );
				if ( mb_strlen( $pdisp ) > 26 ) {
					$pdisp = mb_substr( $pdisp, 0, 26 ) . '…';
				}
				$ppct = $max_n > 0 ? round( (int) $prow['n'] / $max_n * 100, 1 ) : 0;
				$html .= '<div class="jperf-hot-row">'
					. '<span class="jperf-hot-path" title="' . esc_attr( $prow['path'] ) . '">' . esc_html( $pdisp ) . '</span>'
					. '<span class="jperf-hot-bar"><i style="width:' . $ppct . '%"></i></span>'
					. '<span class="jperf-hot-n">' . number_format_i18n( (int) $prow['n'] ) . '</span></div>';
			}
			$html .= '</div>'
				. '<div class="jperf-wv-worst">' . esc_html__( '按上报样本数排序 · 点击量最高的入口页', 'jinyu-theme-companion' ) . '</div></div>';
		}
		$html .= '<div class="jperf-wv-legend"><span class="lg good">' . esc_html__( '良好', 'jinyu-theme-companion' ) . '</span><span class="lg mid">' . esc_html__( '需优化', 'jinyu-theme-companion' ) . '</span>'
			. '<span class="lg poor">' . esc_html__( '较差', 'jinyu-theme-companion' ) . '</span><span class="lg-note">' . esc_html__( '除综合评分外，数值越低越好', 'jinyu-theme-companion' ) . '</span></div>';
		if ( ! empty( $wv['slowest'] ) && is_array( $wv['slowest'] ) ) {
			$wv_reset_placed = true;
			$html .= '<div class="jperf-slow">'
				. '<div class="jperf-slow-head"><span class="jperf-slow-t">' . esc_html__( '最慢路径（按 LCP 最差）', 'jinyu-theme-companion' ) . '</span>'
				. '<span class="jperf-slow-hint">' . esc_html__( '各路径真实访客的首屏最慢渲染时长', 'jinyu-theme-companion' ) . '</span>'
				. '<span class="jperf-slow-act">' . $wv_reset_btn . '</span></div>'
				. '<div id="jperf-wv-result" class="jperf-result" aria-live="polite"></div>'
				. '<div class="jperf-slow-list">';
			$rank = 0;
			foreach ( $wv['slowest'] as $srow ) {
				++$rank;
				$srate  = jinyu_perf_wv_rate( (float) $srow['lcp_max'], $wvm['lcp'] );
				$sbadge = 'good' === $srate ? __( '良好', 'jinyu-theme-companion' ) : ( 'poor' === $srate ? __( '较差', 'jinyu-theme-companion' ) : __( '需优化', 'jinyu-theme-companion' ) );
				$spct   = min( 100, (int) ( (float) $srow['lcp_max'] / (float) $wvm['lcp']['poor'] * 100 ) );
				$slcp   = number_format_i18n( (int) $srow['lcp_max'] ) . ' ms';
				// 显示用美化：URL 解码 + 超长截断（title 悬浮保留完整原始路径）
				$sdisp = urldecode( (string) $srow['path'] );
				if ( mb_strlen( $sdisp ) > 52 ) {
					$sdisp = mb_substr( $sdisp, 0, 52 ) . '…';
				}
				$html .= '<div class="jperf-slow-row rate-' . $srate . '">'
					. '<span class="jperf-slow-rank">' . $rank . '</span>'
					. '<span class="jperf-slow-path" title="' . esc_attr( $srow['path'] ) . '">' . esc_html( $sdisp ) . '</span>'
				// translators: Placeholder values are substituted at runtime.
					. '<span class="jperf-slow-meta">' . sprintf( __( '最差 %1$s · %2$s 次', 'jinyu-theme-companion' ), $slcp, number_format_i18n( (int) $srow['n'] ) ) . '</span>'
					. '<span class="jperf-slow-bar"><i style="width:' . $spct . '%"></i></span>'
					. '<span class="jperf-slow-badge">' . $sbadge . '</span></div>';
			}
			$html .= '</div></div>';
		}
	}
	// 体验数据操作：有「最慢路径」卡时按钮已挂在该卡标题行右上角，此处不再重复；
	// 无该卡（无样本 / 无路径聚合）时退回板块底部的操作条。
	if ( ! $wv_reset_placed ) {
		$html .= '<div class="jperf-wv-foot"><span class="jperf-foot-label">' . esc_html__( '数据管理', 'jinyu-theme-companion' ) . '</span>';
		$html .= $wv_reset_btn;
		$html .= '<div id="jperf-wv-result" class="jperf-result" aria-live="polite"></div>';
		$html .= '</div>';
	}
	$html .= '</div>';

	$html .= '</div>';
	return $html;
}

/* ───────────────────────── AJAX ───────────────────────── */

/**
 * 性能中心 AJAX 门卫：复用 primitives 的统一实现。
 *
 * 本模块的 nonce action 是 'jinyu_perf_center'、字段名是 'nonce'（内联 JS 单独签发，
 * 不与设置页主表单的 jinyu_companion_nonce 混用——两处语义相反，混用极易误改）。
 */
function jinyu_perf_guard(): void {
	jinyu_companion_guard( 'jinyu_perf_center', 'nonce' );
}

add_action( 'wp_ajax_jinyu_perf_optimize', 'jinyu_perf_ajax_optimize' );

/** 一键优化：保存开关 → 清理 → 优化表 → 刷缓存 → 返回前后对比。 */
function jinyu_perf_ajax_optimize(): void {
	jinyu_perf_guard();

	$oc_b = jinyu_perf_opcache_stats();
	$mc_b = jinyu_perf_memcached_stats();
	$before = jinyu_perf_status();

	// 前端以 JSON 字符串提交开关集合，逐 key 白名单式写回（不在清单里的键直接丢弃）
	// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 jinyu_perf_guard()（内部走 companion_guard）校验
	if ( isset( $_POST['options'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 jinyu_perf_guard()（内部走 companion_guard）校验
		$posted = json_decode( sanitize_text_field( wp_unslash( $_POST['options'] ) ), true );
		if ( is_array( $posted ) ) {
			$opts    = jinyu_perf_get_options();
			$allowed = array_keys( jinyu_perf_toggle_meta() );
			foreach ( $allowed as $k ) {
				$opts[ $k ] = ! empty( $posted[ $k ] ) ? 1 : 0;
			}
			update_option( 'jinyu_perf_options_v2', $opts, false );
			jinyu_perf_get_options( true );
		}
	}

	$cleaned = jinyu_perf_clean_transients();
	$opt     = jinyu_perf_optimize_tables();
	$flush   = array_filter( jinyu_perf_flush_caches(), 'strlen' ); // 过滤空串（无整页缓存）
	$oc_a = jinyu_perf_opcache_stats();
	$mc_a = jinyu_perf_memcached_stats();
	$after   = jinyu_perf_status();

	// 前端展示用的人类可读对比（保留原始数值，展示层单独给格式化结果）
	$disp = static function ( array $s ): array {
		return [
			'autoload_bytes'    => jinyu_perf_human( (float) $s['autoload_bytes'] ),
			'transient_expired' => (int) $s['transient_expired'],
			'table_overhead'    => jinyu_perf_human( (float) $s['table_overhead'] ),
		];
	};

	// 命中率：清缓存后会明显回落，单独给前后对比
	$hit = static function ( $oc, $mc ): array {
		return [
			'opcache'   => $oc ? (float) ( $oc['hit_rate'] ?? 0 ) : null,
			'memcached' => ( $mc && ! empty( $mc['reachable'] ) ) ? (float) ( $mc['hit_rate'] ?? 0 ) : null,
		];
	};

	wp_send_json_success(
        [
			'before_d'           => $disp( $before ),
			'after_d'            => $disp( $after ),
			'before_hit'         => $hit( $oc_b, $mc_b ),
			'after_hit'          => $hit( $oc_a, $mc_a ),
			'cleaned_transients' => $cleaned,
			'optimized_tables'   => $opt['optimized'],
			'table_list'         => $opt['list'],
			'cache'              => $flush,
		]
    );
}

add_action( 'wp_ajax_jinyu_perf_flush', 'jinyu_perf_ajax_flush' );

/** 按需单独清缓存：target = opcache | memcached | page | all。 */
function jinyu_perf_ajax_flush(): void {
	jinyu_perf_guard();

	// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 jinyu_perf_guard()（内部走 companion_guard）校验
	$target = isset( $_POST['target'] ) ? sanitize_key( (string) $_POST['target'] ) : 'all';
	switch ( $target ) {
		case 'opcache':
			$msg = jinyu_perf_reset_opcache();
			break;
		case 'memcached':
			$msg = jinyu_perf_flush_memcached();
			break;
		case 'page':
			$msg = jinyu_perf_flush_page_cache() ?: __( '本站未启用整页缓存插件，无需清理', 'jinyu-theme-companion' );
			break;
		case 'warmed':
			$msg = implode( __( '；', 'jinyu-theme-companion' ), array_filter( jinyu_perf_flush_warmed_cache(), 'strlen' ) );
			break;
		default:
			$msg = implode( __( '；', 'jinyu-theme-companion' ), array_filter( jinyu_perf_flush_caches(), 'strlen' ) );
	}

	wp_send_json_success( [ 'msg' => $msg ] );
}

/** 仅保存开关与数值配置（不跑清理/优化）。与「一键应用推荐优化」解耦，避免改动被吞。 */
function jinyu_perf_ajax_save(): void {
	jinyu_perf_guard();

	// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 jinyu_perf_guard()（内部走 companion_guard）校验
	if ( isset( $_POST['options'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 jinyu_perf_guard()（内部走 companion_guard）校验
		$posted = json_decode( sanitize_text_field( wp_unslash( $_POST['options'] ) ), true );
		if ( is_array( $posted ) ) {
			$opts    = jinyu_perf_get_options();
			$allowed = array_keys( jinyu_perf_toggle_meta() );
			foreach ( $allowed as $k ) {
				$opts[ $k ] = ! empty( $posted[ $k ] ) ? 1 : 0;
			}
			update_option( 'jinyu_perf_options_v2', $opts, false );
			jinyu_perf_get_options( true );

			// 必须清页面缓存：html_minify / disable_emoji / clean_wp_head / iframe_lazy /
			// dns_preconnect 这几个开关直接影响最终 HTML 产物，而整页缓存会把旧 HTML
			// 直接吐给访客，绕开所有开关。不同步清理的话，用户看到「已保存」但访客
			// 最长一个 TTL（默认 1 小时）仍拿到旧页面——提示语就成了错误承诺。
			$flushed = array_filter( (array) jinyu_perf_flush_caches(), 'strlen' );
			wp_send_json_success(
				[
					'msg'    => $flushed
						? __( '设置已保存，页面缓存已同步清理', 'jinyu-theme-companion' )
						: __( '设置已保存，下一次请求起生效', 'jinyu-theme-companion' ),
					'flush'  => array_values( $flushed ),
				]
			);
		}
	}
	wp_send_json_success( [ 'msg' => __( '没有需要保存的改动', 'jinyu-theme-companion' ) ] );
}

/** 清空真实用户体验聚合（调试/重测用）。 */
function jinyu_perf_ajax_reset_wv(): void {
	jinyu_perf_guard();
	delete_option( 'jinyu_web_vitals_stats' );
	wp_send_json_success( [ 'msg' => __( '体验数据已清空', 'jinyu-theme-companion' ) ] );
}

add_action( 'wp_ajax_jinyu_perf_save', 'jinyu_perf_ajax_save' );
add_action( 'wp_ajax_jinyu_perf_reset_wv', 'jinyu_perf_ajax_reset_wv' );
add_action( 'wp_ajax_jinyu_perf_status', 'jinyu_perf_ajax_status' );
add_action( 'wp_ajax_jinyu_perf_deploy_cache', 'jinyu_perf_ajax_deploy_cache' );
add_action( 'wp_ajax_jinyu_perf_upgrade_cache', 'jinyu_perf_ajax_upgrade_cache' );

/** 部署 / 回滚对象缓存 drop-in：deploy=1 部署，deploy=0 移除。 */
function jinyu_perf_ajax_deploy_cache(): void {
	jinyu_perf_guard();
	// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 jinyu_perf_guard()（内部走 companion_guard）校验
	$deploy = ! empty( $_POST['deploy'] ) ? (int) $_POST['deploy'] : 0;
	$res    = 1 === $deploy ? jinyu_perf_deploy_object_cache() : jinyu_perf_remove_object_cache();
	if ( ! empty( $res['ok'] ) ) {
		wp_send_json_success( [ 'msg' => $res['msg'] ] );
	}
	wp_send_json_error( [ 'msg' => $res['msg'] ?? __( '操作失败', 'jinyu-theme-companion' ) ] );
}

/** 升级替换：把外部 object-cache.php 换成金玉版（可选先备份原文件）。 */
function jinyu_perf_ajax_upgrade_cache(): void {
	jinyu_perf_guard();
	$target = WP_CONTENT_DIR . '/object-cache.php';
	if ( ! file_exists( $target ) || jinyu_perf_object_cache_is_jinyu( $target ) ) {
		wp_send_json_error( [ 'msg' => __( '没有可替换的外部 object-cache.php', 'jinyu-theme-companion' ) ] );
	}
	// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 jinyu_perf_guard()（内部走 companion_guard）校验
	$backup = ! empty( $_POST['backup'] );
	$res    = jinyu_perf_deploy_object_cache( true, $backup );
	if ( ! empty( $res['ok'] ) ) {
		wp_send_json_success( [ 'msg' => $res['msg'] ] );
	}
	wp_send_json_error( [ 'msg' => $res['msg'] ?? __( '升级失败', 'jinyu-theme-companion' ) ] );
}

/** 刷新状态看板：返回整块状态区 HTML（与页面初始渲染同一函数，杜绝两处漂移）。 */
function jinyu_perf_ajax_status(): void {
	jinyu_perf_guard();
	wp_send_json_success( [ 'html' => jinyu_perf_render_status_html() ] );
}

/* ───────────────────────── 评论懒加载：增量拉取下一页 ───────────────────────── */

/**
 * 「加载更多评论」增量接口（公开，游客可用）。
 * 复用 WP_Comment_Query 的分页语义（number/offset 作用于顶层评论，回复随父评论
 * 一并返回），再经 Walker_Comment 渲染，与主题原生评论回调/嵌套结构完全一致。
 * 仅校验来源文章与开关，不做权限限制（公开评论本就可读）。
 */
function jinyu_perf_ajax_load_comments() {
	// phpcs:ignore WordPress.Security.NonceVerification -- 公开评论增量端点（游客可用），仅按文章状态过滤，无 nonce 属设计
	$post_id = absint( wp_unslash( $_GET['post_id'] ?? 0 ) );
	// phpcs:ignore WordPress.Security.NonceVerification -- 公开评论增量端点（游客可用），仅按文章状态过滤，无 nonce 属设计
	$page    = max( 1, absint( wp_unslash( $_GET['page'] ?? 1 ) ) );
	$post    = get_post( $post_id );
	// 仅公开文章的评论可被匿名增量拉取：私有/草稿/待审文章即使评论开放也不经此端点外泄。
	if ( ! $post || 'publish' !== get_post_status( $post ) || ! comments_open( $post ) ) {
		wp_send_json_error( 'invalid' );
	}
	// 限流：匿名端点防刷（每 IP 每小时 120 次翻页已远超正常浏览节奏）。
	if ( ! jinyu_companion_rate_limit( 'comments_load', 120, HOUR_IN_SECONDS ) ) {
		wp_send_json_error( 'rate_limited' );
	}
	$opt = jinyu_perf_get_options();
	if ( empty( $opt['comment_lazyload'] ) ) {
		wp_send_json_error( 'disabled' );
	}

	$per_page = (int) get_option( 'comments_per_page' );
	if ( $per_page < 1 ) {
		$per_page = 20;
	}
	$max_depth = get_option( 'thread_comments' ) ? (int) get_option( 'thread_comments_depth' ) : 0;

	// 页码上限：offset 直接进 WP_Comment_Query，深翻页（page=100000 → offset 200 万）
	// 会触发大 offset 全表扫描 + threaded 模式下的复杂子查询。限流只约束「次数」，
	// 约束不了「单次成本」，两者必须都设。20 页足够读完任何正常长度的评论串。
	$max_page = 20;
	if ( $page > $max_page ) {
		wp_send_json_success(
            [
				'html' => '',
				'done' => true,
			]
        );
	}

	$comments = get_comments(
        [
			'post_id'      => $post_id,
			'status'       => 'approve',
			'type'         => 'all',
			'hierarchical' => get_option( 'thread_comments' ) ? 'threaded' : false,
			'number'       => $per_page,
			'offset'       => ( $page - 1 ) * $per_page,
			'order'        => get_option( 'comment_order' ) === 'desc' ? 'DESC' : 'ASC',
		]
    );

	if ( ! class_exists( 'Walker_Comment' ) ) {
		wp_send_json_error( 'walker' );
	}

	$walker = new Walker_Comment();
	$html   = $walker->paged_walk(
		$comments,
		$max_depth,
		1, // 传入的 $comments 已是该页切片，按顶层计数从第 1 页开始渲染即整页
		$per_page,
		[
			'style'       => 'ol',
			// 评论渲染回调属呈现层职责：主题在场时通过过滤器报名自己的回调，插件不探测主题函数名
			// （探测即「插件认识主题」，是耦合而非解耦）。主题缺席 / 未报名时传空串，
			// Walker_Comment 回退 WP 原生渲染，结构依然完整。
			'callback'    => (string) apply_filters( 'jinyu_perf_comment_walker_callback', '' ),
			'avatar_size' => 48,
			'max_depth'   => $max_depth,
		]
	);

	wp_send_json_success(
        [
			'html' => $html,
			'page' => $page,
		]
    );
}
add_action( 'wp_ajax_nopriv_jinyu_load_comments', 'jinyu_perf_ajax_load_comments' );
add_action( 'wp_ajax_jinyu_load_comments', 'jinyu_perf_ajax_load_comments' );

/* ───────────────────────── 渲染：设置面板「性能中心」分区 ───────────────────────── */

/**
 * 标题行右侧组件：对象缓存状态胶囊 + 紧凑刷新按钮。
 * 由 settings.php 在 pane 标题行调用（与 h1 同行）。
 */
function jinyu_perf_render_headside(): void {
	// 轻量判断：仅为一个小状态胶囊，不跑整套看板查询（status() 含 autoload/transient/information_schema 共 4 条 SQL）
	$mc    = jinyu_perf_memcached_stats();
	$oc_on = wp_using_ext_object_cache() || ( $mc && ! empty( $mc['reachable'] ) );
	?>
	<div class="jperf-headside">
		<span class="jperf-pill"><span class="jperf-dot <?php echo $oc_on ? 'ok' : 'off'; ?>"></span><span class="jperf-pill-txt"><?php echo $oc_on ? esc_html__( '对象缓存运行中', 'jinyu-theme-companion' ) : esc_html__( '对象缓存未启用', 'jinyu-theme-companion' ); ?></span></span>
		<?php
		// 清除整页 / 全部缓存：全局操作，挂在页面标题行右上角（与状态胶囊同行）。
		echo jinyu_perf_flush_btn_html( 'page', __( '清除整页缓存', 'jinyu-theme-companion' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 函数内已 esc_html 文本、esc_attr 属性
		echo jinyu_perf_flush_btn_html( 'all', __( '清除全部缓存', 'jinyu-theme-companion' ), 'jperf-btn-hero' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 同上
		?>
		<div id="jperf-cache-result" class="jperf-result" aria-live="polite"></div>
	</div>
	<?php
}

/**
 * 渲染性能优化中心（嵌入插件设置面板 pane-perfcenter 分区）。
 * 原为主题独立子菜单页，迁入插件后由 settings.php 在对应分区内调用，
 * 不再注册 admin_menu 子页。
 */
function jinyu_perf_render_pane(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opts    = jinyu_perf_get_options();
	$rec     = jinyu_perf_recommended_options();
	$nonce   = wp_create_nonce( 'jinyu_perf_center' );
	$toggles = jinyu_perf_toggle_meta();
	$st      = jinyu_perf_status_snapshot(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控/对外原始输出（JSON-LD/SVG/缓存页/内部构造 HTML），无需转义
	$oc_on   = ! empty( $st['object_cache'] );
	?>
	<div class="jperf-wrap">
		<div id="jperf-status-zone"><?php echo jinyu_perf_render_status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部均为服务端构造的受控 HTML ?></div>

		<section class="jperf-block">
			<div class="jperf-block-head">
				<p class="jperf-eyebrow"><?php esc_html_e( '优化开关', 'jinyu-theme-companion' ); ?></p>
				<span class="jperf-hint"><?php esc_html_e( '每项均可单独开启 / 回退，不影响有效数据', 'jinyu-theme-companion' ); ?></span>
			</div>
			<div id="jperf-toggles">
				<?php
				$jperf_groups = [
					'front'    => __( '前端优化', 'jinyu-theme-companion' ),

					'admin'    => __( '后台减负', 'jinyu-theme-companion' ),

					'security' => __( '安全加固', 'jinyu-theme-companion' ),

					'cache'    => __( '缓存与评论', 'jinyu-theme-companion' ),

				];
				foreach ( $jperf_groups as $g_id => $g_name ) :
					$g_items = [];
					foreach ( $toggles as $key => $meta ) {
						if ( ( $meta['group'] ?? '' ) === $g_id ) {
							$g_items[ $key ] = $meta;
						}
					}
					if ( empty( $g_items ) ) {
						continue;
					}
					?>
				<div class="jgroup">
					<div class="jgroup-head">
						<span class="jgroup-tt"><?php echo esc_html( $g_name ); ?></span>
						<span class="jgroup-n"><?php echo (int) count( $g_items ); ?> <?php esc_html_e( '项', 'jinyu-theme-companion' ); ?></span>
					</div>
					<div class="jgrid">
						<?php foreach ( $g_items as $key => $meta ) : ?>
						<div class="jcard">
							<div class="jcard-body">
								<div class="jcard-t"><?php echo esc_html( $meta['label'] ); ?>
                                <?php
                                if ( ! empty( $opts[ $key ] ) ) :
									?>
                                    <span class="jbadge"><?php esc_html_e( '默认开', 'jinyu-theme-companion' ); ?></span><?php endif; ?><button type="button" class="jperf-help" aria-expanded="false" aria-label="<?php echo esc_attr( sprintf( __( '%s 的说明', 'jinyu-theme-companion' ), $meta['label'] ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment ?>"><svg viewBox="0 0 24 24"><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2.4-3 4"/><circle cx="12" cy="17.3" r=".6"/></svg></button></div>
								<div class="jperf-tip" role="tooltip" hidden><?php echo esc_html( $meta['desc'] ); ?></div>
							</div>
						<div class="jperf-sw <?php echo ! empty( $opts[ $key ] ) ? 'on' : ''; ?>"
							data-key="<?php echo esc_attr( $key ); ?>"
							data-rec="<?php echo empty( $rec[ $key ] ) ? '0' : '1'; ?>"
							role="switch" aria-checked="<?php echo ! empty( $opts[ $key ] ) ? 'true' : 'false'; ?>"
							tabindex="0"></div>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="jperf-btnrow">
				<button type="button" id="jperf-rec" class="jperf-btn">
					<svg viewBox="0 0 24 24"><path d="M4 21v-6M4 11V3M12 21v-9M12 8V3M20 21v-4M20 13V3M1 15h6M9 8h6M17 13h6"/></svg><span><?php esc_html_e( '一键打开推荐开关', 'jinyu-theme-companion' ); ?></span>
				</button>
				<button type="button" id="jperf-run" class="jperf-btn">
					<svg viewBox="0 0 24 24"><path d="M13 2L3 14h7l-1 8 10-12h-7z"/></svg><span><?php esc_html_e( '一键应用推荐优化', 'jinyu-theme-companion' ); ?></span>
				</button>
			</div>
			<p class="jperf-hint" style="margin-top:10px"><?php esc_html_e( '「保存设置」保存以上优化开关；改动任意一项后，底部会出现悬浮「保存设置」按钮。「一键打开推荐开关」仅切换界面状态不直接保存；「推荐优化」= 保存 + 清理过期 transient + 优化碎片表 + 重置 OPcache 与 Memcached。缓存预热已移至「前台加速」分区，配置与触发在那里独立进行。', 'jinyu-theme-companion' ); ?></p>
			<div id="jperf-result" class="jperf-result" aria-live="polite"></div>
			<div id="jperf-floatsave" class="jperf-floatsave" role="status" aria-hidden="true">
				<span class="jperf-floatsave-dot" aria-hidden="true"></span>
				<span class="jperf-floatsave-txt"><?php esc_html_e( '有改动未保存', 'jinyu-theme-companion' ); ?></span>
				<span class="jperf-floatsave-act">
					<button type="button" id="jperf-undo" class="jperf-fsave-btn jperf-fsave-ghost"><?php esc_html_e( '撤销改动', 'jinyu-theme-companion' ); ?></button>
					<button type="button" id="jperf-save-float" class="jperf-fsave-btn jperf-fsave-primary">
						<svg viewBox="0 0 24 24"><path d="M5 3h11l3 3v15H5z"/><path d="M8 3v5h6M8 13h8M8 17h5"/></svg><span><?php esc_html_e( '保存设置', 'jinyu-theme-companion' ); ?></span>
					</button>
				</span>
			</div>
		</section>


	</div>


	<?php ob_start(); ?>
	(function(){
		'use strict';
		var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

		/** 统一 POST 封装，失败文案集中处理。 */
		function post(action, extra){
			var fd = new FormData();
			fd.append('action', action);
			fd.append('nonce', NONCE);
			if (extra) { for (var k in extra) { fd.append(k, extra[k]); } }
			return fetch(ajaxurl, {method:'POST', body: fd, credentials:'same-origin'}).then(function(r){ return r.json(); });
		}

	/** 环形图 + 中心数字绘制动画（页面加载与看板刷新后各跑一次）。 */
	function animateBoards(){
		var C = 289;
		document.querySelectorAll('#jperf-status-zone .jperf-bar').forEach(function(bar){
			if (bar.getAttribute('data-null')) { return; }
			var pct = parseFloat(bar.getAttribute('data-pct')) || 0;
			requestAnimationFrame(function(){ bar.style.strokeDashoffset = C * (1 - pct / 100); });
		});
		document.querySelectorAll('#jperf-status-zone .jperf-pct').forEach(function(el){
			if (el.getAttribute('data-null')) { return; }
			var pct = parseFloat(el.getAttribute('data-pct')) || 0, cur = 0,
				step = Math.max(0.5, pct / 28),
				t = setInterval(function(){
					cur += step;
					if (cur >= pct) { cur = pct; clearInterval(t); }
					el.innerHTML = (cur % 1 === 0 ? cur : cur.toFixed(1)) + '<u>%</u>';
				}, 20);
		});
		countUpAll();
	}

	/** 数值从 0 缓动到目标：stats 命中率 / 综合评分卡。 */
	function countUpAll(){
		document.querySelectorAll('#jperf-status-zone [data-count]').forEach(function(el){
			var target = parseFloat(el.getAttribute('data-count')) || 0, dur = 950, start = null;
			function tick(ts){
				if (start === null) start = ts;
				var p = Math.min(1, (ts - start) / dur), e = 1 - Math.pow(1 - p, 3);
				el.textContent = Math.round(target * e);
				if (p < 1) { requestAnimationFrame(tick); }
				else { el.textContent = target; }
			}
			requestAnimationFrame(tick);
		});
	}

		/** 结果区渲染：textContent 逐条写入（无 innerHTML 拼接，杜绝注入/裂图）。 */
		var resTimer = null;
		/** 收起全部已展开结果区：10 秒自动收起与点击立即收起共用。 */
		function collapseResults(){
			document.querySelectorAll('.jperf-result.show').forEach(function(b){ b.classList.remove('show'); });
		}
		function showResult(id, lines){
			var box = document.getElementById(id);
			box.innerHTML = '';
			box.classList.add('show');
			if (resTimer) { clearTimeout(resTimer); }
			// 一键优化/保存结果 10 秒后自动收起（状态看板会同步刷新，结果无需常驻）
			resTimer = setTimeout(collapseResults, 10000);
			lines.forEach(function(t){
				var d = document.createElement('div');
				d.className = 'item';
				// 兼容「标签 | 值」结构：用 b 标记数值
				var i = document.createElementNS('http://www.w3.org/2000/svg','svg');
				i.setAttribute('viewBox','0 0 24 24');
				var p = document.createElementNS('http://www.w3.org/2000/svg','path');
				p.setAttribute('d','M20 6L9 17l-5-5');
				i.appendChild(p);
				d.appendChild(i);
				var span = document.createElement('span');
				span.textContent = t;
				d.appendChild(span);
				box.appendChild(d);
			});
		}

		// 点击已展开的结果区任意位置 → 立即收起（不必等自动收起计时）
		document.addEventListener('click', function(e){
			if (e.target.closest('.jperf-result.show')) { collapseResults(); }
		});

		function showErr(id, msg){
			var box = document.getElementById(id);
			box.innerHTML = '';
			box.classList.add('show');
			var d = document.createElement('div');
			d.className = 'item';
			d.textContent = '<?php echo esc_js( __( '失败：', 'jinyu-theme-companion' ) ); ?>' + msg;
			box.appendChild(d);
		}

		/** 刷新状态看板：整块替换（服务端同一渲染函数，结构零漂移）。 */
		function refreshStatus(){
			return post('jinyu_perf_status').then(function(j){
				if (j.success) {
					// 整块替换前先收起：卡片 DOM 即将消失，浮层里的 tip 会变成无主残留
					closeTips(null);
					document.getElementById('jperf-status-zone').innerHTML = j.data.html;
					animateBoards();
					var at = document.getElementById('jperf-refreshed-at');
					if (at) { at.textContent = '<?php echo esc_js( __( '更新于 ', 'jinyu-theme-companion' ) ); ?>' + new Date().toLocaleTimeString(); }
				}
			});
		}

		var floatSave = document.getElementById('jperf-floatsave');
		// 搬到 .jyc-app 根级：逃出 .jyc-pane 入场动画 transform 的 fixed 包含块，
		// 否则 position:fixed 相对 pane 定位、浮条整条错位（全端复现，见 admin.css #jperf-tip-layer 注释）。
		var jycApp = document.querySelector('.jyc-app');
		if (floatSave && jycApp && floatSave.parentElement !== jycApp) {
			jycApp.appendChild(floatSave);
		}
		function markUnsaved(){ if (floatSave) { floatSave.classList.add('show'); floatSave.setAttribute('aria-hidden','false'); } }
		function clearUnsaved(){ if (floatSave) { floatSave.classList.remove('show'); floatSave.setAttribute('aria-hidden','true'); } }

		// 快照：撤销改动的基线 = 最近一次落库状态。初始化拍一次，保存成功后刷新。
		var snapToggles = [];
		document.querySelectorAll('#jperf-toggles .jperf-sw').forEach(function(sw){
			snapToggles.push({ el: sw, on: sw.classList.contains('on') });
		});
		function refreshSnapshot(){
			snapToggles.forEach(function(t){ t.on = t.el.classList.contains('on'); });
		}
		var undo = document.getElementById('jperf-undo');
		if (undo) {
			undo.addEventListener('click', function(){
				snapToggles.forEach(function(t){
					t.el.classList.toggle('on', t.on);
					t.el.setAttribute('aria-checked', t.on ? 'true' : 'false');
				});
				clearUnsaved();
				window.jycToast('<?php echo esc_js( __( '已撤销改动，恢复到上次保存的状态', 'jinyu-theme-companion' ) ); ?>');
			});
		}

		// 开关：点击 + 键盘可达
		document.querySelectorAll('#jperf-toggles .jperf-sw').forEach(function(sw){
			function toggle(){
				sw.classList.toggle('on');
				sw.setAttribute('aria-checked', sw.classList.contains('on') ? 'true' : 'false');
				markUnsaved();
			}
			sw.addEventListener('click', toggle);
			sw.addEventListener('keydown', function(e){
				if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); }
			});
		});

		/* 气泡先搬进 .jyc-app 直属浮层，再按视口坐标 fixed 定位。
			原因（实测复现）：.jcard / .jperf-wv-chip 的 hover transform（translateY）
			与 .jyc-pane 入场动画 fill-mode 残留的 identity matrix 都会成为 fixed 的
			包含块 —— JS 写入的「视口坐标」被当成「祖先坐标」，气泡整块下坠数百 px，
			桌面 / 平板 / 手机全端复现。搬进直属浮层后祖先只剩 .jyc-app（无 transform），
			坐标恒等于视口；同时仍继承面板的 --surface / --ink / --j-accent 等 token。 */
		var tipLayer = null;
		function getTipLayer(){
			var app = document.querySelector('.jyc-app') || document.body;
			if (!tipLayer || !tipLayer.isConnected) {
				tipLayer = document.createElement('div');
				tipLayer.id = 'jperf-tip-layer';
				app.appendChild(tipLayer);
			}
			return tipLayer;
		}
		function tipOf(btn){
			if (!btn._tip || !btn._tip.isConnected) {
				var host = btn.closest('.jcard, .jperf-wv-chip');
				btn._tip = host ? host.querySelector('.jperf-tip') : null;
			}
			return btn._tip;
		}
		/** 收起并送回卡片原位（卡片 DOM 被整块替换时 home 失效，直接丢弃）。 */
		function restoreTip(tip){
			tip.hidden = true;
			var home = tip._home;
			tip._home = null;
			if (home && home.parent && home.parent.isConnected) {
				home.parent.insertBefore(tip, (home.next && home.next.parentNode === home.parent) ? home.next : null);
			}
		}
		function showTip(btn, tip){
			if (!tip._home) { tip._home = { parent: tip.parentNode, next: tip.nextSibling }; }
			getTipLayer().appendChild(tip);
			tip.hidden = false;
			positionTip(btn, tip);
		}
		/* 所有宽度统一：fixed 浮层 + JS 视口坐标定位到被点问号按钮。
			不依赖媒体查询断点（断点漏判会让某宽度整组问号退回错位 CSS）。 */
		function positionTip(btn, tip){
			tip.style.position = 'fixed';
			tip.style.left = '0px'; tip.style.top = '0px';
			var tw = tip.offsetWidth, th = tip.offsetHeight;
			var r = btn.getBoundingClientRect();
			var vw = window.innerWidth, vh = window.innerHeight, m = 10;
			/* 水平贴着按钮那一侧：按钮在屏幕左半 → 气泡左缘对齐按钮左缘向右铺；
				按钮在右半（性能中心标题问号在右上）→ 气泡右缘对齐按钮右缘向左铺。
				避免把 15px 按钮吊在 320px 气泡中央、文字区离问号过远。 */
			var bc = r.left + r.width / 2;
			var left = bc < vw / 2 ? r.left : (r.right - tw);
			left = Math.max(m, Math.min(left, vw - tw - m));
			var top = r.bottom + 8;
			if (top + th > vh - m) {
				var above = r.top - 8 - th;
				top = above < m ? m : above;
			}
			tip.style.left = left + 'px';
			tip.style.top = top + 'px';
		}
		function closeTips(except){
			var layer = getTipLayer();
			Array.prototype.slice.call(layer.children).forEach(function(t){
				if (t !== except) { restoreTip(t); }
			});
			document.querySelectorAll('.jperf-help[aria-expanded="true"]').forEach(function(b){
				if (b._tip !== except) { b.setAttribute('aria-expanded', 'false'); }
			});
		}
		document.addEventListener('click', function(e){
			var b = e.target.closest('.jperf-help');
			if (b) {
				var tip = tipOf(b);
				if (!tip) { return; }
				var open = b.getAttribute('aria-expanded') === 'true';
				closeTips(tip);
				b.setAttribute('aria-expanded', open ? 'false' : 'true');
				if (open) { restoreTip(tip); } else { showTip(b, tip); }
				return;
			}
			if (!e.target.closest('.jperf-tip')) { closeTips(null); }
		});
		document.addEventListener('keydown', function(e){
			if (e.key === 'Escape') { closeTips(null); }
		});
		/* fixed 气泡不随页面滚动/视口旋转，脱离按钮即收起，避免悬空错位（所有宽度） */
		window.addEventListener('scroll', function(){ closeTips(null); }, true);
		window.addEventListener('resize', function(){ closeTips(null); });

		// 一键打开推荐开关：按 data-rec 把界面开关切到推荐状态（不落库，需再点「保存设置」生效）
		var rec = document.getElementById('jperf-rec');
		if (rec) {
			rec.addEventListener('click', function(){
				var changed = 0;
				document.querySelectorAll('#jperf-toggles .jperf-sw').forEach(function(sw){
					var want = sw.getAttribute('data-rec') === '1';
					if (sw.classList.contains('on') !== want) { changed++; }
					sw.classList.toggle('on', want);
					sw.setAttribute('aria-checked', want ? 'true' : 'false');
				});
				markUnsaved();
				showResult('jperf-result', [changed > 0
					? '<?php echo esc_js( __( '已按推荐状态切换 ', 'jinyu-theme-companion' ) ); ?>' + changed + '<?php echo esc_js( __( ' 项开关（尚未保存）。请核对后点「保存设置」生效。', 'jinyu-theme-companion' ) ); ?>'
					: '<?php echo esc_js( __( '当前开关已是推荐状态，无需改动。', 'jinyu-theme-companion' ) ); ?>']);
			});
		}

		// 一键应用推荐优化
		var run = document.getElementById('jperf-run');
		if (run) {
			run.addEventListener('click', function(){
				var opts = {};
				document.querySelectorAll('#jperf-toggles .jperf-sw').forEach(function(sw){
					opts[sw.dataset.key] = sw.classList.contains('on') ? 1 : 0;
				});
				run.disabled = true;
				var lbl = run.querySelector('span');
				var old = lbl.textContent;
				lbl.textContent = '<?php echo esc_js( __( '优化中…', 'jinyu-theme-companion' ) ); ?>';
				post('jinyu_perf_optimize', {options: JSON.stringify(opts)})
					.then(function(j){
						run.disabled = false; lbl.textContent = old;
						if (!j.success) { showErr('jperf-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '未知错误', 'jinyu-theme-companion' ) ); ?>'); return; }
						var d = j.data, lines = [];
						lines.push('<?php echo esc_js( __( '清理过期 transient：', 'jinyu-theme-companion' ) ); ?>' + d.cleaned_transients + '<?php echo esc_js( __( ' 条', 'jinyu-theme-companion' ) ); ?>');
						lines.push('<?php echo esc_js( __( '优化数据表：', 'jinyu-theme-companion' ) ); ?>' + d.optimized_tables + '<?php echo esc_js( __( ' 张', 'jinyu-theme-companion' ) ); ?>' + (d.table_list && d.table_list.length ? '<?php echo esc_js( __( '（', 'jinyu-theme-companion' ) ); ?>' + d.table_list.join('<?php echo esc_js( __( '、', 'jinyu-theme-companion' ) ); ?>') + '<?php echo esc_js( __( '）', 'jinyu-theme-companion' ) ); ?>' : ''));
						(d.cache || []).forEach(function(m){ lines.push(m); });
						var fmtHit = function(name, b, a){
							if (b === null || b === undefined) return name + '<?php echo esc_js( __( '：未启用', 'jinyu-theme-companion' ) ); ?>';
							return name + '：' + b + '% → ' + a + '%';
						};
						lines.push(fmtHit('<?php echo esc_js( __( 'OPcache 命中率', 'jinyu-theme-companion' ) ); ?>', d.before_hit.opcache, d.after_hit.opcache));
						lines.push(fmtHit('<?php echo esc_js( __( 'Memcached 命中率', 'jinyu-theme-companion' ) ); ?>', d.before_hit.memcached, d.after_hit.memcached));
						lines.push('<?php echo esc_js( __( '自动加载选项：', 'jinyu-theme-companion' ) ); ?>' + d.before_d.autoload_bytes + '<?php echo esc_js( __( ' → ', 'jinyu-theme-companion' ) ); ?>' + d.after_d.autoload_bytes);
						lines.push('<?php echo esc_js( __( '过期 transient：', 'jinyu-theme-companion' ) ); ?>' + d.before_d.transient_expired + '<?php echo esc_js( __( ' → ', 'jinyu-theme-companion' ) ); ?>' + d.after_d.transient_expired + '<?php echo esc_js( __( ' 条', 'jinyu-theme-companion' ) ); ?>');
						lines.push('<?php echo esc_js( __( '数据表碎片：', 'jinyu-theme-companion' ) ); ?>' + d.before_d.table_overhead + '<?php echo esc_js( __( ' → ', 'jinyu-theme-companion' ) ); ?>' + d.after_d.table_overhead);
						lines.push('<?php echo esc_js( __( '开关已保存，下一次请求起生效。', 'jinyu-theme-companion' ) ); ?>');
						showResult('jperf-result', lines);
						refreshStatus();
						syncWpFooter();
						window.jycToast('<?php echo esc_js( __( '推荐优化已应用', 'jinyu-theme-companion' ) ); ?>');
					})
					.catch(function(e){ run.disabled = false; lbl.textContent = old; showErr('jperf-result', e); });
			});
		}

		// 保存后即时同步 WP 后台页脚显隐，免去手动刷新（服务端 filter 仅在整页加载时生效）。
		function syncWpFooter() {
			var sw = document.querySelector('#jperf-toggles .jperf-sw[data-key="hide_wp_footer"]');
			var footer = document.getElementById('wpfooter');
			if (!footer) { return; }
			footer.style.display = (sw && sw.classList.contains('on')) ? 'none' : '';
		}

		// 统一保存：优化开关 + 缓存预热配置一并落库（不跑清理/优化）。固定按钮与悬浮按钮共用。
		function saveAll(btn){
			if (!btn) { return; }
			var opts = {};
			document.querySelectorAll('#jperf-toggles .jperf-sw').forEach(function(sw){
				opts[sw.dataset.key] = sw.classList.contains('on') ? 1 : 0;
			});
			btn.disabled = true;
			var lbl = btn.querySelector('span'), old = lbl ? lbl.textContent : '';
			if (lbl) { lbl.textContent = '<?php echo esc_js( __( '保存中…', 'jinyu-theme-companion' ) ); ?>'; }
			post('jinyu_perf_save', {options: JSON.stringify(opts)})
				.then(function(j){
					btn.disabled = false; if (lbl) { lbl.textContent = old; }
					if (!j.success) { showErr('jperf-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '未知错误', 'jinyu-theme-companion' ) ); ?>'); return; }
					showResult('jperf-result', [j.data.msg]);
					window.jycToast(j.data.msg); // 复用全局统一保存成功浮层
					refreshSnapshot(); // 撤销基线更新为本次落库状态
					clearUnsaved();
					refreshStatus();
					syncWpFooter();
				})
				.catch(function(e){ btn.disabled = false; if (lbl) { lbl.textContent = old; } showErr('jperf-result', e); });
		}
		var save = document.getElementById('jperf-save');
		if (save) { save.addEventListener('click', function(){ saveAll(save); }); }
		var saveFloat = document.getElementById('jperf-save-float');
		if (saveFloat) { saveFloat.addEventListener('click', function(){ saveAll(saveFloat); }); }

		// 缓存清理：事件委托（按钮在状态看板内，refreshStatus 重渲染后委托仍有效）
		function handleFlush(b){
			b.disabled = true;
			var lbl = b.querySelector('span');
			var old = lbl ? lbl.textContent : '';
			if (lbl) { lbl.textContent = '<?php echo esc_js( __( '清除中…', 'jinyu-theme-companion' ) ); ?>'; }
			post('jinyu_perf_flush', {target: b.getAttribute('data-flush')})
				.then(function(j){
					b.disabled = false; if (lbl) { lbl.textContent = old; }
					if (!j.success) { showErr('jperf-cache-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '未知错误', 'jinyu-theme-companion' ) ); ?>'); return; }
					showResult('jperf-cache-result', [j.data.msg]);
					refreshStatus();
				})
				.catch(function(e){ b.disabled = false; if (lbl) { lbl.textContent = old; } showErr('jperf-cache-result', e); });
		}
		// 清空真实用户体验聚合：事件委托（同上，避免重渲染后失效）
		function handleResetWv(b){
			if (!window.confirm('<?php echo esc_js( __( '确定清空真实用户体验聚合数据？此操作不可撤销。', 'jinyu-theme-companion' ) ); ?>')) { return; }
			b.disabled = true;
			var lbl = b.querySelector('span');
			var old = lbl ? lbl.textContent : '';
			if (lbl) { lbl.textContent = '<?php echo esc_js( __( '清空中…', 'jinyu-theme-companion' ) ); ?>'; }
			post('jinyu_perf_reset_wv')
				.then(function(j){
					b.disabled = false; if (lbl) { lbl.textContent = old; }
					if (!j.success) { showErr('jperf-wv-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '未知错误', 'jinyu-theme-companion' ) ); ?>'); return; }
					showResult('jperf-wv-result', [j.data.msg]);
					refreshStatus();
				})
				.catch(function(e){ b.disabled = false; if (lbl) { lbl.textContent = old; } showErr('jperf-wv-result', e); });
		}

		// 缓存清理 / 清空体验数据：委托到 document —— 按钮已分别移到页面标题行右上角与状态看板内，
		// 两处不在同一个容器里，且看板会被 refreshStatus 整块重渲染，委托到 document 最稳妥。
		document.addEventListener('click', function(e){
			var fb = e.target.closest('.jperf-btn[data-flush]');
			if (fb) { handleFlush(fb); return; }
			var rb = e.target.closest('#jperf-reset-wv');
			if (rb) { handleResetWv(rb); return; }
		});

		// 对象缓存 drop-in 部署 / 回滚（事件委托：状态看板刷新后仍有效）
		var ocZone = document.getElementById('jperf-status-zone');
		if (ocZone) {
			ocZone.addEventListener('click', function(e){
				// 升级替换：展开/收起确认面板
				var up = e.target.closest('[data-upgrade="1"]');
				if (up) {
					var panel = ocZone.querySelector('.jperf-oc-confirm');
					if (panel) { panel.hidden = !panel.hidden; }
					return;
				}
				var canc = e.target.closest('[data-upgrade-cancel="1"]');
				if (canc) {
					var p2 = ocZone.querySelector('.jperf-oc-confirm');
					if (p2) { p2.hidden = true; }
					return;
				}
				// 升级替换：确认并执行
				var cf = e.target.closest('[data-upgrade-confirm="1"]');
				if (cf) {
					e.preventDefault();
					var chk = ocZone.querySelector('[data-backup="1"]');
					var backup = chk && chk.checked ? 1 : 0;
					var oldTxt = cf.textContent;
					cf.disabled = true;
					cf.textContent = '<?php echo esc_js( __( '升级中…', 'jinyu-theme-companion' ) ); ?>';
					post('jinyu_perf_upgrade_cache', {backup: backup})
						.then(function(j){
							cf.disabled = false; cf.textContent = oldTxt;
							var p3 = ocZone.querySelector('.jperf-oc-confirm'); if (p3) { p3.hidden = true; }
							if (!j.success) { showErr('jperf-cache-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '升级失败', 'jinyu-theme-companion' ) ); ?>'); return; }
							showResult('jperf-cache-result', [j.data.msg]);
							refreshStatus();
						})
						.catch(function(err){ cf.disabled = false; cf.textContent = oldTxt; showErr('jperf-cache-result', err); });
					return;
				}
				var b = e.target.closest('.jperf-btn[data-deploy]');
				if (!b) { return; }
				e.preventDefault();
				b.disabled = true;
				var lbl = b.querySelector('span');
				var old = lbl ? lbl.textContent : '';
				if (lbl) { lbl.textContent = '<?php echo esc_js( __( '处理中…', 'jinyu-theme-companion' ) ); ?>'; }
				post('jinyu_perf_deploy_cache', {deploy: b.getAttribute('data-deploy')})
					.then(function(j){
						b.disabled = false; if (lbl) { lbl.textContent = old; }
						if (!j.success) { showErr('jperf-cache-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '未知错误', 'jinyu-theme-companion' ) ); ?>'); return; }
						showResult('jperf-cache-result', [j.data.msg]);
						refreshStatus();
					})
					.catch(function(err){ b.disabled = false; if (lbl) { lbl.textContent = old; } showErr('jperf-cache-result', err); });
			});
		}

		// 手动刷新看板
		var rf = document.getElementById('jperf-refresh');
		if (rf) {
			rf.addEventListener('click', function(){ rf.disabled = true; refreshStatus().then(function(){ rf.disabled = false; }).catch(function(){ rf.disabled = false; }); });
		}

		// 首次绘制
		animateBoards();
	})();
	<?php
	wp_print_inline_script_tag( (string) ob_get_clean() );
}

/**
 * 渲染「缓存预热」折叠段（嵌入整页缓存卡 jyc-panel--pc 内，压缩布局）。
 * 暖的是整页 / 对象缓存，逻辑上从属整页缓存，折叠收纳节省纵向空间；
 * 后台预热进行时由 JS 自动展开并轮询进度。开关 / 频率 / URL 存于
 * jinyu_perf_options_v2（与性能中心开关同一真源），经独立 AJAX 端点 jinyu_perf_warmup 落库与触发。
 */
function jinyu_perf_render_warmup_section(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opts     = function_exists( 'jinyu_perf_get_options' ) ? jinyu_perf_get_options() : array();
	$en       = ! empty( $opts['warmup_enable'] ) ? 1 : 0;
	$interval = $opts['warmup_interval'] ?? 'manual';
	$urls     = $opts['warmup_urls'] ?? '';
	$max      = (int) ( $opts['warmup_max'] ?? 300 );
	$st       = function_exists( 'jinyu_warmup_state_get' ) ? jinyu_warmup_state_get() : array(
		'position' => 0,
		'total' => 0,
		'warmed' => 0,
		'running' => false,
	);
	$st_total  = (int) ( $st['total'] ?? 0 );
	$st_warmed = (int) ( $st['warmed'] ?? 0 );
	$st_pct    = $st_total > 0 ? (int) round( $st_warmed / $st_total * 100 ) : 0;
	$nonce     = wp_create_nonce( 'jinyu_perf_center' );

	// 折叠头一行式状态摘要：预热中 > 有进度 > 已启用 > 默认关闭。
	if ( ! empty( $st['running'] ) ) {
		$wu_sub = __( '预热中…', 'jinyu-theme-companion' );
	} elseif ( $st_total > 0 ) {
		/* translators: 1: 已暖数, 2: 总数, 3: 覆盖率百分比 */
		$wu_sub = sprintf( __( '已暖 %1$d / %2$d（%3$d%%）', 'jinyu-theme-companion' ), $st_warmed, $st_total, $st_pct );
	} elseif ( $en ) {
		$wu_sub = __( '已启用', 'jinyu-theme-companion' );
	} else {
		$wu_sub = __( '默认关闭', 'jinyu-theme-companion' );
	}
	?>
	<div class="jyc-collapse jyc-collapse--wu" id="jycWarmupBox">
		<button type="button" class="jyc-collapse-head" aria-expanded="false" aria-controls="jycWarmupBody" onclick="window.jycCollapseToggle(this)">
			<span class="jyc-collapse-title"><?php echo esc_html__( '缓存预热', 'jinyu-theme-companion' ); ?></span>
			<span class="jyc-collapse-sub" id="jycWarmupSub"><?php echo esc_html( $wu_sub ); ?></span>
			<svg class="jyc-collapse-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
		</button>
		<div class="jyc-collapse-body" id="jycWarmupBody" hidden>
			<div class="jyc-frow">
				<label class="jyc-switch"><input type="checkbox" id="jinyu-warmup-enable" name="warmup_enable" <?php checked( $en ); ?>><span class="jyc-track"></span></label>
				<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用缓存预热', 'jinyu-theme-companion' ); ?></div>
					<div class="jyc-fdesc"><?php echo esc_html__( '清缓存 / 发文 / 定时任务后自动爬取首页、列表、分类与近期热门文章暖缓存，访客始终命中热缓存。', 'jinyu-theme-companion' ); ?></div></div>
				<label class="jyc-fl jyc-wu-freq"><span class="jyc-fname-sm"><?php echo esc_html__( '频率', 'jinyu-theme-companion' ); ?></span>
					<select class="jyc-inp" id="jinyu-warmup-interval" name="warmup_interval">
						<?php foreach ( jinyu_warmup_intervals() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $k, $interval ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
			<div class="jyc-fpair">
				<label class="jyc-fl">
					<span class="jyc-fname-sm"><?php echo esc_html__( '额外预热 URL（每行一个）', 'jinyu-theme-companion' ); ?></span>
					<textarea class="jyc-inp" id="jinyu-warmup-urls" name="warmup_urls" rows="3" placeholder="https://<?php echo esc_attr( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>/about/"><?php echo esc_textarea( $urls ); ?></textarea>
					<span class="jyc-fnote"><?php echo esc_html__( '仅本域名或显式 http(s)，不外发第三方。', 'jinyu-theme-companion' ); ?></span>
				</label>
				<label class="jyc-fl">
					<span class="jyc-fname-sm"><?php echo esc_html__( '最多暖 N 篇（0 = 全量，按修改时间倒序取前 N）', 'jinyu-theme-companion' ); ?></span>
					<input class="jyc-inp" type="number" min="0" step="50" id="jinyu-warmup-max" name="warmup_max" value="<?php echo esc_attr( (string) $max ); ?>">
					<span class="jyc-fnote"><?php echo esc_html__( '新发布 / 更新文章会优先即时预热；首页与核心分类每轮强制重暖（并发抓取，不阻塞访客）。', 'jinyu-theme-companion' ); ?></span>
				</label>
			</div>
			<div class="jyc-test-row" style="margin-top:10px">
				<button type="button" class="jyc-btn jyc-btn-soft" id="jinyu-warmup-run" data-loading="<?php echo esc_attr__( '预热中…', 'jinyu-theme-companion' ); ?>"><?php echo esc_html__( '立即预热', 'jinyu-theme-companion' ); ?></button>
				<button type="button" class="jyc-btn jyc-btn-soft" data-wuflush="warmed" data-loading="<?php echo esc_attr__( '清除中…', 'jinyu-theme-companion' ); ?>"><?php echo esc_html__( '清除预热数据', 'jinyu-theme-companion' ); ?></button>
				<span class="jyc-fnote"><?php echo esc_html__( '「立即预热」点击即保存配置并开始；「清除预热数据」仅删预热队列各 URL 的整页缓存，不动其他缓存、不自动重暖。', 'jinyu-theme-companion' ); ?></span>
			</div>
			<div id="jinyu-warmup-cache-result" class="jperf-result" aria-live="polite"></div>
			<?php
			$wu_last = get_option( 'jinyu_warmup_last' );
			if ( is_array( $wu_last ) && ! empty( $wu_last['at'] ) ) :
				?>
				<p class="jyc-muted" style="font-size:12px;margin-top:10px"><?php echo esc_html( sprintf( /* translators: %1$s 上次时间, %2$d 成功数, %3$d 总数, %4$s 耗时秒数 */ __( '上次：%1$s，成功 %2$d / %3$d，耗时 %4$s 秒', 'jinyu-theme-companion' ), wp_date( 'Y-m-d H:i', $wu_last['at'] ), $wu_last['ok'], $wu_last['total'], $wu_last['elapsed'] ) ); ?></p>
				<?php
			endif;
			?>
			<div id="jinyu-warmup-progress" style="margin-top:12px;display:<?php echo ( $st['running'] || $st_total > 0 ) ? 'block' : 'none'; ?>">
				<div style="height:8px;background:var(--line-2,#eceef2);border-radius:6px;overflow:hidden">
					<span id="jinyu-warmup-bar" style="display:block;height:100%;width:<?php echo esc_attr( (string) $st_pct ); ?>%;background:linear-gradient(90deg,#1746c4,#3b82f6);transition:width .4s ease"></span>
				</div>
				<div class="jyc-muted" id="jinyu-warmup-txt" style="font-size:12px;margin-top:6px">
                <?php
					/* translators: %1$d 已暖数, %2$d 总数, %3$d 覆盖率百分比 */
					echo esc_html( sprintf( __( '已暖 %1$d / 共 %2$d（覆盖率 %3$d%%）', 'jinyu-theme-companion' ), $st_warmed, $st_total, $st_pct ) );
					echo $st['running'] ? ' · ' . esc_html__( '预热中…', 'jinyu-theme-companion' ) : '';
				?>
                </div>
			</div>
			<div id="jinyu-warmup-result" class="jperf-result" aria-live="polite"></div>
		</div>
	</div>
	<?php
	ob_start();
	?>
	(function(){
		var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
		function post(action, extra){
			var fd = new FormData();
			fd.append('action', action);
			fd.append('nonce', NONCE);
			if (extra) { for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { fd.append(k, extra[k]); } } }
			return fetch(ajaxurl, {method:'POST', body: fd, credentials:'same-origin'}).then(function(r){ return r.json(); });
		}
		function showResult(id, lines){
			var box = document.getElementById(id);
			if (!box) { return; }
			box.innerHTML = '';
			box.classList.add('show');
			lines.forEach(function(t){
				var d = document.createElement('div');
				d.className = 'item';
				var i = document.createElementNS('http://www.w3.org/2000/svg','svg');
				i.setAttribute('viewBox','0 0 24 24');
				var p = document.createElementNS('http://www.w3.org/2000/svg','path');
				p.setAttribute('d','M20 6L9 17l-5-5');
				i.appendChild(p);
				d.appendChild(i);
				var span = document.createElement('span');
				span.textContent = t;
				d.appendChild(span);
				box.appendChild(d);
			});
		}
		function showErr(id, msg){
			var box = document.getElementById(id);
			if (!box) { return; }
			box.innerHTML = '';
			box.classList.add('show');
			var d = document.createElement('div');
			d.className = 'item';
			d.textContent = '<?php echo esc_js( __( '失败：', 'jinyu-theme-companion' ) ); ?>' + msg;
			box.appendChild(d);
		}
		function runWarmup(){
			var en = document.getElementById('jinyu-warmup-enable');
			if (!en || !en.checked) { return; }
			var wi = document.getElementById('jinyu-warmup-interval');
			var wu = document.getElementById('jinyu-warmup-urls');
			var mx = document.getElementById('jinyu-warmup-max');
			var btn = document.getElementById('jinyu-warmup-run');
			if (btn) { btn.disabled = true; }
			var old = btn ? btn.textContent : '';
			if (btn) { btn.textContent = '<?php echo esc_js( __( '处理中…', 'jinyu-theme-companion' ) ); ?>'; }
			post('jinyu_perf_warmup', {
				enable: en && en.checked ? 1 : 0,
				interval: wi ? wi.value : 'manual',
				urls: wu ? wu.value : '',
				max: mx ? mx.value : 300,
				run: 1
			}).then(function(j){
				if (btn) { btn.disabled = false; btn.textContent = old; }
				if (!j.success) { showErr('jinyu-warmup-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '未知错误', 'jinyu-theme-companion' ) ); ?>'); return; }
				var r = j.data && j.data.result ? j.data.result : null;
				if (r) { wuRender({total:r.total, warmed:r.ok, running:!r.done}); }
				// 手动点击只暖首批（时间预算内），其余交 cron 续跑；轮询进度直到 running=false。
				startWarmupPoll();
			}).catch(function(e){
				if (btn) { btn.disabled = false; btn.textContent = old; }
				showErr('jinyu-warmup-result', e);
			});
		}
		var wuPoll = null;
		function wuOpen(){
			var box = document.getElementById('jycWarmupBox');
			var body = document.getElementById('jycWarmupBody');
			if (box) { box.classList.add('is-open'); }
			if (body) { body.hidden = false; }
		}
		function wuRender(st){
			var prog = document.getElementById('jinyu-warmup-progress');
			var bar = document.getElementById('jinyu-warmup-bar');
			var txt = document.getElementById('jinyu-warmup-txt');
			var sub = document.getElementById('jycWarmupSub');
			var total = parseInt(st.total || '0', 10);
			var warmed = parseInt(st.warmed || '0', 10);
			var pct = total > 0 ? Math.round(warmed / total * 100) : 0;
			var label = '<?php echo esc_js( __( '已暖 ', 'jinyu-theme-companion' ) ); ?>' + warmed + ' / ' + total + '（<?php echo esc_js( __( '覆盖率 ', 'jinyu-theme-companion' ) ); ?>' + pct + '%）' + (st.running ? ' · <?php echo esc_js( __( '预热中…', 'jinyu-theme-companion' ) ); ?>' : '');
			if (prog && bar && txt) {
				prog.style.display = (st.running || total > 0) ? 'block' : 'none';
				bar.style.width = pct + '%';
				txt.textContent = label;
			}
			if (sub && (st.running || total > 0)) { sub.textContent = label; }
		}
		function pollWarmup(){
			post('jinyu_perf_warmup_status', {}).then(function(j){
				if (!j.success) { stopWarmupPoll(); return; }
				wuRender(j.data.state || {});
				if (j.data.state && j.data.state.running) {
					wuPoll = setTimeout(pollWarmup, 2500);
				} else {
					stopWarmupPoll();
					var r = j.data.last || {};
					if (r.total) {
						showResult('jinyu-warmup-result', ['<?php echo esc_js( __( '成功 ', 'jinyu-theme-companion' ) ); ?>' + r.ok + ' / ' + r.total + '<?php echo esc_js( __( '，失败 ', 'jinyu-theme-companion' ) ); ?>' + r.fail + '<?php echo esc_js( __( '，耗时 ', 'jinyu-theme-companion' ) ); ?>' + r.elapsed + 's' + (r.done ? '' : '（后台续跑中）')]);
					}
				}
			}).catch(function(){ stopWarmupPoll(); });
		}
		function startWarmupPoll(){ wuOpen(); stopWarmupPoll(); pollWarmup(); }
		function stopWarmupPoll(){ if (wuPoll) { clearTimeout(wuPoll); wuPoll = null; } }
		function syncWarmupBtn(){
			var en = document.getElementById('jinyu-warmup-enable');
			var btn = document.getElementById('jinyu-warmup-run');
			if (en && btn) { btn.disabled = !en.checked; }
		}
		var wuEn = document.getElementById('jinyu-warmup-enable');
		if (wuEn) { wuEn.addEventListener('change', syncWarmupBtn); }
		syncWarmupBtn();
		var wuRun = document.getElementById('jinyu-warmup-run');
		if (wuRun) { wuRun.addEventListener('click', runWarmup); }
		// 面板加载时若后台正在预热（cron 续跑 / 改文章触发），自动开始轮询，打开面板即可看到实时进度。
		if ( <?php echo wp_json_encode( ! empty( $st['running'] ) ); ?> ) {
			startWarmupPoll();
		}
		// 「缓存预热」卡内「清除预热数据」：仅精确删预热队列各 URL 的整页缓存，不动其他缓存（data-wuflush 避开 perf-center 全局委托）。
		document.querySelectorAll('[data-wuflush]').forEach(function(b){
			b.addEventListener('click', function(){
				var t = b.getAttribute('data-wuflush');
				var old = b.textContent;
				b.disabled = true;
				b.textContent = '<?php echo esc_js( __( '清除中…', 'jinyu-theme-companion' ) ); ?>';
				post('jinyu_perf_flush', {target: t}).then(function(j){
					b.disabled = false;
					b.textContent = old;
					if (!j.success) { showErr('jinyu-warmup-cache-result', j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '未知错误', 'jinyu-theme-companion' ) ); ?>'); return; }
					showResult('jinyu-warmup-cache-result', [ j.data && j.data.msg ? j.data.msg : '<?php echo esc_js( __( '已清除', 'jinyu-theme-companion' ) ); ?>' ]);
					if ( typeof wuRender === 'function' ) { wuRender( { total: 0, warmed: 0, running: false } ); }
				}).catch(function(e){
					b.disabled = false;
					b.textContent = old;
					showErr('jinyu-warmup-cache-result', e);
				});
			});
		});
	})();
	<?php
	wp_print_inline_script_tag( (string) ob_get_clean() );
}
