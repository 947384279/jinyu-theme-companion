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
	public function icon(): string { return 'G'; }
	public function color(): string { return '#24292e'; }

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
			]
		);
	}
}
