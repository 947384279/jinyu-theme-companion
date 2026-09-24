<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QQ 互联 OAuth 2.0
 *
 * 平台坑：
 * 1. openid 需单独调 /oauth2.0/me 获取（返回 JSONP，靠基类 decode_json 剥壳）。
 * 2. 授权接口默认 scope 不返回邮箱；要拿邮箱须额外申请权限且用户 QQ 邮箱需公开，
 *    因此 returns_email() 返回 false —— 不参与邮箱匹配绑定。
 */
class Jinyu_OAuth_Provider_QQ extends Jinyu_OAuth_Provider
{
	public function id(): string { return 'qq'; }
	public function label(): string { return 'QQ'; }
	public function icon(): string { return 'Q'; }
	public function color(): string { return '#12b7f5'; }
	public function returns_email(): bool { return false; }
	public function console_url(): string { return 'https://connect.qq.com/manage.html'; }

	public function authorize_url( string $state, string $redirect_uri ): string {
		return add_query_arg(
			[
				'response_type' => 'code',
				'client_id'     => rawurlencode( $this->conf['client_id'] ),
				'redirect_uri'  => rawurlencode( $redirect_uri ),
				'state'         => $state,
				'scope'         => 'get_user_info',
			],
			'https://graph.qq.com/oauth2.0/authorize'
		);
	}

	public function exchange_token( string $code, string $redirect_uri ) {
		$resp = wp_remote_post(
			'https://graph.qq.com/oauth2.0/token',
			[
				'timeout' => 15,
				'body'    => [
					'grant_type'    => 'authorization_code',
					'client_id'     => $this->conf['client_id'],
					'client_secret' => $this->conf['client_secret'],
					'code'          => $code,
					'redirect_uri'  => $redirect_uri,
					'fmt'           => 'json',
				],
			]
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$body = trim( wp_remote_retrieve_body( $resp ) );
		$data = $this->decode_json( $body );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'jinyu_oauth_qq_token', 'QQ 换取 token 失败' );
		}
		if ( ! empty( $data['error'] ) ) {
			// 诊断用：记录 client_id / secret 长度与 secret 前 12 字符（不记完整明文）。
			// secret_head 若为 jinyu_enc2:: 说明读出的是主题密文（二次加密未解开）；若为 jinyu_sl_enc:: 说明解密后仍是插件密文；否则为真实密钥字符。
			error_log(
				sprintf(
					'Jinyu QQ token error: desc=%s client_id_len=%d secret_len=%d secret_head=%s',
					$data['error_description'] ?? $data['error'],
					strlen( $this->conf['client_id'] ?? '' ),
					strlen( $this->conf['client_secret'] ?? '' ),
					substr( (string) ( $this->conf['client_secret'] ?? '' ), 0, 12 )
				)
			);
			return new WP_Error(
				'jinyu_oauth_qq_token',
				sprintf( 'QQ 授权失败：%s', $data['error_description'] ?? $data['error'] )
			);
		}
		$token = $data['access_token'] ?? '';
		if ( '' === $token ) {
			return new WP_Error( 'jinyu_oauth_qq_token', 'QQ 未返回 access_token' );
		}
		return $token;
	}

	public function fetch_user( string $token, array $extra = [] ) {
		// 第一步：取 openid（JSONP，靠 decode_json 剥壳）
		$me = $this->request(
			add_query_arg(
				[ 'access_token' => $token, 'fmt' => 'json' ],
				'https://graph.qq.com/oauth2.0/me'
			)
		);
		if ( is_wp_error( $me ) ) {
			return $me;
		}
		$openid = $me['openid'] ?? '';
		if ( '' === $openid ) {
			return new WP_Error( 'jinyu_oauth_qq_openid', 'QQ 未返回 openid' );
		}

		// 第二步：取用户资料（接口无 fmt 时返回 JSONP，靠 decode_json 剥壳）
		$info = $this->request(
			add_query_arg(
				[
					'access_token'       => $token,
					'oauth_consumer_key' => $this->conf['client_id'],
					'openid'             => $openid,
					'fmt'                => 'json',
				],
				'https://graph.qq.com/user/get_user_info'
			)
		);
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( isset( $info['ret'] ) && (int) $info['ret'] !== 0 ) {
			return new WP_Error(
				'jinyu_oauth_qq_profile',
				sprintf( 'QQ 获取资料失败：%s', $info['msg'] ?? '未知错误' )
			);
		}

		return $this->normalize_user(
			[
				'id'       => $openid,
				'nickname' => $info['nickname'] ?? '',
				'avatar'   => $info['figureurl_qq_2'] ?? ( $info['figureurl_qq_1'] ?? '' ),
				'email'    => '',
			]
		);
	}
}
