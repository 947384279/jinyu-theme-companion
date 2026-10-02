<?php
/**
 * 从主题迁出的「插件领地」功能兼容层。
 *
 * 主题自 1.x 起定位为纯呈现层。下列能力原属主题（安全加固、浏览量采集、客户端真实 IP、
 * 通用 IP 速率限制），现统一由配套插件提供。
 *
 * 解耦约束：本文件**不探测任何主题函数**（不写 function_exists( 'jinyu_get_option' ) 之类）。
 * 探测即「插件认识主题」，插件的行为会随主题在否而变——对安全功能而言尤其危险：
 * 主题缺席就静默失去防护，那不是降级而是裸奔。配置一律读本插件自己的数据源
 * （companion 设置表 / 性能中心开关表），主题若也要这个能力，走过滤器接入。
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
--------------------------------------------------------------------------
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
		$remote        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$trusted_proxy = filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
		&& (
			str_starts_with( $remote, '10.' )          // RFC1918 私有地址.
			|| str_starts_with( $remote, '172.16.' )   // 172.16.0.0/12.
			|| str_starts_with( $remote, '192.168.' )  // 192.168.0.0/16.
			|| '127.0.0.1' === $remote
		);
		if ( $trusted_proxy ) {
			foreach ( [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ] as $k ) {
				$raw = isset( $_SERVER[ $k ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) : '';
				if ( '' !== $raw ) {
					$ip = trim( explode( ',', $raw )[0] );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
		}
		return '' !== $remote ? $remote : '0.0.0.0';
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
		$ip = function_exists( 'jinyu_companion_client_ip' ) ? jinyu_companion_client_ip() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0' );
		// 统一走原语里的原子计数（有持久对象缓存时用 add + incr，避免读-改-写丢计数）。
		return jinyu_companion_rate_limit_hit( 'jinyu_rl_' . md5( $action . '|' . $ip ), $max, $seconds );
	}
}

/*
 * 响应主题的需求广播：主题是纯呈现层，只声明「我需要客户端真实 IP / 需要限流」，
 * 不认识本插件任何函数名。契约方向：主题 apply_filters → 本插件 add_filter。
 */
add_filter( 'jinyu_client_ip', 'jinyu_companion_filter_client_ip' );
/**
 * 过滤器回调：把主题请求的客户端 IP 解析为本插件的可信实现。
 *
 * @param string $fallback 主题给出的默认值（REMOTE_ADDR 或 0.0.0.0）。
 * @return string
 */
function jinyu_companion_filter_client_ip( $fallback ): string {
	$ip = jinyu_companion_client_ip();
	return '' !== $ip ? $ip : (string) $fallback;
}

add_filter( 'jinyu_rate_limit_check', 'jinyu_companion_filter_rate_limit_check', 10, 3 );
/**
 * 过滤器回调：接管主题的 IP 速率限制判定。
 *
 * @param bool   $allow   主题默认值（恒为 true=放行）。
 * @param string $action  动作标识。
 * @param int    $max     窗口内最大请求数。
 * @param int    $seconds 窗口秒数。
 * @return bool
 */
function jinyu_companion_filter_rate_limit_check( $allow, $action = '', $max = 10, $seconds = 60 ): bool {
	return jinyu_companion_rate_limit_check( (string) $action, (int) $max, (int) $seconds );
}

/*
--------------------------------------------------------------------------
 * 安全加固：XML-RPC / REST API / 版本号 / 登录防暴破
 * 原属主题 security.php（无条件行为），迁出后主题不再承担任何安全逻辑。
 * 全部加固默认开启，且**不依赖主题在场**——安全功能握在可选组件手里意味着
 * 主题缺席时站点静默失去防护，那不是优雅降级，是隐性裸奔。
 * 「限制游客 REST API」是本插件性能中心的一个开关（restrict_guest_rest，默认关）。
 * ------------------------------------------------------------------------ */

// XML-RPC 默认关闭：常见暴破与 pingback 攻击面.
add_filter( 'xmlrpc_enabled', '__return_false' );

/*
 * 限制游客 REST API。
 *
 * 开关经 jinyu_restrict_guest_rest 过滤器广播，由性能中心（restrict_guest_rest，默认关）
 * 响应。此处不直接读性能中心的 option——那会让本文件与 perf-center 的存储结构硬耦合，
 * 且 perf-center 可能因互斥守卫未加载。过滤器是唯一通道：无人响应即默认不限制，
 * 行为可预期。
 */
if ( (bool) apply_filters( 'jinyu_restrict_guest_rest', false ) ) {
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

	// 登录失败时累计该 IP 的失败次数（锁定窗口内持续刷新）. 走原子计数，避免并发下丢计数。
	add_action(
		'wp_login_failed',
		function () use ( $jinyu_brute_ttl ) {
			jinyu_companion_counter_incr( 'jinyu_brute_' . md5( jinyu_companion_client_ip() ), $jinyu_brute_ttl );
		}
	);

	// 认证阶段：达到失败阈值时一律拒绝（即便密码正确），确保锁定对所有请求生效，防范暴力枚举.
	add_filter(
		'authenticate',
		function ( $user, $username, $password ) use ( $jinyu_brute_max, $jinyu_brute_min ) {
			$fails = jinyu_companion_counter_get( 'jinyu_brute_' . md5( jinyu_companion_client_ip() ) );
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
			jinyu_companion_counter_delete( 'jinyu_brute_' . md5( jinyu_companion_client_ip() ) );
		}
	);
	}

	/*
	--------------------------------------------------------------------------
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
			$pid = $post->ID;
			// 冷却秒数：写死常量，IP 冷却防刷新刷量。
			$wait = 10;
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
