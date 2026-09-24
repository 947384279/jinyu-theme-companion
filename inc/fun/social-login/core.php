<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * Provider 注册表（第三方插件可通过 filter 注入新平台）
 * ======================================================================== */
function jinyu_sl_default_providers(): array {
	return [
		Jinyu_OAuth_Provider_GitHub::class,
		Jinyu_OAuth_Provider_Gitee::class,
		Jinyu_OAuth_Provider_QQ::class,
		Jinyu_OAuth_Provider_Apple::class,
	];
}

function jinyu_sl_providers(): array {
	$map = [];
	foreach ( apply_filters( 'jinyu_social_login_providers', jinyu_sl_default_providers() ) as $cls ) {
		if ( class_exists( $cls ) ) {
			$inst          = new $cls();
			$map[ $inst->id() ] = $inst;
		}
	}
	return $map;
}

/* ==========================================================================
 * 配置读写（自有 option，与主题解耦）
 * 结构：['enable'=>bool, 'accounts'=>[ platform => ['client_id'=>,'client_secret'=>enc, ...] ]]
 * ======================================================================== */
function jinyu_sl_get_option(): array {
	$opt = get_option( JINYU_SL_OPT, [] );
	return is_array( $opt ) ? $opt : [];
}

function jinyu_sl_get_accounts(): array {
	$opt = jinyu_sl_get_option();
	$acc = $opt['accounts'] ?? [];
	return is_array( $acc ) ? $acc : [];
}

/** 单平台已解密配置 */
function jinyu_sl_get_config( string $platform ): array {
	$acc = jinyu_sl_get_accounts();
	$a   = $acc[ $platform ] ?? [];
	if ( ! is_array( $a ) ) {
		return [];
	}
	// 解密可能存在的密文字段
		foreach ( [ 'client_secret', 'private_key' ] as $k ) {
		if ( ! empty( $a[ $k ] ) ) {
			$a[ $k ] = jinyu_sl_decrypt( $a[ $k ] );
			// 兼容迁移可能造成的二次加密：解密结果若仍是主题格式密文（jinyu_enc2::/jinyu_enc::），再解一层。
			// 否则会出现「secret 合法却报 client secret is illegal」—— 发出的是主题密文乱码。
			if ( is_string( $a[ $k ] )
				&& ( strpos( $a[ $k ], 'jinyu_enc2::' ) === 0 || strpos( $a[ $k ], 'jinyu_enc::' ) === 0 )
			) {
				$a[ $k ] = jinyu_sl_decrypt_theme_secret( $a[ $k ] );
			}
			// 防御：若仍是插件格式密文（理论上 encrypt 已拦截二次加密），继续解开一层，最多 3 次，避免死循环
			$guard = 0;
			while ( is_string( $a[ $k ] ) && strpos( $a[ $k ], 'jinyu_sl_enc::' ) === 0 && $guard < 3 ) {
				$a[ $k ] = jinyu_sl_decrypt( $a[ $k ] );
				$guard++;
			}
		}
	}
	return $a;
}

/**
 * 解密主题侧 jinyu_enc2:: / jinyu_enc:: 密文（与主题 crypto.php 同源 AES 密钥，仅 MAC key 不同）。
 * 仅用于「从主题配置迁移密钥」后可能残留的二次加密，做向下兼容解密。
 */
function jinyu_sl_decrypt_theme_secret( string $val ): string {
	if ( ! is_string( $val ) ) {
		return '';
	}
	$is_new = strpos( $val, 'jinyu_enc2::' ) === 0;
	$is_old = strpos( $val, 'jinyu_enc::' ) === 0;
	if ( ! $is_new && ! $is_old ) {
		return $val;
	}
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$key     = hash( 'sha256', wp_salt( 'auth' ), true );
	$mac_key = hash( 'sha256', wp_salt( 'auth' ) . '|jinyu_mac', true );
	$prefix  = $is_new ? 'jinyu_enc2::' : 'jinyu_enc::';
	$raw     = base64_decode( substr( $val, strlen( $prefix ) ), true );
	if ( false === $raw ) {
		return '';
	}
	if ( $is_new ) {
		if ( strlen( $raw ) < 48 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$enc = substr( $raw, 16, -32 );
		$mac = substr( $raw, -32 );
		if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $enc, $mac_key, true ) ) ) {
			return '';
		}
	} else {
		if ( strlen( $raw ) < 17 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$enc = substr( $raw, 16 );
	}
	$dec = openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return false === $dec ? '' : $dec;
}

