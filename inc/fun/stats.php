<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 金玉访问统计 - PV/UV 面板

// 建表 (首次，带 fallback)
add_action('after_switch_theme', 'jinyu_stats_install');
function jinyu_stats_install()
{
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
    dbDelta($sql);
}

/**
 * 是否应计入统计：过滤爬虫（空 UA / bot 特征）、预取请求（Speculation /
 * Sec-Purpose: prefetch）与 AJAX / REST / cron 上下文，避免 PV 虚高。
 */
function jinyu_stats_should_track()
{
    if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) return false;

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|curl|wget|python|java\/|httpclient|go-http|facebookexternalhit|bingpreview|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|headlesschrome|phantomjs|okhttp|libwww/i', $ua)) {
        return false;
    }

    $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_PURPOSE'] ?? ''));
    if (strpos($purpose, 'prefetch') !== false || strpos($purpose, 'prerender') !== false) {
        return false;
    }

    return true;
}

// 前端计数
// 延迟到 shutdown 写库：统计计数不阻塞页面渲染（原挂 wp_footer 会在 HTML 输出阶段同步写库）
add_action('shutdown', 'jinyu_stats_track');
function jinyu_stats_track()
{
    if (is_admin() || is_robots() || is_feed() || !jinyu_stats_should_track()) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'jinyu_stats';
    $today = current_time('Y-m-d');

    // 确保表存在（带 transient 锁）
    if (!get_transient('jinyu_stats_checked')) {
        $wpdb->query("CREATE TABLE IF NOT EXISTS $tbl (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            pv INT UNSIGNED DEFAULT 0,
            uv INT UNSIGNED DEFAULT 0,
            UNIQUE KEY uk_date (stat_date)
        ) {$wpdb->get_charset_collate()}");
        set_transient('jinyu_stats_checked', 1, HOUR_IN_SECONDS * 12);
    }

    $wpdb->query($wpdb->prepare("INSERT INTO $tbl (stat_date, pv, uv) VALUES (%s, 1, 1) ON DUPLICATE KEY UPDATE pv=pv+1", $today));

    // UV 去重：Cookie 已在 wp 钩子（响应头发送前）写入，此处仅按 Cookie 判定是否计数
    $uv_key = 'jinyu_uv_' . $today;
    if (!isset($_COOKIE[$uv_key])) {
        $wpdb->query($wpdb->prepare("UPDATE $tbl SET uv=uv+1 WHERE stat_date=%s", $today));
    }
}

// UV Cookie 必须在响应头发送前写入：shutdown 阶段头已发送，setcookie 必失败（headers already sent）。
// 挂在 wp 钩子（query 已解析，可正确判定 feed/robots，且 HTML 尚未输出）。
add_action('wp', 'jinyu_stats_set_uv_cookie');
function jinyu_stats_set_uv_cookie()
{
    if (is_admin() || is_robots() || is_feed() || !jinyu_stats_should_track()) return;
    jinyu_stats_set_uv_cookie_now();
}

/**
 * 立即写 UV Cookie（不依赖 WP 条件标签）。
 * 整页缓存命中时页面在 init 阶段即输出并 exit，wp 钩子永不执行，
 * 缓存命中路径（page-cache.php）须显式调用本函数补写 Cookie，
 * 否则 shutdown 统计会把每个缓存页 PV 都当成新 UV（UV 虚高）。
 */
function jinyu_stats_set_uv_cookie_now(): void
{
    if (!jinyu_stats_should_track()) return;
    $uv_key = 'jinyu_uv_' . current_time('Y-m-d');
    if (!isset($_COOKIE[$uv_key])) {
        setcookie($uv_key, '1', time() + DAY_IN_SECONDS, '/');
    }
}

// 后台仪表盘小工具
add_action('wp_dashboard_setup', function(){
    wp_add_dashboard_widget('jinyu_stats_widget', '金玉访问统计', function(){
        global $wpdb;
        $tbl = $wpdb->prefix . 'jinyu_stats';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT stat_date, pv, uv FROM %i ORDER BY stat_date DESC LIMIT 7", $tbl));
        $total = $wpdb->get_row($wpdb->prepare("SELECT SUM(pv) pv, SUM(uv) uv FROM %i", $tbl));
        echo '<p><b>总 PV:</b> ' . number_format((int)$total->pv) . ' &nbsp; <b>总 UV:</b> ' . number_format((int)$total->uv) . '</p>';
        if ($rows) {
            echo '<table class="widefat striped"><thead><tr><th>日期</th><th>PV</th><th>UV</th></tr></thead><tbody>';
            foreach ($rows as $r) echo '<tr><td>'.$r->stat_date.'</td><td>'.$r->pv.'</td><td>'.$r->uv.'</td></tr>';
            echo '</tbody></table>';
        } else {
            echo '<p>暂无数据</p>';
        }
    });
});

// AJAX 统计接口（仅管理员仪表盘小工具使用，关闭匿名访问）
add_action('wp_ajax_jinyu_stats', 'jinyu_stats_ajax');
function jinyu_stats_ajax()
{
    global $wpdb;
    $tbl = $wpdb->prefix . 'jinyu_stats';
    $row = $wpdb->get_row($wpdb->prepare("SELECT SUM(pv) as pv, SUM(uv) as uv FROM %i", $tbl));
    wp_send_json_success(['pv'=>(int)$row->pv, 'uv'=>(int)$row->uv]);
}