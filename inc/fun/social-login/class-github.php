<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub OAuth App
 *
 * 坑：/user 返回的 email 字段在用户设为私密时为空，
 * 必须再调 /user/emails 取 primary + verified 的那一条（防绑到别人邮箱）。
 */
class Jinyu_OAuth_Provider_GitHub extends Jinyu_OAuth_Provider
{
	public function id(): string { return 'github'; }
	public function label(): string { return 'GitHub'; }
	public function icon(): string {
		// simple-icons 官方 GitHub 路径，currentColor 跟随按钮文字色（白）。
		return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>';
	}
	public function color(): string { return '#24292e'; }
	public function console_url(): string { return 'https://github.com/settings/developers'; }

	public function authorize_url( string $state, string $redirect_uri ): string {
		return add_query_arg(
			[
				'client_id'    => $this->conf['client_id'],
				'redirect_uri' => $redirect_uri,
				'scope'        => 'read:user user:email',
				'state'        => $state,
			],
			'https://github.com/login/oauth/authorize'
		);
	}

	public function exchange_token( string $code, string $redirect_uri ) {
		$data = $this->request(
			'https://github.com/login/oauth/access_token',
			[
				'body' => [
					'client_id'     => $this->conf['client_id'],
					'client_secret' => $this->conf['client_secret'],
					'code'          => $code,
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
				'jinyu_oauth_github_token',
				sprintf( 'GitHub 授权失败：%s', $data['error_description'] ?? $data['error'] )
			);
		}
		$token = $data['access_token'] ?? '';
		if ( '' === $token ) {
			return new WP_Error( 'jinyu_oauth_github_token', 'GitHub 未返回 access_token' );
		}
		return $token;
	}

	public function fetch_user( string $token, array $extra = [] ) {
		$headers = [
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/vnd.github+json',
			'User-Agent'    => 'jinyu-theme',
		];

		$user = $this->request( 'https://api.github.com/user', [ 'headers' => $headers ] );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$email = (string) ( $user['email'] ?? '' );

		// email 私密时 /user 返回 null，需单独拉邮箱列表，只取 primary+verified
		if ( '' === $email ) {
			$emails = $this->request( 'https://api.github.com/user/emails', [ 'headers' => $headers ] );
			if ( ! is_wp_error( $emails ) && is_array( $emails ) ) {
				foreach ( $emails as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					if ( ! empty( $row['primary'] ) && ! empty( $row['verified'] ) ) {
						$email = (string) ( $row['email'] ?? '' );
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
