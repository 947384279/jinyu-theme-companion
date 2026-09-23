<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 配套插件自有设置存储（与主题 jinyu_options 隔离，避免被主题设置页「导入 / 重置」误清）。
// 读取统一经下列函数；未设置时按约定给默认值：
//   - 核心呈现开关（seo_open / twitter_card_enable / llms_enable）默认开启（'1'）；
//   - 主动提交类（baidu_auto_submit）、禁用类（ld_json_disable）默认关闭（'0'）。
if ( ! function_exists( 'jinyu_companion_settings_cache' ) ) {
	/**
	 * 设置的请求内缓存（单一真源）。
	 *
	 * 为什么需要「可刷新」：插件在加载期（jinyu_companion_maybe_migrate）就会读一次设置，
	 * 而设置页保存发生在之后的 admin_init。若缓存不可刷新，保存后同请求渲染仍读到旧值，
	 * 表现为「第一次保存不生效、第二次才对」。
	 *
	 * @param array|null $write 传入数组则覆盖缓存。
	 * @param bool       $flush 为 true 时丢弃缓存，下次读取重新查库。
	 * @return array
	 */
	function jinyu_companion_settings_cache( ?array $write = null, bool $flush = false ): array {
		static $cache = null;
		if ( $flush ) {
			$cache = null;
		}
		if ( null !== $write ) {
			$cache = $write;
		}
		if ( null === $cache ) {
			$cache = get_option( 'jinyu_companion_settings', [] );
			if ( ! is_array( $cache ) ) {
				$cache = [];
			}
		}
		return $cache;
	}
}

if ( ! function_exists( 'jinyu_companion_get_settings' ) ) {
	function jinyu_companion_get_settings(): array {
		return jinyu_companion_settings_cache();
	}
}

if ( ! function_exists( 'jinyu_companion_save_settings' ) ) {
	/**
	 * 唯一写入口：落库并同步刷新请求内缓存。
	 * 绕过本函数直接 update_option() 会使缓存失真，请勿这么做。
	 *
	 * @param array $settings 完整设置数组。
	 */
	function jinyu_companion_save_settings( array $settings ): void {
		update_option( 'jinyu_companion_settings', $settings );
		jinyu_companion_settings_cache( $settings );
	}
}

if ( ! function_exists( 'jinyu_companion_get_option' ) ) {
	function jinyu_companion_get_option( string $key, $default = '' ) {
		$settings = jinyu_companion_get_settings();
		return $settings[ $key ] ?? $default;
	}
}

// 开关类：显式存 '0' 视为关闭；未设置时返回 $default（核心呈现开关传 true 默认开，
// auto-link / indexnow 等原主题默认关的开关传 false）。
if ( ! function_exists( 'jinyu_companion_is_checked' ) ) {
	function jinyu_companion_is_checked( string $key, bool $default = false ): bool {
		$val = jinyu_companion_get_option( $key, null );
		if ( null === $val ) {
			return $default;
		}
		return $val !== '0';
	}
}

// 一次性迁移：若配套设置中 SMTP 等键为空，且主题 jinyu_get_option 可用，
// 则从主题 jinyu_options 把已存配置（含已透明解密的 SMTP 密码）回填到 companion 设置，
// 使主题「导入 / 重置」不再影响插件配置。迁移仅执行一次（由 jinyu_companion_migrated 标记）。
if ( ! function_exists( 'jinyu_companion_maybe_migrate' ) ) {
	function jinyu_companion_maybe_migrate(): void {
		if ( get_option( 'jinyu_companion_migrated' ) ) {
			return;
		}
		$s = jinyu_companion_get_settings();
		if ( function_exists( 'jinyu_get_option' ) ) {
			$keys    = array( 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pwd', 'smtp_from' );
			$changed = false;
			foreach ( $keys as $k ) {
				if ( empty( $s[ $k ] ) ) {
					$v = jinyu_get_option( $k, '' );
					if ( '' !== $v ) {
						$s[ $k ] = $v;
						$changed = true;
					}
				}
			}
			if ( $changed ) {
				jinyu_companion_save_settings( $s );
			}
		}
		update_option( 'jinyu_companion_migrated', 1 );
	}
	jinyu_companion_maybe_migrate();
}

// 是否安装了主流 SEO 插件（Yoast / Rank Math / AIOSEO / SEOPress / TSF）。
// 这些插件已自带验证元标签、XML Sitemap、结构化数据等能力；配套插件在对应功能上主动让位，
// 避免重复输出造成冲突或权重稀释。
if ( ! function_exists( 'jinyu_seo_plugin_active' ) ) {
	function jinyu_seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| class_exists( 'The_SEO_Framework\\Load' );
	}
}
