<?php
/**
 * 主题更新通道（自主题 inc/fun/update.php 迁入）。
 *
 * 背景：主题回归纯呈现层后，后台「检查更新」按钮的 AJAX 处理端（wp_ajax_jinyu_check_update）
 * 一度无实现，点击会被 WP 的 wp_die() 挡下返回 HTML，前端 r.json() 报
 * "Unexpected token < in JSON at position 0"。本文件补回处理端并同时恢复 WP 原生更新通道。
 *
 * 能力：
 * - 驱动仪表盘「有可用更新」与一键升级（pre_set_site_transient_update_themes 注入）。
 * - 供应链防护：① 版本 JSON 经 RSA-SHA256 签名，内置公钥验签；② 更新包 zip 校验 sha256。
 *
 * 期望 JSON：
 * {"version":"1.2.3","changelog":"...","download_url":"https://.../jinyu.zip",
 *  "detail_url":"...","hash":"<sha256 of zip>","signature":"<base64(RSA-SHA256(payload))>"}
 *
 * 签名载荷（固定字段顺序，ASCII 0x1F 分隔）：
 *   version \x1F changelog \x1F download_url \x1F detail_url \x1F hash
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* 更新源：优先沿用 wp-config.php 中的历史常量名 JINYU_UPDATE_SERVER，便于站点自定义覆盖。 */
if ( ! defined( 'JINYU_COMPANION_UPDATE_SERVER' ) ) {
	define(
		'JINYU_COMPANION_UPDATE_SERVER',
		defined( 'JINYU_UPDATE_SERVER' ) ? JINYU_UPDATE_SERVER : 'https://update.qicaiyun.top/jinyu-update.json'
	);
}

/* 验签公钥（仅公钥随包分发，私钥仅发布者持有）。 */
define(
	'JINYU_COMPANION_UPDATE_PUBKEY',
	"-----BEGIN PUBLIC KEY-----\n"
	. "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqLakGXi75f8FkiajP3La\n"
	. "C85un5mTfg/KC9I6sadx895kG1yf1zirVqml5li+r+SX/pUWWfZnRunPewhXV/UC\n"
	. "yDbBwP3MmkydDQQpy5en4CXjfbWQN974NjL2XQpBUQhPDvtXi6GSy1iG9J3jnxn4\n"
	. "mS1dK1ceb6C+5ulDqZPVn1zeWzqbXhRX8BFWviSNUDqQSZiRfZL9syGNHpbUt2ey\n"
	. "8cnrvkOUJU8IQRhxYiTpF0aBNNWQK8lw5AUIrgSi+IeEmyXj/0iX0ab/0v0O7pXy\n"
	. "B23GHORyOPUrorod65Au2hWt8PYsEvQSMm0YK7gToj+67RmAddUxtKm0zYW9pIyl\n"
	. "pwIDAQAB\n"
	. "-----END PUBLIC KEY-----\n"
);

if ( ! function_exists( 'jinyu_companion_update_target_slug' ) ) {
	/**
	 * 更新通道只服务「金玉」主题：非该主题在场时返回空，避免误注入别的主题。
	 *
	 * @return string 主题目录名，或空字符串。
	 */
	function jinyu_companion_update_target_slug(): string {
		$slug  = (string) get_template();
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return '';
		}

		$textdomain = strtolower( (string) $theme->get( 'TextDomain' ) );
		if ( 'jinyu' !== $textdomain && 'jinyu' !== strtolower( $slug ) ) {
			return '';
		}

		return $slug;
	}
}

if ( ! function_exists( 'jinyu_companion_update_payload' ) ) {
	/**
	 * 构造待验签载荷：固定字段顺序 + 0x1F 分隔，两端字节一致。
	 *
	 * @param array $data 解析后的 JSON 数组。
	 * @return string
	 */
	function jinyu_companion_update_payload( array $data ): string {
		$parts = [];
		foreach ( [ 'version', 'changelog', 'download_url', 'detail_url', 'hash' ] as $field ) {
			$parts[] = isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
		}

		return implode( "\x1f", $parts );
	}
}

if ( ! function_exists( 'jinyu_companion_verify_signature' ) ) {
	/**
	 * 用内置公钥验证版本 JSON 签名。
	 *
	 * @param array $data 含 signature 字段的数据数组。
	 * @return bool
	 */
	function jinyu_companion_verify_signature( array $data ): bool {
		if ( empty( $data['signature'] ) || ! defined( 'JINYU_COMPANION_UPDATE_PUBKEY' ) ) {
			return false;
		}

		$sig = base64_decode( (string) $data['signature'], true );
		if ( false === $sig ) {
			return false;
		}

		// openssl_verify 缺失（极简 PHP 构建）时按失败处理，绝不放行未验签数据。
		if ( ! function_exists( 'openssl_verify' ) ) {
			return false;
		}

		return 1 === openssl_verify(
			jinyu_companion_update_payload( $data ),
			$sig,
			JINYU_COMPANION_UPDATE_PUBKEY,
			OPENSSL_ALGO_SHA256
		);
	}
}

