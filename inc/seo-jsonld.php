<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// jinyu_truncate_desc() 统一在 inc/seo.php 定义：core.php 先加载本文件、再加载 seo.php，
// 而该函数仅在 wp_head 运行时被调用，故此处直接复用，无需重复定义。

/**
 * 判断两个 URL 是否指向同一站点（含 www 互等）。
 *
 * @param string $url 待判断的 URL。
 * @return bool
 */
function jinyu_jsonld_is_same_site_url( $url ) {
    $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $url_host  = (string) wp_parse_url( $url, PHP_URL_HOST );
    if ( $home_host === '' || $url_host === '' ) {
        return false;
    }
    $home_host = strtolower( $home_host );
    $url_host  = strtolower( $url_host );
    return $url_host === $home_host
        || $url_host === 'www.' . $home_host
        || $home_host === 'www.' . $url_host;
}

/**
 * 清洗 sameAs 候选值：只保留合法 http(s) URL，并剔除指向本站自身的项。
 *
 * 剔除同域项很关键：把站点首页写进 Person.sameAs 会让人与组织同形，
 * 搜索与 AI 无法区分「作者」与「网站」，等于没声明甚至造成伤害。
 *
 * @param string|array $raw 换行分隔的 URL 串或 URL 数组。
 * @return array 去重后的 URL 列表。
 */
function jinyu_jsonld_sameas_urls( $raw ) {
    if ( is_array( $raw ) ) {
        $raw = implode( "\n", $raw );
    }
    $urls = array();
    foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            continue;
        }
        if ( filter_var( $line, FILTER_VALIDATE_URL ) === false ) {
            continue;
        }
        if ( jinyu_jsonld_is_same_site_url( $line ) ) {
            continue;
        }
        $urls[] = $line;
    }
    return array_values( array_unique( $urls ) );
}

/**
 * 组织实体（Organization）的公共字段：name / url / logo / sameAs。
 *
 * 首页的独立 Organization 节点与文章页的 publisher 共用此取值，避免两处各写一套而漂移。
 * logo 无值时不输出 logo 字段——输出空的 ImageObject 会被判为脏数据。
 *
 * @return array
 */
function jinyu_jsonld_org_fields() {
    $fields = array(
        'name' => get_bloginfo( 'name' ),
        'url'  => home_url(),
    );

    // logo 取值顺序：面板指定 > 主题自定义 Logo > 站点图标。
    $logo = trim( (string) jinyu_companion_get_option( 'org_logo_url', '' ) );
    if ( '' === $logo ) {
        $logo_id = (int) get_theme_mod( 'custom_logo' );
        $logo    = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
    }
    if ( '' === $logo ) {
        $logo = (string) get_site_icon_url();
    }
    $logo = trim( (string) apply_filters( 'jinyu_seo_org_logo', $logo ) );
    if ( '' !== $logo ) {
        $fields['logo'] = array(
            '@type' => 'ImageObject',
            'url'   => $logo,
        );
    }

    $sameas = jinyu_jsonld_sameas_urls( apply_filters( 'jinyu_seo_entity_sameas', (string) jinyu_companion_get_option( 'entity_sameas', '' ) ) );
    if ( $sameas ) {
        $fields['sameAs'] = $sameas;
    }

    return $fields;
}

