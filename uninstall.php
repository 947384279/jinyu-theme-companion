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
	'jinyu_companion_push_log',
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

	// 卸载时清理本插件部署的对象缓存 drop-in：仅删除带本插件标记的文件，
	// 外部部署的 object-cache.php 一律保留，避免误删用户自有缓存配置。
	$jinyu_oc = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/object-cache.php';
	if ( '' !== $jinyu_oc && is_file( $jinyu_oc ) ) {
		$head = (string) @file_get_contents( $jinyu_oc, false, null, 0, 2048 );
		if ( false !== strpos( $head, 'JINYU_DROPIN_MARKER:jinyu-memcached-object-cache' ) ) {
			@wp_delete_file( $jinyu_oc );
		}
	}

	// 残留 transient（限流 / 验证码 / 海报缓存）：memcached 下删库表无意义，按前缀清一次。
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_jyc\_rl\_%'
		    OR option_name LIKE '\_transient\_jy\_captcha\_%'
		    OR option_name LIKE '\_transient\_jinyu\_poster\_%'
		    OR option_name LIKE '\_transient\_timeout\_jy\_captcha\_%'"
	);

	/* 图片水印的残留清理：
	 * 1) 附件上的水印签名 meta，删掉插件后就是没人认识的空字段；
	 * 2) 水印限流与批量任务的 transient（限流键是 jyc_wm_rl_，不在上面的通用前缀里）；
	 * 3) uploads 里的 xxx-jywmo.* 孤儿备份——按设计它们只是水印前的原图替身，
	 *    插件卸载后没有任何东西再引用它们，留着就是纯占地方。 */
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_jinyu_wm_sig' ], [ '%s' ] );
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_jinyu_wm_files' ], [ '%s' ] );
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_jyc\_wm\_rl\_%'
		    OR option_name LIKE '\_transient\_timeout\_jyc\_wm\_rl\_%'
		    OR option_name LIKE '\_transient\_jinyu\_wm\_task\_%'
		    OR option_name LIKE '\_transient\_timeout\_jinyu\_wm\_task\_%'"
	);
	jinyu_companion_uninstall_wm_files();

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名来自本文件硬编码，非用户输入
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}

/**
 * 清掉 uploads 目录里水印留下的 -jywmo 备份孤儿文件。
 * 只删本插件造的文件（文件名形如 xxx-jywmo.jpg），且限定在 uploads 之内。
 */
function jinyu_companion_uninstall_wm_files(): void {
	$uploads = wp_upload_dir();
	$dir     = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
	if ( '' === $dir || ! is_dir( $dir ) || ! function_exists( 'glob' ) ) {
		return;
	}
	// 年 / 月分目录最多再深两层，够覆盖绝大多数站点
	$patterns = [
		$dir . '/*-jywmo.*',
		$dir . '/*/*-jywmo.*',
		$dir . '/*/*/*-jywmo.*',
	];
	$seen     = [];
	foreach ( $patterns as $pattern ) {
		foreach ( (array) glob( $pattern ) as $path ) {
			if ( ! is_file( $path ) || isset( $seen[ $path ] ) ) {
				continue;
			}
			$seen[ $path ] = true;
			@wp_delete_file( $path );
		}
	}
}
