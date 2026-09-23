<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 性能：Speculation Rules（WordPress 6.8+ 原生支持）。
 *
 * 在 <head> 注入 <script type="speculationrules">，对站内同站链接做预取（prefetch）
 * 或预渲染（prerender），显著降低站内跳转的感知延迟（TTFB 体感）。
 *
 * 与主题 perf.php 不重叠——主题未实现该功能；也与任何 SEO 插件无冲突（纯性能，不产出 SEO 标签）。
 * 仅当开关开启且站点 WP 版本 ≥ 6.8 时输出；旧版本核心不识别此脚本类型，静默跳过。
 */

add_action( 'wp_head', 'jinyu_speculation_rules', 3 );
function jinyu_speculation_rules(): void {
	if ( is_admin() ) {
		return;
	}
	if ( ! jinyu_companion_is_checked( 'speculation_enable', false ) ) {
		return;
	}
	// Speculation Rules 需 WordPress 6.8+（原生提供 speculation_rules 支持）
	if ( version_compare( (string) get_bloginfo( 'version' ), '6.8', '<' ) ) {
		return;
	}

	$mode = jinyu_companion_get_option( 'speculation_mode', 'prefetch' );
	if ( ! in_array( $mode, array( 'prefetch', 'prerender' ), true ) ) {
		$mode = 'prefetch';
	}
	$eager = jinyu_companion_get_option( 'speculation_eagerness', 'conservative' );
	if ( ! in_array( $eager, array( 'conservative', 'moderate', 'eager' ), true ) ) {
		$eager = 'conservative';
	}

	$rule = array(
		'version' => 1,
		$mode     => array(
			array(
				'source'      => 'document',
				'where'       => array(
					'and' => array(
						array( 'href_matches' => '/*' ),
						array( 'relative_to' => 'document' ),
					),
				),
				'eagerness'   => $eager,
			),
		),
	);

	// 允许其它模块/主题微调规则（例如排除特定路径或追加预渲染白名单）
	$rule = apply_filters( 'jinyu_speculation_rules_config', $rule, $mode, $eager );

	echo "\n<script type=\"speculationrules\">\n"
		. wp_json_encode( $rule, JSON_UNESCAPED_SLASHES )
		. "\n</script>\n";
}
