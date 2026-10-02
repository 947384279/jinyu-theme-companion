<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 时光圈（说说 / 动态）自定义文章类型
 * 使「说说」成为独立数据流，
 * 可在后台「时光圈」菜单发布，前端由主题 pages/template-moments.php 以时间线展示。
 *
 * 命名：注册名带 jinyu_ 前缀（wp.org 要求注册的文章类型名至少 4 字符且唯一，
 * 裸名 moments 属于通用词，容易与其他插件冲突）。前台 URL 保持 /moments/ 不变，
 * 存量数据由下方一次性迁移从旧注册名 `moments` 迁到新注册名。
 *
 * 长度硬约束：WP_Post_Type::set_props() 对 post_type 键有 20 字符上限，
 * 超限时 register_post_type() 返回 WP_Error 且不注册（不报错、静默失效）。
 * 故不可用 jinyu_companion_moments（23 字符），只能用 jinyu_moments（13 字符）。
 */

const JINYU_COMPANION_MOMENTS_POST_TYPE = 'jinyu_moments';

function jinyu_moments_init(): void {
    $name = __( '时光圈', 'jinyu-theme-companion' );
    register_post_type(
        JINYU_COMPANION_MOMENTS_POST_TYPE,
        [
			'labels' => [
				'name'               => $name,
				'singular_name'      => $name,
				// translators: Placeholder values are substituted at runtime.
										'add_new'            => sprintf( __( '发表%s', 'jinyu-theme-companion' ), $name ),
				// translators: Placeholder values are substituted at runtime.
										'add_new_item'       => sprintf( __( '发表%s', 'jinyu-theme-companion' ), $name ),
				// translators: Placeholder values are substituted at runtime.
										'edit_item'          => sprintf( __( '编辑%s', 'jinyu-theme-companion' ), $name ),
				// translators: Placeholder values are substituted at runtime.
										'new_item'           => sprintf( __( '新%s', 'jinyu-theme-companion' ), $name ),
				// translators: Placeholder values are substituted at runtime.
										'view_item'          => sprintf( __( '查看%s', 'jinyu-theme-companion' ), $name ),
				// translators: Placeholder values are substituted at runtime.
										'search_items'       => sprintf( __( '搜索%s', 'jinyu-theme-companion' ), $name ),
				// translators: Placeholder values are substituted at runtime.
										'not_found'          => sprintf( __( '暂无%s', 'jinyu-theme-companion' ), $name ),
				// translators: Placeholder values are substituted at runtime.
										'not_found_in_trash' => sprintf( __( '没有已遗弃的%s', 'jinyu-theme-companion' ), $name ),
				'menu_name'          => $name,
			],
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'query_var'           => true,
			'rewrite'             => [
				'slug' => 'moments',
				'with_front' => false,
			],
			'capability_type'     => 'post',
			'has_archive'         => true,
			'hierarchical'        => false,
			'menu_icon'           => 'dashicons-format-status',
			'supports'            => [ 'title', 'editor', 'author', 'comments', 'thumbnail' ],
			'show_in_rest'        => true,
		]
    );

    // 单条说说使用 /moments/{id}.html 的固定链接
    add_rewrite_rule(
        'moments/([0-9]+)\.html$',
        'index.php?post_type=' . JINYU_COMPANION_MOMENTS_POST_TYPE . '&p=$matches[1]',
        'top'
    );
}
add_action( 'init', 'jinyu_moments_init' );

/**
 * 一次性迁移：把历史 `moments` 文章改挂到新的注册名。
 * 用核心 set_post_type() 而非直接写库，避免绕过缓存 / 钩子。
 *
 * 钩在 init(20)：jinyu_moments_init() 在 init(10) 注册 CPT，迁移必须晚于它，
 * 否则目标注册名不存在，set_post_type() 静默失败。
 * 迁移成功后 flush 一次重写规则：post_type 键变化会改变 rewrite 规则的 query var。
 */
function jinyu_moments_migrate_legacy(): void {
    if ( get_option( 'jinyu_moments_migrated' ) ) {
        return;
    }

    // 不显式传 suppress_filters：get_posts() 的默认值本就是 true，
    // 而 WPCS/PCP 的 WPQueryParams.SuppressFilters 嗅探对显式传值报 ERROR 级错误。
    $legacy = get_posts(
        [
			'post_type'   => 'moments',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		]
    );

    foreach ( $legacy as $post_id ) {
        set_post_type( (int) $post_id, JINYU_COMPANION_MOMENTS_POST_TYPE );
    }

    update_option( 'jinyu_moments_migrated', 1, false );
    flush_rewrite_rules();
}
add_action( 'init', 'jinyu_moments_migrate_legacy', 20 );

function jinyu_moments_link( string $link, \WP_Post $post ): string {
    if ( $post->post_type === JINYU_COMPANION_MOMENTS_POST_TYPE ) {
        return home_url( 'moments/' . $post->ID . '.html' );
    }
    return $link;
}
add_filter( 'post_type_link', 'jinyu_moments_link', 1, 2 );

// 主题启用 / 切换时刷新重写规则，使 /moments/ 归档与 .html 单页生效
function jinyu_moments_flush(): void {
    jinyu_moments_init();
    flush_rewrite_rules();
}
add_action( 'after_switch_theme', 'jinyu_moments_flush' );
