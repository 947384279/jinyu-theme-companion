<?php
/**
 * 卸载清理：删除插件选项与自有数据表，不留残留。
 * 安全策略：仅当 multisite 单站/主站，且使用 WP 标准卸载入口时执行；
 * 只处理本插件命名空间（jinyu_companion_* / jinyu_ 前缀表），不碰任何其它数据。
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* 选项：设置 + 内部标记（epoch 代际 / flush 标记 / 迁移标记 / 临时缓存） */
$options = [
	'jinyu_companion_settings',
	'jinyu_companion_flush_rewrite',
	'jinyu_companion_storage_migrated',
	'jinyu_page_cache_epoch',
];

/* 通知表由 comment-notify 模块定义，常量表名以实际 DB 为准 */
global $wpdb;
$tables = [
	$wpdb->prefix . 'jinyu_stats',
	$wpdb->prefix . 'jinyu_notify',
	$wpdb->prefix . 'jinyu_storage_tasks',
];

/* Multisite：清理所有站点（表名按各站前缀） */
if ( is_multisite() ) {
	$site_ids = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		jinyu_companion_uninstall_site( $options, $tables );
		restore_current_blog();
	}
} else {
	jinyu_companion_uninstall_site( $options, $tables );
}

/**
 * 清理当前站点的选项、transient 与数据表。
 *
 * @param string[] $options 选项名列表。
 * @param string[] $tables  数据表全名列表。
 */
function jinyu_companion_uninstall_site( array $options, array $tables ): void {
	global $wpdb;

	foreach ( $options as $name ) {
		delete_option( $name );
	}

	// 残留 transient（限流 / 验证码 / 海报缓存）：memcached 下删库表无意义，按前缀清一次。
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_jyc\_rl\_%'
		    OR option_name LIKE '\_transient\_jy\_captcha\_%'
		    OR option_name LIKE '\_transient\_jinyu\_poster\_%'
		    OR option_name LIKE '\_transient\_timeout\_jy\_captcha\_%'"
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名来自本文件硬编码，非用户输入
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}
