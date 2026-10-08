<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 敏感字段加密存储（companion 自有实现，独立于主题）
 * ------------------------------------------------------------------
 * - 命名唯一（jinyu_companion_encrypt / decrypt），避免与主题 jinyu_encrypt/decrypt
 *   发生「编译期 Cannot redeclare」（PHP 8.5 实测：文件级 return 守卫拦不住编译期重声明）。
 * - 算法与主题 crypto.php 完全同源：wp_salt('auth') 派生 AES-256-CBC 密钥 + HMAC 完整性，
 *   密文前缀同为 "jinyu_enc2::" —— 因此可透明解密历史主题密文（用于一次性迁移）。
 * - 解密失败或前缀不符时回落为原文（兼容历史明文）。
 */
if ( ! function_exists( 'jinyu_companion_encrypt' ) ) {
	function jinyu_companion_encrypt( $plain ) {
		if ( '' === $plain || null === $plain ) {
			return $plain;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// 无 openssl 时拒绝加密：返回 false 由调用方决定是否保存，绝不降级为明文落库，
			// 避免 SMTP 授权码 / 对象存储 Secret / OAuth client_secret 等凭证明文入库。
			return false;
		}
		$key     = hash( 'sha256', wp_salt( 'auth' ), true );
		$mac_key = hash( 'sha256', wp_salt( 'auth' ) . '|jinyu_mac', true );
		$iv      = random_bytes( 16 );
		$enc     = openssl_encrypt( (string) $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return $plain;
		}
		// encrypt-then-MAC：HMAC 覆盖 iv+密文，防御 CBC 篡改
		$mac = hash_hmac( 'sha256', $iv . $enc, $mac_key, true );
		return 'jinyu_enc2::' . base64_encode( $iv . $enc . $mac );
	}
}

if ( ! function_exists( 'jinyu_companion_decrypt' ) ) {
	function jinyu_companion_decrypt( $val ) {
		if ( ! is_string( $val ) ) {
			return $val;
		}

		// 新格式：带 HMAC，先校验完整性再解密
		if ( strpos( $val, 'jinyu_enc2::' ) === 0 ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$key     = hash( 'sha256', wp_salt( 'auth' ), true );
			$mac_key = hash( 'sha256', wp_salt( 'auth' ) . '|jinyu_mac', true );
			$raw     = base64_decode( substr( $val, strlen( 'jinyu_enc2::' ) ), true );
			if ( false === $raw || strlen( $raw ) < 16 + 32 ) {
				return '';
			}
			$iv   = substr( $raw, 0, 16 );
			$enc  = substr( $raw, 16, -32 );
			$mac  = substr( $raw, -32 );
			$calc = hash_hmac( 'sha256', $iv . $enc, $mac_key, true );
			if ( ! hash_equals( $calc, $mac ) ) {
				return ''; // 完整性校验失败，拒绝
			}
			$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			return false === $dec ? '' : $dec;
		}

		// 兼容旧格式（无 MAC）：仅解密不校验，保证历史密文可读
		if ( strpos( $val, 'jinyu_enc::' ) === 0 ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return $val;
			}
			$key = hash( 'sha256', wp_salt( 'auth' ), true );
			$raw = base64_decode( substr( $val, strlen( 'jinyu_enc::' ) ), true );
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return '';
			}
			$iv  = substr( $raw, 0, 16 );
			$enc = substr( $raw, 16 );
			$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			return false === $dec ? '' : $dec;
		}

		return $val;
	}
}

/*
 * 响应主题的需求广播：主题只声明「这个字段要加密 / 这串要解密」，不认识本插件函数名。
 * 契约方向：主题 apply_filters( 'jinyu_encrypt' / 'jinyu_decrypt' ) → 本插件 add_filter 响应。
 */
add_filter( 'jinyu_encrypt', 'jinyu_companion_filter_encrypt' );
/**
 * 过滤器回调：为主题提供加密实现。
 *
 * @param mixed $plain 明文。
 * @return mixed 密文（带 jinyu_enc2:: 前缀）。
 */
function jinyu_companion_filter_encrypt( $plain ) {
	return jinyu_companion_encrypt( $plain );
}

add_filter( 'jinyu_decrypt', 'jinyu_companion_filter_decrypt' );
/**
 * 过滤器回调：为主题提供解密实现（含历史格式兼容）。
 *
 * @param mixed $val 密文或历史明文。
 * @return mixed
 */
function jinyu_companion_filter_decrypt( $val ) {
	return jinyu_companion_decrypt( $val );
}
