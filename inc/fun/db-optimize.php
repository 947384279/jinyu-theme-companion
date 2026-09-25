<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 数据库优化（设置面板「性能 / 缓存」分区「数据库优化」入口）
 * 仅清理可安全删除的冗余数据，绝不触碰正常文章 / 评论 / 用户。
 * 校验：本插件设置 nonce（jinyu_companion_settings）+ manage_options 权限。
 */

add_action( 'wp_ajax_jinyu_db_optimize', 'jinyu_db_optimize' );

function jinyu_db_optimize() {
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( '权限不足', 'jinyu-theme-companion') );
	}

	global $wpdb;

	// 仅允许对当前站点的表做 OPTIMIZE，且限定为 WordPress 核心表前缀，防止越权操作。
	$items = [];

	/* 1. 文章修订版本（revision） */
	$deleted = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE p, pm FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s",
			'revision'
		)
	);
	$items['revisions'] = $deleted;

	/* 2. 自动草稿（auto-draft）与草稿中无内容残留 */
	$deleted = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE p, pm FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND p.post_status = %s",
			'post',
			'auto-draft'
		)
	);
	$items['auto_drafts'] = $deleted;

	/* 3. 垃圾评论（spam）与回收站评论（trash） */
	$deleted = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE c, cm FROM {$wpdb->comments} c LEFT JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID WHERE c.comment_approved IN (%s, %s)",
			'spam',
			'trash'
		)
	);
	$items['comments'] = $deleted;

	/* 4. 孤立 postmeta（post_id 已不存在） */
	$items['orphan_postmeta'] = jinyu_db_delete_orphan( $wpdb->postmeta, 'post_id', $wpdb->posts, 'ID' );

	/* 5. 孤立 commentmeta（comment_id 已不存在） */
	$items['orphan_commentmeta'] = jinyu_db_delete_orphan( $wpdb->commentmeta, 'comment_id', $wpdb->comments, 'comment_ID' );

	/* 6. 孤立 termmeta（term_id 已不存在） */
	if ( jinyu_db_table_exists( $wpdb->termmeta ) ) {
		$items['orphan_termmeta'] = jinyu_db_delete_orphan( $wpdb->termmeta, 'term_id', $wpdb->terms, 'term_id' );
	}

	/* 7. 过期 transient（含站点健康临时数据） */
	$items['expired_transients'] = jinyu_db_delete_expired_transients();

	/* 8. 优化数据表（回收空洞、降低碎片） */
	$optimized = jinyu_db_optimize_tables();
	$items['optimized_tables'] = $optimized;

	$total = 0;
	foreach ( $items as $k => $v ) {
		if ( $k === 'optimized_tables' ) {
			continue;
		}
		$total += (int) $v;
	}

	wp_send_json_success( [
		'msg'  => sprintf( __( '数据库优化完成：共清理 %1$d 条冗余记录，优化 %2$d 张表。', 'jinyu-theme-companion'), $total, $optimized ),
		'data' => $items,
	] );
}

/**
 * 删除子表中指向父表已不存在的孤儿行，返回删除行数。
 */
function jinyu_db_delete_orphan( string $child, string $child_fk, string $parent, string $parent_pk ): int {
	global $wpdb;
	// 用 LEFT JOIN ... IS NULL 一次性删除孤儿行（限定两张表同属本站前缀，安全）。
	return (int) $wpdb->query(
		"DELETE c FROM {$child} c LEFT JOIN {$parent} p ON p.{$parent_pk} = c.{$child_fk} WHERE p.{$parent_pk} IS NULL"
	);
}

/**
 * 删除已过期的 transient：_transient_timeout_* 时间已过即视为过期。
 * 过期项对应的 _transient_* 一并清理（避免残留）。
 */
function jinyu_db_delete_expired_transients(): int {
	global $wpdb;
	$now = time();
	// 先删超时标记，再删对应值；用单条语句按超时时间过滤，避免逐条 PHP 循环。
	$count = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE t, tv FROM {$wpdb->options} t
			 INNER JOIN {$wpdb->options} tv ON tv.option_name = REPLACE(t.option_name, '_transient_timeout_', '_transient_')
			 WHERE t.option_name LIKE %s AND t.option_value < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$now
		)
	);
	// 只删「已过期」瞬态：上面的 INNER JOIN 已覆盖本主题前缀（_transient_timeout_jinyu_*），
	// 故不再额外清理未过期瞬态，避免误删仍在用的整页缓存等有效数据。
	return $count;
}

