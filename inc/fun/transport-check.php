<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP 传输层体检（前台加速分区「传输体检」卡）。
 *
 * 测的是「站内配置之外、由服务器 / CDN 决定的那部分速度」：
 *   文本压缩（Brotli / gzip）、静态资源浏览器缓存、HTML 缓存头、HTTP/3。
 * 这四项任何一项缺失，站内怎么优化都补不回来，且都不在本插件权限内 ——
 * 卡片的定位是「告诉你该找谁改」，而不是替你改。
 *
 * 成本约束（重要）：
 *   - 设置页加载**不发任何出站请求**，只渲染 transient 缓存里的上次结果；
 *   - 仅在用户点「立即体检」时经 AJAX 跑一次（2 个请求，各 8s 超时）；
 *   - 结果缓存 10 分钟，避免反复点击刷请求。
 */

if ( ! defined( 'JINYU_TRANSPORT_TTL' ) ) {
	define( 'JINYU_TRANSPORT_TTL', 600 );
}

/**
 * 探测目标：首页 HTML + 一个必然存在且体积够大的静态资源。
 *
 * 两者分开测是有意的 —— nginx 常把 HTML 与静态资源的压缩 / 缓存头分开配置。
 * 静态资源选 jQuery（WP 内置、约 30KB）而不是主题 style.css：nginx 默认
 * gzip_min_length 1024，几百字节的 CSS 会被跳过压缩，测出来是「未压缩」的假警报。
 *
 * @return array{home:string,static:string}
 */
function jinyu_transport_targets(): array {
	return array(
		'home'   => home_url( '/' ),
		'static' => includes_url( 'js/jquery/jquery.min.js' ),
	);
}

/**
 * 取一次响应：状态码 + 小写头名映射 + 响应体长度。
 *
 * 显式带 Accept-Encoding，否则 WP HTTP 层默认不声明压缩能力，
 * 服务器（尤其 nginx 按 Accept-Encoding 决策的）会直接回未压缩原文，测出来全是「未启用」的假阴性。
 *
 * @return array{error?:string,code?:int,headers?:array,size?:int}
 */
