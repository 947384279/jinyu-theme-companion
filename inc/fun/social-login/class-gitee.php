<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gitee 码云 OAuth
 *
 * 差异：token 端点用 POST body；/api/v5/user 仅在用户开启「公开邮箱」时返回 email，
 * 否则为空——此时降级为不参与邮箱匹配。
 */
class Jinyu_OAuth_Provider_Gitee extends Jinyu_OAuth_Provider
{
	public function id(): string { return 'gitee'; }
	public function label(): string { return 'Gitee'; }
	public function icon(): string {
		// simple-icons 官方 Gitee 路径，currentColor 跟随按钮文字色（白）。
		return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11.984 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.016 0zm6.09 5.333c.328 0 .593.266.592.593v1.482a.594.594 0 0 1-.593.592H9.777c-.982 0-1.778.796-1.778 1.778v5.63c0 .327.266.592.593.592h5.63c.982 0 1.778-.796 1.778-1.778v-.296a.593.593 0 0 0-.592-.593h-4.15a.592.592 0 0 1-.592-.592v-1.482a.593.593 0 0 1 .593-.592h6.815c.327 0 .593.265.593.592v3.408a4 4 0 0 1-4 4H5.926a.593.593 0 0 1-.593-.593V9.778a4.444 4.444 0 0 1 4.445-4.444h8.296Z"/></svg>';
	}
	public function color(): string { return '#c71d23'; }
	public function console_url(): string { return 'https://gitee.com/oauth/applications'; }

	public function authorize_url( string $state, string $redirect_uri ): string {
		return add_query_arg(
			[
				'client_id'     => $this->conf['client_id'],
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => 'user_info emails',
				'state'         => $state,
			],
			'https://gitee.com/oauth/authorize'
		);
	}

	public function exchange_token( string $code, string $redirect_uri ) {
		$data = $this->request(
			'https://gitee.com/oauth/token',
			[
				'body' => [
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $this->conf['client_id'],
					'client_secret' => $this->conf['client_secret'],
					'redirect_uri'  => $redirect_uri,
				],
			],
			'POST'
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $data['error'] ) ) {
			return new WP_Error(
				'jinyu_oauth_gitee_token',
				sprintf( 'Gitee 授权失败：%s', $data['error_description'] ?? $data['error'] )
			);
		}
		$token = $data['access_token'] ?? '';
		if ( '' === $token ) {
			return new WP_Error( 'jinyu_oauth_gitee_token', 'Gitee 未返回 access_token' );
		}
		return $token;
	}

	public function fetch_user( string $token, array $extra = [] ) {
		$user = $this->request(
			'https://gitee.com/api/v5/user',
			[ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ]
		);
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$email = (string) ( $user['email'] ?? '' );

		if ( '' === $email ) {
			$emails = $this->request(
				'https://gitee.com/api/v5/emails',
				[ 'headers' => [ 'Authorization' => 'Bearer ' . $token ] ]
			);
			if ( ! is_wp_error( $emails ) && is_array( $emails ) ) {
				foreach ( $emails as $row ) {
					if ( ! is_array( $row ) || empty( $row['email'] ) ) {
						continue;
					}
					if ( ! empty( $row['state'] ) && 'confirmed' === $row['state'] ) {
						$email = (string) $row['email'];
						break;
					}
				}
			}
		}

		return $this->normalize_user(
			[
				'id'       => $user['id'] ?? '',
				'nickname' => $user['name'] ?? ( $user['login'] ?? '' ),
				'avatar'   => $user['avatar_url'] ?? '',
				'email'    => $email,
				'email_verified' => true,
			]
		);
	}
}
