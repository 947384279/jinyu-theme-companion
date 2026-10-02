<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_jinyu_poster', 'jinyu_poster_generate' );
add_action( 'wp_ajax_nopriv_jinyu_poster', 'jinyu_poster_generate' );

/**
 * 海报生成端点（公开，游客可用）。
 *
 * CSRF 防护：校验本插件自持的 nonce（action = jinyu_poster），由本插件在文章渲染时
 * 经过滤器广播给前端。此前这里是「双 nonce 任一通过」：主题的 jinyu_front +
 * 本插件的 jinyu_companion_settings。问题有两个——
 * ① jinyu_companion_settings 只在**后台设置页**签发，前台根本拿不到，插件独立运行时
 *    无人能通过校验，端点形同虚设；
 * ② 探测主题的 nonce 名是「插件认识主题」，属双向耦合，违反解耦约束。
 * 改为插件只认自己签发的 nonce，主题通过 apply_filters( 'jinyu_poster_nonce' ) 取值，
 * 主题缺席时该过滤器无人响应，前端自然不带 nonce——端点不可用是正确降级，
 * 好过用一个「看起来能过实则过不了」的校验糊弄。
 */
function jinyu_poster_generate() {
    if ( ! check_ajax_referer( 'jinyu_poster', '_ajax_nonce', false ) ) {
        wp_send_json_error( __( '海报生成请求已失效，请刷新页面后重试。', 'jinyu-theme-companion' ) );
    }

    $post_id = absint( wp_unslash( $_REQUEST['post_id'] ?? 0 ) );
    if ( ! $post_id ) {
		wp_send_json_error( 'invalid' );
    }
    $post = get_post( $post_id );
    if ( ! $post ) {
		wp_send_json_error( 'not found' );
    }

    // 防探测：本端点对匿名开放，非公开内容不得经它外泄。
    // 已发布文章对所有人放行（海报按钮本就是前台匿名分享功能）；
    // 草稿 / 私密 / 待审文章仅限有阅读权限的用户（如管理员预览），匿名请求一律按不存在处理。
    if ( 'publish' !== $post->post_status
        && ( ! is_user_logged_in() || ! current_user_can( 'read_post', $post_id ) ) ) {
        wp_send_json_error( 'not found' );
    }

    // 防滥用：每 IP 速率限制，避免匿名用户频繁触发 GD 图像生成消耗服务器资源
    if ( ! jinyu_companion_rate_limit( 'poster', 20, MINUTE_IN_SECONDS ) ) {
        wp_send_json_error( '请求过于频繁，请稍后再试' );
    }

    $cache_key = 'jinyu_poster_' . $post_id;
    $cached = get_transient( $cache_key );
    // 仅当本地文件真实存在时复用缓存（旧版纯 URL transient 会被跳过并重建为新结构）
    if ( is_array( $cached ) && ! empty( $cached['file'] ) && file_exists( $cached['file'] ) ) {
        wp_send_json_success( [ 'url' => $cached['url'] ] );
    }
    if ( ! extension_loaded( 'gd' ) ) {
		wp_send_json_error( 'GD 库未启用' );
    }

    $w = 750;
	$h = 1000;
    $img = imagecreatetruecolor( $w, $h );
    $primary = jinyu_companion_get_option( 'style_color_primary', '#FF6B35' );
    if ( ! is_string( $primary ) || ! preg_match( '/^#?[0-9a-fA-F]{6}$/', $primary ) ) {
        $primary = '#FF6B35'; // 空值/非法值兜底，避免 sscanf 返回 null 触发 imagecolorallocate 参数错误
    }
    list($r, $g, $b) = sscanf( ltrim( $primary, '#' ), '%02x%02x%02x' );
    $bg = imagecolorallocate( $img, 247, 248, 250 );
    $accent = imagecolorallocate( $img, $r, $g, $b );
    $white = imagecolorallocate( $img, 255, 255, 255 );
    $title_c = imagecolorallocate( $img, 17, 19, 23 );
    $site_c = imagecolorallocate( $img, 107, 114, 128 );

    imagefill( $img, 0, 0, $bg );
    imagefilledrectangle( $img, 0, 0, $w, 200, $accent );

    $cover_url = jinyu_companion_post_cover( $post_id, 'large' );
    if ( $cover_url ) {
        // 用 WP HTTP API 并限时 8s：file_get_contents 无超时，CDN 抖动时 AJAX 会挂满 PHP 超时
        $resp = wp_remote_get( $cover_url, [ 'timeout' => 8 ] );
        $cover_data = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
        if ( $cover_data ) {
            // imagecreatefromstring 对损坏数据会发告警，此处仅抑制该调用的告警（结果已显式判断）
            set_error_handler(
                static function () {
					return true;
				}
            );
            $cover = imagecreatefromstring( $cover_data );
            restore_error_handler();
            if ( $cover ) {
                $cw = imagesx( $cover );
				$ch = imagesy( $cover );
                imagecopyresampled( $img, $cover, 50, 250, 0, 0, 650, 400, $cw, $ch );
                imagedestroy( $cover );
            }
        }
    }

    // 海报标题多为中文：优先选 CJK 字体，否则 Linux 服务器无中文字体时中文渲染成方块。
    // 字体候选列表的唯一真源在 watermark.php 的 jinyu_companion_find_font()，此处复用不另存一份。
    $font = jinyu_companion_find_font();
    if ( function_exists( 'imagettftext' ) && is_string( $font ) && file_exists( $font ) ) {
        imagettftext( $img, 28, 0, 50, 720, $title_c, $font, $post->post_title );
        imagettftext( $img, 16, 0, 50, 850, $site_c, $font, get_bloginfo( 'name' ) );
        imagettftext( $img, 14, 0, 50, 930, $site_c, $font, get_permalink( $post_id ) );
    } else {
        imagestring( $img, 5, 50, 700, substr( $post->post_title, 0, 40 ), $title_c );
    }

    $upload = wp_upload_dir();
    $file = $upload['basedir'] . '/jinyu-poster-' . $post_id . '.png';
    imagepng( $img, $file );
    imagedestroy( $img );

    $url = str_replace( $upload['basedir'], $upload['baseurl'], $file );
    set_transient(
        $cache_key,
        [
			'url' => $url,
			'file' => $file,
		],
		HOUR_IN_SECONDS
    );

    // 顺带清理：每天最多扫一次，删除 7 天前的旧海报 PNG（文件名按文章固定，正常只覆盖；此处兜底防长期堆积）
    if ( false === get_transient( 'jinyu_poster_swept' ) ) {
        jinyu_poster_sweep_old();
        set_transient( 'jinyu_poster_swept', 1, DAY_IN_SECONDS );
    }

    wp_send_json_success( [ 'url' => $url ] );
}

/**
 * 广播海报端点的 nonce 供前端使用。
 *
 * 契约方向：主题渲染「生成海报」按钮时 apply_filters( 'jinyu_poster_nonce' ) 取值，
 * 填进请求的 _ajax_nonce 字段。本插件只提供值，不认识主题任何函数。
 * 默认返回空串——主题未取用时端点不可用，属正确降级（好过用一个恒定可绕过的校验）。
 *
 * @param string $default 主题侧默认值（本插件不接管时为空）。
 * @return string
 */
add_filter( 'jinyu_poster_nonce', 'jinyu_poster_nonce' );
function jinyu_poster_nonce( $default = '' ): string {
	unset( $default );
	return wp_create_nonce( 'jinyu_poster' );
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
            @wp_delete_file( $f );
        }
    }
}
