<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sign in with Apple
 *
 * 与常规 OAuth 的三处硬差异：
 * 1. client_secret 不是静态字符串，而是用 .p8 私钥现签的 ES256 JWT（1 小时有效）。
 * 2. 回调强制 form_post（response_mode() = form_post），且首次登录才在 user 字段回传姓名。
 * 3. 身份标识走 id_token（Apple 签名的 JWT）：我们校验签名 + 声明（iss/aud/exp/nonce）
 *    后直接采信 sub 与 email，无需再调 userinfo 接口。
 *
 * 依赖：openssl（服务器 PHP 8.5 已验证支持 prime256v1 / ES256 / RS256）。
 */
class Jinyu_OAuth_Provider_Apple extends Jinyu_OAuth_Provider
{
	public function id(): string { return 'apple'; }
	public function label(): string { return 'Apple'; }
	public function icon(): string {
		// simple-icons 官方 Apple 路径，currentColor 跟随按钮文字色（白）。
		return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701"/></svg>';
	}
	public function color(): string { return '#000000'; }
	public function response_mode(): string { return 'form_post'; }
	public function returns_email(): bool { return true; }
	public function console_url(): string { return 'https://developer.apple.com/account/resources/identifiers/list/serviceId'; }

	/** Apple 的“密钥”是 .p8 私钥；且须 Team ID / Key ID 齐全才算配置完整 */
	protected function secret_field(): string { return 'private_key'; }

	public function is_configured(): bool {
		return ! empty( $this->conf['client_id'] )
			&& ! empty( $this->conf['private_key'] )
			&& ! empty( $this->conf['team_id'] )
			&& ! empty( $this->conf['key_id'] );
	}

	public function config_fields(): array {
		return [
			[ 'id' => 'team_id', 'label' => 'Team ID', 'type' => 'text' ],
			[ 'id' => 'key_id', 'label' => 'Key ID（Auth Key）', 'type' => 'text' ],
			[ 'id' => 'private_key', 'label' => '私钥 .p8 内容', 'type' => 'textarea' ],
		];
	}

	public function authorize_url( string $state, string $redirect_uri ): string {
		return add_query_arg(
			[
				'client_id'     => $this->conf['client_id'],
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code id_token',
				'scope'         => 'name email',
				'state'         => $state,
			],
			'https://appleid.apple.com/auth/authorize'
		);
	}

	/**
	 * Apple 身份在 id_token 里，这里不真正换 token，返回 code 供 fetch_user 旁路使用。
	 * 若回调未携带 id_token（极少见），fallback 会走 token 端点。
	 */
	public function exchange_token( string $code, string $redirect_uri ) {
		return $code;
	}

	public function fetch_user( string $code, array $extra = [] ): array {
		$id_token = $extra['id_token'] ?? '';
		$nonce    = $extra['nonce'] ?? '';
		$client   = $this->conf['client_id'] ?? '';

		// 兜底：无 id_token 时走 token 端点
		if ( '' === $id_token ) {
			$jwt = $this->client_secret_jwt();
			if ( is_wp_error( $jwt ) ) {
				return $jwt;
			}
			$tok = $this->request(
				'https://appleid.apple.com/auth/token',
				[
					'body' => [
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'client_id'     => $client,
						'client_secret' => $jwt,
						'redirect_uri'  => $redirect_uri ?? '',
					],
				],
				'POST'
			);
			if ( is_wp_error( $tok ) ) {
				return $tok;
			}
			$id_token = $tok['id_token'] ?? '';
		}

		if ( '' === $id_token ) {
			return new WP_Error( 'jinyu_apple_token', 'Apple 未返回 id_token' );
		}

		$claims = $this->verify_id_token( $id_token, $nonce );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$sub   = (string) ( $claims['sub'] ?? '' );
		$email = (string) ( $claims['email'] ?? '' );

		$nickname = '';
		$user_json = $extra['user'] ?? '';
		if ( '' !== $user_json ) {
			$u = json_decode( $user_json, true );
			if ( is_array( $u ) && isset( $u['name'] ) && is_array( $u['name'] ) ) {
				$nickname = trim( ( $u['name']['firstName'] ?? '' ) . ' ' . ( $u['name']['lastName'] ?? '' ) );
			}
		}
		if ( '' === $nickname ) {
			$nickname = $email ?: 'Apple 用户';
		}

		return $this->normalize_user(
			[
				'id'       => $sub,
				'nickname' => $nickname,
				'avatar'   => '',
				'email'    => $email,
				'email_verified' => true,
			]
		);
	}

