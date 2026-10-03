<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 自动内链（Auto Internal Links）
 * --------------------------------------------------------------------------
 * 把正文里出现的「其它已发布文章标题」自动转成指向该文章的站内链接，
 * 是 WPJAM 所说的「链接建设」里最实在的站内一环：把权重在站点内部打通，
 * 也帮搜索引擎/AI  crawler 发现更多页面。
 *
 * 安全边界（避免变成屎山/误伤正文）：
 *  - 仅对单篇文章正文（the_content）生效，feed / 后台 / ajax 跳过；
 *  - 关键词索引（标题→链接）缓存为 transient，发布/更新/删除文章时失效重建，
 *    不每篇实时全表扫描；
 *  - 长词优先匹配，避免短词先占位把长词切碎；
 *  - 每个关键词整篇只链第一次出现；单篇总链接数上限 JINYU_AUTO_LINK_LIMIT；
 *  - 跳过已在 <a> 内、以及 <h1-6>/<pre>/<code>/<script>/<style>/<button> 内的文本；
 *  - 不链当前文章自身（自链无意义）。
 */

if ( ! defined( 'JINYU_AUTO_LINK_LIMIT' ) ) {
	define( 'JINYU_AUTO_LINK_LIMIT', 5 );
}

/**
 * 构建关键词索引：去重小写标题 => ['id'=>, 'url'=>]
 * 结果缓存为 transient（每日过期），内容变更时主动删除。
 *
 * 索引只用到「标题 + 链接」两件事，因此直接一条 SQL 取 ID/post_title，不再加载文章对象。
 * 原实现用 get_posts( posts_per_page => -1 ) + _prime_post_caches() 把全站文章整行读进内存：
 * 实测（827 篇文章、对象缓存已预热）索引重建 ≈29ms → ≈20ms；且 _prime_post_caches() 第二参是
 * 布尔，旧代码误传字符串 'post'（真值），会连带预热全部文章的术语缓存（再多两条重查询）。
 * 索引重建由「内容变更后首个访客」承担，是一次性成本，越轻越好。
 *
 * 链接一律走 get_permalink()：它会应用 post_link 过滤器，多语言/自定义固定链接插件都靠它改写 URL，
 * 绝不能为了省几次调用而自己拼字符串。
 */
if ( ! function_exists( 'jinyu_auto_link_map' ) ) {
	function jinyu_auto_link_map(): array {
		$cached = get_transient( 'jinyu_auto_link_map' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// 一条 SQL 只取两列：不加载文章对象、不预热术语/自定义字段缓存。
		// 排序对齐 get_posts 默认（按日期倒序），保证标题重复时仍是「较新的一篇」入选。
		$rows = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY post_date DESC, ID DESC"
		);

		$map = [];

		foreach ( (array) $rows as $row ) {
			$key = mb_strtolower( trim( (string) $row->post_title ), 'UTF-8' );
			// 过短标题太泛，跳过，避免大量误链
			if ( mb_strlen( $key, 'UTF-8' ) < 3 ) {
				continue;
			}
			// 仅保留首个（最早）匹配，标题互相包含时不会乱链
			if ( isset( $map[ $key ] ) ) {
				continue;
			}
			$pid         = (int) $row->ID;
			$map[ $key ] = [
				'id'  => $pid,
				'url' => (string) get_permalink( $pid ),
			];
		}

		set_transient( 'jinyu_auto_link_map', $map, DAY_IN_SECONDS );
		return $map;
	}
}

/**
 * the_content 过滤器：注入自动内链。
 */
