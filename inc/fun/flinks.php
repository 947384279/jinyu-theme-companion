<?php
/**
 * 友情链接：jy_link 自定义文章类型 + 响应主题的 jinyu_flink_items 广播。
 *
 * 原属主题 inc/link.php（v1.1.0 解耦时迁出到私有插件）。主题现为纯呈现层，
 * 只经 apply_filters( 'jinyu_flink_items', [] ) 声明「我需要友链数据」，
 * 本模块作为数据提供方应答：注册 CPT 后台管理 + 读取发布状态的友链经过滤器回传。
 * 主题侧另有 WP 原生书签（bookmarks）兜底，本模块无数据时主题自会回落。
 *
 * 解耦约束：不探测任何主题函数；CPT 注册用 post_type_exists 守卫，
 * 私有插件（wordpress-plugin-jinyu）若在场注册同名 CPT，双方均静默让位。
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
--------------------------------------------------------------------------
 * CPT / 分类法注册：后台「友情链接」菜单，数据模型与主题历史版本完全一致
 * （meta：jy_link_url / jy_link_desc；分类法：jy_link_cat）。
 * ------------------------------------------------------------------------ */
add_action(
	'init',
	static function (): void {
		if ( ! post_type_exists( 'jy_link' ) ) {
			register_post_type(
				'jy_link',
				[
					'labels'        => [
						'name'          => __( '友情链接', 'jinyu-theme-companion' ),
						'singular_name' => __( '链接', 'jinyu-theme-companion' ),
						'menu_name'     => __( '友情链接', 'jinyu-theme-companion' ),
					],
					'public'        => false,
					'show_ui'       => true,
					'show_in_menu'  => true,
					'menu_icon'     => 'dashicons-admin-links',
					'show_in_rest'  => false,
					'supports'      => [ 'title', 'thumbnail' ],
					'menu_position' => 56,
				]
			);
		}
		if ( ! taxonomy_exists( 'jy_link_cat' ) ) {
			register_taxonomy(
				'jy_link_cat',
				'jy_link',
				[
					'label'        => __( '链接分类', 'jinyu-theme-companion' ),
					'public'       => false,
					'show_ui'      => true,
					'hierarchical' => true,
					'show_in_rest' => false,
				]
			);
		}
	}
);

/*
--------------------------------------------------------------------------
 * 链接信息 meta box：URL + 描述（描述为历史数据字段，主题当前仅消费 title/url）。
 * ------------------------------------------------------------------------ */
add_action(
	'add_meta_boxes',
	static function (): void {
		add_meta_box( 'jy_link_meta', __( '链接信息', 'jinyu-theme-companion' ), 'jinyu_companion_link_meta_box', 'jy_link', 'normal', 'high' );
	}
);

/**
 * 渲染链接信息 meta box。
 *
 * @param WP_Post $post 当前文章。
 * @return void
 */
function jinyu_companion_link_meta_box( $post ): void {
	wp_nonce_field( 'jinyu_companion_link_meta', 'jinyu_companion_link_nonce' );
	$url  = (string) get_post_meta( $post->ID, 'jy_link_url', true );
	$desc = (string) get_post_meta( $post->ID, 'jy_link_desc', true );
	echo '<p>' . esc_html__( 'URL', 'jinyu-theme-companion' ) . ' <input type="text" name="jy_link_url" value="' . esc_attr( $url ) . '" class="widefat"></p>';
	echo '<p>' . esc_html__( '描述', 'jinyu-theme-companion' ) . ' <textarea name="jy_link_desc" rows="2" class="widefat">' . esc_textarea( $desc ) . '</textarea></p>';
}

add_action(
	'save_post_jy_link',
	static function ( $post_id ): void {
		if ( ! isset( $_POST['jinyu_companion_link_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['jinyu_companion_link_nonce'] ), 'jinyu_companion_link_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['jy_link_url'] ) ) {
			update_post_meta( $post_id, 'jy_link_url', esc_url_raw( wp_unslash( $_POST['jy_link_url'] ) ) );
		}
		if ( isset( $_POST['jy_link_desc'] ) ) {
			update_post_meta( $post_id, 'jy_link_desc', sanitize_textarea_field( wp_unslash( $_POST['jy_link_desc'] ) ) );
		}
	}
);

/*
--------------------------------------------------------------------------
 * 响应主题广播：apply_filters( 'jinyu_flink_items', [] ) → 回传发布状态的友链。
 * 结果缓存 1 小时（transient，有对象缓存时自动走内存），友链增删改时精确失效。
 * 主题侧自行截取前 6 条，这里同样限量，避免大站友链过多时缓存对象过大。
 * ------------------------------------------------------------------------ */
add_filter( 'jinyu_flink_items', 'jinyu_companion_flink_items' );

/**
 * 读取友链数据（带缓存）。
 *
 * @param array $items 主题给出的默认值（恒为空数组）。
 * @return array [['title' => ..., 'url' => ...], ...]
 */
function jinyu_companion_flink_items( $items ): array {
	$cached = get_transient( 'jinyu_companion_flink_items' );
	if ( is_array( $cached ) ) {
		return array_merge( (array) $items, $cached );
	}

	$posts = get_posts(
		[
			'post_type'        => 'jy_link',
			'post_status'      => 'publish',
			'posts_per_page'   => 6,
			'orderby'          => 'menu_order title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		]
	);

	$out = [];
	foreach ( $posts as $p ) {
		$url = (string) get_post_meta( $p->ID, 'jy_link_url', true );
		if ( '' === $url ) {
			continue;
		}
		$out[] = [
			'title' => (string) $p->post_title,
			'url'   => $url,
		];
	}
	set_transient( 'jinyu_companion_flink_items', $out, HOUR_IN_SECONDS );

	return array_merge( (array) $items, $out );
}

// 友链增删改 / 状态流转时精确失效缓存（含清空回收站）。
add_action(
	'save_post_jy_link',
	static function (): void {
		delete_transient( 'jinyu_companion_flink_items' );
	}
);
add_action(
	'delete_post',
	static function ( $post_id ): void {
		if ( 'jy_link' === get_post_type( $post_id ) ) {
			delete_transient( 'jinyu_companion_flink_items' );
		}
	}
);
