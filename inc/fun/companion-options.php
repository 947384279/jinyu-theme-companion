<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 配套插件自有设置存储（与主题 jinyu_options 隔离，避免被主题设置页「导入 / 重置」误清）。
// 读取统一经下列函数；未设置时按约定给默认值：
//   - 核心呈现开关（seo_open / twitter_card_enable / llms_enable）默认开启（'1'）；
//   - 主动提交类（baidu_auto_submit）、禁用类（ld_json_disable）默认关闭（'0'）。
if ( ! function_exists( 'jinyu_companion_get_settings' ) ) {
	function jinyu_companion_get_settings(): array {
		static $s = null;
		if ( null === $s ) {
			$s = get_option( 'jinyu_companion_settings', [] );
			if ( ! is_array( $s ) ) {
				$s = [];
			}
		}
		return $s;
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
				update_option( 'jinyu_companion_settings', $s );
			}
		}
		update_option( 'jinyu_companion_migrated', 1 );
	}
	jinyu_companion_maybe_migrate();
}
