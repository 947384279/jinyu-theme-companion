<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('wp_ajax_jinyu_poster', 'jinyu_poster_generate');
add_action('wp_ajax_nopriv_jinyu_poster', 'jinyu_poster_generate');
function jinyu_poster_generate()
{
    // 双 nonce 兼容：主题在场时前端用主题 jinyu_front nonce；插件独立运行时
    // 接受本插件设置页签发的 jinyu_companion_settings nonce。两者任一通过即可。
    $ok = check_ajax_referer('jinyu_front', '_ajax_nonce', false)
        || check_ajax_referer('jinyu_companion_settings', '_ajax_nonce', false);
    if (!$ok) {
        wp_send_json_error(__('nonce 校验失败', 'jinyu-theme-companion'), 403);
    }

    $post_id = isset($_REQUEST['post_id']) ? intval($_REQUEST['post_id']) : 0;
    if (!$post_id) wp_send_json_error('invalid');
    $post = get_post($post_id);
    if (!$post) wp_send_json_error('not found');

    // 防滥用：每 IP 速率限制，避免匿名用户频繁触发 GD 图像生成消耗服务器资源
    if (!jinyu_rate_limit_check('poster', 20, MINUTE_IN_SECONDS)) {
        wp_send_json_error('请求过于频繁，请稍后再试');
    }

    $cache_key = 'jinyu_poster_' . $post_id;
    $cached = get_transient($cache_key);
    // 仅当本地文件真实存在时复用缓存（旧版纯 URL transient 会被跳过并重建为新结构）
    if (is_array($cached) && !empty($cached['file']) && file_exists($cached['file'])) {
        wp_send_json_success(['url' => $cached['url']]);
    }
    if (!extension_loaded('gd')) wp_send_json_error('GD 库未启用');

    $w = 750; $h = 1000;
    $img = imagecreatetruecolor($w, $h);
    $primary = jinyu_companion_get_option('style_color_primary', '#FF6B35');
    if (!is_string($primary) || !preg_match('/^#?[0-9a-fA-F]{6}$/', $primary)) {
        $primary = '#FF6B35'; // 空值/非法值兜底，避免 sscanf 返回 null 触发 imagecolorallocate 参数错误
    }
    list($r,$g,$b) = sscanf(ltrim($primary,'#'),'%02x%02x%02x');
    $bg = imagecolorallocate($img, 247, 248, 250);
    $accent = imagecolorallocate($img, $r, $g, $b);
    $white = imagecolorallocate($img, 255, 255, 255);
    $title_c = imagecolorallocate($img, 17, 19, 23);
    $site_c = imagecolorallocate($img, 107, 114, 128);

    imagefill($img, 0, 0, $bg);
    imagefilledrectangle($img, 0, 0, $w, 200, $accent);

    $cover_url = jinyu_get_post_cover($post_id, 'large');
    if ($cover_url) {
        // 用 WP HTTP API 并限时 8s：file_get_contents 无超时，CDN 抖动时 AJAX 会挂满 PHP 超时
        $resp = wp_remote_get($cover_url, ['timeout' => 8]);
        $cover_data = is_wp_error($resp) ? '' : (string) wp_remote_retrieve_body($resp);
        if ($cover_data) {
            // imagecreatefromstring 对损坏数据会发告警，此处仅抑制该调用的告警（结果已显式判断）
            set_error_handler(static function () { return true; });
            $cover = imagecreatefromstring($cover_data);
            restore_error_handler();
            if ($cover) {
                $cw = imagesx($cover); $ch = imagesy($cover);
                imagecopyresampled($img, $cover, 50, 250, 0, 0, 650, 400, $cw, $ch);
                imagedestroy($cover);
            }
        }
    }

    $font = null;
    // 海报标题多为中文：优先选 CJK 字体，拉丁字体（DejaVu/Liberation）只作最后兜底，
    // 否则 Linux 服务器无中文字体时中文渲染成方块
    $font_candidates = [
        'C:/Windows/Fonts/msyh.ttc',            // 微软雅黑
        'C:/Windows/Fonts/msyh.ttf',
        'C:/Windows/Fonts/simsun.ttc',          // 宋体
        '/System/Library/Fonts/PingFang.ttc',   // macOS 苹方
        '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',   // Linux 文泉驿
        '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc', // Noto CJK（Debian fonts-noto-cjk）
        '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/truetype/arphic/uming.ttc',     // AR PL UMing
        ABSPATH . 'wp-includes/fonts/opensans/OpenSans-Regular.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ];
    foreach ($font_candidates as $c) {
        if ($c && file_exists($c)) { $font = $c; break; }
    }
    if (function_exists('imagettftext') && is_string($font) && file_exists($font)) {
        imagettftext($img, 28, 0, 50, 720, $title_c, $font, $post->post_title);
        imagettftext($img, 16, 0, 50, 850, $site_c, $font, get_bloginfo('name'));
        imagettftext($img, 14, 0, 50, 930, $site_c, $font, get_permalink($post_id));
    } else {
        imagestring($img, 5, 50, 700, substr($post->post_title,0,40), $title_c);
    }

    $upload = wp_upload_dir();
    $file = $upload['basedir'] . '/jinyu-poster-' . $post_id . '.png';
    imagepng($img, $file);
    imagedestroy($img);

    $url = str_replace($upload['basedir'], $upload['baseurl'], $file);
    set_transient($cache_key, ['url' => $url, 'file' => $file], HOUR_IN_SECONDS);

    // 顺带清理：每天最多扫一次，删除 7 天前的旧海报 PNG（文件名按文章固定，正常只覆盖；此处兜底防长期堆积）
    if ( false === get_transient( 'jinyu_poster_swept' ) ) {
        jinyu_poster_sweep_old();
        set_transient( 'jinyu_poster_swept', 1, DAY_IN_SECONDS );
    }

    wp_send_json_success(['url' => $url]);
}

/**
 * 清理过期的文章分享海报 PNG（uploads 根目录下 jinyu-poster-<post_id>.png）。
 * 仅删除修改时间超过 7 天的文件，避免误删近期仍在用的缓存图。
 */
function jinyu_poster_sweep_old(): void {
    $upload = wp_upload_dir();
    $dir    = $upload['basedir'] ?? '';
    if ( '' === $dir || ! is_dir( $dir ) ) {
        return;
    }
    $cut = time() - WEEK_IN_SECONDS;
    foreach ( glob( $dir . '/jinyu-poster-*.png' ) ?: [] as $f ) {
        if ( is_file( $f ) && filemtime( $f ) < $cut ) {
            @unlink( $f );
        }
    }
}