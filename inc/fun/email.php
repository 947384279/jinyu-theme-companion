<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SMTP 发信：按后台配置接管 wp_mail，并提供「发送测试邮件」入口。
 */

add_action(
    'phpmailer_init',
    function ( $phpmailer ) {
		( new \Jinyu\Mail\Jinyu_SmtpConfig() )->apply( $phpmailer );
	}
);

add_action(
    'wp_ajax_jinyu_test_smtp',
    function () {
		jinyu_companion_guard( 'jinyu_companion_settings', 'jinyu_companion_nonce' );

		$to = wp_get_current_user()->user_email;
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! $to || ! is_email( $to ) ) {
			wp_send_json_error( __( '无法确定收件人邮箱，请先在个人资料中填写邮箱', 'jinyu-theme-companion' ) );
		}

		// 测试时优先使用后台表单当前值（可能尚未保存），其余字段回退到数据库已保存值。
		// 特别处理密码：表单密码框默认留空（"留空则不修改"），若用户未填则必须用已保存密码，
		// 否则会出现「正确用户名 + 空密码」导致 SMTP 535 认证失败。
		$map = [
			'smtp_host'   => 'host',
			'smtp_port'   => 'port',
			'smtp_secure' => 'secure',
			'smtp_user'   => 'user',
			'smtp_pwd'    => 'pwd',
			'smtp_from'   => 'from',
			'smtp_from_name' => 'from_name',
		];
		$form = [
			'host'   => jinyu_companion_get_option( 'smtp_host', '' ),
			'port'   => (int) jinyu_companion_get_option( 'smtp_port', 465 ),
			'secure' => jinyu_companion_get_option( 'smtp_secure', 'ssl' ),
			'user'   => jinyu_companion_get_option( 'smtp_user', '' ),
			'pwd'    => jinyu_companion_decrypt( (string) jinyu_companion_get_option( 'smtp_pwd', '' ) ),
			'from'   => jinyu_companion_get_option( 'smtp_from', '' ),
			'from_name' => jinyu_companion_get_option( 'smtp_from_name', '' ),
		];
		foreach ( $map as $post => $key ) {
			// 只接受字符串：数组值透传进 SmtpConfig 会触发 PHP 8 的 array-to-string TypeError
			// 键名 $map 的每一项都是本文件写死的字面量，非用户输入。
			// phpcs:ignore WordPress.Security.NonceVerification -- nonce 已在入口经 jinyu_companion_guard() 校验
			if ( isset( $_POST[ $post ] ) && is_string( $_POST[ $post ] ) && $_POST[ $post ] !== '' ) {
				$val = wp_unslash( $_POST[ $post ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification -- $val 为 wp_unslash 后的中间变量，随后在本分支内按 $key 经 sanitize_email / sanitize_text_field 处理；密码分支特例不 sanitize 以免破坏 +/= 等合法字符
				if ( 'pwd' === $key ) {
					$form[ $key ] = $val; // 密码不 sanitize，避免破坏合法特殊字符（如 + / = 等）
				} elseif ( 'from' === $key ) {
					$form[ $key ] = sanitize_email( $val );
				} else {
					$form[ $key ] = sanitize_text_field( $val );
				}
			}
		}
		$use_form = ! empty( $form['host'] );
		if ( $use_form ) {
			\Jinyu\Mail\Jinyu_SmtpConfig::$testOverride = $form;
		} elseif ( ! jinyu_companion_get_option( 'smtp_host', '' ) ) {
			wp_send_json_error( __( '请先在「邮件 SMTP」填写 SMTP 主机并保存，再发送测试邮件。', 'jinyu-theme-companion' ) );
		}

		$subj = sprintf( '[%s] 金玉主题 SMTP 测试', get_bloginfo( 'name' ) );
		$body = "这是一封来自金玉主题的 SMTP 测试邮件。\n发送时间：" . current_time( 'mysql' );

		global $phpmailer;
		$sent = wp_mail( $to, $subj, $body );
		\Jinyu\Mail\Jinyu_SmtpConfig::$testOverride = null;
		$err  = ( isset( $phpmailer ) && is_object( $phpmailer ) ) ? $phpmailer->ErrorInfo : '';

		if ( $sent ) {
			// translators: Placeholder values are substituted at runtime.
			wp_send_json_success( sprintf( __( '测试邮件已发送至 %s', 'jinyu-theme-companion' ), $to ) );
		}
		wp_send_json_error( __( '发送失败：', 'jinyu-theme-companion' ) . ( $err ?: __( '请检查 SMTP 主机/端口/账号或服务器发信权限', 'jinyu-theme-companion' ) ) );
	}
);

add_action(
    'wp_ajax_jinyu_clear_cache',
    function () {
		jinyu_companion_guard( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		// 走插件独占的失效入口（jinyu_companion_cache_flush 返回清理条目数；
		// 旧别名 jinyu_cache_flush 签名是 void，拿不到计数）。
		$n = function_exists( 'jinyu_companion_cache_flush' ) ? jinyu_companion_cache_flush() : 0;
		// translators: Placeholder values are substituted at runtime.
		wp_send_json_success( sprintf( __( '已清理 %d 条缓存', 'jinyu-theme-companion' ), (int) $n ) );
	}
);
