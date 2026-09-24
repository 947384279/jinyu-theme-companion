<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 整页缓存（仅未登录访客的 GET 请求）
 * 启用开关与有效期统一由配套插件设置面板（jinyu_companion_*）管控，不再读主题配置；
 * 内容更新时由 inc/fun/cache.php 的 jinyu_cache_flush() 自动失效。
 */

add_action('init', 'jinyu_page_cache_serve');
add_action('template_redirect', 'jinyu_page_cache_capture');

// 内容变更自动失效：发布/更新/删除文章、评论增删审核都会触碰整页缓存。
// 不依赖主题的 jinyu_cache_flush()，本插件自闭环。
add_action('save_post', 'jinyu_page_cache_flush', 999);
add_action('deleted_post', 'jinyu_page_cache_flush');
add_action('comment_post', static function ( $comment_id ): void {
	$comment = get_comment( $comment_id );
	if ( $comment && 1 === (int) $comment->comment_approved ) {
		jinyu_page_cache_flush();
	}
}, 999);
add_action('wp_set_comment_status', static function ( $comment_id, $status ): void {
	if ( in_array( $status, [ 'approve', '1', 'hold', '0', 'spam', 'trash' ], true ) ) {
		jinyu_page_cache_flush();
	}
}, 999, 2);

/**
 * 整页缓存代际（epoch）：flush 时推进代际号，旧代际的所有 key 一次性整体失效，
 * 无需逐 URI 枚举删除。option 不自动加载（autoload=no），成本为一次主键级 UPDATE。
 */
function jinyu_page_cache_epoch(): string
{
    $epoch = get_option('jinyu_page_cache_epoch');
    if (!is_string($epoch) || '' === $epoch) {
        $epoch = '1';
        update_option('jinyu_page_cache_epoch', $epoch, false);
    }
    return $epoch;
}

function jinyu_page_cache_flush(): void
{
    update_option('jinyu_page_cache_epoch', (string) time(), false);
}

function jinyu_page_cache_key(): string
{
    // 缓存页里内嵌了 wp_create_nonce('jinyu_front')，nonce 以 12h 为一个 tick 轮换。
    // 把当前 tick 并入 key：跨 tick 后旧缓存自然失效，绝不会把已过期的 nonce 发给访客
    // （否则点赞 / AI 对话 / 评论会被 check_ajax_referer 打回 -1）。
    // 代际 epoch 并入 key：save_post 等内容变更后旧页面立即失效。
    $tick = function_exists('wp_nonce_tick') ? wp_nonce_tick() : (int) ceil(time() / (DAY_IN_SECONDS / 2));
    return jinyu_cache_key('page_' . jinyu_page_cache_epoch() . '_' . $tick . '_' . md5($_SERVER['REQUEST_URI'] ?? '/'));
}

/**
 * 按 URI 特征判定搜索请求（?s=… 或 /search/… 重写）。
 * serve 端先于 WP 查询运行，is_search() 尚不可用，故以 URI 判定；
 * capture 端仅作 is_search() 之外的兜底。
 */
function jinyu_page_cache_is_search_uri(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ('' === $uri) return false;
    return (bool) (preg_match('#[?&]s=[^&]*#', $uri) || stripos($uri, '/search/') !== false);
}

function jinyu_page_cache_serve(): void
{
    if ( ! jinyu_companion_is_checked( 'page_cache_enable' ) ) {
        return;
    }
    if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
    if (is_user_logged_in() || is_admin()) return;
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'wp-admin') !== false) return;
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'wp-login') !== false) return;
    // 搜索页不缓存：serve 端早于 WP 查询（is_search() 不可用），按 URI 特征判定；
    // 每个搜索词一个 URI，缓存只会膨胀且命中意义不大。
    if (jinyu_page_cache_is_search_uri()) return;

    $html = get_transient(jinyu_page_cache_key());
    if ($html !== false) {
        // 标记本次为缓存命中：性能采样（inc/fun/live.php）据此跳过，
        // 否则几毫秒的缓存响应会把「实时心跳」曲线压成一条直线。
        define('JINYU_CACHE_HIT', true);
        header('X-Jinyu-Cache: HIT');
        // 缓存命中时已在 init 阶段 echo+exit，template_redirect 不会触发，
        // 而来源统计(jinyu_track_visit_source)挂在该钩子上，故在此显式调用，
        // 确保匿名访客的来源 PV/UV 在缓存命中时仍被记录（写库已在函数内延迟到 shutdown，exit 后仍会执行）。
        if (function_exists('jinyu_track_visit_source')) {
            jinyu_track_visit_source();
        }
        // UV Cookie 补写：命中路径绕过了 wp 钩子，不补写则每个缓存 PV 都会被 shutdown 统计记为新 UV。
        if (function_exists('jinyu_stats_set_uv_cookie_now')) {
            jinyu_stats_set_uv_cookie_now();
        }
        echo $html;
        exit;
    }
}

function jinyu_page_cache_capture(): void
{
    if ( ! jinyu_companion_is_checked( 'page_cache_enable' ) ) {
        return;
    }
    // 检测到第三方整页缓存插件时自动让位，避免两层 HTML 缓存冲突 / 内容不同步。
    if (function_exists('jinyu_has_external_page_cache') && jinyu_has_external_page_cache()) return;
    if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
    if (is_user_logged_in() || is_admin()) return;
    // 这些响应体不该进整页缓存：404 / feed / 预览 / robots / trackback / 搜索
    if (is_404() || is_feed() || is_preview() || is_robots() || is_trackback() || is_search()) return;
    if (jinyu_page_cache_is_search_uri()) return;

    ob_start(function ($html) {
        if (strlen($html) < 500) return $html;
        $ttl = max( 60, (int) jinyu_companion_get_option( 'page_cache_ttl', '3600' ) );
        set_transient(jinyu_page_cache_key(), $html, $ttl);
        return $html;
    });
}