/* ==========================================================================
 * 对外公开 API（主题以同名函数调用；插件未启用时主题侧提供降级空实现）
 * ======================================================================== */
function jinyu_oauth_enabled(): bool {
	if ( empty( jinyu_sl_get_option()['enable'] ) ) {
		return false;
	}
	foreach ( jinyu_sl_providers() as $p => $prov ) {
		$prov->set_config( jinyu_sl_get_config( $p ) );
		if ( $prov->is_configured() ) {
			return true;
		}
	}
	return false;
}

function jinyu_oauth_platforms(): array {
	$out     = [];
	$enabled = jinyu_oauth_enabled();
	if ( ! $enabled ) {
		return $out;
	}
	foreach ( jinyu_sl_providers() as $p => $prov ) {
		$prov->set_config( jinyu_sl_get_config( $p ) );
		if ( ! $prov->is_configured() ) {
			continue;
		}
		$out[ $p ] = [
			'label' => $prov->label(),
			'icon'  => $prov->icon(),
			'color' => $prov->color(),
		];
	}
	return $out;
}

function jinyu_oauth_bindings( int $uid ): array {
	$out = [];
	foreach ( array_keys( jinyu_sl_providers() ) as $p ) {
		$out[ $p ] = (string) get_user_meta( $uid, 'jinyu_oauth_' . $p . '_id', true );
	}
	return $out;
}

/**
 * 已登录用户「绑定第三方账号」的发起 URL。
 * 回调（jinyu_sl_callback）识别 intent=bind 后把社交身份关联到当前登录账号，而非新建账号。
 * redirect_to 仅用于绑定完成后回跳（会被 wp_validate_redirect 限制同域）。
 */
function jinyu_oauth_bind_url( string $platform, string $redirect_to = '' ): string {
	$start = admin_url( 'admin-post.php?action=jinyu_social_login&mode=start' );
	$args  = [ 'platform' => $platform, 'intent' => 'bind' ];
	if ( $redirect_to ) {
		$args['redirect_to'] = $redirect_to;
	}
	return add_query_arg( $args, $start );
}

/** 短代码 + 登录弹窗按钮。已登录不显示（绑定在用户中心做）。 */
function jinyu_oauth_shortcode(): string {
	if ( is_user_logged_in() || ! jinyu_oauth_enabled() ) {
		return '';
	}
	$platforms = jinyu_oauth_platforms();
	if ( empty( $platforms ) ) {
		return '';
	}
	$start = admin_url( 'admin-post.php?action=jinyu_social_login&mode=start' );
	$out   = '<div class="jinyu-oauth-btns">';
	foreach ( $platforms as $p => $info ) {
		$url = add_query_arg( 'platform', $p, $start );
		// 直接输出徽标文字（Q/G/码/A），圆形底色由主题按平台类（jinyu-oauth-gitee 等）提供，不依赖 FontAwesome
		$out .= sprintf(
			'<a class="jinyu-oauth-btn jinyu-oauth-%1$s" href="%2$s" title="%3$s 登录" aria-label="%3$s 登录">%4$s</a>',
			esc_attr( $p ),
			esc_url( $url ),
			esc_attr( $info['label'] ),
			esc_html( $info['icon'] )
		);
	}
	$out .= '</div>';
	return $out;
}
add_shortcode( 'jinyu_oauth', 'jinyu_oauth_shortcode' );

function jinyu_get_oauth_accounts(): array {
	$out = [];
	foreach ( jinyu_sl_providers() as $p => $prov ) {
		$prov->set_config( jinyu_sl_get_config( $p ) );
		if ( $prov->is_configured() ) {
			$out[] = [ 'platform' => $p, 'client_id' => $prov->conf['client_id'] ];
		}
	}
	return $out;
}

/* ==========================================================================
 * 回调分发（admin-post.php?action=jinyu_social_login）
 *   mode=start  → 发起授权
 *   无 mode     → 平台回调（GET query / POST form_post）
 * ======================================================================== */
add_action( 'admin_post_nopriv_jinyu_social_login', 'jinyu_sl_dispatch' );
add_action( 'admin_post_jinyu_social_login', 'jinyu_sl_dispatch' );

function jinyu_sl_dispatch(): void {
	if ( ( $_GET['mode'] ?? '' ) === 'start' ) {
		jinyu_sl_begin( sanitize_key( $_GET['platform'] ?? '' ) );
		return;
	}
	jinyu_sl_callback();
}