	/* ----------------------------------------------------- Apple 专属工具 */

	/** 用 .p8 私钥生成 client_secret JWT（ES256） */
	private function client_secret_jwt(): string|WP_Error {
		$team   = $this->conf['team_id'] ?? '';
		$key_id = $this->conf['key_id'] ?? '';
		$pem    = $this->normalize_pem( $this->conf['private_key'] ?? '' );
		if ( '' === $team || '' === $key_id || '' === $pem ) {
			return new WP_Error( 'jinyu_apple_cfg', 'Apple 私钥 / Team ID / Key ID 未配置完整' );
		}

		$header  = [ 'alg' => 'ES256', 'kid' => $key_id ];
		$now     = time();
		$payload = [
			'iss' => $team,
			'iat' => $now,
			'exp' => $now + 3600,
			'aud' => 'https://appleid.apple.com',
			'sub' => $this->conf['client_id'],
		];

		$seg  = static function ( $d ): string {
			return rtrim( strtr( base64_encode( (string) json_encode( $d ) ), '+/', '-_' ), '=' );
		};
		$signing = $seg( $header ) . '.' . $seg( $payload );

		$sig = '';
		if ( ! openssl_sign( $signing, $sig, $pem, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'jinyu_apple_sign', 'Apple client_secret 签名失败' );
		}
		$sig_b64 = rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );

