<?php
/**
 * GEO 信号补齐：文章页的作者署名与语音朗读结构化数据。
 *
 * 【为什么放在插件而不是主题】
 * 这两条都不是「主题长什么样」，而是「站点怎么被机器理解」，属站点级策略。
 * 主题可换，策略留得住。
 *
 * 【为什么需要补】
 * 结构化数据里的 author 字段只有解析 JSON-LD 的爬虫会读，而多数 SEO 检查工具、
 * 部分 AI 管道与浏览器插件只读 <meta>。HTML 层缺作者署名，等于权威信号只对一半
 * 消费者可见。speakable 则是让语音助手能朗读正文的开关，缺它则语音搜索完全进不来。
 *
 * 【为什么正文类名走设置而非硬编码】
 * 正文容器名由主题决定，同一插件可能装在不同主题下。写死类名会在换主题后指向
 * 不存在的元素——而 cssSelector 指空比不写更糟：结构化数据会被判定无效并整体忽略。
 * 故由站点在后台填写，填错由站长负责，插件不猜。
 *
 * @package jinyu-theme-companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 本模块是否启用。
 *
 * @return bool
 */
function jinyu_geo_signals_enabled(): bool {
	return jinyu_companion_is_checked( 'geo_signals_enable', true );
}

/**
 * 解析正文容器选择器列表。
 *
 * 后台填CSS 选择器，多个用换行或逗号分隔。空数组表示不输出 speakable。
 *
 * @return array<int,string>
 */
function jinyu_geo_speakable_selectors(): array {
	$raw = (string) jinyu_companion_get_option( 'geo_speakable_selector', '' );

	$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

	$clean = array();

	foreach ( (array) $parts as $selector ) {
		// 只接受形如 .foo / #bar / article 的简单选择器，
		// 防止把结构化数据输出变成任意文本注入位。
		if ( preg_match( '/^[.#]?[A-Za-z][\w\-]*$/', $selector ) ) {
			$clean[] = $selector;
		}
	}

	/**
	 * 过滤 speakable 使用的正文选择器。
	 *
	 * @param array<int,string> $selectors 正文选择器列表。
	 */
	$filtered = (array) apply_filters( 'jinyu_geo_speakable_selectors', $clean );

	return array_values( array_unique( array_filter( array_map( 'strval', $filtered ) ) ) );
}

/**
 * 输出文章页 <meta name="author">。
 *
 * 取 WP 原生作者体系，不读任何主题或第三方私有字段：本文作者优先，
 * 缺失时回退到本站发文最多的作者。取不到就整段不输出，不填占位值。
 */
function jinyu_geo_output_author_meta(): void {
	if ( ! jinyu_geo_signals_enabled() || ! is_singular( 'post' ) ) {
		return;
	}

	$post_id    = (int) get_the_ID();
	$post_author = (int) get_post_field( 'post_author', $post_id );
	$name        = $post_author ? (string) get_the_author_meta( 'display_name', $post_author ) : '';

	if ( '' === $name ) {
		$authors = get_users(
			array(
				'number'              => 1,
				'has_published_posts' => true,
				'orderby'             => 'post_count',
				'order'               => 'DESC',
				'fields'              => array( 'display_name' ),
			)
		);

		if ( ! empty( $authors ) ) {
			$name = (string) $authors[0]->display_name;
		}
	}

	if ( '' === $name ) {
		return;
	}

	printf( '<meta name="author" content="%s" />' . "\n", esc_attr( $name ) );
}
add_action( 'wp_head', 'jinyu_geo_output_author_meta', 2 );

/**
 * 输出 speakable 结构化数据，让语音助手能朗读正文。
 *
 * 仅在站点填了正文选择器时输出；正文过短时不输出——几百字的碎片不具备
 * 朗读价值，硬加只是给结构化数据添噪音。
 */
function jinyu_geo_output_speakable(): void {
	if ( ! jinyu_geo_signals_enabled() || ! is_singular( 'post' ) ) {
		return;
	}

	$selectors = jinyu_geo_speakable_selectors();

	if ( ! $selectors ) {
		return;
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$min_len = (int) jinyu_companion_get_option( 'geo_speakable_min_length', 400 );

	if ( $min_len > 0 && mb_strlen( wp_strip_all_tags( $post->post_content ) ) < $min_len ) {
		return;
	}

	$payload = array(
		'@context'  => 'https://schema.org',
		'@type'     => 'WebPage',
		'@id'       => get_permalink( $post ),
		'speakable' => array(
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => $selectors,
		),
	);

	$json = str_ireplace( '</', '<\/', (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

	if ( '' === $json ) {
		return;
	}

	echo wp_get_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag 生成的标签；JSON 已做大小写不敏感的 </ 中和（不加前导换行，保持与改动前字节一致）
}
add_action( 'wp_head', 'jinyu_geo_output_speakable', 20 );
