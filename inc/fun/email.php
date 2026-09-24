<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SMTP 发信：按后台配置接管 wp_mail，并提供「发送测试邮件」入口。
 */

add_action('phpmailer_init', function ($phpmailer) {
    (new \Jinyu\Mail\Jinyu_SmtpConfig())->apply($phpmailer);
});

add_action('wp_ajax_jinyu_test_smtp', function () {
    check_ajax_referer('jinyu_companion_settings', 'jinyu_companion_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(__('权限不足', 'jinyu-theme-companion'));

    $to = wp_get_current_user()->user_email;
    if (!$to) $to = get_option('admin_email');
    if (!$to || !is_email($to)) wp_send_json_error(__('无法确定收件人邮箱，请先在个人资料中填写邮箱', 'jinyu-theme-companion'));

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
        'host'   => jinyu_companion_get_option('smtp_host', ''),
        'port'   => (int) jinyu_companion_get_option('smtp_port', 465),
        'secure' => jinyu_companion_get_option('smtp_secure', 'ssl'),
        'user'   => jinyu_companion_get_option('smtp_user', ''),
        'pwd'    => jinyu_companion_get_option('smtp_pwd', ''),
        'from'   => jinyu_companion_get_option('smtp_from', ''),
        'from_name' => jinyu_companion_get_option('smtp_from_name', ''),
    ];
    foreach ($map as $post => $key) {
        if (isset($_POST[$post]) && $_POST[$post] !== '') {
            $form[$key] = is_string($_POST[$post]) ? wp_unslash($_POST[$post]) : $_POST[$post];
        }
    }
    $useForm = !empty($form['host']);
    if ($useForm) {
        \Jinyu\Mail\Jinyu_SmtpConfig::$testOverride = $form;
    } elseif (!jinyu_companion_get_option('smtp_host', '')) {
        wp_send_json_error(__('请先在「邮件 SMTP」填写 SMTP 主机并保存，再发送测试邮件。', 'jinyu-theme-companion'));
    }

    $subj = sprintf('[%s] 金玉主题 SMTP 测试', get_bloginfo('name'));
    $body = "这是一封来自金玉主题的 SMTP 测试邮件。\n发送时间：" . current_time('mysql');

    global $phpmailer;
    $sent = wp_mail($to, $subj, $body);
    \Jinyu\Mail\Jinyu_SmtpConfig::$testOverride = null;
    $err  = (isset($phpmailer) && is_object($phpmailer)) ? $phpmailer->ErrorInfo : '';

    if ($sent) {
        wp_send_json_success(sprintf(__('测试邮件已发送至 %s', 'jinyu-theme-companion'), $to));
    }
    wp_send_json_error(__('发送失败：', 'jinyu-theme-companion') . ($err ?: __('请检查 SMTP 主机/端口/账号或服务器发信权限', 'jinyu-theme-companion')));
});

add_action('wp_ajax_jinyu_clear_cache', function () {
    check_ajax_referer('jinyu_companion_settings', 'jinyu_companion_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(__('权限不足', 'jinyu-theme-companion'));
    $n = function_exists('jinyu_cache_flush') ? jinyu_cache_flush() : 0;
    wp_send_json_success(sprintf(__('已清理 %d 条缓存', 'jinyu-theme-companion'), (int) $n));
});