function jinyu_transport_fetch( string $url ): array {
	$res = wp_remote_get(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array( 'Accept-Encoding' => 'gzip, deflate, br' ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return array( 'error' => $res->get_error_message() );
	}

	$headers = array();
	foreach ( wp_remote_retrieve_headers( $res ) as $k => $v ) {
		if ( is_array( $v ) ) {
			$v = end( $v );
		}
		$headers[ strtolower( (string) $k ) ] = (string) $v;
	}

	return array(
		'code'    => ( int ) wp_remote_retrieve_response_code( $res ),
		'headers' => $headers,
		'size'    => strlen( (string) wp_remote_retrieve_body( $res ) ),
	);
}

/**
 * content-encoding → 展示名（空串表示未压缩）。
 */
function jinyu_transport_encoding_label( array $headers ): string {
	$enc = strtolower( trim( (string) ( $headers['content-encoding'] ?? '' ) ) );
	if ( '' === $enc ) {
		return '';
	}
	if ( false !== strpos( $enc, 'br' ) ) {
		return 'Brotli';
	}
	if ( false !== strpos( $enc, 'gzip' ) ) {
		return 'gzip';
	}
	if ( false !== strpos( $enc, 'deflate' ) ) {
		return 'deflate';
	}
	return $enc;
}

/**
 * max-age / Expires → 人类可读时长（秒数为 0 或未设置返回 ''）。
 */
function jinyu_transport_max_age( array $headers ): int {
	$cc = strtolower( (string) ( $headers['cache-control'] ?? '' ) );
	if ( preg_match( '/max-age\s*=\s*(\d+)/', $cc, $m ) ) {
		return (int) $m[1];
	}
	// s-maxage 只对共享缓存（CDN）生效，同样算「有缓存策略」
	if ( preg_match( '/s-maxage\s*=\s*(\d+)/', $cc, $m ) ) {
		return (int) $m[1];
	}
	if ( isset( $headers['expires'] ) ) {
		$ts = strtotime( (string) $headers['expires'] );
		if ( $ts > time() ) {
			return $ts - time();
		}
	}
	return 0;
}

function jinyu_transport_human_seconds( int $sec ): string {
	if ( $sec <= 0 ) {
		return '';
	}
	if ( $sec >= DAY_IN_SECONDS ) {
		$d = (int) round( $sec / DAY_IN_SECONDS );
		/* translators: %d: number of days */
		return sprintf( _n( '%d 天', '%d 天', $d, 'jinyu-theme-companion' ), $d );
	}
	if ( $sec >= HOUR_IN_SECONDS ) {
		$h = (int) round( $sec / HOUR_IN_SECONDS );
		/* translators: %d: number of hours */
		return sprintf( _n( '%d 小时', '%d 小时', $h, 'jinyu-theme-companion' ), $h );
	}
	$m = max( 1, (int) round( $sec / MINUTE_IN_SECONDS ) );
	/* translators: %d: number of minutes */
	return sprintf( _n( '%d 分钟', '%d 分钟', $m, 'jinyu-theme-companion' ), $m );
}

/**
 * 跑一次体检并组装为渲染所需的行数据。
 *
 * 每行：label / value / state(ok|warn|bad|none) / note。
 * state 只表达「要不要处理」，不表达好坏道德判断；none = 信息项（无需改动）。
 *
 * @return array{ok:bool,at:int,error:string,target:string,rows:array}
 */
function jinyu_transport_run(): array {
	$targets = jinyu_transport_targets();
	$home    = jinyu_transport_fetch( $targets['home'] );
	$static  = jinyu_transport_fetch( $targets['static'] );

	$base = array(
		'ok'     => true,
		'at'     => time(),
		'error'  => '',
		'target' => (string) wp_parse_url( $targets['home'], PHP_URL_HOST ),
		'rows'   => array(),
	);

	if ( isset( $home['error'] ) || isset( $static['error'] ) ) {
		$base['ok']    = false;
		$base['error'] = (string) ( $home['error'] ?? $static['error'] );
		return $base;
	}
	if ( ( $home['code'] ?? 0 ) >= 400 || ( $static['code'] ?? 0 ) >= 400 ) {
		$base['ok']    = false;
		$base['error'] = sprintf(
			/* translators: 1: HTTP status of home 2: HTTP status of the static asset */
			__( '目标返回异常状态（首页 %1$d · 静态资源 %2$d）。站点若开启了访问口令 / 防火墙，请把本服务器 IP 加入白名单后重试。', 'jinyu-theme-companion' ),
			(int) ( $home['code'] ?? 0 ),
			(int) ( $static['code'] ?? 0 )
		);
		return $base;
	}

	$hh    = $home['headers'];
	$sh    = $static['headers'];
	$enc_h = jinyu_transport_encoding_label( $hh );
	$enc_s = jinyu_transport_encoding_label( $sh );
	// nginx 常见 gzip_min_length 1024：极小响应不压缩是正常行为，不能报成待处理项。
	$tiny_h = ( $home['size'] ?? 0 ) < 1024;
	$tiny_s = ( $static['size'] ?? 0 ) < 1024;
	$gap_h  = '' === $enc_h && ! $tiny_h;
	$gap_s  = '' === $enc_s && ! $tiny_s;
	$rows   = array();

	// 1) 文本压缩：HTML 与静态资源分开测，任一侧缺失都要提示（nginx 常常只开了一半）。
	if ( ! $gap_h && ! $gap_s ) {
		$both = '' !== $enc_h && '' !== $enc_s;
		$rows[] = array(
			'label' => __( '文本压缩', 'jinyu-theme-companion' ),
			'value' => $both ? ( $enc_h === $enc_s ? $enc_h : $enc_h . ' / ' . $enc_s ) : ( '' !== $enc_h ? $enc_h : $enc_s ),
			'state' => ( $both && $enc_h !== $enc_s ) ? 'warn' : 'ok',
			'note'  => ( $both && $enc_h !== $enc_s )
				? __( 'HTML 与静态资源压缩算法不一致，通常只配了一处。', 'jinyu-theme-companion' )
				: ( ( $tiny_h || $tiny_s )
					? __( '已压缩；另有体积小于 1KB 的响应未压缩，属 nginx 正常行为。', 'jinyu-theme-companion' )
					: __( 'HTML 与静态资源均已压缩传输。', 'jinyu-theme-companion' ) ),
		);
	} else {
		$miss = array();
		if ( $gap_h ) {
			$miss[] = __( 'HTML', 'jinyu-theme-companion' );
		}
		if ( $gap_s ) {
			$miss[] = __( '静态资源', 'jinyu-theme-companion' );
		}
		$rows[] = array(
			'label' => __( '文本压缩', 'jinyu-theme-companion' ),
			/* translators: %s: HTML / 静态资源，可能两者并列 */
			'value' => sprintf( __( '%s未压缩', 'jinyu-theme-companion' ), implode( ' / ', $miss ) ),
			'state' => ( $gap_h && $gap_s ) ? 'bad' : 'warn',
			'note'  => __( '需在 nginx / CDN 开启 gzip 或 Brotli，并确认覆盖对应 MIME 类型。', 'jinyu-theme-companion' ),
		);
	}

	// 2) 静态资源浏览器缓存：回访体验的大头。
	$age_s = jinyu_transport_max_age( $sh );
	$rows[] = array(
		'label' => __( '静态资源缓存', 'jinyu-theme-companion' ),
		'value' => $age_s > 0 ? jinyu_transport_human_seconds( $age_s ) : __( '未设置', 'jinyu-theme-companion' ),
		'state' => $age_s >= DAY_IN_SECONDS ? 'ok' : ( $age_s > 0 ? 'warn' : 'bad' ),
		'note'  => $age_s > 0
			? __( '回访时静态资源可直接命中本地缓存。', 'jinyu-theme-companion' )
			: __( '每次访问都会重新下载静态资源，需在 nginx / CDN 配置 Cache-Control。', 'jinyu-theme-companion' ),
	);

	// 3) HTML 缓存头：多数情况应为 no-store（HTML 由整页缓存接管），是信息项而非问题项。
	$cc_h = strtolower( (string) ( $hh['cache-control'] ?? '' ) );
	if ( '' === $cc_h ) {
		$rows[] = array(
			'label' => __( 'HTML 缓存头', 'jinyu-theme-companion' ),
			'value' => __( '未设置', 'jinyu-theme-companion' ),
			'state' => 'none',
			'note'  => __( '浏览器按启发式缓存处理；HTML 建议由整页缓存或 CDN 显式接管。', 'jinyu-theme-companion' ),
		);
	} elseif ( false !== strpos( $cc_h, 'no-store' ) || false !== strpos( $cc_h, 'no-cache' ) || preg_match( '/max-age\s*=\s*0/', $cc_h ) ) {
		$rows[] = array(
			'label' => __( 'HTML 缓存头', 'jinyu-theme-companion' ),
			'value' => false !== strpos( $cc_h, 'no-store' ) ? 'no-store' : ( false !== strpos( $cc_h, 'no-cache' ) ? 'no-cache' : 'max-age=0' ),
			'state' => 'none',
			'note'  => __( 'HTML 不被共享缓存保存，符合预期 —— 由整页缓存负责加速。', 'jinyu-theme-companion' ),
		);
	} else {
		$rows[] = array(
			'label' => __( 'HTML 缓存头', 'jinyu-theme-companion' ),
			'value' => ( false !== strpos( $cc_h, 'public' ) ? 'public · ' : ( false !== strpos( $cc_h, 'private' ) ? 'private · ' : '' ) ) . ( jinyu_transport_human_seconds( jinyu_transport_max_age( $hh ) ) ?: __( '已设置', 'jinyu-theme-companion' ) ),
			'state' => 'ok',
			'note'  => __( 'CDN / 共享缓存会保存 HTML，请确认内容更新后能及时失效。', 'jinyu-theme-companion' ),
		);
	}

	// 4) HTTP/3：只能靠 alt-svc 宣告判断，测不到不代表 nginx 没开（可能只在 CDN 边缘生效）。
	$h3 = false !== strpos( strtolower( (string) ( $hh['alt-svc'] ?? '' ) ), 'h3' );
	$rows[] = array(
		'label' => __( 'HTTP/3 (QUIC)', 'jinyu-theme-companion' ),
		'value' => $h3 ? __( '已宣告', 'jinyu-theme-companion' ) : __( '未宣告', 'jinyu-theme-companion' ),
		'state' => $h3 ? 'ok' : 'none',
		'note'  => $h3
			? __( '服务器已通过 alt-svc 宣告支持 HTTP/3。', 'jinyu-theme-companion' )
			: __( '响应未带 alt-svc: h3（可能由 CDN 边缘提供，本机探测不可见）。', 'jinyu-theme-companion' ),
	);

	$base['rows'] = $rows;
	return $base;
}

/**
 * **渲染专用**：只读缓存，永不发请求。从未体检过时返回空 rows（卡片显示空态）。
 *
 * 设置页渲染必须走这里 —— 走 jinyu_transport_result() 会在首次打开页面时
 * 偷偷发出两个出站请求，把设置页加载时间拖到网络往返上。
 *
 * @return array{ok:bool,at:int,error:string,target:string,rows:array}
 */
function jinyu_transport_cached(): array {
	$cached = get_transient( 'jinyu_transport_probe' );
	if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
		return $cached;
	}
	return array( 'ok' => true, 'at' => 0, 'error' => '', 'target' => '', 'rows' => array() );
}

