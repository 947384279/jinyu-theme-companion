<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 主题兼容层（theme shims）。
 *
 * 本插件设计为可脱离金玉主题独立运行（.org 外发插件不应依赖特定主题）。
 * 原属主题、被各功能模块调用的原语在此用 function_exists() 守卫提供兜底实现：
 * 主题在场时使用主题版本（优先），主题缺席时降级到等价实现，站点不会白屏 / 致命。
 */

// 用户 meta 列表读取（社交关注 / 粉丝）
if ( ! function_exists( 'jinyu_meta_ids' ) ) {
	function jinyu_meta_ids( $uid, string $key ) {
		$raw = get_user_meta( $uid, $key, true );
		if ( empty( $raw ) ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'trim', $raw ) ) );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) ) );
	}
}

// 缓存原语：主题用 Memcached 封装，此处用 WP transients 兜底
if ( ! function_exists( 'jinyu_cache_key' ) ) {
	function jinyu_cache_key( string $seed ): string {
		return 'jinyu_' . md5( (string) $seed );
	}
}
if ( ! function_exists( 'jinyu_cache_get' ) ) {
	function jinyu_cache_get( string $key ) {
		return get_transient( $key );
	}
}
if ( ! function_exists( 'jinyu_cache_set' ) ) {
	function jinyu_cache_set( string $key, $val, int $ttl = 3600 ): bool {
		return (bool) set_transient( $key, $val, $ttl );
	}
}
if ( ! function_exists( 'jinyu_cache_flush' ) ) {
	/**
	 * 内容变更后的缓存失效：整页缓存（代际失效）+ llms.txt 输出缓存。
	 * 不再是空操作 —— 否则插件独立运行时发布/更新文章最长 TTL 内一直是旧页面。
	 */
	function jinyu_cache_flush(): void {
		if ( function_exists( 'jinyu_page_cache_flush' ) ) {
			jinyu_page_cache_flush();
		}
		delete_transient( 'jinyu_llms_index_cache' );
		delete_transient( 'jinyu_llms_full_cache' );
	}
}

// 用缓存填充 WP_Post 对象（相关文章 / 系列文章）
if ( ! function_exists( 'jinyu_hydrate_posts_cached' ) ) {
	function jinyu_hydrate_posts_cached( array $ids, string $key, int $ttl = 600 ): array {
		if ( empty( $ids ) ) {
			return array();
		}
		$cached = jinyu_cache_get( $key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$posts = get_posts(
			array(
				'post__in'            => $ids,
				'orderby'            => 'post__in',
				'posts_per_page'      => count( $ids ),
				'ignore_sticky_posts' => true,
			)
		);
		if ( ! is_array( $posts ) ) {
			$posts = array();
		}
		jinyu_cache_set( $key, $posts, $ttl );
		return $posts;
	}
}

// 文章封面：无主题时回退特色图像
if ( ! function_exists( 'jinyu_get_post_cover' ) ) {
	function jinyu_get_post_cover( $post_id, string $size = 'large', bool $fallback = true ) {
		$id = get_post_thumbnail_id( $post_id );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, $size );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}
}

// WebP 地址转换：无主题时原样返回
if ( ! function_exists( 'jinyu_img_to_webp_url' ) ) {
	function jinyu_img_to_webp_url( string $url ) {
		return $url;
	}
}

// CSP nonce 属性：无主题时返回空
if ( ! function_exists( 'jinyu_csp_nonce_attr' ) ) {
	function jinyu_csp_nonce_attr(): string {
		return '';
	}
}

// 访问来源统计：无主题时空操作
if ( ! function_exists( 'jinyu_track_visit_source' ) ) {
	function jinyu_track_visit_source(): void {
	}
}

// 外部整页缓存检测：无主题时返回 false（page-cache 据此让位）
if ( ! function_exists( 'jinyu_has_external_page_cache' ) ) {
	function jinyu_has_external_page_cache(): bool {
		return false;
	}
}

// 限流检查（真实实现）：按 IP + 动作做滑动窗口计数（transient 承载）。
// 主题在场时优先用主题版（Memcached）；主题缺席时本实现兜底，
// 供海报生成 / Web Vitals 上报等匿名端点防滥用，不再是「恒放行」。
if ( ! function_exists( 'jinyu_rate_limit_check' ) ) {
	function jinyu_rate_limit_check( string $action, int $limit, int $window ): bool {
		$raw_ip = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '' );
		$ip     = trim( explode( ',', $raw_ip )[0] );
		if ( '' === $ip ) {
			return true; // 无法识别来源时放行，避免误杀
		}
		$key = 'jyc_rl_' . md5( $action . '|' . $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}
}
