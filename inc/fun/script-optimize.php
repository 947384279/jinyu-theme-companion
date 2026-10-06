<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 第三方脚本延迟加载 + 预连接（defer / preconnect 管理器）。
 *
 * 设计边界（与「JS/CSS 合并压缩」讨论结论一致）：
 *  - 主题已用 gulp 自做合并压缩（assets/dist/*.min.* 是构建产物），插件绝不碰其内部；
 *  - 插件自有前端资源极小且多为 .min / 外部 CDN，通用合并器收益≈0、风险>0；
 *  - 本模块只针对「外部域名」脚本（如微信 JS-SDK、GitHub gist）做 defer，并对配置的主机
 *    输出 preconnect —— 这些才是真实渲染阻塞点，且外部域本就不参与合并。
 *  - 仅当开关开启时生效；尊重已自带 defer/async 的脚本，不重复加。
 *
 * 配置键（jinyu_companion_settings）：
 *  so_enable       总开关（默认关）
 *  so_preconnect   预连接主机列表（逗号 / 换行分隔，自动规整为 host）
 */

add_filter( 'script_loader_tag', 'jinyu_so_defer_external', 20, 3 );
/**
 * 给外部域名的 <script> 追加 defer（不改变依赖执行顺序，仅改开始时机）。
 *
 * @param string $tag    原始标签。
 * @param string $handle 脚本句柄。
 * @param string $src    脚本地址。
 * @return string
 */
function jinyu_so_defer_external( string $tag, string $handle, string $src ): string {
	if ( ! jinyu_companion_is_checked( 'so_enable', false ) ) {
		return $tag;
	}
	if ( is_admin() ) {
		return $tag;
	}
	if ( '' === $src ) {
		return $tag;
	}
	$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$src_host  = (string) wp_parse_url( $src, PHP_URL_HOST );
	if ( '' === $src_host || $src_host === $site_host ) {
		return $tag; // 仅处理外部域名
	}
	if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
		return $tag; // 已是异步，不重复
	}
	return str_replace( '<script ', '<script defer ', $tag );
}

add_action( 'wp_head', 'jinyu_so_preconnect', 2 );
/**
 * 输出 preconnect 链接（仅对配置且非本站的主机）。
 */
function jinyu_so_preconnect(): void {
	if ( ! jinyu_companion_is_checked( 'so_enable', false ) ) {
		return;
	}
	if ( is_admin() ) {
		return;
	}
	$hosts = jinyu_so_parse_hosts( (string) jinyu_companion_get_option( 'so_preconnect', '' ) );
	if ( empty( $hosts ) ) {
		return;
	}
	$out = '';
	foreach ( $hosts as $h ) {
		$out .= '<link rel="preconnect" href="https://' . esc_attr( $h ) . '" crossorigin>' . "\n";
	}
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr 已转义 host
}

/**
 * 规整主机列表：提取 host、去协议/斜杠、去重、排除本站。
 *
 * @param string $raw 原始输入（逗号 / 换行分隔，可带协议）。
 * @return string[]
 */
function jinyu_so_parse_hosts( string $raw ): array {
	$self = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$out  = array();
	foreach ( preg_split( '/[\s,]+/', $raw ) ?: array() as $item ) {
		$item = trim( $item );
		if ( '' === $item ) {
			continue;
		}
		$host = (string) wp_parse_url( $item, PHP_URL_HOST );
		if ( '' === $host ) {
			$host = preg_replace( '#^https?://#', '', $item );
		}
		$host = trim( (string) $host, '/' );
		if ( '' === $host || $host === $self || in_array( $host, $out, true ) ) {
			continue;
		}
		$out[] = $host;
	}
	return $out;
}
