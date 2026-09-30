<?php
/**
 * 插件自持原语（companion primitives）。
 *
 * 独立性契约：本插件不依赖金玉主题，也不复用主题的同名函数。内部一律调用
 * jinyu_companion_* 前缀的原语，由本插件独占定义、行为不随主题是否在场而变化。
 *
 * 历史上这里叫 theme-shims.php，语义是「主题在场用主题版、缺席才降级」——
 * 那等于把插件的行为绑定在特定主题实现上（封面取值、缓存介质、限流策略全由主题决定），
 * 主题一升级插件行为就跟着变，且主题侧无法感知自己被插件依赖。已废弃该模式。
 *
 * 两类符号分开放：
 * A. jinyu_companion_* —— 插件独占，无 function_exists 守卫（前缀独占，
 *    守卫只会掩盖重复加载这类真实错误，问题暴露越早越好）。
 * B. 扩展点契约（jinyu_track_visit_source / jinyu_has_external_page_cache）——
 *    公开给任意主题或插件实现的钩子式函数，本插件只给空实现兜底，故带守卫。
 *
 * 一律不读主题的私有数据模型（自定义封面字段、类目封面回退等）：那些是主题内部实现，
 * 插件若依赖就等于反向绑定。文章封面只认 WP 标准的特色图像。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ── A1. 缓存 ─────────────────────────────────────────────────────────────
 * transient 承载：站点装了持久对象缓存时 WP 自动落到对象缓存，未装则落 options 表，
 * 两种环境都能用，不依赖主题那套 Memcached 封装。 */

function jinyu_companion_cache_key( string $seed ): string {
	return 'jyc_' . md5( $seed );
}

function jinyu_companion_cache_get( string $key ) {
	return get_transient( $key );
}

function jinyu_companion_cache_set( string $key, $val, int $ttl = 3600 ): bool {
	return ( bool ) set_transient( $key, $val, $ttl );
}

/* ── A2. 用户 meta ID 列表（社交关注 / 粉丝 / 关注的分类）─────────────────── */

function jinyu_companion_meta_ids( int $uid, string $key ): array {
	if ( $uid <= 0 ) {
		return array();
	}
	$raw = get_user_meta( $uid, $key, true );
	if ( '' === $raw || null === $raw || false === $raw ) {
		return array();
	}
	$ids = array_map( 'intval', (array) $raw );
	return array_values( array_unique( array_filter( $ids ) ) );
}

/* ── A3. 文章封面 ────────────────────────────────────────────────────────
 * 只取 WP 标准特色图像。主题另有「自定义字段封面 / 类目封面兜底 / 死图探测」等能力，
 * 那是主题的数据模型，插件不感知；站点需要统一封面时，用插件 SEO 面板的全局 og_image。 */

function jinyu_companion_post_cover( int $post_id, string $size = 'large' ): string {
	if ( $post_id <= 0 ) {
		return '';
	}
	$thumb_id = get_post_thumbnail_id( $post_id );
	if ( ! $thumb_id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $thumb_id, $size );
	return $url ? (string) $url : '';
}

/* ── A4. 批量取文章（带缓存）────────────────────────────────────────────── */

function jinyu_companion_hydrate_posts( array $ids, string $key, int $ttl = 600 ): array {
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) {
		return array();
	}
	$cached = jinyu_companion_cache_get( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$posts = get_posts(
		array(
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'posts_per_page'      => count( $ids ),
			'ignore_sticky_posts' => true,
		)
	);
	$posts = is_array( $posts ) ? $posts : array();
	jinyu_companion_cache_set( $key, $posts, $ttl );
	return $posts;
}

/* ── A5. WebP 地址转换 ───────────────────────────────────────────────────
 * 仅在上传目录内、且同名 .webp 实体文件确实存在时才改写，避免返回死链。
 * 转换器由谁生成 .webp 不在本插件职责内（主题或图片插件均可），这里只做「有则用」。 */