if ( ! function_exists( 'jinyu_companion_fetch_update_info' ) ) {
	/**
	 * 拉取并验签更新源返回的版本 JSON。
	 *
	 * @param bool $force 跳过 1 小时缓存强制重新拉取（后台「检查更新」用）。
	 * @return array|null 验签通过返回数据数组，任何异常返回 null。
	 */
	function jinyu_companion_fetch_update_info( bool $force = false ): ?array {
		$url = trim( (string) JINYU_COMPANION_UPDATE_SERVER );
		if ( '' === $url ) {
			return null;
		}

		$cache_key = 'jinyu_companion_update_info';
		if ( $force ) {
			delete_transient( $cache_key );
		} else {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : null;
			}
		}

		$mark_failed = static function ( bool $verify_failed ) use ( $cache_key ): void {
			// 缓存哨兵 'err'：get_transient 区分不出「没缓存」与「缓存了 null」，
			// 用字符串哨兵避免每次请求都去打更新源。
			set_transient( $cache_key, 'err', HOUR_IN_SECONDS );
			if ( $verify_failed ) {
				set_transient( 'jinyu_companion_update_verify_failed', true, HOUR_IN_SECONDS );
			} else {
				delete_transient( 'jinyu_companion_update_verify_failed' );
			}
		};

		$resp = wp_remote_get(
			$url,
			[
				'timeout' => 15,
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			$mark_failed( false );

			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			$mark_failed( false );

			return null;
		}

		// 验签不通过一律视为不可信：不提示更新、不给出下载地址。
		if ( ! jinyu_companion_verify_signature( $data ) ) {
			$mark_failed( true );

			return null;
		}

		set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		delete_transient( 'jinyu_companion_update_verify_failed' );

		return $data;
	}
}

if ( ! function_exists( 'jinyu_companion_inject_theme_update' ) ) {
	/**
	 * 将自有更新信息注入 update_themes transient，驱动原生更新提示与一键升级。
	 *
	 * @param mixed $transient update_themes transient 对象。
	 * @return mixed
	 */
	function jinyu_companion_inject_theme_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$slug = jinyu_companion_update_target_slug();
		if ( '' === $slug ) {
			return $transient;
		}

		$theme   = wp_get_theme( $slug );
		$current = (string) $theme->get( 'Version' );

		// 标记已检查，避免 WP 回退到 wp.org 查询同名主题（本主题不在 wp.org）。
		$transient->checked[ $slug ] = $current;

		$info = jinyu_companion_fetch_update_info();
		if ( null === $info ) {
			return $transient;
		}

		$latest = trim( (string) $info['version'] );
		if ( version_compare( $latest, $current, '>' ) ) {
			$transient->response[ $slug ] = [
				'theme'        => $slug,
				'new_version'  => $latest,
				'url'          => ! empty( $info['detail_url'] ) ? (string) $info['detail_url'] : '',
				'package'      => ! empty( $info['download_url'] ) ? (string) $info['download_url'] : '',
				'requires'     => '6.0',
				'requires_php' => '8.0',
				'jinyu_hash'   => ! empty( $info['hash'] ) ? strtolower( (string) $info['hash'] ) : '',
			];
		} else {
			unset( $transient->response[ $slug ] );
		}

		return $transient;
	}

	add_filter( 'pre_set_site_transient_update_themes', 'jinyu_companion_inject_theme_update' );
	add_filter( 'site_transient_update_themes', 'jinyu_companion_inject_theme_update' );
}

