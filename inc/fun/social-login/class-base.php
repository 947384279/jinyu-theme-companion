<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OAuth Provider 抽象基类
 *
 * 每个平台实现 authorize_url() / exchange_token() / fetch_user() 三个方法，
 * 上层（回调处理、按钮渲染、后台配置）只依赖本接口，不感知平台差异。
 *
 * 错误约定：方法失败时返回 WP_Error，上层统一转成前台提示。
 */
abstract class Jinyu_OAuth_Provider
{
	/** @var array 该平台的配置（client_id / client_secret 等，已解密） */
	protected array $conf;

	public function __construct( array $conf = [] ) {
		$this->conf = $conf;
	}

	/** 运行时注入（已解密）配置；jinyu_sl_providers() 构造时不带配置，分发前必须调用。 */
	public function set_config( array $conf ): void {
		$this->conf = $conf;
	}

	/* ---------------------------------------------------------------- 元信息 */

	/** 平台标识（小写，用于 rewrite / meta key / CSS 类名） */
	abstract public function id(): string;

	/** 展示名 */
	abstract public function label(): string;

	/** 品牌色（按钮背景），无则用中性色 */
	public function color(): string {
		return '#3c4045';
	}

	/** 平台徽标：内置品牌 SVG（simple-icons 路径，currentColor），不依赖外部图标库 */
	abstract public function icon(): string;

	/**
	 * 承载“密钥”的字段名（默认 client_secret；Apple 用 private_key）。
	 * 子类按需覆盖。
	 */
	protected function secret_field(): string {
		return 'client_secret';
	}

	/** 是否已完成必要配置：client_id 与对应密钥字段均非空 */
	public function is_configured(): bool {
		return ! empty( $this->conf['client_id'] )
			&& ! empty( $this->conf[ $this->secret_field() ] );
	}

	/** 该平台是否在后台使用通用「Client Secret」字段（Apple 用 private_key，返回 false，避免渲染无用输入框） */
	public function uses_client_secret(): bool {
		return $this->secret_field() === 'client_secret';
	}

	/**
	 * 回调响应方式：query（GET，默认）或 form_post（POST）。
	 * Apple 强制 form_post。
	 */
	public function response_mode(): string {
		return 'query';
	}

	/** 该平台可返回「可信邮箱」时返回 true（决定能否走邮箱匹配绑定） */
	public function returns_email(): bool {
		return true;
	}

	/**
	 * 开放平台开发者后台地址（申请 client_id / secret 的地方）。
	 * 供后台配置卡片渲染「去申请」入口；无则返回空串，面板自动不渲染该链接。
	 */
	public function console_url(): string {
		return '';
	}

	/* ------------------------------------------------------------ 后台配置项 */

	/**
	 * 平台专属配置字段（除 client_id / client_secret 外）。
	 * 返回 [ ['id'=>'team_id','label'=>'Team ID','type'=>'text'], ... ]
	 */
	public function config_fields(): array {
		return [];
	}

	/* -------------------------------------------------------------- OAuth 流程 */

	/** 拼接授权跳转地址 */
	abstract public function authorize_url( string $state, string $redirect_uri ): string;

	/**
	 * 用 code 换取 access_token。
	 * @return string|WP_Error token 字符串
	 */
	abstract public function exchange_token( string $code, string $redirect_uri );

	/**
	 * 拉取并归一化用户信息。
	 * @return array|WP_Error ['id','nickname','avatar','email']
	 */
	abstract public function fetch_user( string $token, array $extra = [] );

	/* ------------------------------------------------------------------ 工具 */

	/**
	 * 带 WP_Error 语义的 JSON 请求。
	 */
	protected function request( string $url, array $args = [], string $method = 'GET' ) {
		$args['timeout'] = $args['timeout'] ?? 15;
		$args['headers'] = $args['headers'] ?? [];
		if ( ! isset( $args['headers']['Accept'] ) ) {
			$args['headers']['Accept'] = 'application/json';
		}
		// Gitee 等国内 API 的 WAF 会拦截 WordPress 默认 UA（返回 403），统一带一个非 WordPress 的 UA
		if ( ! isset( $args['headers']['User-Agent'] ) ) {
			$args['headers']['User-Agent'] = 'JinyuSocialLogin/1.0 (+https://www.qicaiyun.top)';
		}

		$resp = ( 'POST' === $method )
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );
		if ( $code >= 400 ) {
			return new WP_Error(
				'jinyu_oauth_http_' . $code,
				sprintf( '平台接口 %s 返回 %d', $url, $code )
			);
		}

		$data = $this->decode_json( $body );
		if ( null === $data ) {
			return new WP_Error( 'jinyu_oauth_bad_json', '平台返回数据无法解析' );
		}
		return $data;
	}

	/**
	 * 容错 JSON 解析：剥离 JSONP 包裹；补救 QQ 老接口的「单引号键名」非法 JSON。
	 *
	 * graph.qq.com 系列接口在未显式指定 fmt=json 时返回 `callback( {...} );`，
	 * 直接 json_decode 必然失败——这是 QQ 登录挂掉的经典根因，此处统一剥壳。
	 */
	protected function decode_json( string $body ) {
		$body = trim( $body );
		if ( '' === $body ) {
			return null;
		}

		if ( preg_match( '/^[A-Za-z_$][\w$.]*\s*\(\s*(.*?)\s*\)\s*;?$/s', $body, $m ) ) {
			$body = $m[1];
		}

		$data = json_decode( $body, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		$fixed = preg_replace( "/([{,]\s*)([A-Za-z_]\w*)\s*:/", '$1"$2":', $body );
		if ( is_string( $fixed ) && $fixed !== $body ) {
			$data = json_decode( $fixed, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return null;
	}

	/** 归一化用户信息，补齐缺省字段，统一为字符串。 */
	protected function normalize_user( array $u ): array {
		return [
			'id'             => (string) ( $u['id'] ?? '' ),
			'nickname'       => (string) ( $u['nickname'] ?? '' ),
			'avatar'         => (string) ( $u['avatar'] ?? '' ),
			'email'          => (string) ( $u['email'] ?? '' ),
			// 邮箱是否经平台验证：仅取平台标记为 verified 的邮箱才为 true，供 core 防邮箱伪造接管。
			'email_verified' => ! empty( $u['email_verified'] ),
		];
	}
}