/**
 * 对所有本站核心表执行 ANALYZE TABLE（更新统计信息；InnoDB 下 OPTIMIZE 会重建整表并锁表，故改用 ANALYZE）。
 */
function jinyu_db_optimize_tables(): int {
	global $wpdb;
	$tables = $wpdb->get_col( "SHOW TABLES LIKE " . $wpdb->prepare( '%s', $wpdb->prefix . '%' ) );
	if ( empty( $tables ) ) {
		return 0;
	}
	$done = 0;
	foreach ( $tables as $table ) {
		// 仅优化属于本站前缀的表，绝不越权操作其它库表。
		if ( strpos( $table, $wpdb->prefix ) !== 0 ) {
			continue;
		}
		$res = $wpdb->query( "ANALYZE TABLE " . esc_sql( $table ) );
		if ( $res !== false ) {
			$done++;
		}
	}
	return $done;
}

/**
 * 判断数据表是否存在（跨 MySQL/MariaDB 兼容）。
 */
function jinyu_db_table_exists( string $table ): bool {
	global $wpdb;
	$name = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $name === $table;
}

/* ───────────────────────── Autoload 瘦身 ───────────────────────── */

add_action( 'wp_ajax_jinyu_autoload_scan', 'jinyu_autoload_scan' );
add_action( 'wp_ajax_jinyu_autoload_fix', 'jinyu_autoload_fix' );

/**
 * 判定大选项的体积阈值：超过此值的 autoload 选项才进入扫描结果。
 */
function jinyu_autoload_min_size(): int {
	return 128 * 1024; // 128KB
}

/**
 * 受保护选项判定：核心启动必需 / 高频读取 / 本插件自身配置，绝不改 autoload。
 * 兜底原则：拿不准的一律视为受保护（宁可少优化，不可白屏）。
 */
function jinyu_autoload_protected( string $name ): bool {
	$exact = [
		'rewrite_rules', 'cron', 'active_plugins', 'active_sitewide_plugins', 'wp_user_roles',
		'template', 'stylesheet', 'siteurl', 'home', 'admin_email', 'new_admin_email',
		'users_can_register', 'blog_public', 'timezone_string', 'start_of_week',
		'permalink_structure', 'sidebars_widgets', 'recently_edited', 'uninstall_plugins',
		'can_compress_scripts', 'show_on_front', 'page_on_front', 'page_for_posts',
		'category_children', 'theme_switched', 'auto_updater.lock', 'db_version',
		'initial_db_version', 'upload_path', 'upload_url_path', 'wp_user_settings',
		'wpseo', 'wpseo_taxonomy_meta', 'wpseo_permalinks', 'wpseo_titles',
		'wpseo_social', 'wpseo_ms', 'wpseo_rss', 'wpseo_flush_rewrite',
	];
	if ( in_array( $name, $exact, true ) ) {
		return true;
	}
	// 前缀保护：小工具/主题定制器/Cron/瞬态/本插件与金玉主题配置（每请求读取）等。
	if ( preg_match( '/^(widget_|theme_mods_|_transient_|_site_transient_|jinyu_|wp_page_for_|finished_|recovery_|auto_update_|customize_)/', $name ) ) {
		return true;
	}
	return false;
}

/**
 * 「会进 autoload 预加载」的 SQL IN 片段。
 * 兼容双词表：WP < 6.6 用 yes/no；WP 6.6+ 用 on/off/auto/auto-on/auto-off。
 * auto = 核心按体积自动决定（大值实际不加载，但仍列出供参考）；auto-on = 强制加载。
 * 值全部为固定字面量，无注入面。
 */
function jinyu_autoload_sql_in(): string {
	return "autoload IN ('yes','on','auto','auto-on')";
}

/**
 * 「非 autoload」的 SQL IN 片段（undo 前置校验用）。
 */
function jinyu_autoload_sql_in_off(): string {
	return "autoload IN ('no','off','auto-off')";
}

/**
 * 扫描大体积 autoload 选项（> 128KB），供管理员判断是否改为按需加载。
 * 直接 SQL 聚合，不把大值读进内存。
 */