/** 默认（内置）回调地址：确保与接收端点完全一致，第三方平台注册时也必须填此值。 */
function jinyu_sl_default_redirect_uri(): string {
	return admin_url( 'admin-post.php?action=jinyu_social_login' );
}

/**
 * 重定向 URI：多域名 / 子目录 / 套 CDN / 负载均衡场景下，
 * 若对外访问域名与 admin_url() 生成的不一致，可在后台覆盖为实际对外地址。
 * 优先使用用户在后台自定义的地址，否则回退内置固值（与接收端点一致）。
 */
function jinyu_sl_redirect_uri(): string {
	$opt = jinyu_sl_get_option();
	if ( ! empty( $opt['redirect_uri'] ) && filter_var( $opt['redirect_uri'], FILTER_VALIDATE_URL ) ) {
		return $opt['redirect_uri'];
	}
	return jinyu_sl_default_redirect_uri();
}

/** 绑定完成后回跳地址：优先主题配置的用户中心页，否则 WP 个人资料页 */
function jinyu_sl_user_center_url(): string {
	$opt     = get_option( 'jinyu_options', [] );
	$page_id = is_array( $opt ) ? (int) ( $opt['user_center_page'] ?? 0 ) : 0;
	if ( $page_id ) {
		$link = get_permalink( $page_id );
		if ( $link ) {
			return $link;
		}
	}
	return admin_url( 'profile.php' );
}

function jinyu_sl_begin( string $platform ): void {
	$providers = jinyu_sl_providers();
	if ( ! isset( $providers[ $platform ] ) ) {
		wp_die( '不支持的登录方式' );
	}
	$prov = $providers[ $platform ];
	$conf = jinyu_sl_get_config( $platform );
	$prov->set_config( $conf ); // 关键：provider 构造时不带配置，此处注入已解密配置，否则 client_id/secret 全空
	if ( empty( $conf['client_id'] ) ) {
		wp_die( '请先在后台配置 ' . esc_html( $prov->label() ) . ' 登录' );
	}
	// 配置完整性预检：密钥/私钥等必填项齐全才允许发起授权，避免带着残缺配置去请求而报“client secret is illegal”等诡异错
	if ( ! $prov->is_configured() ) {
		wp_die( esc_html( $prov->label() ) . ' 配置不完整：请补全 Client ID / 密钥等必填项后再使用。' );
	}

	$redirect = jinyu_sl_redirect_uri();
	$state    = bin2hex( random_bytes( 16 ) );
	$nonce    = ( 'apple' === $platform ) ? bin2hex( random_bytes( 16 ) ) : '';

	// 绑定意图：已登录用户从用户中心发起，回调后关联当前账号并回跳用户中心（而非新建账号/写文章页）
	$intent      = ( ( $_GET['intent'] ?? '' ) === 'bind' ) ? 'bind' : '';
	$redirect_to = '';
	// 回跳地址（bind 与登录通用）：仅在同域安全时采纳，防开放重定向
	if ( ! empty( $_GET['redirect_to'] ) ) {
		$redirect_to = wp_validate_redirect( wp_unslash( $_GET['redirect_to'] ), '' );
	}

	// state 存 transient（多 worker 共享，替代 $_SESSION），并下发 cookie 绑定浏览器
	set_transient(
		'jinyu_sl_' . $state,
		[ 'platform' => $platform, 'nonce' => $nonce, 'intent' => $intent, 'redirect_to' => $redirect_to ],
		600
	);
	setcookie(
		JINYU_SL_COOKIE,
		$state,
		[
			'expires'  => time() + 600,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		]
	);

	$auth = $prov->authorize_url( $state, $redirect );
	if ( '' !== $nonce ) {
		$auth = add_query_arg( 'nonce', $nonce, $auth );
	}
	wp_redirect( $auth );
	exit;
}

