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

/*
 * 选项：设置主键 + 各模块的独立键。
 * 原则：凡是本插件写入 wp_options 的键都列进来，卸载后不留残留。
 * 凡是 jinyu_companion_settings 数组的内部字段（page_cache_* / seo_* / smtp_* / storage_* 等）
 * 不在此列——它们随主键一并删除。
 */
$jinyu_uninstall_options = [
	// 主设置 + 迁移 / 代际标记
	'jinyu_companion_settings',
	'jinyu_companion_flush_rewrite',
	'jinyu_companion_smtp_migrated',
	'jinyu_companion_storage_migrated',
	'jinyu_moments_migrated',
	'jinyu_page_cache_epoch',
	'jinyu_oc_dropin_synced_ver',
	// 性能中心开关键（新键 + 迁移标记；jinyu_perf_options / jyc_perf_options 旧键
	// 由 jinyu_perf_maybe_migrate_options() 在迁移时删除，此处兜底再删一次）
	'jinyu_perf_options_v2',
	'jinyu_perf_options_v2_migrated',
	'jinyu_perf_options',
	'jyc_perf_options',
	// 页面缓存运行期状态（探测 / GC / 封禁原因，删后下次请求自动重建）
	'jinyu_page_cache_gc_at',
	'jinyu_page_cache_probe_at',
	'jinyu_page_cache_noticed_at',
	'jinyu_page_cache_blocked_at',
	'jinyu_page_cache_blocked_reason',
	// 表结构版本号（删后下次请求按需重建）
	'jinyu_notify_dbver',
	'jinyu_storage_dbver',
	// rewrite 规则刷新标记
	'jinyu_series_flush',
	'jinyu_indexnow_rewrite_ver',
	'jinyu_llms_rewrite_ver',
	'jinyu_llms_full_rewrite_ver',
	// IndexNow 密钥
	'jinyu_indexnow_key',
	// 推送记录
	'jinyu_companion_push_log',
	// Web Vitals 真实访客统计
	'jinyu_web_vitals_stats',
	// 微信 JS-SDK 票据缓存（含 access_token，属临时数据）
	'jinyu_wechat_access_token',
	'jinyu_wechat_ticket',
];

/* 通知表由 comment-notify 模块定义，常量表名以实际 DB 为准 */
global $wpdb;
$jinyu_uninstall_tables = [
	$wpdb->prefix . 'jinyu_stats',
	$wpdb->prefix . 'jinyu_notify',
	$wpdb->prefix . 'jinyu_storage_tasks',
];

/* Multisite：清理所有站点（表名按各站前缀） */
if ( is_multisite() ) {
	$jinyu_uninstall_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $jinyu_uninstall_site_ids as $jinyu_uninstall_site_id ) {
		switch_to_blog( $jinyu_uninstall_site_id );
		jinyu_companion_uninstall_site( $jinyu_uninstall_options, $jinyu_uninstall_tables );
		restore_current_blog();
	}
} else {
	jinyu_companion_uninstall_site( $jinyu_uninstall_options, $jinyu_uninstall_tables );
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

	/*
	 * 残留 transient 一律按 `jinyu_` / `jyc_` 统一前缀清一次。
	 *
	 * 此前是逐个前缀枚举（限流 / 验证码 / 海报 / 水印任务……），漏掉过好几项：
	 * llms 索引缓存（两个可达数 MB 的 transient）、no-category 规则标记、
	 * 海报清扫标记、storage 任务清理标记。逐个枚举的必然结果是「新增一个键就漏一次」。
	 * 统一前缀覆盖后，将来新增的 jinyu_ 键自动被包含，不需要回来改这个文件。
	 *
	 * ⚠️ 必须排除 jinyu_options：那是**主题**的配置项，与本插件同前缀。
	 * 通配清理绝不能连坐别人的数据 —— 卸载本插件不该让主题丢配置。
	 *
	 * LIKE 里的下划线是单字符通配，要转义成字面量（写 jinyu\_% 而非 jinyu_%）。
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 卸载清理必须直查插件自建表与元数据，一次性动作
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE (
		        option_name LIKE '\_transient\_jinyu\_%'
		     OR option_name LIKE '\_transient\_timeout\_jinyu\_%'
		     OR option_name LIKE '\_transient\_jyc\_%'
		     OR option_name LIKE '\_transient\_timeout\_jyc\_%'
		     OR option_name LIKE '\_transient\_jy\_%'
		     OR option_name LIKE '\_transient\_timeout\_jy\_%'
		     OR option_name LIKE '\_site\_transient\_jinyu\_%'
		     OR option_name LIKE '\_site\_transient\_timeout\_jinyu\_%'
		     OR option_name LIKE '\_site\_transient\_jyc\_%'
		     OR option_name LIKE '\_site\_transient\_timeout\_jyc\_%'
		 )
		 AND option_name NOT LIKE '%\_transient\_jinyu\_options'
		 AND option_name NOT LIKE '%jinyu\_options%'"
	);

	/*
	 * 用户级残留：逐键列举，不用 `jinyu_%` 通配。
	 * 通配会连坐——本插件与其它同前缀实现（如独立部署的增强插件）共享 jinyu_ 命名空间，
	 * 卸载本插件不该删掉别人的数据。宁可漏删一个键，也不能误删用户数据。
	 */
	foreach (
		array(
			'jinyu_followers',
			'jinyu_following',
			'jinyu_following_terms',
			'jinyu_sl_no_password',
			'jinyu_companion_theme_notice_dismissed',
		) as $jinyu_um_key
	) {
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 卸载清理必须直查插件自建表与元数据，一次性动作
		$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => $jinyu_um_key ], [ '%s' ] );
	}
	// 第三方登录的平台绑定键形如 jinyu_oauth_github / jinyu_oauth_avatar_github，按前缀清。
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 卸载清理必须直查插件自建表与元数据，一次性动作
	$wpdb->query(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'jinyu\_oauth\_%'"
	);

	/*
	 * 图片水印的残留清理：
	 * 1) 附件上的水印签名 meta，删掉插件后就是没人认识的空字段；
	 * 2) uploads 里的 xxx-jywmo.* 孤儿备份——按设计它们只是水印前的原图替身，
	 *    插件卸载后没有任何东西再引用它们，留着就是纯占地方。
	 * （transient 已由上面的统一前缀清理覆盖。）
	 */
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 卸载清理必须直查插件自建表与元数据，一次性动作
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_jinyu_wm_sig' ], [ '%s' ] );
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 卸载清理必须直查插件自建表与元数据，一次性动作
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => '_jinyu_wm_files' ], [ '%s' ] );
	jinyu_companion_uninstall_wm_files();

	// 整页缓存落地目录：wp-content/cache/jinyu/（仅删本插件前缀目录，不动其它缓存）。
	$jinyu_cache = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) . '/cache/jinyu';
	if ( '' !== $jinyu_cache && is_dir( $jinyu_cache ) ) {
		jinyu_companion_uninstall_dir( $jinyu_cache );
	}

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 表名来自本文件硬编码，非用户输入
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 卸载清理必须直查插件自建表与元数据，一次性动作
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

/**
 * 递归删除本插件缓存目录（仅限传入目录之内，不越界）。
 *
 * @param string $dir 待删除的目录绝对路径。
 * @return void
 */
function jinyu_companion_uninstall_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = @scandir( $dir );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) ) {
			jinyu_companion_uninstall_dir( $path );
		} else {
			@wp_delete_file( $path );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors -- 卸载时递归删除插件自有缓存目录，WP_Filesystem 在卸载上下文不可用
}
