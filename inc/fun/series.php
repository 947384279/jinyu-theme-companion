<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 文章系列（Series）自定义分类法
 * - 非层级（类似标签），用于把同一主题/连载的多篇文章归到一组
 * - 提供 jinyu_get_series_posts() 取同系列文章列表（按发布时间升序），供单篇显示「上一篇/下一篇」
 */

add_action('init', function () {
    register_taxonomy('jinyu_series', 'post', [
        'labels'            => [
            'name'                       => __('文章系列', 'jinyu-theme-companion'),
            'singular_name'              => __('系列', 'jinyu-theme-companion'),
            'search_items'               => __('搜索系列', 'jinyu-theme-companion'),
            'popular_items'              => __('热门系列', 'jinyu-theme-companion'),
            'all_items'                  => __('全部系列', 'jinyu-theme-companion'),
            'edit_item'                  => __('编辑系列', 'jinyu-theme-companion'),
            'update_item'                => __('更新系列', 'jinyu-theme-companion'),
            'add_new_item'               => __('新建系列', 'jinyu-theme-companion'),
            'new_item_name'              => __('新系列名', 'jinyu-theme-companion'),
            'separate_items_with_commas' => __('用逗号分隔多个系列', 'jinyu-theme-companion'),
            'add_or_remove_items'        => __('添加或移除系列', 'jinyu-theme-companion'),
            'menu_name'                   => __('文章系列', 'jinyu-theme-companion'),
        ],
        'public'            => true,
        'hierarchical'      => false,
        'show_ui'           => true,
        'show_in_nav_menus' => false,
        'show_tagcloud'      => false,
        'rewrite'           => ['slug' => 'series', 'with_front' => false],
    ]);
});

// 新分类法注册后刷新一次重写规则，确保 /series/ 归档页可访问（仅在版本变更时执行一次）
add_action('init', function () {
    if (get_option('jinyu_series_flush') !== JINYU_CUR_VER) {
        flush_rewrite_rules();
        update_option('jinyu_series_flush', JINYU_CUR_VER);
    }
}, 20);

/**
 * 取某系列的文章 ID 列表（发布时间升序）。
 * 按 term 级缓存：列表页多篇文章同属一个系列时只查一次，避免逐篇重复查询。
 */
function jinyu_series_ids(int $term_id): array
{
    if ($term_id <= 0) return [];

    $key = 'series_ids_' . $term_id;
    $ids = jinyu_cache_get($key);
    if (is_array($ids)) return $ids;

    $q = new WP_Query([
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            ['taxonomy' => 'jinyu_series', 'field' => 'term_id', 'terms' => $term_id],
        ],
    ]);

    $ids = array_map('intval', (array) $q->posts);
    jinyu_cache_set($key, $ids, HOUR_IN_SECONDS);
    return $ids;
}

/**
 * 当前文章在所属系列中的位置（列表卡片徽章用）。
 * 只做 1 次 term 查询 + 共享的 ID 列表缓存，不额外查库内容。
 * @return array{term:WP_Term,index:int,total:int}|false
 */
function jinyu_series_badge(int $post_id = 0)
{
    $post_id = $post_id ?: (int) get_the_ID();
    if (!$post_id) return false;

    $terms = get_the_terms($post_id, 'jinyu_series');
    if (empty($terms) || is_wp_error($terms)) return false;

    $term = $terms[0];
    $ids  = jinyu_series_ids((int) $term->term_id);
    $idx  = array_search($post_id, $ids, true);
    if ($idx === false) return false;

    return ['term' => $term, 'index' => $idx, 'total' => count($ids)];
}

/**
 * 取当前文章所属系列的文章列表（按发布时间升序）。
 * @return array|false ['term'=>WP_Term, 'posts'=>int[], 'prev'=>int, 'next'=>int] 或 false
 */
function jinyu_get_series_posts($post_id = 0)
{
    $post_id = (int) ($post_id ?: get_the_ID());
    if (!$post_id) return false;

    $key = 'series_' . $post_id;
    $cached = jinyu_cache_get($key);
    if (is_array($cached)) {
        return $cached;
    }

    $terms = get_the_terms($post_id, 'jinyu_series');
    if (empty($terms) || is_wp_error($terms)) return false;

    $term = $terms[0];
    $ids  = jinyu_series_ids((int) $term->term_id);
    if (empty($ids)) return false;

    $idx = array_search($post_id, $ids, true);
    if ($idx === false) return false;

    $result = [
        'term'    => $term,
        'posts'   => $ids,
        'index'   => $idx,
        'total'   => count($ids),
        'prev'    => ($idx > 0) ? $ids[$idx - 1] : 0,
        'next'    => ($idx < count($ids) - 1) ? $ids[$idx + 1] : 0,
    ];
    jinyu_cache_set($key, $result, HOUR_IN_SECONDS);
    return $result;
}
