<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 金玉访问统计 - PV/UV 面板

// 建表 (首次，带 fallback)
add_action( 'after_switch_theme', 'jinyu_stats_install' );
function jinyu_stats_install() {
    global $wpdb;
    $tbl = $wpdb->prefix . 'jinyu_stats';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $tbl (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stat_date DATE NOT NULL,
        pv INT UNSIGNED DEFAULT 0,
        uv INT UNSIGNED DEFAULT 0,
        UNIQUE KEY uk_date (stat_date)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/**
 * 确保统计表已存在。
 */
function jinyu_stats_ensure_table(): void {
    global $wpdb;
    $tbl = $wpdb->prefix . 'jinyu_stats';
    if ( ! get_transient( 'jinyu_stats_checked' ) ) {
        /* phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 自建统计表建表（一次性，transient 节流）；表名由 schema 常量构造，多行 SQL 整句豁免 */
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $tbl (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            pv INT UNSIGNED DEFAULT 0,
            uv INT UNSIGNED DEFAULT 0,
            UNIQUE KEY uk_date (stat_date)
        ) {$wpdb->get_charset_collate()}"
        );
        // phpcs:enable
        set_transient( 'jinyu_stats_checked', 1, HOUR_IN_SECONDS * 12 );
    }
}

/**
 * 是否为「真实访客」请求：过滤爬虫、空 UA、预取请求与缓存预热。
 * 与 jinyu_stats_should_track 不同，此处不排斥 AJAX（UV beacon 本身就是 AJAX）。
 */
function jinyu_stats_is_valid_visitor(): bool {
    // 缓存预热请求（warm-up 爬取，带 X-Jinyu-Warmup 头）不算真实访问。
    if ( ! empty( $_SERVER['HTTP_X_JINYU_WARMUP'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 仅做存在性判断，不落库、不输出。
        return false;
    }

    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|curl|wget|python|java\/|httpclient|go-http|facebookexternalhit|bingpreview|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|headlesschrome|phantomjs|okhttp|libwww/i', $ua ) ) {
        return false;
    }

    $purpose = strtolower(
        ( isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ) ) : '' )
        . ' ' . ( isset( $_SERVER['HTTP_PURPOSE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PURPOSE'] ) ) : '' )
    );
    if ( strpos( $purpose, 'prefetch' ) !== false || strpos( $purpose, 'prerender' ) !== false ) {
        return false;
    }

    return true;
}

/**
 * 是否应计入统计：过滤爬虫（空 UA / bot 特征）、预取请求（Speculation /
 * Sec-Purpose: prefetch）与 AJAX / REST / cron 上下文，避免 PV 虚高。
 */
function jinyu_stats_should_track() {
    if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
    }

    return jinyu_stats_is_valid_visitor();
}

// 前端计数
// 延迟到 shutdown 写库：统计计数不阻塞页面渲染（原挂 wp_footer 会在 HTML 输出阶段同步写库）
add_action( 'shutdown', 'jinyu_stats_track' );
function jinyu_stats_track() {
    if ( is_admin() || is_robots() || is_feed() || ! jinyu_stats_should_track() ) {
		return;
    }
    global $wpdb;
    $tbl = $wpdb->prefix . 'jinyu_stats';
    $today = current_time( 'Y-m-d' );

    jinyu_stats_ensure_table();

	// 仅计 PV；UV 写入按缓存模式分两条路：
	// - edge 模式：主响应不能带 Set-Cookie（否则 nginx fastcgi_cache 首访 MISS），由前端 beacon 写入；
	// - simple / 关闭缓存：主响应由 PHP 输出，Set-Cookie 不影响缓存，服务端直接计 UV（见 jinyu_stats_track_uv_server_side / jinyu_stats_set_uv_cookie_now）。
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 自建统计表高频计数，ON DUPLICATE KEY 原子自增，缓存反而丢计数；表名由 schema 常量构造
	$wpdb->query( $wpdb->prepare( "INSERT INTO $tbl (stat_date, pv, uv) VALUES (%s, 1, 0) ON DUPLICATE KEY UPDATE pv=pv+1", $today ) );
}