function jinyu_sl_callback(): void {
	ob_start(); // 缓冲 token 交换阶段的零散输出，避免其在 Set-Cookie 之前冲刷 header 导致登录 cookie 静默失效
	$is_post = ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST';
	$state   = sanitize_text_field( wp_unslash( $is_post ? ( $_POST['state'] ?? '' ) : ( $_GET['state'] ?? '' ) ) );
	if ( '' === $state ) {
		jinyu_sl_bail( '登录状态丢失（缺少 state），请重新点击第三方登录。' );
	}

	// CSRF：state 参数已在下方与 transient 校验（OAuth 标准做法）。
	// cookie 仅作同浏览器冗余校验；若缺失/不一致不致命，避免代理或子域场景下被误杀，
	// 但记录日志以便排查。真正的防 CSRF 由 state↔transient 保证。
	$cookie = $_COOKIE[ JINYU_SL_COOKIE ] ?? '';
	if ( '' !== $cookie && ! hash_equals( $cookie, $state ) ) {
		error_log( 'Jinyu Social Login: state cookie mismatch, proceeding via transient check.' );
	}

	$stored = get_transient( 'jinyu_sl_' . $state );
	delete_transient( 'jinyu_sl_' . $state );
	if ( ! is_array( $stored ) ) {
		jinyu_sl_bail( '登录会话已过期（state 失效），请重新点击第三方登录。' );
	}
	$platform = $stored['platform'];
	$nonce    = $stored['nonce'] ?? '';

	$providers = jinyu_sl_providers();
	if ( ! isset( $providers[ $platform ] ) ) {
		jinyu_sl_bail( '不支持的登录平台：' . $platform );
	}
	$prov = $providers[ $platform ];
	$prov->set_config( jinyu_sl_get_config( $platform ) ); // 注入已解密配置，保证 exchange_token 能拿到 client_secret

	// 安全：后台已关闭社交登录则中止（防止 600s 内有效的 transient 回调仍被利用完成登录）
	if ( ! jinyu_oauth_enabled() ) {
		jinyu_sl_bail( '第三方登录功能已关闭。' );
	}
	// 绑定意图要求已登录：会话过期/未登录时打开绑定链接不应静默建号，须明确报错
	$intent = $stored['intent'] ?? '';
	if ( 'bind' === $intent && ! is_user_logged_in() ) {
		jinyu_sl_bail( '绑定第三方账号请先登录后再操作。' );
	}

	$code = sanitize_text_field( wp_unslash( $is_post ? ( $_POST['code'] ?? '' ) : ( $_GET['code'] ?? '' ) ) );
	if ( '' === $code ) {
		jinyu_sl_bail( 'QQ 未回传授权码（code 缺失）。' );
	}

	$extra = [];
	if ( $is_post ) {
		$extra['id_token'] = sanitize_text_field( wp_unslash( $_POST['id_token'] ?? '' ) );
		$extra['user']     = wp_unslash( $_POST['user'] ?? '' );
	}
	if ( '' !== $nonce ) {
		$extra['nonce'] = $nonce;
	}

	$token = $prov->exchange_token( $code, jinyu_sl_redirect_uri() );
	if ( is_wp_error( $token ) ) {
		jinyu_sl_fail( $token->get_error_message() );
	}
	$ud = $prov->fetch_user( $token, $extra );
	if ( is_wp_error( $ud ) ) {
		jinyu_sl_fail( $ud->get_error_message() );
	}

	$uid = jinyu_sl_find_or_create( $platform, $ud, is_user_logged_in() );
	if ( is_wp_error( $uid ) ) {
		jinyu_sl_fail( $uid->get_error_message() );
	}
	wp_set_current_user( $uid );
	wp_set_auth_cookie( $uid, true );

	// 绑定意图：回跳用户中心（或指定 redirect_to），不再落到写文章页
	if ( 'bind' === $intent ) {
		$to = ! empty( $stored['redirect_to'] ) ? $stored['redirect_to'] : jinyu_sl_user_center_url();
		if ( ob_get_level() ) { ob_end_clean(); }
		wp_safe_redirect( $to );
		exit;
	}
	// 非绑定：登录页/弹窗显式传入的同域回跳地址优先，否则回前台首页（避免落到 wp-admin 在登录态未即时生效时回落 wp-login）
	if ( ! empty( $stored['redirect_to'] ) ) {
		if ( ob_get_level() ) { ob_end_clean(); }
		wp_safe_redirect( $stored['redirect_to'] );
		exit;
	}
	if ( ob_get_level() ) { ob_end_clean(); }
	wp_safe_redirect( home_url() );
	exit;
}

/** 统一失败出口：记录日志 + 用短时效 transient 带回登录页（login_message 消费一次即删），避免报错卡在 URL 反复复现 */
function jinyu_sl_bail( string $msg ): void {
	error_log( 'Jinyu Social Login failed: ' . $msg );
	$tid = wp_generate_password( 12, false );
	set_transient( 'jinyu_sl_err_' . $tid, $msg, 60 );
	if ( ob_get_level() ) { ob_end_clean(); }
	wp_safe_redirect( add_query_arg( 'jinyu_oauth_err', $tid, wp_login_url() ) );
	exit;
}