// JSON-LD 结构化数据 - SE0 增强
add_action('wp_head', 'jinyu_json_ld', 99);
function jinyu_json_ld()
{
    if ( ! jinyu_companion_is_checked('ld_json_enable', true) ) return;
    $data = [];

    // 组织实体锚点：首页的 Organization 与文章页 publisher 指向同一 @id，
    // 让搜索把「文章 publisher」和「首页组织」认作同一个实体。
    $org_id = home_url( '/' ) . '#organization';

    // Site 信息
    $data[] = [
        '@context'      => 'https://schema.org',
        '@type'         => 'WebSite',
        '@id'           => home_url( '/' ) . '#website',
        'name'          => get_bloginfo('name'),
        'url'           => home_url(),
        'description'   => get_bloginfo('description'),
        'publisher'     => [ '@id' => $org_id ],
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => home_url('/?s={s}'),
            'query-input' => 'required name=s',
        ],
    ];

    // 组织实体：Google 用首页独立出现的 Organization 建立品牌 / 组织知识实体，
    // 缺了它，全站只有文章页内嵌的 publisher，实体图底子就没搭起来。
    if ( is_front_page() ) {
        // 插在 WebSite 之后：HTML 里两个实体相邻，便于人工核对与排查
        array_splice(
            $data,
            1,
            0,
            [ array_merge(
                [ '@context' => 'https://schema.org', '@type' => 'Organization', '@id' => $org_id ],
                jinyu_jsonld_org_fields()
            ) ]
        );
    }

    // 单篇 Article（post 与 page 均输出，扩大 GEO 实体覆盖面；page 无分类故省略 articleSection）
    if (is_singular(['post', 'page'])) {
        global $post;
        $author_id = (int) $post->post_author;
        $author    = get_the_author_meta('display_name', $author_id);
        $cover     = jinyu_companion_post_cover($post->ID, 'large');
        $cats      = is_singular('post') ? get_the_category($post->ID) : [];

        // 组织（publisher）：与首页 Organization 共用取值，并用 @id 与首页实体互相挂接。
        $publisher = array_merge(
            [ '@type' => 'Organization', '@id' => $org_id ],
            jinyu_jsonld_org_fields()
        );

        // 作者实体：sameAs 须指向作者本人的站外身份页（GitHub / 知乎 / X …）。
        // 用户资料里的 user_url 常被误填成站点首页，已在清洗时按同域剔除；
        // 面板的 author_sameas 按作者过滤后可逐人填写，过滤器 jinyu_seo_author_sameas 可完全接管。
        $author_node = [
            '@type'  => 'Person',
            'name'   => $author,
            'url'    => get_author_posts_url($author_id),
            'image'  => get_avatar_url($author_id, ['size'=>96]),
        ];
        $author_sameas = jinyu_jsonld_sameas_urls( implode(
            "\n",
            [
                (string) get_the_author_meta( 'user_url', $author_id ),
                (string) jinyu_companion_get_option( 'author_sameas', '' ),
                (string) apply_filters( "jinyu_seo_author_sameas_{$author_id}", '' ),
                (string) apply_filters( 'jinyu_seo_author_sameas', '' ),
            ]
        ) );
        if ( $author_sameas ) {
            $author_node['sameAs'] = $author_sameas;
        }

        $ld_desc = get_post_meta($post->ID, 'jinyu_seo_desc', true);
        if (!$ld_desc) {
            $ld_desc = $post->post_excerpt ?: $post->post_content;
        }
        $article = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Article',
            'headline'    => $post->post_title,
            'articleSection' => !empty($cats) ? $cats[0]->name : '',
            'datePublished' => get_the_date('c', $post->ID),
            'dateModified'  => get_the_modified_date('c', $post->ID),
            'author'      => $author_node,
            'publisher'   => $publisher,
            'mainEntityOfPage' => ['@type'=>'WebPage', '@id'=>get_permalink($post->ID)],
            'image'       => $cover,
            'description' => jinyu_truncate_desc($ld_desc),
            'wordCount'   => (int) mb_strlen(preg_replace('/\s+/', '', wp_strip_all_tags($post->post_content)), 'UTF-8'),
        ];
        if ( is_singular('page') ) {
            unset( $article['articleSection'] );
        }
        // inLanguage 显式声明语种（多语言站点的 AI 索引关键），isAccessibleForFree 声明非付费墙。
        // 不输出 articleBody：Google 官方 Article 字段清单把该字段列在「非推荐」一档，
        // 既不参与富媒体呈现，又把整篇正文塞进 JSON-LD（单页约 +2KB），是纯粹的净亏损。
        // 同理，这里的 apply_filters('the_content') 二次渲染与 transient 缓存也随之不再需要。
        $article['inLanguage']          = get_locale();
        $article['isAccessibleForFree'] = true;
        $data[] = $article;
    }

    // BreadcrumbList
    if (is_singular() || is_category() || is_tag() || is_search()) {
        $items = [['@type'=>'ListItem', 'position'=>1, 'name'=>get_bloginfo('name'), 'item'=>home_url()]];
        $pos = 2;
        if (is_singular('post')) {
            $cats = get_the_category();
            if ($cats) $items[] = ['@type'=>'ListItem','position'=>$pos++,'name'=>$cats[0]->name,'item'=>get_category_link($cats[0]->term_id)];
            $items[] = ['@type'=>'ListItem','position'=>$pos,'name'=>get_the_title(),'item'=>get_permalink()];
        } elseif (is_singular()) {
            $items[] = ['@type'=>'ListItem','position'=>$pos,'name'=>get_the_title(),'item'=>get_permalink()];
        } elseif (is_category()) {
            $items[] = ['@type'=>'ListItem','position'=>$pos,'name'=>single_cat_title('',false)];
        } elseif (is_tag()) {
            $items[] = ['@type'=>'ListItem','position'=>$pos,'name'=>single_tag_title('',false)];
        } elseif (is_search()) {
            $items[] = ['@type'=>'ListItem','position'=>$pos,'name'=>__('搜索: ', 'jinyu-theme-companion').get_search_query()];
        }
        $data[] = ['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>$items];
    }

    // FAQ (检测 [jinyu_faq] / [jy_faq] 短代码；开合标签两种别名都兼容，避免正则对不上导致 FAQPage 永不输出)
    if (is_singular() && preg_match_all('/\[(?:jinyu_|jy_)faq_item\s*q="([^"]+)"\](.*?)\[\/(?:jinyu_|jy_)faq_item\]/s', get_the_content(), $faqMatches)) {
        $faqs = [];
        foreach ($faqMatches[1] as $i => $q) {
            $faqs[] = [
                '@type'          => 'Question',
                'name'           => $q,
                'acceptedAnswer' => ['@type'=>'Answer','text'=>trim(wp_strip_all_tags($faqMatches[2][$i]))],
            ];
        }
        if ($faqs) $data[] = ['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>$faqs];
    }

    // HowTo（兼容 [jinyu_step name="…"] 与 [jinyu_step] 两种写法）
    if (is_singular() && preg_match_all('/\[jinyu_step(?:\s+name="([^"]*)")?\s*\](.*?)\[\/jinyu_step\]/s', get_the_content(), $stepMatches)) {
        $steps = [];
        foreach ($stepMatches[2] as $i => $text) {
            $name = isset($stepMatches[1][$i]) ? trim($stepMatches[1][$i]) : '';
            $text = trim(wp_strip_all_tags($text));
            if ($name === '' && $text === '') continue;
            $steps[] = [
                '@type' => 'HowToStep',
                // schema.org 的 HowToStep 需至少有 name 或 text：name 缺省时用文本前 40 字兜底
                'name'  => $name !== '' ? $name : mb_substr($text, 0, 40, 'UTF-8'),
                'text'  => $text !== '' ? $text : $name,
            ];
        }
        if ($steps) {
            global $post; // HowTo 由短代码触发，$post 未必在全局作用域内：显式引入并判空
            $data[] = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                'name'     => ( $post instanceof WP_Post ) ? $post->post_title : '',
                'step'     => $steps,
            ];
        }
    }

    // 归档页（分类 / 标签 / 日期 / 作者）：CollectionPage + ItemList。
    // 此前归档页一条结构化数据都不输出，搜索与 AI 无从知道这类页面是「文章集合」而非单篇内容。
    // 条目直接复用当前归档查询 $wp_query->posts，不额外发一次 SQL；单页最多取 50 条，避免长列表撑爆 JSON。
    if ( is_archive() ) {
        global $wp_query, $wp;

        // 作者归档页：先落 Person 实体，再输出集合页。
        // 文章页的 author 已带 sameAs（作者身份已建立），但作者页本身一直没有任何实体节点，
        // 结果是「有署名的文章、没有人」——E-E-A-T 里最直接的 Expertise 信号在归档页断链。
        // 取值与文章页 author 同源（user_url + 面板 author_sameas + 过滤器），保证两处实体一致。
        if ( is_author() ) {
            $author_id  = 0;
            $author_url = '';
            if ( ! empty( $wp_query->query_vars['author'] ) ) {
                $author_id = (int) $wp_query->query_vars['author'];
            } elseif ( ! empty( $wp_query->posts ) ) {
                $author_id = (int) $wp_query->posts[0]->post_author;
            }
            if ( $author_id > 0 ) {
                $author_url = get_author_posts_url( $author_id );
            }
            if ( $author_url ) {
                $person = [
                    '@context' => 'https://schema.org',
                    '@type'    => 'Person',
                    '@id'      => $author_url . '#person',
                    'name'     => get_the_author_meta( 'display_name', $author_id ),
                    'url'      => $author_url,
                    'image'    => get_avatar_url( $author_id, ['size'=>96] ),
                ];
                $person_sameas = jinyu_jsonld_sameas_urls( implode(
                    "\n",
                    [
                        (string) get_the_author_meta( 'user_url', $author_id ),
                        (string) jinyu_companion_get_option( 'author_sameas', '' ),
                        (string) apply_filters( "jinyu_seo_author_sameas_{$author_id}", '' ),
                        (string) apply_filters( 'jinyu_seo_author_sameas', '' ),
                    ]
                ) );
                if ( $person_sameas ) {
                    $person['sameAs'] = $person_sameas;
                }
                $data[] = $person;
            }
        }

        if ( $wp_query instanceof WP_Query && ! empty( $wp_query->posts ) ) {
            $items = array();
            foreach ( array_slice( $wp_query->posts, 0, 50 ) as $i => $archive_post ) {
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => get_the_title( $archive_post ),
                    'url'      => get_permalink( $archive_post ),
                );
            }
            if ( $items ) {
                $archive_path = isset( $wp->request ) && $wp->request ? $wp->request : '/';
                $archive_url  = home_url( trailingslashit( $archive_path ) );
                $data[] = array(
                    '@context'   => 'https://schema.org',
                    '@type'      => 'CollectionPage',
                    '@id'        => $archive_url . '#collectionpage',
                    'name'       => get_the_archive_title(),
                    'url'        => $archive_url,
                    'mainEntity' => array(
                        '@type'           => 'ItemList',
                        'numberOfItems'   => count( $items ),
                        'itemListElement' => $items,
                    ),
                );
            }
        }
    }

    if ($data) {
        // 中和 </script> 闭合标签，防止任意值（标题/FAQ 问题/HowTo 步骤名）提前闭合脚本注入标记
        $json = str_replace('</', '<\/', wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo "\n<script type='application/ld+json'>" . $json . "</script>\n";
    }
}

