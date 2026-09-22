<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 短代码系统（统一前缀 jinyu_）
 * 提示框 / 下载按钮 / 登录可见 / 评论可见 / 仅管理员可见 / 阅读密码 /
 * GitHub 卡片 / Gist / 图片组 / 徽章 / 进度条 / 选项卡 / 折叠面板 /
 * 代码块 / 音乐 / 按钮 / 登录+邮箱可见
 *
 * 向后兼容：旧标签（tip/download/...）仍作为别名注册，避免线上已有文章短代码失效。
 * 如需彻底清理，删除文件末尾「旧标签别名」段落即可。
 */

// --- 提示框 [jinyu_tip] ---
$jinyu_tip = function ($atts, $content = '') {
	$a = shortcode_atts( [ 'type' => 'info', 'title' => '' ], $atts );
	return '<div class="jinyu-tip jinyu-tip-' . esc_attr( $a['type'] ) . '">'
		 . ( $a['title'] ? '<div class="jinyu-tip-title">' . esc_html( $a['title'] ) . '</div>' : '' )
		 . '<div class="jinyu-tip-body">' . do_shortcode( $content ) . '</div>'
		 . '</div>';
};
add_shortcode( 'jinyu_tip', $jinyu_tip );

// --- 下载按钮 [jinyu_download] ---
$jinyu_download = function ($atts) {
	$a = shortcode_atts( [ 'url' => '', 'name' => '下载', 'type' => 'free', 'pwd' => '' ], $atts );
	if ( ! $a['url'] ) {
		return '';
	}
	$html = '<a class="jinyu-btn jinyu-download-btn" href="' . esc_url( $a['url'] ) . '" target="_blank" rel="noopener">';
	$html .= '⬇ ' . esc_html( $a['name'] );
	if ( $a['type'] !== 'free' ) {
		$html .= ' <span class="jinyu-download-type">' . esc_html( $a['type'] ) . '</span>';
	}
	if ( $a['pwd'] ) {
		$html .= ' <span class="jinyu-download-pwd">密码: ' . esc_html( $a['pwd'] ) . '</span>';
	}
	$html .= '</a>';
	return $html;
};
add_shortcode( 'jinyu_download', $jinyu_download );

// --- 登录可见 [jinyu_login]...[/jinyu_login] ---
$jinyu_login = function ($atts, $content = '') {
	if ( is_user_logged_in() ) {
		return do_shortcode( $content );
	}
	return '<div class="jinyu-login-hide">'
		 . '<div class="jinyu-login-hide-text">🔒 ' . __( '登录后可见', 'jinyu-theme-companion') . '</div>'
		 . '<a class="jinyu-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . __( '立即登录', 'jinyu-theme-companion') . '</a>'
		 . '</div>';
};
add_shortcode( 'jinyu_login', $jinyu_login );

// --- 评论可见 [jinyu_comment]...[/jinyu_comment] ---
$jinyu_comment = function ($atts, $content = '') {
	if ( comments_open() && is_user_logged_in() ) {
		return do_shortcode( $content );
	}
	return '<div class="jinyu-comment-hide">'
		 . '<i class="fa-regular fa-comment"></i> ' . __( '评论后可见，请先登录并评论', 'jinyu-theme-companion')
		 . '</div>';
};
add_shortcode( 'jinyu_comment', $jinyu_comment );

// --- 仅管理员可见 [jinyu_hide]...[/jinyu_hide] ---
$jinyu_hide = function ($atts, $content = '') {
	if ( current_user_can( 'manage_options' ) ) {
		return do_shortcode( $content );
	}
	return '<div class="jinyu-comment-hide">👀 ' . __( '隐藏内容，仅管理员可见', 'jinyu-theme-companion') . '</div>';
};
add_shortcode( 'jinyu_hide', $jinyu_hide );

