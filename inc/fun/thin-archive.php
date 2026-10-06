<?php
/**
 * 薄归档页索引控制：文章数不足阈值的标签 / 分类页不再进索引与站点地图。
 *
 * 【为什么需要这一层】
 * 归档页数量随内容增长而膨胀，标签尤甚——一篇只出现一次的标签会生成一个
 * 只承载单篇文章的归档页。这类页面：
 *   - 对读者没有导航价值（凑不出内容集合）；
 *   - 对抓取是纯消耗（同一篇文章在正文页 + 标签页 + 分类页各出现一次）；
 *   - 对 AI 爬虫是重复内容信号，密集出现会让站点被判定为低质量。
 * 若放任不管，站点地图里绝大多数 URL 会是这种薄页，正文的权重被摊薄。
 *
 * 【为什么用 noindex 而不是删除标签】
 * 删除标签会让已收录的 URL 变成 404，排名与站内指向它的内链一并损失；
 * 而归档页本身仍承担着「让爬虫沿标签链发现文章」的作用。
 * noindex 只回答「搜索结果里展不展示」，follow 保留抓取通道，
 * 页面继续存在、继续传递内链权重，只是不再占用结果页位置。可随时反悔。
 *
 * 【为什么跟随输出而不是前置过滤】
 * 归档页 URL 必须返回 200。若在输出 noindex 之前就 404，爬虫拿到的不是
 * 「请勿收录」而是「此页已消失」，行为不可预期。
 *
 * 【与 seo-sitemap.php 的分工】
 * 本模块负责 robots 层的 noindex 标记，seo-sitemap.php 负责 XML 层的条目剔除。
 * 两者成对使用：内核生成 sitemap 时不读 wp_robots 的输出，
 * 只打 noindex 不清 XML 等于一边说不收它、一边继续发邀请函。
 *
 * @package jinyu-theme-companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 本模块是否启用。
 *
 * 随SEO 总开关一起关闭，避免主开关关掉后本模块仍在改 robots。
 *
 * @return bool
 */
function jinyu_thin_archive_enabled(): bool {
	return jinyu_companion_is_checked( 'thin_archive_noindex_enable', true );
}

/**
 * 各分类法的「最低内容条目数」阈值。
 *
 * 未在此表内的分类法不参与本模块，避免误伤产品分类、系列等业务分类法。
 * 标签默认 3（凑不出 3 篇就不成其为集合）；分类默认 2（分类粒度本就比标签粗，
 * 单篇分类虽薄但往往承载有意义的入口）。
 *
 * @return array<string,int> 分类法 => 最低条目数。
 */
function jinyu_thin_archive_limits(): array {
	$defaults = array(
		'post_tag' => 3,
		'category' => 2,
	);

	$limits = (array) apply_filters( 'jinyu_thin_archive_limits', $defaults );

	$clean = array();

	foreach ( $limits as $taxonomy => $min ) {
		$min = (int) $min;

		if ( is_string( $taxonomy ) && '' !== $taxonomy && $min > 0 ) {
			$clean[ $taxonomy ] = $min;
		}
	}

	return $clean;
}

/**
 * 始终保留索引的分类项。
 *
 * 某些分类是站点主动搭建的导航枢纽（首页、近期更新等），内容天然少于阈值，
 * 但承担真实入口价值，不应被本模块处理。默认留空，由站点按需填写。
 * 填term_id 而非 slug：slug 随命名习惯变化，写死不便移植。
 *
 * @return array<int,int> term_id 列表。
 */
function jinyu_thin_archive_kept_term_ids(): array {
	$raw = (array) jinyu_companion_get_option( 'thin_archive_keep_term_ids', '' );

	$parts = preg_split( '/[^0-9]+/', is_array( $raw ) ? implode( ',', $raw ) : (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

	$ids = array_map( 'absint', is_array( $parts ) ? $parts : array() );

	$ids = (array) apply_filters( 'jinyu_thin_archive_kept_term_ids', $ids );

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * 判断某个分类项是否属于应 noindex 的薄页。
 *
 * @param WP_Term $term      待判断的分类项。
 * @param int     $min_items 阈值。
 * @return bool true 表示应 noindex。
 */
function jinyu_thin_archive_is_thin( WP_Term $term, int $min_items ): bool {
	if ( in_array( (int) $term->term_id, jinyu_thin_archive_kept_term_ids(), true ) ) {
		return false;
	}

	/**
	 * 过滤「该分类项是否按薄页处理」。
	 *
	 * 站点可对特定分类项做例外处理（例如某标签虽是单篇，但承担了落地页价值）。
	 *
	 * @param bool    $is_thin   默认判定结果。
	 * @param WP_Term $term      分类项对象。
	 * @param int     $min_items 当前阈值。
	 */
	return (bool) apply_filters( 'jinyu_thin_archive_is_thin', (int) $term->count < $min_items, $term, $min_items );
}

/**
 * 当前被请求的归档页是否应 noindex。
 *
 * @return bool
 */
function jinyu_thin_archive_current_is_thin(): bool {
	if ( ! jinyu_thin_archive_enabled() ) {
		return false;
	}

	$term = get_queried_object();

	if ( ! $term instanceof WP_Term ) {
		return false;
	}

	$limits = jinyu_thin_archive_limits();

	if ( ! isset( $limits[ $term->taxonomy ] ) ) {
		return false;
	}

	return jinyu_thin_archive_is_thin( $term, $limits[ $term->taxonomy ] );
}

/**
 * robots 层闸门：薄归档页输出 noindex, follow。
 *
 * 优先级 20：晚于内核的默认值写入，确保覆盖内核对归档页的默认处理。
 *
 * @param array<string,bool> $robots robots 指令表。
 * @return array<string,bool>
 */
function jinyu_thin_archive_filter_robots( $robots ) {
	if ( is_singular( 'post' ) ) {
		$robots['index']  = true;
		$robots['follow'] = true;

		return $robots;
	}

	if ( jinyu_thin_archive_current_is_thin() ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;

		unset( $robots['index'] );
	}

	return $robots;
}
add_filter( 'wp_robots', 'jinyu_thin_archive_filter_robots', 20 );

/**
 * 站点地图层闸门：把薄归档页从 XML 中剔除。
 *
 * 内核生成 XML 时不读 wp_robots 的输出，故本层与上面的 robots 层成对使用。
 * 返回空数组即从该sitemap 中去掉这一条。
 *
 * @param array   $entry    当前条目的sitemap 数据。
 * @param int     $term_id  分类项 ID。
 * @param string  $taxonomy 分类法。
 * @param WP_Term $term     分类项对象。
 * @return array
 */
function jinyu_thin_archive_filter_sitemap_entry( $entry, $term_id, $taxonomy, $term ) {
	if ( ! jinyu_thin_archive_enabled() || ! $term instanceof WP_Term ) {
		return $entry;
	}

	$limits = jinyu_thin_archive_limits();

	if ( ! isset( $limits[ $taxonomy ] ) ) {
		return $entry;
	}

	return jinyu_thin_archive_is_thin( $term, $limits[ $taxonomy ] ) ? array() : $entry;
}
add_filter( 'wp_sitemaps_taxonomies_entry', 'jinyu_thin_archive_filter_sitemap_entry', 10, 4 );
