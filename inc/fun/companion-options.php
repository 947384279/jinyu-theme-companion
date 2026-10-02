<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 配套插件自有设置存储（与主题 jinyu_options 隔离，避免被主题设置页「导入 / 重置」误清）。
// 读取统一经下列函数；未设置时按约定给默认值：
// - 核心呈现开关（seo_open / twitter_card_enable / llms_enable）默认开启（'1'）；
// - 主动提交类（baidu_auto_submit）、禁用类（ld_json_disable）默认关闭（'0'）。
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
		return '0' !== $val;
	}
}

// 一次性迁移：SMTP 配置从主题 jinyu_options 回填到 companion 独立设置（含授权码加密）。
//
// 【为什么挂在 init 而不是插件加载期】插件先于主题载入：加载期主题 functions.php 尚未执行，
// jinyu_get_option() 根本不存在，早期版本在此处的迁移被 function_exists 判定静默跳过，
// 表现为「SMTP 面板已迁到插件、但旧配置一个都没跟过来」。改到 init 阶段后主题必已就绪。
// 迁移幂等：由 jinyu_companion_smtp_migrated 标记，仅执行一次；换主题/未装主题时自动跳过。
if ( ! function_exists( 'jinyu_companion_maybe_migrate_smtp' ) ) {
	/**
	 * SMTP 配置项迁移：把主题侧已存的值回填到插件，并统一为插件的加密格式。
	 *
	 * 注意：回调不接收任何参数，必须以 accepted_args=0 注册——
	 * add_action 默认 accepted_args=1，WP 会把 $value 透传给回调，
	 * 与形参类型不匹配时直接 TypeError（整站 500）。
	 */
	function jinyu_companion_maybe_migrate_smtp(): void {
		// 迁移版本：库中标记值小于该版本时重跑（修复存量脏数据用，例如历史上的「密文套密文」）。
		$version = 2;
		if ( (int) get_option( 'jinyu_companion_smtp_migrated', 0 ) >= $version ) {
			return;
		}
		// 先置位：主题缺席时不必每次请求重试
		update_option( 'jinyu_companion_smtp_migrated', $version );
		if ( ! function_exists( 'jinyu_get_option' ) ) {
			return; // 主题未启用，无可迁移数据
		}

		$s       = jinyu_companion_get_settings();
		$changed = false;

		// 标量配置项：仅在本插件尚无值时回填，不覆盖用户已在插件里改过的值。
		// smtp_enable 决定「是否接管全站发信」，必须与主题时代的语义一致（主题 sdt 默认 0=不接管），
		// 否则原先关闭 SMTP 的站点会在迁移后被强制改道。
		$keys = array( 'smtp_enable', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_from' );
		foreach ( $keys as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				continue;
			}
			$v = jinyu_get_option( $k, '' );
			if ( null === $v || '' === $v ) {
				continue;
			}
			$s[ $k ] = is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v;
			$changed = true;
		}

		// 授权码：统一落成插件用的 jinyu_enc2:: 密文。
		// 为什么需要逐级剥离：主题 crypto 已不再把 smtp_pwd 列为敏感字段，读到的可能是
		// 主题侧历史密文（jinyu_enc:: / jinyu_enc2::）；直接拿去再加密会形成「密文套密文」，
		// 发信时只解开一层 → 拿到的仍是密文 → SMTP 535 认证失败。最多解 3 层防御异常数据。
		$stored = isset( $s['smtp_pwd'] ) ? (string) $s['smtp_pwd'] : '';
		if ( '' === $stored ) {
			$stored = (string) jinyu_get_option( 'smtp_pwd', '' );
		}
		if ( '' !== $stored ) {
			$plain  = $stored;
			$layers = 0;
			while ( preg_match( '#^jinyu_enc2?::#', $plain ) && $layers < 3 ) {
				$plain = (string) jinyu_companion_decrypt( $plain );
				++$layers;
			}
			// 解到最后仍是密文 → 数据已损坏（密钥变更过），宁可不动也不写坏值。
			// $layers > 1 表示原值是嵌套密文；非 enc2 前缀表示明文或旧格式——两种情况都要重写。
			$need_write = '' !== $plain
				&& ! preg_match( '#^jinyu_enc2?::#', $plain )
				&& ( $layers > 1 || 0 !== strpos( $stored, 'jinyu_enc2::' ) );
			if ( $need_write ) {
				$s['smtp_pwd'] = jinyu_companion_encrypt( $plain );
				$changed       = true;
			}
		}

		if ( $changed ) {
			jinyu_companion_save_settings( $s );
		}
	}
	add_action( 'init', 'jinyu_companion_maybe_migrate_smtp', 5, 0 );
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