function jinyu_sl_fail( string $msg ): void {
	jinyu_sl_bail( $msg );
}

/** 在 wp-login 上渲染第三方登录失败原因（一次性 transient，消费后即删除，陈旧链接不再复现报错） */
add_filter( 'login_message', 'jinyu_sl_login_message' );
function jinyu_sl_login_message( $msg ) {
	$tid = sanitize_key( wp_unslash( $_GET['jinyu_oauth_err'] ?? '' ) );
	if ( '' === $tid ) {
		return $msg;
	}
	$err = get_transient( 'jinyu_sl_err_' . $tid );
	if ( ! is_string( $err ) ) {
		return $msg; // 已消费或过期：不残留陈旧报错
	}
	delete_transient( 'jinyu_sl_err_' . $tid );
	$msg .= '<div style="border-left:4px solid #d63638;color:#d63638;background:#fcf0f1;padding:10px 14px;margin:0 0 16px;">'
		. esc_html( $err ) . '</div>';
	return $msg;
}

/**
 * 按平台用户标识查找 / 创建 / 绑定 WP 用户。
 * 策略（用户选定）：邮箱匹配 + 自动建号。
 * - 登录（未登录）：已绑该平台 id → 直接登录；可信邮箱已存在 → 绑定该账号；否则自动建号
 * - 绑定（已登录）：把 openid 关联到当前账号；若该 openid 已挂在别的账号上，则归并到当前账号
 *   （解绑旧账号），杜绝「两账号同挂一个 openid → 登录时查到哪个算哪个」的串号问题。
 */
function jinyu_sl_find_or_create( string $platform, array $ud, bool $logged_in ): int|WP_Error {
	$current_uid = $logged_in ? (int) get_current_user_id() : 0;

	// —— 登录路径（未登录）——
	if ( 0 === $current_uid ) {
		// 1) 已按平台 id 绑定的账号：直接登录
		$existing = jinyu_sl_uid_by_oauth_id( $platform, $ud['id'] );
		if ( $existing ) {
			return $existing;
		}
		// 2) 可信邮箱且站内已存在：绑定到该账号（防邮箱伪造接管：仅 verified/签名来源）
		if ( ! empty( $ud['email'] ) ) {
			$mail_uid = (int) email_exists( $ud['email'] );
			if ( $mail_uid ) {
				update_user_meta( $mail_uid, jinyu_sl_oauth_id_key( $platform ), $ud['id'] );
				jinyu_sl_save_avatar( $mail_uid, $platform, $ud['avatar'] ?? '' );
				return $mail_uid;
			}
		}
		// 3) 全新用户：自动建号（可关闸 + 角色面板可配，见「第三方登录 → 新用户注册」）
		$sl_opt = jinyu_sl_get_option();
		if ( empty( $sl_opt['allow_register'] ) ) {
			jinyu_sl_fail( __( '第三方登录自动注册已被管理员关闭，请先注册本站账号后在个人中心绑定社交身份。', 'jinyu-theme-companion' ) );
		}
		return jinyu_sl_create_oauth_user( $platform, $ud );
	}

	// —— 绑定路径（已登录，意图把社交身份关联到当前账号）——
	$id_key = jinyu_sl_oauth_id_key( $platform );
	// 已绑在当前账号：幂等，无需重复写入
	if ( (string) get_user_meta( $current_uid, $id_key, true ) === (string) $ud['id'] ) {
		return $current_uid;
	}
	// 该 openid 已挂在别的账号 → 归并到当前账号（解绑旧账号，避免重复绑定/串号）
	$other = jinyu_sl_uid_by_oauth_id( $platform, $ud['id'] );
	if ( $other && $other !== $current_uid ) {
		delete_user_meta( $other, $id_key );
		delete_user_meta( $other, jinyu_sl_oauth_avatar_key( $platform ) );
	}
	update_user_meta( $current_uid, $id_key, $ud['id'] );
	jinyu_sl_save_avatar( $current_uid, $platform, $ud['avatar'] ?? '' );
	return $current_uid;
}

/** 平台 id meta 键名（按平台分键，openid 互不串扰） */
function jinyu_sl_oauth_id_key( string $platform ): string {
	return 'jinyu_oauth_' . $platform . '_id';
}

