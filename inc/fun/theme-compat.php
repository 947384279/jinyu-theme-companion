<?php
/**
 * 从主题迁出的「插件领地」功能兼容层。
 *
 * 主题自 1.x 起定位为纯呈现层。下列能力原属主题（安全加固、浏览量采集、客户端真实 IP、
 * 通用 IP 速率限制），现统一由配套插件提供，主题侧仅保留委托壳（插件缺失即优雅降级）。
 *
 * 注意：本文件在 after_setup_theme 阶段随主文件加载，此时主题函数（jinyu_is_checked /
 * jinyu_get_option 等）已就绪，可直接复用；并全部以 function_exists 守卫，未启用主题时
 * 安全项默认关闭、采集项不执行，不会致命。
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* --------------------------------------------------------------------------
 * 客户端真实 IP 与通用 IP 速率限制
 * 公共能力，供全站 AJAX 接口复用。原属主题 security.php。
 * ------------------------------------------------------------------------ */

if ( ! function_exists( 'jinyu_companion_client_ip' ) ) {
	/**
	 * 获取客户端真实 IP：自托管环境优先 REMOTE_ADDR（TCP 对端，不可伪造）。
	 * 仅当 REMOTE_ADDR 属于可信代理私网时才回退到 X-Forwarded-For，避免伪造该头绕过限流。
	 *
	 * @return string
	 */
	function jinyu_companion_client_ip(): string {
		$remote        = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		$trusted_proxy = filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
		&& (
			str_starts_with( $remote, '10.' )          // RFC1918 私有地址.
			|| str_starts_with( $remote, '172.16.' )   // 172.16.0.0/12.
			|| str_starts_with( $remote, '192.168.' )  // 192.168.0.0/16.
			|| '127.0.0.1' === $remote
		);
		if ( $trusted_proxy ) {
			foreach ( [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ] as $k ) {
				if ( ! empty( $_SERVER[ $k ] ) ) {
					$ip = trim( explode( ',', (string) $_SERVER[ $k ] )[0] );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
		}
		return $remote !== '' ? $remote : '0.0.0.0';
	}
}

if ( ! function_exists( 'jinyu_companion_rate_limit_check' ) ) {
	/**
	 * 通用 IP 速率限制（基于 transient 的近似滑动窗口）。
	 * 用于匿名 AJAX 接口（注册 / 找回密码 / 点赞 / 投票等）防刷。
	 *
	 * @param string $action  动作标识（区分不同接口）
	 * @param int    $max     时间窗口内允许的最大请求数
	 * @param int    $seconds 时间窗口（秒）
	 * @return bool true=放行，false=已超限需拒绝
	 */
	function jinyu_companion_rate_limit_check( string $action, int $max = 10, int $seconds = 60 ): bool {
		$ip    = function_exists( 'jinyu_companion_client_ip' ) ? jinyu_companion_client_ip() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0' );
		$key   = 'jinyu_rl_' . md5( $action . '|' . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		// 计数 +1；首次写入带 TTL，自然过期后窗口重置.
		set_transient( $key, $count + 1, $seconds );
		return true;
	}
}

/* --------------------------------------------------------------------------
 * 安全加固：XML-RPC / REST API / 版本号 / 登录防暴破
 * 原属主题 security.php（无条件行为），迁出后主题不再承担任何安全逻辑。
 * 关闭 REST API 受主题「关闭 REST API」开关控制；其余默认开启（安全加固），
 * 未启用主题时全部跳过（优雅降级）。
 * ------------------------------------------------------------------------ */

// XML-RPC 默认关闭：常见暴破与 pingback 攻击面.
add_filter( 'xmlrpc_enabled', '__return_false' );

// 关闭 REST API 给未登录用户的访问（受主题开关控制）.
if ( function_exists( 'jinyu_is_checked' ) && jinyu_is_checked( 'close_rest_api' ) ) {
	add_filter(
		'rest_authentication_errors',
		function ( $result ) {
			if ( ! empty( $result ) ) {
				return $result;
			}
			if ( ! is_user_logged_in() ) {
				return new WP_Error(
					'jinyu_rest_disabled',
					__( 'REST API 已对未登录用户关闭。', 'jinyu-theme-companion' ),
					[ 'status' => rest_authorization_required_code() ]
				);
			}
			return $result;
		}
	);
	add_filter( 'rest_jsonp_enabled', '__return_false' );

	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-info"><p>' .
			esc_html__( '「关闭 REST API」已生效：仅未登录访客被拒绝，已登录用户在后台使用古登堡编辑器不受影响。', 'jinyu-theme-companion' ) .
			'</p></div>';
		}
	);
}

// 去掉 WordPress 版本号输出（防扫描）.
add_filter( 'the_generator', '__return_empty_string' );

// 登录防暴破（按客户端 IP 限流，连续失败锁定一段时间）.
{
	$jinyu_brute_max = 5;                       // 允许的最大连续失败次数.
	$jinyu_brute_ttl = 15 * MINUTE_IN_SECONDS; // 锁定时长（秒）.
	$jinyu_brute_min = (int) ceil( $jinyu_brute_ttl / MINUTE_IN_SECONDS ); // 用于提示文案，随 TTL 联动.

	// 登录失败时累计该 IP 的失败次数（锁定窗口内持续刷新）.
	add_action(
		'wp_login_failed',
		function () use ( $jinyu_brute_ttl ) {
			$key   = 'jinyu_brute_' . md5( jinyu_companion_client_ip() );
			$fails = (int) get_transient( $key ) + 1;
			set_transient( $key, $fails, $jinyu_brute_ttl );
		}
	);

	// 认证阶段：达到失败阈值时一律拒绝（即便密码正确），确保锁定对所有请求生效，防范暴力枚举.
	add_filter(
		'authenticate',
		function ( $user, $username, $password ) use ( $jinyu_brute_max, $jinyu_brute_min ) {
			$fails = (int) get_transient( 'jinyu_brute_' . md5( jinyu_companion_client_ip() ) );
			if ( $fails >= $jinyu_brute_max ) {
				return new WP_Error(
					'jinyu_brute',
					sprintf(
// translators: Placeholder values are substituted at runtime.
						__( '登录尝试过于频繁，已被临时锁定，请 %d 分钟后再试。', 'jinyu-theme-companion' ),
						$jinyu_brute_min
					)
				);
			}
			return $user;
		},
		30,
		3
	);

	// 登录成功后清零该 IP 计数器.
	add_action(
		'wp_login',
		function () {
			delete_transient( 'jinyu_brute_' . md5( jinyu_companion_client_ip() ) );
		}
	);
}

/* --------------------------------------------------------------------------
 * 浏览量采集（写入 post meta，插件领地：数据采集 / 分析）
 * 原属主题 post-meta.php 的 jinyu_auto_increment_views()，迁出后主题仅保留读取壳。
 * ------------------------------------------------------------------------ */
add_action( 'wp_head', 'jinyu_companion_auto_increment_views' );
/**
 * 单篇文章访问时按 IP 冷却自增浏览量（写入 post meta jinyu_views）。
 */
function jinyu_companion_auto_increment_views() {
	if ( is_single() && ! is_admin() ) {
		global $post;
		if ( ! $post ) {
			return;
		}
		$pid  = $post->ID;
		// 冷却秒数：后台「全局设置 › 同一 IP 浏览量冷却秒数」.
		$wait = function_exists( 'jinyu_get_option' ) ? max( 1, (int) jinyu_get_option( 'views_wait_seconds', 10 ) ) : 10;
		$key  = 'jinyu_vw_' . md5( jinyu_companion_client_ip() . '|' . $pid );
		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, $wait );
			add_action(
				'shutdown',
				function () use ( $pid ) {
					$count = (int) get_post_meta( $pid, 'jinyu_views', true );
					update_post_meta( $pid, 'jinyu_views', $count + 1 );
				}
			);
		}
	}
}