// FAQ 短代码
$jinyu_faq = function($atts, $c=''){
    global $jinyu_faq_index;
    $jinyu_faq_index = -1; // 每个 FAQ 块独立计数，首条展开
    return '<div class="jinyu-faq">' . do_shortcode($c) . '</div>';
};
// FAQ 项：默认折叠；首条自动展开（open 属性显式传 0 可强制折叠）
$jinyu_faq_item = function($atts, $c=''){
    global $jinyu_faq_index;
    $a = shortcode_atts(['q'=>'','open'=>''],$atts);
    $jinyu_faq_index = isset($jinyu_faq_index) ? $jinyu_faq_index + 1 : 0;
    if ($a['open'] !== '') {
        $is_open = in_array(strtolower((string)$a['open']), ['1','yes','true','on'], true);
    } else {
        $is_open = ($jinyu_faq_index === 0);
    }
    return '<details class="jinyu-faq-item"' . ($is_open ? ' open' : '') . '><summary>' . esc_html($a['q']) . '</summary><div class="jinyu-faq-ans">' . do_shortcode($c) . '</div></details>';
};
add_shortcode('jinyu_faq', $jinyu_faq);
add_shortcode('jinyu_faq_item', $jinyu_faq_item);
// 旧标签别名（向后兼容）
add_shortcode('jy_faq', $jinyu_faq);
add_shortcode('jy_faq_item', $jinyu_faq_item);

// HowTo 步骤短代码：[jinyu_howto][jinyu_step name="..."]...[/jinyu_step]...[/jinyu_howto]
add_shortcode('jinyu_howto', function ($atts, $c = '') {
    return '<div class="jinyu-howto"><ol class="jinyu-steps">' . do_shortcode($c) . '</ol></div>';
});
add_shortcode('jinyu_step', function ($atts, $c = '') {
    $a = shortcode_atts(['name' => ''], $atts);
    return '<li class="jinyu-step"><span class="jinyu-step-name">' . esc_html($a['name']) . '</span><div class="jinyu-step-text">' . do_shortcode($c) . '</div></li>';
});