if ( ! function_exists( 'jinyu_auto_link_content' ) ) {
	function jinyu_auto_link_content( $content ) {
		if ( is_feed() || is_admin() || wp_doing_ajax() ) {
			return $content;
		}
		if ( ! jinyu_companion_is_checked( 'auto_link_enable', false ) ) {
			return $content;
		}
		if ( ! is_singular( 'post' ) ) {
			return $content;
		}

		$map = jinyu_auto_link_map();
		if ( empty( $map ) ) {
			return $content;
		}

		// 排除当前文章自身
		$current_id = (int) get_the_ID();
		$local      = $map;
		if ( $current_id ) {
			foreach ( $local as $k => $v ) {
				if ( (int) $v['id'] === $current_id ) {
					unset( $local[ $k ] );
				}
			}
		}
		if ( empty( $local ) ) {
			return $content;
		}

		// 预筛：把候选词从「全站标题」（现 800+，且随发文字数增长）收敛到「本文正文里真正出现过的标题」。
		// 否则下面每个文本节点都要对全部关键词各跑一次 mb_stripos，开销 = 关键词数 × 文本节点数，
		// 长文上百个文本节点时绝大部分是无用匹配。正文与关键词统一转小写后用字节级 strpos，一次扫完。
		// 注意：这里只做「收敛候选」，即使收敛为空也继续往下走 DOM 流程——
		// DOM 归一化本身是既有输出的一部分，提前 return 会改变正文 HTML 字节。
		$plain = mb_strtolower(
			html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'UTF-8'
		);
		foreach ( $local as $k => $v ) {
			if ( false === strpos( $plain, $k ) ) {
				unset( $local[ $k ] );
			}
		}

		// 长词优先，避免短词先占位
		uksort(
            $local,
            function ( $a, $b ) {
				return mb_strlen( $b, 'UTF-8' ) - mb_strlen( $a, 'UTF-8' );
			}
        );

		$doc = new DOMDocument();
		$doc->substituteEntities = false;
		$prev = libxml_use_internal_errors( true );
		// 前置 charset，让 DOMDocument 按 UTF-8 解析中文（避免已废弃的 mb_convert_encoding）
		$doc->loadHTML( '<meta charset="utf-8">' . $content, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath  = new DOMXPath( $doc );
		$expr   = '//text()'
			. '[not(ancestor::a)]'
			. '[not(ancestor::script)][not(ancestor::style)]'
			. '[not(ancestor::pre)][not(ancestor::code)]'
			. '[not(ancestor::h1)][not(ancestor::h2)][not(ancestor::h3)]'
			. '[not(ancestor::h4)][not(ancestor::h5)][not(ancestor::h6)]'
			. '[not(ancestor::button)]';
		$texts  = $xpath->query( $expr );
		$targets = [];
		foreach ( $texts as $t ) {
			$targets[] = $t;
		}

		$count       = 0;
		$linked_keys = [];
		// 上限面板可调（1-20），未配置时回退常量默认值 5
		$limit = max( 1, min( 20, (int) jinyu_companion_get_option( 'auto_link_limit', JINYU_AUTO_LINK_LIMIT ) ) );

		foreach ( $targets as $t ) {
			if ( $count >= $limit ) {
				break;
			}
			$text = $t->nodeValue;
			if ( trim( $text ) === '' ) {
				continue;
			}

			// 收集本文本节点内所有可链关键词（非重叠、按出现位置升序、同位置长词优先）
			$hits = [];
			foreach ( $local as $key => $info ) {
				if ( isset( $linked_keys[ $key ] ) ) {
					continue;
				}
				$pos = mb_stripos( $text, $key, 0, 'UTF-8' );
				if ( false === $pos ) {
					continue;
				}
				$hits[] = [ $pos, mb_strlen( $key, 'UTF-8' ), $key, $info ];
			}
			if ( empty( $hits ) ) {
				continue;
			}
			usort(
                $hits,
                function ( $a, $b ) {
					if ( $a[0] === $b[0] ) {
						return $b[1] - $a[1]; // 同位置长词优先
					}
					return $a[0] - $b[0];
				}
            );

			$frag     = $doc->createDocumentFragment();
			$last_end = 0;
			$linked   = false;
			foreach ( $hits as $h ) {
				if ( $count >= $limit ) {
					break;
				}
				list( $pos, $len, $key, $info ) = $h;
				if ( $pos < $last_end ) {
					continue; // 与已链片段重叠，跳过
				}
				$before = mb_substr( $text, $last_end, $pos - $last_end, 'UTF-8' );
				$match  = mb_substr( $text, $pos, $len, 'UTF-8' );
				if ( '' !== $before ) {
					$frag->appendChild( $doc->createTextNode( $before ) );
				}
				$a = $doc->createElement( 'a', $match );
				$a->setAttribute( 'href', esc_url( $info['url'] ) );
				$a->setAttribute( 'class', 'jinyu-auto-link' );
				$a->setAttribute( 'rel', 'bookmark' );
				$frag->appendChild( $a );
				$last_end = $pos + $len;
				++$count;
				$linked_keys[ $key ] = true;
				$linked = true;
			}
			$tail = mb_substr( $text, $last_end, null, 'UTF-8' );
			if ( '' !== $tail ) {
				$frag->appendChild( $doc->createTextNode( $tail ) );
			}
			if ( $linked ) {
				$t->parentNode->replaceChild( $frag, $t );
			}
		}

		// 仅取 body 内部，避免输出 <html>/<head>/<meta> 等包裹（与 optimize.php 一致）
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( $body ) {
			$out = '';
			foreach ( $body->childNodes as $node ) {
				$out .= $doc->saveHTML( $node );
			}
			return $out;
		}
		return $doc->saveHTML();
	}
}
add_filter( 'the_content', 'jinyu_auto_link_content', 12 );

// 内容变更时让关键词索引失效重建
foreach ( [ 'save_post', 'deleted_post', 'trashed_post' ] as $jinyu_hook ) {
	add_action(
        $jinyu_hook,
        function () {
			delete_transient( 'jinyu_auto_link_map' );
		},
        10,
        0
    );
}