		return $seg( $header ) . '.' . $seg( $payload ) . '.' . $sig_b64;
	}

	/** 校验 Apple id_token：签名（RS256，JWK→PEM）+ 声明 */
	private function verify_id_token( string $jwt, string $nonce ): array|WP_Error {
		$parts = explode( '.', $jwt );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'jinyu_apple_jwt', 'id_token 格式错误' );
		}
		[ $h, $p, $s ] = $parts;

		$b64 = static function ( string $s ): string {
			$d = base64_decode( strtr( $s, '-_', '+/' ), true );
			return false === $d ? '' : $d;
		};

		$header  = json_decode( $b64( $h ), true );
		$payload = json_decode( $b64( $p ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'jinyu_apple_jwt', 'id_token 解析失败' );
		}

		$kid = (string) ( $header['kid'] ?? '' );
		$pem = $this->apple_public_key( $kid );
		if ( is_wp_Error( $pem ) ) {
			return $pem;
		}

		$raw_sig = $b64( $s );
		if ( '' === $raw_sig ) {
			return new WP_Error( 'jinyu_apple_jwt', 'id_token 签名解析失败' );
		}
		$ok = openssl_verify( $h . '.' . $p, $raw_sig, $pem, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			return new WP_Error( 'jinyu_apple_jwt', 'id_token 签名验证失败' );
		}

		if ( ( $payload['iss'] ?? '' ) !== 'https://appleid.apple.com' ) {
			return new WP_Error( 'jinyu_apple_jwt', 'iss 不匹配' );
		}
		if ( ( $payload['aud'] ?? '' ) !== ( $this->conf['client_id'] ?? '' ) ) {
			return new WP_Error( 'jinyu_apple_jwt', 'aud 不匹配' );
		}
		if ( ! empty( $payload['exp'] ) && time() > (int) $payload['exp'] ) {
			return new WP_Error( 'jinyu_apple_jwt', 'id_token 已过期' );
		}
		if ( '' !== $nonce && ( $payload['nonce'] ?? '' ) !== $nonce ) {
			return new WP_Error( 'jinyu_apple_jwt', 'nonce 不匹配' );
		}

		return $payload;
	}

	/** 取 Apple 公钥（JWKS，带 1 小时缓存）并转 PEM */
	private function apple_public_key( string $kid ): string|WP_Error {
		$keys = get_transient( 'jinyu_apple_jwks' );
		if ( ! is_array( $keys ) ) {
			$r = wp_remote_get( 'https://appleid.apple.com/auth/keys', [ 'timeout' => 15 ] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$j    = json_decode( wp_remote_retrieve_body( $r ), true );
			$keys = is_array( $j ) ? ( $j['keys'] ?? [] ) : [];
			set_transient( 'jinyu_apple_jwks', $keys, HOUR_IN_SECONDS );
		}
		foreach ( $keys as $k ) {
			if ( ( $k['kid'] ?? '' ) === $kid && ( $k['kty'] ?? '' ) === 'RSA' ) {
				$pem = $this->jwk_to_pem( $k );
				if ( false !== $pem ) {
					return $pem;
				}
			}
		}
		return new WP_Error( 'jinyu_apple_key', '未找到匹配的 Apple 公钥' );
	}

	/** JWK (n,e) → PEM 公钥（手动构造 RSA SubjectPublicKeyInfo DER） */
	private function jwk_to_pem( array $k ): string|false {
		$n = base64_decode( strtr( (string) ( $k['n'] ?? '' ), '-_', '+/' ), true );
		$e = base64_decode( strtr( (string) ( $k['e'] ?? '' ), '-_', '+/' ), true );
		if ( false === $n || false === $e || '' === $n || '' === $e ) {
			return false;
		}
		$alg   = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$enc_n = $this->der_int( $n );
		$enc_e = $this->der_int( $e );
		// RSAPublicKey = SEQUENCE { modulus, publicExponent }
		$rsa   = "\x30" . $this->der_len( strlen( $enc_n ) + strlen( $enc_e ) ) . $enc_n . $enc_e;
		// SubjectPublicKeyInfo：BIT STRING 包着上面的 RSAPublicKey（注意前缀 0x03 + 未用位 0x00）
		$bit   = "\x03" . $this->der_len( strlen( $rsa ) + 1 ) . "\x00" . $rsa;
		$spki  = "\x30" . $this->der_len( strlen( $alg ) + strlen( $bit ) ) . $alg . $bit;
		return "-----BEGIN PUBLIC KEY-----\n"
			   . chunk_split( base64_encode( $spki ), 64, "\n" )
			   . "-----END PUBLIC KEY-----\n";
	}

	private function der_len( int $n ): string {
		if ( $n < 0x80 ) {
			return chr( $n );
		}
		$out = '';
		while ( $n > 0 ) {
			$out = chr( $n & 0xff ) . $out;
			$n >>= 8;
		}
		return chr( 0x80 | strlen( $out ) ) . $out;
	}

	private function der_int( string $bin ): string {
		if ( ord( $bin[0] ) > 0x7f ) {
			$bin = "\x00" . $bin;
		}
		return "\x02" . $this->der_len( strlen( $bin ) ) . $bin;
	}

	/** 规整 .p8 内容：去掉 BEGIN/END 头尾与空白，重新包成 PKCS8 PEM */
	private function normalize_pem( string $raw ): string {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( strpos( $raw, 'BEGIN' ) !== false ) {
			return $raw;
		}
		$b64 = preg_replace( '/\s+/', '', $raw );
		return "-----BEGIN PRIVATE KEY-----\n"
			   . chunk_split( $b64, 64, "\n" )
			   . "-----END PRIVATE KEY-----\n";
	}
}
