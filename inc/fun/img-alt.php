<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 图片 SEO：输出层过滤（the_content），不改写数据库。
 *
 * 1) alt 补全：正文里「完全没有 alt 属性」的站内图片自动补——优先媒体库附件标题，文件名兜底。
 *    已显式写 alt=""（有意留空的装饰图）的不动。
 * 2) 尺寸补全：缺 width/height 的站内图片从媒体库元数据补齐，浏览器可提前占位，改善 CLS（Core Web Vitals）。
 *    WP 生成缩略图的文件名带 -WxH 后缀，精确取该尺寸；原图取元数据全尺寸。
 *
 * 与主题及其它插件无冲突：各功能独立开关（img_alt_enable / img_dim_enable）。
 */

add_filter( 'the_content', 'jinyu_img_seo_filter', 12 );
function jinyu_img_seo_filter( $content ) {
	if ( is_admin() ) {
		return $content;
	}
	$fix_alt = jinyu_companion_is_checked( 'img_alt_enable', false );
	$fix_dim = jinyu_companion_is_checked( 'img_dim_enable', true );
	if ( ! $fix_alt && ! $fix_dim ) {
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
		static function ( $m ) use ( $fix_alt, $fix_dim ) {
			$attrs   = $m[1];
			$changed = false;

			// 1) alt 补全：完全没有 alt 属性才处理
			if ( $fix_alt && ! preg_match( '#\balt\s*=#i', $attrs ) ) {
				if ( preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs, $s ) ) {
					$src = $s[1];
					$alt = '';

					// 优先：本站媒体库附件标题
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

					$attrs   = 'alt="' . esc_attr( $alt ) . '" ' . $attrs;
					$changed = true;
				}
			}

			// 2) 尺寸补全：width / height 都缺才补（只缺一个说明用户有意控制，不干预）
			if ( $fix_dim && ! preg_match( '#\bwidth\s*=#i', $attrs ) && ! preg_match( '#\bheight\s*=#i', $attrs ) ) {
				if ( preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs, $s ) ) {
					$dims = jinyu_img_seo_dims_cached( $s[1] );
					if ( $dims ) {
						$attrs  .= ' width="' . $dims[0] . '" height="' . $dims[1] . '"';
						$changed = true;
					}
				}
			}

			if ( ! $changed ) {
				return $m[0];
			}
			return '<img ' . trim( $attrs ) . ( substr( $m[2], 0, 1 ) === '/' ? ' />' : '>' );
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
	if ( ! $id ) {
		// 对象存储 / CDN 改写过的 URL（域名不同 + 又拍云式 !参数）：还原为本站 URL 再查一次。
		$clean = (string) preg_replace( '#!.*$#', '', $url );
		$h     = (string) parse_url( $clean, PHP_URL_HOST );
		$hh    = (string) parse_url( home_url(), PHP_URL_HOST );
		if ( $h && $hh && 0 !== strcasecmp( $h, $hh ) ) {
			$id = (int) attachment_url_to_postid( str_ireplace( $h, $hh, $clean ) );
		}
	}
	set_transient( $key, $id, DAY_IN_SECONDS );
	$memo[ $url ] = $id;
	return $id;
}

/**
 * 取图片真实尺寸：[宽, 高]。URL 无法映射到媒体库时返回 []（外链图不猜尺寸）。
 * 缩略图文件名带 -WxH 后缀直接解析（最精确）；原图用附件元数据宽高。
 */
function jinyu_img_seo_dims_cached( string $url ): array {
	static $memo = [];
	if ( isset( $memo[ $url ] ) ) {
		return $memo[ $url ];
	}
	$key = 'jyc_img_dim_' . md5( $url );
	$hit = get_transient( $key );
	if ( is_array( $hit ) && isset( $hit[0], $hit[1] ) ) {
		$memo[ $url ] = $hit;
		return $hit;
	}
	$dims = [];
	$path = (string) parse_url( $url, PHP_URL_PATH );
	$file = basename( (string) preg_replace( '#!.*$#', '', $path ?: $url ) );
	if ( preg_match( '#-(\d+)x(\d+)\.[a-z0-9]+$#i', $file, $m ) ) {
		$dims = [ (int) $m[1], (int) $m[2] ];
	} else {
		$id = jinyu_img_alt_postid_cached( $url );
		if ( $id ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( $meta && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				$dims = [ (int) $meta['width'], (int) $meta['height'] ];
			}
		}
	}
	set_transient( $key, $dims, DAY_IN_SECONDS );
	$memo[ $url ] = $dims;
	return $dims;
}

/* ───────────────────────── 图片 SEO 体检 ───────────────────────── */

add_action( 'wp_ajax_jinyu_img_audit', 'jinyu_img_seo_audit_ajax' );