// --- GitHub 卡片 [jinyu_github user="xxx" repo="xxx"] ---
$jinyu_github = function ($atts) {
	$a = shortcode_atts( [ 'user' => '', 'repo' => '' ], $atts );
	if ( ! $a['user'] ) {
		return '';
	}
	$url = $a['repo']
		? 'https://github.com/' . $a['user'] . '/' . $a['repo']
		: 'https://github.com/' . $a['user'];
	return '<a class="jinyu-github-card" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
		 . '<span class="jinyu-github-icon">🐙</span>'
		 . '<span class="jinyu-github-name">' . esc_html( $a['user'] ) . esc_html( $a['repo'] ? '/' . $a['repo'] : '' ) . '</span>'
		 . '</a>';
};
add_shortcode( 'jinyu_github', $jinyu_github );

// --- Gist 嵌入 [jinyu_gist id="xxx"] ---
$jinyu_gist = function ($atts) {
	$a = shortcode_atts( [ 'id' => '', 'file' => '' ], $atts );
	if ( ! $a['id'] ) {
		return '';
	}
	return '<script src="https://gist.github.com/' . esc_attr( $a['id'] ) . '.js'
		 . ( $a['file'] ? '?file=' . esc_attr( $a['file'] ) : '' ) . '"></script>';
};
add_shortcode( 'jinyu_gist', $jinyu_gist );

// --- 图片组 [jinyu_gallery ids="1,2,3"] ---
$jinyu_gallery = function ($atts) {
	$a = shortcode_atts( [ 'ids' => '', 'cols' => '3' ], $atts );
	$ids = array_filter( array_map( 'intval', explode( ',', $a['ids'] ) ) );
	if ( empty( $ids ) ) {
		return '';
	}
	$cols  = max( 1, min( 6, intval( $a['cols'] ) ) );
	$html  = '<div class="jinyu-gallery jinyu-gallery-cols-' . $cols . '">';
	foreach ( $ids as $id ) {
		$url   = jinyu_img_to_webp_url( wp_get_attachment_image_url( $id, 'large' ) );
		$thumb = jinyu_img_to_webp_url( wp_get_attachment_image_url( $id, 'medium' ) );
		if ( $url ) {
			$html .= '<a class="jinyu-gallery-item" href="' . esc_url( $url ) . '">'
				. '<img src="' . esc_url( $thumb ) . '" alt="">' . '</a>';
		}
	}
	$html .= '</div>';
	return $html;
};
add_shortcode( 'jinyu_gallery', $jinyu_gallery );

// --- 徽章 [jinyu_badge color="primary"]...[/jinyu_badge] ---
$jinyu_badge = function ($atts, $content = '') {
	$a = shortcode_atts( [ 'color' => 'primary' ], $atts );
	return '<span class="jinyu-badge jinyu-badge-' . esc_attr( $a['color'] ) . '">' . do_shortcode( $content ) . '</span>';
};
add_shortcode( 'jinyu_badge', $jinyu_badge );

// --- 进度条 [jinyu_progress value="50" color="primary" label=""] ---
$jinyu_progress = function ($atts) {
	$a   = shortcode_atts( [ 'value' => '50', 'color' => 'primary', 'label' => '' ], $atts );
	$v   = max( 0, min( 100, intval( $a['value'] ) ) );
	return '<div class="jinyu-progress-wrap"><div class="jinyu-progress-label">' . esc_html( $a['label'] ) . ' <span>' . $v . '%</span></div><div class="jinyu-progress-bar"><div class="jinyu-progress-fill jinyu-progress-' . esc_attr( $a['color'] ) . '" style="width:' . $v . '%"></div></div></div>';
};
add_shortcode( 'jinyu_progress', $jinyu_progress );

// --- 选项卡 [jinyu_tabs]...[jinyu_tab title="x"]...[/jinyu_tab]...[/jinyu_tabs] ---
$jinyu_tabs = function ($atts, $content = '') {
	preg_match_all( '/\[jinyu_tab[^\]]*\](.*?)\[\/jinyu_tab\]/s', $content, $m );
	preg_match_all( '/\[jinyu_tab[^\]]*title="([^"]*)"[^\]]*\]/', $content, $t );
	if ( empty( $m[1] ) ) {
		return '';
	}
	$tabs  = '';
	$panes = '';
	foreach ( $m[1] as $i => $body ) {
		$title  = $t[1][ $i ] ?? ( 'Tab ' . ( $i + 1 ) );
		$active = $i === 0 ? ' jinyu-tab-active' : '';
		$tabs  .= '<button class="jinyu-tab-btn' . $active . '" data-target="jinyu-tab-pane-' . $i . '">' . esc_html( $title ) . '</button>';
		$panes .= '<div class="jinyu-tab-pane' . $active . '" id="jinyu-tab-pane-' . $i . '">' . do_shortcode( $body ) . '</div>';
	}
	return '<div class="jinyu-tabs"><div class="jinyu-tabs-nav">' . $tabs . '</div><div class="jinyu-tabs-content">' . $panes . '</div></div>';
};
add_shortcode( 'jinyu_tabs', $jinyu_tabs );