function jinyu_companion_webp_url( string $url ): string {
	if ( '' === $url || ! is_string( $url ) ) {
		return $url;
	}
	if ( preg_match( '/\.(?:webp|svg|gif)(?:$|\?)/i', $url ) ) {
		return $url;
	}
	$up = wp_get_upload_dir();
	if ( empty( $up['baseurl'] ) || empty( $up['basedir'] ) ) {
		return $url;
	}
	if ( 0 !== strpos( $url, $up['baseurl'] ) ) {
		return $url; // 非本站上传目录（外链 / 主题资源）：不处理.
	}
	$rel      = substr( $url, strlen( $up['baseurl'] ) );
	$rel_path = ltrim( wp_parse_url( $rel, PHP_URL_PATH ) ?: $rel, '/' );
	$src_file = $up['basedir'] . '/' . $rel_path;
	$webp_rel = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $rel_path );
	if ( null === $webp_rel || $webp_rel === $rel_path ) {
		return $url;
	}
	// 源图必须存在，否则说明 URL 指向的是尺寸变体/缩略图，直接改写会 404.
	if ( ! is_file( $src_file ) || ! is_file( $up['basedir'] . '/' . $webp_rel ) ) {
		return $url;
	}
	return $up['baseurl'] . '/' . $webp_rel;
}

/* ── A6. CSP nonce 属性 ──────────────────────────────────────────────────
 * nonce 必须与响应头 Content-Security-Policy 里的值一致才有效。本插件不下发 CSP 头，
 * 默认返回空串（不加属性，脚本照常执行）；站点真启用了 CSP，由下发方经过滤器提供值。
 * 绝不自行生成随机 nonce —— 那会让内联脚本被自己的 CSP 拦掉。 */

function jinyu_companion_csp_nonce_attr(): string {
	$nonce = (string) apply_filters( 'jinyu_companion_csp_nonce', '' );
	if ( '' === $nonce ) {
		return '';
	}
	return ' nonce="' . esc_attr( $nonce ) . '"';
}

/* ── A7. 限流（滑动窗口计数）──────────────────────────────────────────────
 * 供海报生成 / Web Vitals / 验证码等匿名端点防滥用。 */

function jinyu_companion_rate_limit( string $action, int $limit, int $window ): bool {
	// 默认只信 REMOTE_ADDR（不可伪造）。XFF 可被客户端任意伪造，仅当站点确实部署了
	// 反向代理 / CDN 并经过滤器显式声明信任时才采信。
	$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
	if ( apply_filters( 'jinyu_companion_rate_limit_trust_proxy', false ) ) {
		$xff      = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' );
		$parts    = array_map( 'trim', explode( ',', $xff ) );
		$proxy_ip = trim( (string) end( $parts ) );
		if ( '' !== $proxy_ip ) {
			$ip = $proxy_ip;
		}
	}
	if ( '' === $ip ) {
		return true; // 无法识别来源时放行，避免误杀.
	}
	$key = 'jyc_rl_' . md5( $action . '|' . $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= $limit ) {
		return false;
	}
	set_transient( $key, $n + 1, $window );
	return true;
}

/* ── B. 扩展点契约（公开给任意主题 / 插件实现，本插件只给空实现兜底）───────── */

if ( ! function_exists( 'jinyu_track_visit_source' ) ) {
	/**
	 * 访问来源统计。整页缓存命中路径不触发 template_redirect，由 page-cache 显式调用。
	 * 真实实现可由统计类插件提供；无人实现时为空操作。
	 */
	function jinyu_track_visit_source(): void {
	}
}

if ( ! function_exists( 'jinyu_has_external_page_cache' ) ) {
	/**
	 * 站点是否已有第三方整页缓存。返回 true 时本插件的整页缓存自动让位，
	 * 避免两层 HTML 缓存内容不同步。
	 */
	function jinyu_has_external_page_cache(): bool {
		return false;
	}
}