/**
 * 图片 SEO 体检（面板按钮）：全站已发布文章正文扫描缺 alt / 缺尺寸的 <img>。
 * 纯只读查询，结果缓存 12h（force=1 强制重扫），供作者对症补内容。
 */
function jinyu_img_seo_audit_ajax() {
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
	}
	$force = isset( $_POST['force'] ) && '1' === $_POST['force'];
	wp_send_json_success( jinyu_img_seo_audit( $force ) );
}

function jinyu_img_seo_audit( bool $force = false ): array {
	$key = 'jyc_img_audit_v3';
	if ( ! $force ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT ID, post_title, post_content
		 FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_type IN ('post','page')
		   AND post_content LIKE '%<img%'"
	);

	// 口径：统计的是数据库原文。前台输出层会自动补 alt（附件标题/文件名兜底）与尺寸（媒体库元数据），
	// 因此分级呈现：①真问题=前台补不了的（外链图/已删附件 stuck、只能文件名兜底 alt weak）；
	// ②已自动兜底=原文没写但前台已补齐的，属正面信息，不作为问题列出。
	$imgs = 0; $no_alt = 0; $no_dim = 0; $stuck_dim = 0; $weak_alt = 0; $found = []; $auto_found = [];
	foreach ( $rows as $r ) {
		if ( ! preg_match_all( '#<img\s([^>]*?)/?>#i', (string) $r->post_content, $ms ) ) {
			continue;
		}
		$na = 0; $nd = 0; $ns = 0; $nw = 0;
		foreach ( $ms[1] as $attrs ) {
			$imgs++;
			$src = '';
			if ( preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs, $s ) ) {
				$src = $s[1];
			}
			if ( ! preg_match( '#\balt\s*=#i', $attrs ) ) {
				$no_alt++; $na++;
				$id = $src ? jinyu_img_alt_postid_cached( $src ) : 0;
				if ( ! $id || ! get_the_title( $id ) ) {
					$weak_alt++; $nw++;
				}
			}
			if ( ! preg_match( '#\bwidth\s*=#i', $attrs ) ) {
				$no_dim++; $nd++;
				$dims = $src ? jinyu_img_seo_dims_cached( $src ) : [];
				if ( ! $dims ) {
					$stuck_dim++; $ns++;
				}
			}
		}
		if ( $ns || $nw ) {
			// 真问题：有前台补不了的项。
			$found[] = [ 'id' => (int) $r->ID, 'title' => $r->post_title, 'a' => $na, 'd' => $nd, 's' => $ns, 'w' => $nw ];
		} elseif ( $na || $nd ) {
			// 已自动兜底：原文没写但前台能补齐。
			$auto_found[] = [ 'id' => (int) $r->ID, 'title' => $r->post_title, 'a' => $na, 'd' => $nd ];
		}
	}

	// 真问题排序：外链图最多在前，其次文件名兜底 alt 多的
	usort( $found, static function ( $x, $y ) {
		return ( $y['s'] * 100 + $y['w'] * 10 ) - ( $x['s'] * 100 + $x['w'] * 10 );
	} );
	$top  = array_slice( $found, 0, 20 );
	$list = [];
	foreach ( $top as $it ) {
		$list[] = [
			'url'   => (string) get_permalink( $it['id'] ),
			'edit'  => (string) get_edit_post_link( $it['id'], 'raw' ),
			'title' => $it['title'],
			'a'     => $it['a'],
			'd'     => $it['d'],
			's'     => $it['s'],
			'w'     => $it['w'],
		];
	}
	// 已兜底明细（默认折叠展示，取缺得最多的前 10 篇）
	usort( $auto_found, static function ( $x, $y ) {
		return ( $y['a'] + $y['d'] ) - ( $x['a'] + $x['d'] );
	} );
	$auto_top  = array_slice( $auto_found, 0, 10 );
	$auto_list = [];
	foreach ( $auto_top as $it ) {
		$auto_list[] = [
			'url'   => (string) get_permalink( $it['id'] ),
			'title' => $it['title'],
			'a'     => $it['a'],
			'd'     => $it['d'],
		];
	}

	$out = [
		'posts'         => count( $rows ),
		'imgs'          => $imgs,
		'no_alt'        => $no_alt,
		'no_dim'        => $no_dim,
		'stuck_dim'     => $stuck_dim,
		'weak_alt'      => $weak_alt,
		'auto_alt'      => $no_alt - $weak_alt,
		'auto_dim'      => $no_dim - $stuck_dim,
		'auto_articles' => count( $auto_found ),
		'list'          => $list,
		'list_total'    => count( $found ),
		'auto_list'     => $auto_list,
	];
	set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}