// --- 折叠面板 [jinyu_collapse title=""]...[/jinyu_collapse] ---
$jinyu_collapse = function ($atts, $content = '') {
	$a = shortcode_atts( [ 'title' => '' ], $atts );
	return '<details class="jinyu-collapse"><summary class="jinyu-collapse-title">' . esc_html( $a['title'] ) . '</summary><div class="jinyu-collapse-body">' . do_shortcode( $content ) . '</div></details>';
};
add_shortcode( 'jinyu_collapse', $jinyu_collapse );

// --- 代码块 [jinyu_pre lang="php" title="示例"]...[/jinyu_pre] ---
$jinyu_pre = function ($atts, $content = '') {
	$a    = shortcode_atts( [ 'lang' => '', 'title' => '' ], $atts );
	$code = trim( (string) $content, "\n\r" );
	// 先还原可视化编辑器可能已转成的实体（如 &lt;），再统一转义，避免双重编码
	$code = html_entity_decode( $code, ENT_HTML5, 'UTF-8' );
	$code = htmlspecialchars( $code, ENT_NOQUOTES | ENT_HTML5, 'UTF-8' );
	$label = $a['title'] ?: ( $a['lang'] ? strtoupper( $a['lang'] ) : __( 'CODE', 'jinyu-theme-companion') );
	return '<div class="jinyu-pre">'
		. '<div class="jinyu-pre-bar">'
		. '<span class="jinyu-pre-lang">' . esc_html( $label ) . '</span>'
		. '<button type="button" class="jinyu-pre-copy" data-copy aria-label="' . esc_attr__( '复制代码', 'jinyu-theme-companion') . '">' . __( '复制', 'jinyu-theme-companion') . '</button>'
		. '</div>'
		. '<pre class="jinyu-pre-code"><code>' . $code . '</code></pre>'
		. '</div>';
};
add_shortcode( 'jinyu_pre', $jinyu_pre );

// --- 音乐播放器 [jinyu_music url="..." name="..." artist="..." cover="..."] 或 [jinyu_music]url[/jinyu_music] ---
$jinyu_music = function ($atts, $content = '') {
	$a   = shortcode_atts( [ 'url' => '', 'name' => '', 'artist' => '', 'cover' => '' ], $atts );
	$src = $a['url'] ?: trim( (string) $content );
	if ( ! $src ) {
		return '';
	}
	$src    = esc_url( $src );
	$cover  = $a['cover'] ? esc_url( $a['cover'] ) : '';
	$name   = $a['name'] ? esc_html( $a['name'] ) : '';
	$artist = $a['artist'] ? esc_html( $a['artist'] ) : '';
	$html   = '<div class="jinyu-music">';
	if ( $cover ) {
		$html .= '<img class="jinyu-music-cover" src="' . esc_url( jinyu_img_to_webp_url( $cover ) ) . '" alt="" loading="lazy">';
	}
	$html .= '<div class="jinyu-music-meta">';
	if ( $name ) {
		$html .= '<div class="jinyu-music-name">' . $name . '</div>';
	}
	if ( $artist ) {
		$html .= '<div class="jinyu-music-artist">' . $artist . '</div>';
	}
	if ( ! $name && ! $artist ) {
		$html .= '<div class="jinyu-music-name">' . __( '音乐', 'jinyu-theme-companion') . '</div>';
	}
	$html     .= '</div>';
	$html     .= '<audio class="jinyu-music-audio" controls preload="none" src="' . $src . '"></audio>';
	$html     .= '</div>';
	return $html;
};
add_shortcode( 'jinyu_music', $jinyu_music );