/** 平台头像 meta 键名（按平台分键，避免多平台头像互相覆盖） */
function jinyu_sl_oauth_avatar_key( string $platform ): string {
	return 'jinyu_oauth_' . $platform . '_avatar';
}

/** 按 openid 查绑定的用户 id（LIMIT 1，避免多绑定时返回不确定行） */
function jinyu_sl_uid_by_oauth_id( string $platform, string $id ): int {
	global $wpdb;
	$uid = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT user_id FROM $wpdb->usermeta WHERE meta_key=%s AND meta_value=%s LIMIT 1",
			jinyu_sl_oauth_id_key( $platform ),
			$id
		)
	);
	return $uid ? (int) $uid : 0;
}

/** 仅在有头像时写入按平台头像键（保留旧全局 jinyu_oauth_avatar 以兼容历史数据） */
function jinyu_sl_save_avatar( int $uid, string $platform, string $avatar ): void {
	if ( '' === $avatar ) {
		return;
	}
	update_user_meta( $uid, jinyu_sl_oauth_avatar_key( $platform ), $avatar );
}

/**
 * 自动建号。角色由面板配置（subscriber/contributor/author，默认 subscriber——
 * contributor 可投草稿并占用媒体上传等能力，对开放注册站点偏宽）；仅接受白名单，防越权注入。
 */
function jinyu_sl_create_oauth_user( string $platform, array $ud ): int|WP_Error {
	$opt  = jinyu_sl_get_option();
	$role = in_array( $opt['role'] ?? '', [ 'subscriber', 'contributor', 'author' ], true ) ? $opt['role'] : 'subscriber';
	$slug     = substr( md5( $ud['id'] ), 0, 8 );
	$username = sanitize_user( $platform . '_' . $slug );
	if ( username_exists( $username ) ) {
		$username .= '_' . wp_generate_password( 4, false );
	}
	$fallback = $platform . '_' . $slug . '@' . ( parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' );
	$uid      = wp_create_user( $username, wp_generate_password( 16, true ), $ud['email'] ?: $fallback );
	if ( is_wp_error( $uid ) ) {
		return $uid;
	}
	wp_update_user(
		[
			'ID'            => $uid,
			'role'          => $role,
			'display_name'  => $ud['nickname'] ?: $username,
			'nickname'      => $ud['nickname'] ?: $username,
		]
	);
	update_user_meta( $uid, jinyu_sl_oauth_id_key( $platform ), $ud['id'] );
	jinyu_sl_save_avatar( $uid, $platform, $ud['avatar'] ?? '' );
	return $uid;
}

/* ==========================================================================
 * 从主题旧配置一次性迁移（插件首次接管时）
 * ======================================================================== */
function jinyu_sl_maybe_migrate(): void {
	$existing = get_option( JINYU_SL_OPT );
	if ( false !== $existing && ! empty( $existing ) ) {
		return; // 已配置过，不覆盖
	}
	// 主题旧配置存于 option jinyu_options['oauth_enable'] / ['oauth_accounts']
	$theme_opt = get_option( 'jinyu_options', [] );
	if ( ! is_array( $theme_opt ) ) {
		return;
	}
	$old_enable = ! empty( $theme_opt['oauth_enable'] );
	$old_acc    = $theme_opt['oauth_accounts'] ?? [];
	if ( is_string( $old_acc ) ) {
		$old_acc = json_decode( $old_acc, true ) ?: [];
	}
	if ( ! is_array( $old_acc ) || empty( $old_acc ) ) {
		return;
	}

	$accounts = [];
	foreach ( $old_acc as $a ) {
		if ( ! is_array( $a ) ) {
			continue;
		}
		$p          = $a['platform'] ?? '';
		$cfg        = is_array( $a ) ? $a : [];
		$client_id  = $cfg['client_id'] ?? '';
		// 主题侧 client_secret 已是 jinyu_enc2:: 密文，迁移前先解主题格式再按插件格式重加密，避免二次加密
		$secret     = jinyu_sl_decrypt_theme_secret( $cfg['client_secret'] ?? '' );
		if ( '' === $client_id ) {
			continue;
		}
		$accounts[ $p ] = [
			'client_id'     => $client_id,
			'client_secret' => $secret ? jinyu_sl_encrypt( $secret ) : '',
		];
	}

	if ( empty( $accounts ) ) {
		return;
	}
	update_option(
		JINYU_SL_OPT,
		[ 'enable' => $old_enable, 'accounts' => $accounts ]
	);
}
