<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 隐私合规：个人数据导出 / 擦除（WordPress 核心隐私工具对接）。
 *
 * 覆盖本插件写入的全部用户级数据：
 *  - 三方登录绑定：jinyu_oauth_{platform}_id / jinyu_oauth_{platform}_avatar（github/gitee/qq/apple）
 *  - 社交关系：jinyu_following / jinyu_followers / jinyu_following_terms
 *  - 登录安全标记：jinyu_sl_no_password
 *
 * 【擦除一致性】用户 A 被擦除时，关注关系是双向的——
 * 其他用户的 jinyu_following / jinyu_followers 数组里可能仍含 A 的 ID，
 * 必须同步摘除，否则会出现「关注了不存在的用户」的悬挂引用。
 *
 * 【性能口径】含关注关系的用户属低频小集合，扫描用 get_users + 内存过滤
 * （序列化数组无法走 SQL 精确匹配）；被擦用户无任何关系数据时零扫描。
 */

/** 本插件写入的用户 meta 键清单。 */
function jinyu_privacy_meta_keys(): array {
	$keys = array(
		'jinyu_following',
		'jinyu_followers',
		'jinyu_following_terms',
		'jinyu_sl_no_password',
	);
	foreach ( array( 'github', 'gitee', 'qq', 'apple' ) as $platform ) {
		$keys[] = 'jinyu_oauth_' . $platform . '_id';
		$keys[] = 'jinyu_oauth_' . $platform . '_avatar';
	}
	return $keys;
}

/** 三方登录展示名。 */
function jinyu_privacy_platform_label( string $platform ): string {
	$labels = array(
		'github' => 'GitHub',
		'gitee'  => 'Gitee',
		'qq'     => 'QQ',
		'apple'  => 'Apple',
	);
	return $labels[ $platform ] ?? $platform;
}

/**
 * 导出器注册。
 */
add_filter( 'wp_privacy_personal_data_exporters', 'jinyu_privacy_register_exporter' );
function jinyu_privacy_register_exporter( array $exporters ): array {
	$exporters['jinyu-theme-companion'] = array(
		'exporter_friendly_name' => __( '金玉增强（绑定账号与社交关系）', 'jinyu-theme-companion' ),
		'callback'               => 'jinyu_privacy_export_user_data',
	);
	return $exporters;
}

/**
 * 导出指定用户的本插件数据。
 *
 * @return array{data: array<int, array{group_id:string, group_label:string, group_description:string, items:array<int, array{name:string, value:string}>}>}
 */
function jinyu_privacy_export_user_data( string $email, int $page = 1 ): array {
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return array( 'data' => array(), 'done' => true );
	}
	$items = array();
	foreach ( array( 'github', 'gitee', 'qq', 'apple' ) as $platform ) {
		$openid = get_user_meta( $user->ID, 'jinyu_oauth_' . $platform . '_id', true );
		if ( $openid ) {
			$items[] = array(
				'name'  => jinyu_privacy_platform_label( $platform ) . ' — 绑定 ID',
				'value' => (string) $openid,
			);
		}
		$avatar = get_user_meta( $user->ID, 'jinyu_oauth_' . $platform . '_avatar', true );
		if ( $avatar ) {
			$items[] = array(
				'name'  => jinyu_privacy_platform_label( $platform ) . ' — 头像 URL',
				'value' => (string) $avatar,
			);
		}
	}
	$following = get_user_meta( $user->ID, 'jinyu_following', true );
	if ( is_array( $following ) && $following ) {
		$items[] = array(
			'name'  => __( '正在关注的用户', 'jinyu-theme-companion' ),
			'value' => implode( ', ', $following ),
		);
	}
	$followers = get_user_meta( $user->ID, 'jinyu_followers', true );
	if ( is_array( $followers ) && $followers ) {
		$items[] = array(
			'name'  => __( '关注该用户的用户', 'jinyu-theme-companion' ),
			'value' => implode( ', ', $followers ),
		);
	}
	$terms = get_user_meta( $user->ID, 'jinyu_following_terms', true );
	if ( is_array( $terms ) && $terms ) {
		$items[] = array(
			'name'  => __( '正在关注的分类/标签', 'jinyu-theme-companion' ),
			'value' => implode( ', ', $terms ),
		);
	}

	$data = array();
	if ( $items ) {
		$data[] = array(
			'group_id'          => 'jinyu_companion',
			'group_label'       => __( '金玉增强', 'jinyu-theme-companion' ),
			'group_description' => __( '第三方登录绑定与关注关系数据。', 'jinyu-theme-companion' ),
			'items'             => $items,
		);
	}
	return array( 'data' => $data, 'done' => true );
}

/**
 * 擦除器注册。
 */
add_filter( 'wp_privacy_personal_data_erasers', 'jinyu_privacy_register_eraser' );
function jinyu_privacy_register_eraser( array $erasers ): array {
	$erasers['jinyu-theme-companion'] = array(
		'eraser_friendly_name' => __( '金玉增强（绑定账号与社交关系）', 'jinyu-theme-companion' ),
		'callback'             => 'jinyu_privacy_erase_user_data',
	);
	return $erasers;
}

/**
 * 把 $uid 从「其他用户的关注关系数组」中摘除。
 */
function jinyu_privacy_detach_from_others( int $uid ): void {
	$users = get_users(
		array(
			'fields'     => 'ID',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 关系数组为序列化值，无法 SQL 精确匹配；含关注数据的用户是小集合。
				'relation' => 'OR',
				array( 'key' => 'jinyu_following', 'compare' => 'EXISTS' ),
				array( 'key' => 'jinyu_followers', 'compare' => 'EXISTS' ),
			),
			'number'     => 500,
		)
	);
	foreach ( $users as $other ) {
		$other = (int) $other;
		if ( $other === $uid ) {
			continue;
		}
		$following = get_user_meta( $other, 'jinyu_following', true );
		if ( is_array( $following ) && in_array( $uid, array_map( 'intval', $following ), true ) ) {
			$remaining = array_values( array_diff( array_map( 'intval', $following ), array( $uid ) ) );
			update_user_meta( $other, 'jinyu_following', $remaining );
		}
		$followers = get_user_meta( $other, 'jinyu_followers', true );
		if ( is_array( $followers ) && in_array( $uid, array_map( 'intval', $followers ), true ) ) {
			$remaining = array_values( array_diff( array_map( 'intval', $followers ), array( $uid ) ) );
			update_user_meta( $other, 'jinyu_followers', $remaining );
		}
	}
}

/**
 * 擦除指定用户的本插件数据。
 *
 * @return array{items_removed:bool, items_retained:bool, messages:array<int,string>, done:bool}
 */
function jinyu_privacy_erase_user_data( string $email, int $page = 1 ): array {
	$user  = get_user_by( 'email', $email );
	$reply = array(
		'items_removed'  => false,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
	if ( ! $user ) {
		return $reply;
	}
	$uid = (int) $user->ID;

	jinyu_privacy_detach_from_others( $uid );

	foreach ( jinyu_privacy_meta_keys() as $key ) {
		if ( metadata_exists( 'user', $uid, $key ) ) {
			delete_user_meta( $uid, $key );
			$reply['items_removed'] = true;
		}
	}
	return $reply;
}