// --- 按钮 [jinyu_btn type="primary" href="..." target="_blank" icon=""]...[/jinyu_btn] ---
// 对齐 Puock 的 btn-* 系列：用统一 type 参数替代 7 个独立标签
$jinyu_btn = function ($atts, $content = '') {
	$a     = shortcode_atts(
		[ 'type' => 'primary', 'href' => '#', 'target' => '', 'icon' => '' ],
		$atts
	);
	$type  = sanitize_html_class( $a['type'] );
	$href  = $a['href'] ? esc_url( $a['href'] ) : '#';
	$target = ( $a['target'] === '_blank' ) ? ' target="_blank" rel="noopener"' : '';
	$icon  = $a['icon'] ? '<i class="' . esc_attr( $a['icon'] ) . '"></i>' : '';
	return '<a class="jinyu-btn jinyu-btn-' . $type . '" href="' . $href . '"' . $target . '>' . $icon . do_shortcode( $content ) . '</a>';
};
add_shortcode( 'jinyu_btn', $jinyu_btn );

// --- 登录并验证邮箱可见 [jinyu_login_email]...[/jinyu_login_email] ---
// 对齐 Puock 的 login_email：登录且拥有有效邮箱才可见
$jinyu_login_email = function ($atts, $content = '') {
	if ( is_user_logged_in() ) {
		$user  = wp_get_current_user();
		$email = $user->user_email;
		if ( ! empty( $email ) && is_email( $email ) && $email !== get_option( 'admin_email' ) ) {
			return do_shortcode( $content );
		}
	}
	return '<div class="jinyu-login-hide">'
		 . '<div class="jinyu-login-hide-text">🔒 ' . __( '登录并验证邮箱后可见', 'jinyu-theme-companion') . '</div>'
		 . '<a class="jinyu-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . __( '立即登录', 'jinyu-theme-companion') . '</a>'
		 . '</div>';
};
add_shortcode( 'jinyu_login_email', $jinyu_login_email );