function jinyu_autoload_scan() {
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
	}

	global $wpdb;
	$min = jinyu_autoload_min_size();

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, LENGTH(option_value) AS sz
			 FROM {$wpdb->options}
			 WHERE " . jinyu_autoload_sql_in() . " AND LENGTH(option_value) > %d
			 ORDER BY sz DESC LIMIT 30",
			$min
		)
	);

	$total_size  = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE " . jinyu_autoload_sql_in() );
	$total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE " . jinyu_autoload_sql_in() );

	$items = [];
	foreach ( (array) $rows as $r ) {
		$items[] = [
			'name'      => $r->option_name,
			'size'      => (int) $r->sz,
			'size_h'    => size_format( (int) $r->sz ),
			'protected' => jinyu_autoload_protected( $r->option_name ),
		];
	}

	wp_send_json_success( [
		'msg'         => sprintf(
			/* translators: 1: option count, 2: total size */
			__( 'Autoload 选项共 %1$d 条、%2$s；其中 %3$d 条超过 128KB。', 'jinyu-theme-companion' ),
			$total_count,
			size_format( $total_size ),
			count( $items )
		),
		'items'       => $items,
		'total_size'  => size_format( $total_size ),
		'total_count' => $total_count,
	] );
}

/**
 * 单选项 autoload 开关：默认改为按需加载（no），带 undo=1 时恢复自动加载（yes）。
 * 受保护名单两种方向都拦截；改 no 前现场复核（存在 + 仍 autoload=yes + 超阈值）防竞态误改。
 * 优先用 WP 6.6+ 的 wp_set_option_autoload()（内部正确处理 alloptions 缓存失效）。
 */
function jinyu_autoload_fix() {
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
	}

	$name = isset( $_POST['option_name'] ) ? sanitize_text_field( wp_unslash( $_POST['option_name'] ) ) : '';
	if ( '' === $name || strlen( $name ) > 191 ) {
		wp_send_json_error( __( '选项名无效', 'jinyu-theme-companion' ) );
	}
	if ( jinyu_autoload_protected( $name ) ) {
		wp_send_json_error( __( '该选项受保护（核心必需或高频读取），不可更改。', 'jinyu-theme-companion' ) );
	}

	$undo = ! empty( $_POST['undo'] );

	global $wpdb;

	if ( $undo ) {
		// 恢复：仅要求选项存在且当前为非 autoload（不限体积）。
		$still = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s AND " . jinyu_autoload_sql_in_off(), $name )
		);
		if ( $still !== $name ) {
			wp_send_json_error( __( '选项不存在或已是自动加载，无需恢复。', 'jinyu-theme-companion' ) );
		}
		$ok = jinyu_autoload_set( $name, true );
		if ( ! $ok ) {
			wp_send_json_error( __( '恢复失败，请重试。', 'jinyu-theme-companion' ) );
		}
		wp_send_json_success( [
			'msg'  => sprintf(
				/* translators: %s: option name */
				__( '已将 %s 恢复为自动加载（autoload=yes）。', 'jinyu-theme-companion' ),
				$name
			),
			'name' => $name,
		] );
	}

	$min = jinyu_autoload_min_size();

	$still = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s AND " . jinyu_autoload_sql_in() . " AND LENGTH(option_value) > %d",
			$name,
			$min
		)
	);
	if ( $still !== $name ) {
		wp_send_json_error( __( '选项不存在、已是非 autoload 或未达体积阈值。', 'jinyu-theme-companion' ) );
	}

	$ok = jinyu_autoload_set( $name, false );
	if ( ! $ok ) {
		wp_send_json_error( __( '更新失败，请重试。', 'jinyu-theme-companion' ) );
	}

	wp_send_json_success( [
		'msg' => sprintf(
			/* translators: %s: option name */
			__( '已将 %s 改为按需加载（autoload=no）。若前台出现异常，刷新本页扫描后点「恢复」可改回。', 'jinyu-theme-companion' ),
			$name
		),
		'name' => $name,
	] );
}

/**
 * 实际执行 autoload 切换：优先 WP 6.6+ API，旧版手动改行 + 清缓存。
 */
function jinyu_autoload_set( string $name, bool $autoload ): bool {
	if ( function_exists( 'wp_set_option_autoload' ) ) {
		return (bool) wp_set_option_autoload( $name, $autoload );
	}
	global $wpdb;
	$ok = false !== $wpdb->update( $wpdb->options, [ 'autoload' => $autoload ? 'yes' : 'no' ], [ 'option_name' => $name ] );
	if ( $ok ) {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $name, 'options' );
	}
	return (bool) $ok;
}