if ( ! function_exists( 'jinyu_companion_upgrader_pre_download' ) ) {
	/**
	 * 一键升级下载前校验更新包 sha256，防止包被替换投毒。
	 *
	 * @param false|string|\WP_Error $reply    默认 false（交给 WP 自带下载）。
	 * @param string                 $url      待下载的包地址。
	 * @param mixed                  $upgrader 升级器实例。
	 * @return false|string|\WP_Error
	 */
	function jinyu_companion_upgrader_pre_download( $reply, $url, $upgrader ) {
		if ( false !== $reply ) {
			return $reply;
		}

		$slug = jinyu_companion_update_target_slug();
		if ( '' === $slug ) {
			return $reply;
		}

		$transient = get_site_transient( 'update_themes' );
		if ( empty( $transient->response[ $slug ]['package'] )
			|| $transient->response[ $slug ]['package'] !== $url
		) {
			return $reply;
		}

		$expected = strtolower( (string) ( $transient->response[ $slug ]['jinyu_hash'] ?? '' ) );
		if ( '' === $expected ) {
			return $reply; // 无 hash 声明时不阻断（签名已覆盖 hash 字段，此分支仅作兜底）。
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		if ( strtolower( (string) hash_file( 'sha256', $tmp ) ) !== $expected ) {
			wp_delete_file( $tmp );

			return new \WP_Error(
				'jinyu_companion_hash_mismatch',
				__( '更新包校验失败（sha256 不匹配），已阻止安装以防供应链投毒。', 'jinyu-theme-companion' )
			);
		}

		// 部分 WP 路径按 .zip 后缀识别压缩包，download_url 的临时文件已被去扩展名，补回。
		$zip_path = $tmp;
		if ( ! preg_match( '/\.zip$/i', $tmp ) ) {
			$renamed = $tmp . '.zip';
			if ( @rename( $tmp, $renamed ) && file_exists( $renamed ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,  WordPress.PHP.NoSilencedErrors
				$zip_path = $renamed;
			}
		}

		return $zip_path;
	}

	add_filter( 'upgrader_pre_download', 'jinyu_companion_upgrader_pre_download', 10, 3 );
}

if ( ! function_exists( 'jinyu_companion_upgrader_source_selection' ) ) {
	/**
	 * 修正 zip 顶层目录名与主题 slug 不一致导致的升级失败（目录重命名为标准 slug）。
	 *
	 * @param string $source        解压源目录。
	 * @param string $remote_source 临时解压根目录。
	 * @param mixed  $upgrader      升级器实例。
	 * @param array  $hook_extra    升级上下文（含 theme slug）。
	 * @return string
	 */
	function jinyu_companion_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		$slug = jinyu_companion_update_target_slug();
		if ( '' === $slug
			|| empty( $hook_extra['theme'] )
			|| $hook_extra['theme'] !== $slug
			|| empty( $source )
			|| empty( $remote_source )
			|| ! is_dir( $source )
		) {
			return $source;
		}

		$new_source = trailingslashit( $remote_source ) . $slug . '/';
		if ( $source === $new_source || is_dir( $new_source ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && method_exists( $wp_filesystem, 'move' ) ) {
			$wp_filesystem->move( $source, $new_source );
		} else {
			@rename( $source, $new_source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,  WordPress.PHP.NoSilencedErrors
		}

		return is_dir( $new_source ) ? $new_source : $source;
	}

	add_filter( 'upgrader_source_selection', 'jinyu_companion_upgrader_source_selection', 10, 4 );
}

/**
 * 后台「检查更新」按钮：主题设置页顶栏与「关于」面板共用（前端 admin.js 调用
 * admin-ajax.php?action=jinyu_check_update，nonce 由主题以 jinyu_save_options 下发）。
 *
 * 放在插件端而不再回主题：更新源地址、验签、下载服务器均属 plugin-territory，
 * 主题保持纯呈现层以通过 wp.org 审查。
 */
add_action(
	'wp_ajax_jinyu_check_update',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'msg' => __( '权限不足', 'jinyu-theme-companion' ) ] );
		}
		check_ajax_referer( 'jinyu_save_options', 'nonce' );

		$slug = jinyu_companion_update_target_slug();
		if ( '' === $slug ) {
			wp_send_json_error( [ 'msg' => __( '当前主题非「金玉」，无需检查更新。', 'jinyu-theme-companion' ) ] );
		}

		$current = (string) wp_get_theme( $slug )->get( 'Version' );
		$info    = jinyu_companion_fetch_update_info( true );

		if ( null === $info ) {
			wp_send_json_error(
				[
					'msg' => get_transient( 'jinyu_companion_update_verify_failed' )
						? __( '更新源签名校验失败，已阻止升级（疑似更新源被篡改）', 'jinyu-theme-companion' )
						: __( '更新源无响应或返回数据不可用', 'jinyu-theme-companion' ),
				]
			);
		}

		$latest = trim( (string) $info['version'] );
		wp_send_json_success(
			[
				'current'      => $current,
				'latest'       => $latest,
				'has_update'   => version_compare( $latest, $current, '>' ),
				'changelog'    => isset( $info['changelog'] ) ? (string) $info['changelog'] : '',
				'download_url' => isset( $info['download_url'] ) ? (string) $info['download_url'] : '',
				'detail_url'   => isset( $info['detail_url'] ) ? (string) $info['detail_url'] : '',
				'verified'     => true,
			]
		);
	}
);
