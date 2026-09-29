<?php
/**
 * 站点地图兜底：只在内核 WP_Sitemaps 之上做「减法」，不自造渲染。
 *
 * 【为什么是过滤器而不是自定义 Provider】
 * 内核 WP_Sitemaps 已提供分页（第 1 页为子集、第 2 页起为分页）、<lastmod> 取值
 * （WP_Sitemaps_Posts::get_lastmod() 取 post_modified_gmt）、W3C 时间格式与 XSL 样式表。
 * 自造 Provider 意味着重写渲染层，收益为零却多出一份需要长期维护的代码——与「走内核扩展」相悖。
 * 本文件只回答一个问题：收录谁。
 *
 * 【让位必须晚于 SEO 插件的注册】
 * 判断「有没有别的插件接管站点地图」时，不依赖已知插件名单（名单永远有遗漏），
 * 而是看 WP_Sitemaps 里是否出现了非核心 provider —— 对任何会注册 sitemap 的插件都生效。
 * 辅以显式名单判断漏网的老插件。
 * 另：SEO 插件的版本常量在前台可能晚于本插件才定义，在 init 早期完成让位判断会留下窗口期，
 * 两套 sitemap 并存，故让位判断统一收在 jinyu_sitemap_is_active()，
 * 由过滤器回调在进入 WP_Sitemaps 时才求值。
 *
 * 【robots.txt 不参与】站点根 robots.txt 多数由 Web 服务器直出静态文件，内核虚拟 robots 不生效，
 * 插件若再挂 robots_txt 过滤器同样无效，故此处一律不碰 robots。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 站点地图兜底是否启用。
 *
 * 三个前提同时成立才启用：开关打开、未装主流 SEO 插件、运行环境支持内核 WP_Sitemaps。
 *
 * @return bool
 */
function jinyu_sitemap_is_active() {
	// 让位条件之一：另一款 SEO 插件已注册自己的 sitemap provider。
	// 这里不依赖「已知插件名单」——名单永远会有遗漏，用户装了名单外的 SEO 插件，
	// 兜底却照样启用就会和它的 sitemap 撞车。改为直接看 WP_Sitemaps 里是否出现了
	// 非核心 provider，覆盖一切会注册 sitemap 的插件，无论是否知名。
	if ( jinyu_sitemap_other_provider_exists() ) {
		return false;
	}
	// 兜底保留一行显式名单判断：少数不注册内置 provider、自带 sitemap 路由的老插件
	// （部分 SEO 插件走自建输出），只能靠常量识别。jinyu_seo_plugin_active() 定义在
	// inc/fun/companion-options.php，本插件必定已加载。
	if ( jinyu_seo_plugin_active() ) {
		return false;
	}
	return jinyu_companion_is_checked( 'sitemap_enable', true );
}

/**
 * 是否已由其他插件接管站点地图（WP_Sitemaps 中存在非核心 provider）。
 *
 * 核心自带 posts / users / taxonomies 三类 provider，只要出现第四类，
 * 就说明有第三方插件注册了站点地图，本插件应当让位。
 *
 * @return bool
 */
function jinyu_sitemap_other_provider_exists() {
	// 该函数的可用性自 WP 5.5 起，老环境直接放弃判断（此时按显式名单决定）。
	if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
		return false;
	}
	$server = wp_sitemaps_get_server();
	if ( ! is_object( $server ) || ! method_exists( $server, 'get_providers' ) ) {
		return false;
	}
	$core = array( 'posts', 'users', 'taxonomies' );
	foreach ( array_keys( (array) $server->get_providers() ) as $object_type ) {
		if ( ! in_array( (string) $object_type, $core, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * 站点地图排除的文章类型。
 *
 * - attachment：媒体库附件，permalink 可被直接访问，但收录图片对搜索结果无意义，
 *   且会让 sitemap 体积无谓膨胀。
 * - jinyu_link：金玉主题的链接型内容（友链 / 导航），按收录习惯排除。其他站点通常没有这个
 *   文章类型，排除一个不存在的类型不产生任何效果；若你的站点需要收录它，或用别的类型，
 *   用 jinyu_sitemap_excluded_post_types 过滤器调整即可。
 *
 * @return array<int, string> 需从 sitemap 中剔除的 post type 列表。
 */
function jinyu_sitemap_excluded_post_types() {
	/**
	 * 过滤器：调整站点地图排除的文章类型。
	 *
	 * @param array<int, string> $excluded 排除的 post type 列表。
	 */
	return (array) apply_filters(
		'jinyu_sitemap_excluded_post_types',
		array( 'attachment', 'jinyu_link' )
	);
}

/**
 * 从站点地图剔除指定文章类型。
 *
 * @param array<string, WP_Post_Type> $types 内核 WP_Sitemaps 收集到的文章类型映射。
 * @return array<string, WP_Post_Type>
 */
function jinyu_sitemap_filter_post_types( $types ) {
	if ( ! jinyu_sitemap_is_active() ) {
		return $types;
	}
	foreach ( jinyu_sitemap_excluded_post_types() as $post_type ) {
		unset( $types[ $post_type ] );
	}
	return $types;
}
add_filter( 'wp_sitemaps_post_types', 'jinyu_sitemap_filter_post_types' );

/**
 * 调整内核文章 / 页面的站点地图查询参数。
 *
 * 内核已限定 post_status 为 publish（草稿与私有天然被排除），此处只额外排除密码保护文章：
 * 密码文章虽是 publish 状态，permalink 却需带 ?post_password= 才能打开，进 sitemap 只会产出死链。
 *
 * @param array<string, mixed> $args      内核 WP_Sitemaps 的 WP_Query 参数。
 * @param string               $post_type 当前 provider 的文章类型。
 * @return array<string, mixed>
 */
function jinyu_sitemap_filter_query_args( $args, $post_type ) {
	if ( ! jinyu_sitemap_is_active() ) {
		return $args;
	}
	$args['post_password'] = '';
	return $args;
}
add_filter( 'wp_sitemaps_posts_query_args', 'jinyu_sitemap_filter_query_args', 10, 2 );