/**
 * 取体检结果：默认命中缓存；$force 时重测并刷新缓存（AJAX 走这条路）。
 *
 * @param bool $force true 时强制重测。
 */
function jinyu_transport_result( bool $force = false ): array {
	if ( ! $force ) {
		$cached = get_transient( 'jinyu_transport_probe' );
		if ( is_array( $cached ) && ! empty( $cached['rows'] ) ) {
			return $cached;
		}
	}
	$out = jinyu_transport_run();
	set_transient( 'jinyu_transport_probe', $out, JINYU_TRANSPORT_TTL );
	return $out;
}

/**
 * 结果行 → HTML。首屏渲染与 AJAX 返回共用，避免「结果长什么样」在前后端各写一份。
 */
function jinyu_transport_render_rows( array $data ): string {
	if ( empty( $data['rows'] ) ) {
		$msg = '' !== (string) ( $data['error'] ?? '' )
			? (string) $data['error']
			: __( '尚未体检。点击下方按钮发起一次探测（2 个请求，约 1～2 秒）。', 'jinyu-theme-companion' );
		return '<div class="jyc-tp-empty">' . esc_html( $msg ) . '</div>';
	}

	$html = '<div class="jyc-tp">';
	foreach ( $data['rows'] as $row ) {
		$html .= '<div class="jyc-tp-row is-' . esc_attr( (string) $row['state'] ) . '">'
			. '<div class="jyc-tp-line"><span class="jyc-tp-k">' . esc_html( (string) $row['label'] ) . '</span>'
			. '<span class="jyc-tp-v">' . esc_html( (string) $row['value'] ) . '</span></div>'
			. '<div class="jyc-tp-n">' . esc_html( (string) $row['note'] ) . '</div>'
			. '</div>';
	}
	$html .= '</div>';
	return $html;
}

/**
 * 结果时间 → 「刚刚 / n 分钟前」描述。
 */
function jinyu_transport_ago( int $at ): string {
	$diff = time() - $at;
	if ( $diff < 60 ) {
		return __( '刚刚', 'jinyu-theme-companion' );
	}
	if ( $diff < HOUR_IN_SECONDS ) {
		return sprintf(
			/* translators: %d: minutes ago */
			__( '%d 分钟前', 'jinyu-theme-companion' ),
			(int) floor( $diff / MINUTE_IN_SECONDS )
		);
	}
	return sprintf(
		/* translators: %d: hours ago */
		__( '%d 小时前', 'jinyu-theme-companion' ),
		(int) floor( $diff / HOUR_IN_SECONDS )
	);
}

add_action(
	'wp_ajax_jinyu_transport_probe',
	static function (): void {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		$data = jinyu_transport_result( true );
		wp_send_json_success(
			array(
				'html' => jinyu_transport_render_rows( $data ),
				'ago'  => jinyu_transport_ago( (int) ( $data['at'] ?? time() ) ),
			)
		);
	}
);
