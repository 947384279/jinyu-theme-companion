<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 敏感字段加密（脱敏）
 * 用 wp_salt('auth') 派生 AES-256-CBC 密钥，库泄露也不会直接暴露明文。
 * 前缀 jinyu_sl_enc:: 与主题侧的 jinyu_enc2:: 区分，避免误判。
 *
 * 本文件为插件自包含副本——插件不依赖主题 crypto.php。
 */
if ( ! function_exists( 'jinyu_sl_encrypt' ) ) {
	function jinyu_sl_encrypt( $plain ) {
		if ( '' === $plain || null === $plain ) {
			return $plain;
		}
		// 防御：若已是插件密文（如表单误回传密文），原样返回，绝不二次加密——二次加密会导致“密钥合法却报 client secret is illegal”的诡异故障
		if ( is_string( $plain ) && str_starts_with( $plain, 'jinyu_sl_enc::' ) ) {
			return $plain;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$key     = hash( 'sha256', wp_salt( 'auth' ), true );
		$mac_key = hash( 'sha256', wp_salt( 'auth' ) . '|jinyu_sl_mac', true );
		$iv      = random_bytes( 16 );
		$enc     = openssl_encrypt( (string) $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return $plain;
		}
		$mac = hash_hmac( 'sha256', $iv . $enc, $mac_key, true );
		return 'jinyu_sl_enc::' . base64_encode( $iv . $enc . $mac );
	}
}

if ( ! function_exists( 'jinyu_sl_decrypt' ) ) {
	function jinyu_sl_decrypt( $val ) {
		if ( ! is_string( $val ) ) {
			return $val;
		}
		if ( strpos( $val, 'jinyu_sl_enc::' ) !== 0 ) {
			return $val;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$key     = hash( 'sha256', wp_salt( 'auth' ), true );
		$mac_key = hash( 'sha256', wp_salt( 'auth' ) . '|jinyu_sl_mac', true );
		$raw     = base64_decode( substr( $val, strlen( 'jinyu_sl_enc::' ) ), true );
		if ( false === $raw || strlen( $raw ) < 48 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$enc = substr( $raw, 16, -32 );
		$mac = substr( $raw, -32 );
		if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $enc, $mac_key, true ) ) ) {
			return '';
		}
		$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $dec ? '' : $dec;
	}
}
