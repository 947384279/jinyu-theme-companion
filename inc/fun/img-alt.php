<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO：正文图片缺失 alt 自动补全。
 *
 * 主题仅对封面/头像写 alt，正文（the_content）里用户上传时漏填 alt 的图片会损害 SEO 与无障碍。
 * 本模块在输出时给「完全没有 alt 属性」的站内图片补 alt：优先取媒体库附件标题，否则用文件名兜底。
 * 已显式写 alt=""（有意留空，装饰图）的不动。
 *
 * 纯输出层过滤，不改写数据库；与主题及其它插件无冲突。
 */

add_filter( 'the_content', 'jinyu_img_alt_autofill', 12 );
function jinyu_img_alt_autofill( $content ) {
	if ( is_admin() ) {
		return $content;
	}
	if ( ! jinyu_companion_is_checked( 'img_alt_enable', false ) ) {
		return $content;
	}
	if ( ! is_singular() ) {
		return $content;
	}
	if ( false === strpos( (string) $content, '<img' ) ) {
		return $content;
	}

	$content = preg_replace_callback(
		'#<img\s+([^>]*?)(/?>)#i',
		static function ( $m ) {
			$attrs = $m[1];
			// 已有 alt 属性（含 alt=""）则不处理
			if ( preg_match( '#\balt\s*=#i', $attrs ) ) {
				return $m[0];
			}
			if ( ! preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs, $s ) ) {
				return $m[0];
			}
			$src = $s[1];
			$alt = '';

			// 优先：本站媒体库附件标题（按 URL 缓存映射，避免图多时每次渲染 N 条 DB 查询）
			$id = jinyu_img_alt_postid_cached( $src );
			if ( $id ) {
				$alt = (string) get_the_title( $id );
			}
			// 兜底：文件名去扩展名 + 连字符转空格
			if ( ! $alt ) {
				$path = (string) parse_url( $src, PHP_URL_PATH );
				$base = basename( $path ?: $src );
				$base = (string) preg_replace( '#\.[a-z0-9]+$#i', '', $base );
				$alt  = ucwords( (string) preg_replace( '#[-_]+#', ' ', $base ) );
			}
			$alt = (string) wp_strip_all_tags( $alt );

			return '<img alt="' . esc_attr( $alt ) . '" ' . $attrs . ( substr( $m[2], 0, 1 ) === '/' ? '/>' : '>' );
		},
		(string) $content
	);

	return $content;
}

/**
 * attachment_url_to_postid 的缓存包装：0 也缓存（防反复查询同一外链/已删附件），
 * 请求内静态 memo + 24h transient 两级，写库成本摊薄到每 URL 每天一次。
 */
function jinyu_img_alt_postid_cached( string $url ): int {
	static $memo = [];
	if ( isset( $memo[ $url ] ) ) {
		return $memo[ $url ];
	}
	if ( ! function_exists( 'attachment_url_to_postid' ) ) {
		return 0;
	}
	$key     = 'jyc_alt_pid_' . md5( $url );
	$cached  = get_transient( $key );
	if ( false !== $cached && is_numeric( $cached ) ) {
		$memo[ $url ] = (int) $cached;
		return (int) $cached;
	}
	$id = (int) attachment_url_to_postid( $url );
	set_transient( $key, $id, DAY_IN_SECONDS );
	$memo[ $url ] = $id;
	return $id;
}
