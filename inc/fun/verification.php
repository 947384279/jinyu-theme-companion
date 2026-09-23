<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO：搜索引擎站点验证元标签（Google / Bing / Baidu / Yandex / 360）。
 *
 * 让各搜索平台确认站点归属、开启站长工具与收录的前提条件。各平台验证代码由用户在
 * 设置页填入，本模块仅负责在 <head> 输出对应 meta。
 *
 * 与 inc/seo.php 一致：若已安装主流 SEO 插件（已在其中配置验证），则主动让位，避免重复输出。
 * 未安装 SEO 插件时，即使 SEO 主开关关闭也照常输出验证标签（验证属于「收录前提」，独立于社交元标签）。
 */

add_action( 'wp_head', 'jinyu_seo_verification', 1 );
function jinyu_seo_verification(): void {
	if ( is_admin() ) {
		return;
	}
	// 已装主流 SEO 插件时由其接管，避免重复
	if ( jinyu_seo_plugin_active() ) {
		return;
	}

	$map = array(
		'verify_google' => 'google-site-verification',
		'verify_bing'   => 'msvalidate.01',
		'verify_baidu'  => 'baidu-site-verification',
		'verify_yandex' => 'yandex-verification',
		'verify_360'    => '360-site-verification',
	);

	foreach ( $map as $key => $name ) {
		$code = trim( (string) jinyu_companion_get_option( $key, '' ) );
		if ( '' !== $code ) {
			echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $code ) . '">' . PHP_EOL;
		}
	}
}