// --- 阅读密码 [jinyu_password_read pass="123" tip="请输入阅读密码"]...[/jinyu_password_read] ---
// 锁定时不输出正文 HTML（真正隐藏，而非仅 CSS 遮挡）；输入正确后可查看，并写入 cookie 记忆
$jinyu_password_read = function ($atts, $content = '') {
	$a = shortcode_atts(
		[
			'pass' => '',
			'tip'  => __( '请输入阅读密码', 'jinyu-theme-companion'),
		],
		$atts
	);

	$post_id = get_the_ID();
	$key     = 'jinyu_pwd_' . intval( $post_id );

	$unlocked = false;
	if ( $a['pass'] === '' ) {
		// 未设置密码则始终可见
		$unlocked = true;
	} elseif ( isset( $_POST['jinyu_pwd_key'], $_POST['jinyu_pwd_val'] ) && $_POST['jinyu_pwd_key'] === $key ) {
		if ( hash_equals( md5( $a['pass'] ), md5( trim( (string) $_POST['jinyu_pwd_val'] ) ) ) ) {
			$unlocked = true;
			if ( ! headers_sent() ) {
				setcookie( $key, md5( $a['pass'] ), time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
			}
		}
	} elseif ( isset( $_COOKIE[ $key ] ) && is_string( $_COOKIE[ $key ] ) && hash_equals( $_COOKIE[ $key ], md5( $a['pass'] ) ) ) {
		$unlocked = true;
	}

	if ( $unlocked ) {
		return do_shortcode( $content );
	}

	$action = esc_url( get_permalink( $post_id ) );
	return '<form class="jinyu-password-read" method="post" action="' . $action . '">'
		. '<div class="jinyu-password-read-icon" aria-hidden="true">🔒</div>'
		. '<div class="jinyu-password-read-tip">' . esc_html( $a['tip'] ) . '</div>'
		. '<input type="hidden" name="jinyu_pwd_key" value="' . esc_attr( $key ) . '">'
		. '<div class="jinyu-password-read-row">'
		. '<input type="password" class="jinyu-password-read-input" name="jinyu_pwd_val" placeholder="' . esc_attr__( '密码', 'jinyu-theme-companion') . '" autocomplete="off">'
		. '<button type="submit" class="jinyu-password-read-btn">' . __( '查看', 'jinyu-theme-companion') . '</button>'
		. '</div>'
		. '</form>';
};
add_shortcode( 'jinyu_password_read', $jinyu_password_read );

/**
 * 旧标签别名（向后兼容）
 * 线上已有文章可能使用了无前缀的旧标签，保留为别名以免内容失效。
 * 确认全站无旧标签后，可整段删除本区块。
 */
$jinyu_legacy_aliases = [
	'tip'            => $jinyu_tip,
	'download'       => $jinyu_download,
	'login'          => $jinyu_login,
	'comment'        => $jinyu_comment,
	'hide'           => $jinyu_hide,
	'github'         => $jinyu_github,
	'gist'           => $jinyu_gist,
	'jgallery'       => $jinyu_gallery,
	'jy_badge'       => $jinyu_badge,
	'jy_progress'    => $jinyu_progress,
	'jy_tabs'        => $jinyu_tabs,
	'jy_collapse'    => $jinyu_collapse,
	'pre'            => $jinyu_pre,
	'music'          => $jinyu_music,
	'password_read'  => $jinyu_password_read,
];
foreach ( $jinyu_legacy_aliases as $jinyu_old_tag => $jinyu_old_cb ) {
	add_shortcode( $jinyu_old_tag, $jinyu_old_cb );
}

/* ==========================================================================
   视频短代码 [jinyu_video]：原生 HTML5 <video>；识别 B站 链接时自动转为 iframe 直嵌。
   该短代码原注册于金玉主题，因 WordPress.org 主题库禁止主题注册短代码，迁入本配套插件。
   ========================================================================== */
add_shortcode( 'jinyu_video', function ( $atts ) {
	$atts = shortcode_atts(
		[ 'url' => '', 'cover' => '', 'title' => '', 'autoplay' => 'false' ],
		$atts,
		'jinyu_video'
	);
	$url = trim( $atts['url'] );
	if ( ! $url ) {
		return '';
	}
	$bili = jinyu_video_bilibili_src( $url );
	if ( $bili ) {
		$auto = ( 'true' === $atts['autoplay'] || '1' === $atts['autoplay'] ) ? 1 : 0;
		$src  = $bili . '&autoplay=' . $auto;
		return '<div class="jinyu-video jinyu-video-bili" style="position:relative;width:100%;padding-top:56.25%;">'
			. '<iframe loading="lazy" style="position:absolute;width:100%;height:100%;left:0;top:0;border:0;border-radius:8px;" '
			. 'src="' . esc_url( $src ) . '" scrolling="no" frameborder="no" allowfullscreen="true" '
			. 'sandbox="allow-top-navigation allow-same-origin allow-forms allow-scripts"></iframe></div>';
	}
	$out = '<video class="jinyu-video" src="' . esc_url( $url ) . '" controls style="width:100%;border-radius:8px;"';
	if ( $atts['cover'] ) {
		$out .= ' poster="' . esc_url( $atts['cover'] ) . '"';
	}
	$out .= '>' . esc_html( $atts['title'] ) . '</video>';
	return $out;
} );

/**
 * 从链接解析 B站 播放地址：支持 bilibili.com/BVxxx、b23.tv、player.bilibili.com。
 * 返回可用于 iframe 的 player 地址；非 B站 链接返回空字符串。
 */
function jinyu_video_bilibili_src( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) {
		return '';
	}
	$host = strtolower( $host );
	if ( ! preg_match( '/(^|\.)bilibili\.com$/', $host ) && 'b23.tv' !== $host ) {
		return '';
	}
	if ( 'player.bilibili.com' === $host ) {
		return $url;
	}
	if ( preg_match( '/\/video\/(BV[a-zA-Z0-9]+)/', $url, $m ) ) {
		$page = 1;
		if ( preg_match( '/[?&]p=(\d+)/', $url, $pm ) ) {
			$page = max( 1, intval( $pm[1] ) );
		}
		return 'https://player.bilibili.com/player.html?bvid=' . $m[1] . '&page=' . $page . '&as_wide=1&high_quality=1&danmaku=0';
	}
	return '';
}