/**
 * 立即写 UV Cookie 并计 UV（不依赖 WP 条件标签）。
 * 整页缓存（simple 模式）命中时页面在 init 阶段即输出并 exit，wp 钩子永不执行，
 * 缓存命中路径（page-cache.php）调用本函数补写 Cookie + UV，让同浏览器后续请求不再记为新 UV。
 * edge 模式下由 Nginx 直接吐缓存，不运行 PHP，此时 UV 通过前端 beacon 写入，本函数不被调用。
 */
function jinyu_stats_set_uv_cookie_now(): void {
    if ( ! jinyu_stats_is_valid_visitor() ) {
		return;
    }
    $uv_key = 'jinyu_uv_' . current_time( 'Y-m-d' );
    if ( ! isset( $_COOKIE[ $uv_key ] ) ) {
        setcookie( $uv_key, '1', time() + DAY_IN_SECONDS, '/' );
        jinyu_stats_ensure_table();
        global $wpdb;
        $tbl   = $wpdb->prefix . 'jinyu_stats';
        $today = current_time( 'Y-m-d' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 自建统计表高频计数，ON DUPLICATE KEY 原子自增，缓存反而丢计数；表名由 schema 常量构造
        $wpdb->query( $wpdb->prepare( "INSERT INTO $tbl (stat_date, pv, uv) VALUES (%s, 0, 1) ON DUPLICATE KEY UPDATE uv=uv+1", $today ) );
    }
}

/**
 * 非 edge 模式的服务端 UV 计数（simple / 关闭缓存 / 第三方缓存）。
 * 挂在 wp 钩子（早于模板输出），确保 setcookie 在响应头发送前完成；
 * Set-Cookie 由 PHP 自己输出的响应携带，不影响插件自管缓存，故可直接种 cookie + 写库。
 * edge 模式不调用本函数，交由前端 beacon 写入，避免主响应带 Set-Cookie 破坏边缘缓存。
 */
add_action( 'wp', 'jinyu_stats_track_uv_server_side' );
function jinyu_stats_track_uv_server_side(): void {
    if ( 'edge' === jinyu_page_cache_mode() ) {
        return;
    }
    if ( is_admin() || is_feed() || is_robots() || ! jinyu_stats_should_track() ) {
        return;
    }
    $uv_key = 'jinyu_uv_' . current_time( 'Y-m-d' );
    if ( ! isset( $_COOKIE[ $uv_key ] ) ) {
        setcookie( $uv_key, '1', time() + DAY_IN_SECONDS, '/' );
        jinyu_stats_ensure_table();
        global $wpdb;
        $tbl   = $wpdb->prefix . 'jinyu_stats';
        $today = current_time( 'Y-m-d' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 自建统计表高频计数，ON DUPLICATE KEY 原子自增，缓存反而丢计数；表名由 schema 常量构造
        $wpdb->query( $wpdb->prepare( "INSERT INTO $tbl (stat_date, pv, uv) VALUES (%s, 0, 1) ON DUPLICATE KEY UPDATE uv=uv+1", $today ) );
    }
}

// UV Beacon：前端 JS 在 localStorage 中无今日标记时触发。
// 该 endpoint 响应带 Set-Cookie，但主页面不再种 cookie，因此首访可进 nginx fastcgi_cache。
add_action( 'wp_ajax_jinyu_record_uv', 'jinyu_stats_record_uv_ajax' );
add_action( 'wp_ajax_nopriv_jinyu_record_uv', 'jinyu_stats_record_uv_ajax' );
function jinyu_stats_record_uv_ajax(): void {
    // phpcs:ignore WordPress.Security.NonceVerification -- 公开 UV beacon（nopriv），无 nonce 属设计
    $date = isset( $_REQUEST['date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['date'] ) ) : '';
    $today = current_time( 'Y-m-d' );

    // 先写好 Set-Cookie（必须在任何 header() 输出之前），再下发其余防缓存头。
    $uv_key = 'jinyu_uv_' . $today;
    $needs_cookie = ( $date === $today && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && jinyu_stats_is_valid_visitor() && ! isset( $_COOKIE[ $uv_key ] ) );
    if ( $needs_cookie ) {
        setcookie( $uv_key, '1', time() + DAY_IN_SECONDS, '/' );
    }

    // 该 endpoint 本身不应被缓存。
    nocache_headers();

    if ( ! jinyu_stats_is_valid_visitor() ) {
        jinyu_stats_beacon_done();
        return;
    }

    if ( $date !== $today || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        jinyu_stats_beacon_done();
        return;
    }

    if ( ! isset( $_COOKIE[ $uv_key ] ) ) {
        jinyu_stats_ensure_table();
        global $wpdb;
        $tbl = $wpdb->prefix . 'jinyu_stats';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 自建统计表高频计数，ON DUPLICATE KEY 原子自增，缓存反而丢计数；表名由 schema 常量构造
        $wpdb->query( $wpdb->prepare( "INSERT INTO $tbl (stat_date, pv, uv) VALUES (%s, 0, 1) ON DUPLICATE KEY UPDATE uv=uv+1", $today ) );
    }

    jinyu_stats_beacon_done();
}

/**
 * 输出 1x1 透明 GIF 并结束请求。
 */
function jinyu_stats_beacon_done(): void {
    header( 'Content-Type: image/gif' );
    header( 'Cache-Control: no-store, private' );
    // 1x1 transparent GIF（42 字节）。
    echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 固定二进制图片内容。
    exit;
}

// 前端 beacon JS：仅 edge 模式注入。edge 由 Nginx/Apache 直接吐缓存、不运行 PHP，
// 主响应不能带 Set-Cookie，故 UV 改由本 beacon 在客户端触发 admin-ajax 写入。
// simple / 关闭缓存模式走服务端 cookie（jinyu_stats_track_uv_server_side），无需 beacon，避免多余请求。
add_action( 'wp_enqueue_scripts', 'jinyu_stats_enqueue_beacon', 20 );
function jinyu_stats_enqueue_beacon(): void {
    if ( 'edge' !== jinyu_page_cache_mode() ) {
        return;
    }
    if ( is_admin() || is_feed() || is_robots() ) {
        return;
    }
    wp_enqueue_script( 'jinyu-stats-beacon', JINYU_COMPANION_URL . 'assets/stats-beacon.js', array(), JINYU_COMPANION_VER, true );
    wp_localize_script(
        'jinyu-stats-beacon',
        'jinyu_stats',
        array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'today'    => current_time( 'Y-m-d' ),
        )
    );
}

// 后台仪表盘小工具
add_action(
    'wp_dashboard_setup',
    function () {
		wp_add_dashboard_widget(
            'jinyu_stats_widget',
            '金玉访问统计',
            function () {
                global $wpdb;
                $tbl = $wpdb->prefix . 'jinyu_stats';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 自建统计表高频计数，ON DUPLICATE KEY 原子自增，缓存反而丢计数；表名由 schema 常量构造
                $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT stat_date, pv, uv FROM %i ORDER BY stat_date DESC LIMIT 7', $tbl ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- 自建统计表高频计数，ON DUPLICATE KEY 原子自增，缓存反而丢计数；表名由 schema 常量构造
                $total = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(pv) pv, SUM(uv) uv FROM %i', $tbl ) );
                echo '<p><b>总 PV:</b> ' . number_format( $total ? (int) $total->pv : 0 ) . ' &nbsp; <b>总 UV:</b> ' . number_format( $total ? (int) $total->uv : 0 ) . '</p>';
                if ( $rows ) {
                    echo '<table class="widefat striped"><thead><tr><th>日期</th><th>PV</th><th>UV</th></tr></thead><tbody>';
                    foreach ( $rows as $r ) {
                        echo '<tr><td>' . esc_html( $r->stat_date ) . '</td><td>' . esc_html( $r->pv ) . '</td><td>' . esc_html( $r->uv ) . '</td></tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p>暂无数据</p>';
                }
            }
		);
	}
);
