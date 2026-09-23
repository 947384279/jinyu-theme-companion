<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// jinyu_truncate_desc() 统一在 inc/seo.php 定义：core.php 先加载本文件、再加载 seo.php，
// 而该函数仅在 wp_head 运行时被调用，故此处直接复用，无需重复定义。

// JSON-LD 结构化数据 - SE0 增强
add_action('wp_head', 'jinyu_json_ld', 99);
function jinyu_json_ld()
{
    if (jinyu_companion_get_option('ld_json_disable', '0') === '1') return;
    $data = [];

    // Site 信息
    $data[] = [
        '@context'      => 'https://schema.org',
        '@type'         => 'WebSite',
        'name'          => get_bloginfo('name'),
        'url'           => home_url(),
        'description'   => get_bloginfo('description'),
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => home_url('/?s={s}'),
            'query-input' => 'required name=s',
        ],
    ];

    // 单篇 Article（post 与 page 均输出，扩大 GEO 实体覆盖面；page 无分类故省略 articleSection）
    if (is_singular(['post', 'page'])) {
        global $post;
        $author_id = (int) $post->post_author;
        $author    = get_the_author_meta('display_name', $author_id);
        $cover     = jinyu_get_post_cover($post->ID, 'large', false);
        $logo_id   = (int) get_theme_mod('custom_logo');
        $logo_url  = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : get_site_icon_url();
        $cats      = is_singular('post') ? get_the_category($post->ID) : [];

        // 实体关联档案（sameAs）：来自主题配置，指向站点在其它平台的官方档案。
        $ent_sameas = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) jinyu_companion_get_option( 'entity_sameas', '' ) ) ), static function ( $u ) {
            return filter_var( $u, FILTER_VALIDATE_URL ) !== false;
        } ) );

        $publisher = [
            '@type'  => 'Organization',
            'name'   => get_bloginfo('name'),
            'url'    => home_url(),
            'logo'   => ['@type'=>'ImageObject', 'url'=> $logo_url ?: get_site_icon_url()],
        ];
        if ( $ent_sameas ) {
            $publisher['sameAs'] = $ent_sameas;
        }

        // 作者实体：若填写了个人网站则写入 sameAs。
        $author_node = [
            '@type'  => 'Person',
            'name'   => $author,
            'url'    => get_author_posts_url($author_id),
            'image'  => get_avatar_url($author_id, ['size'=>96]),
        ];
        $author_site = trim( (string) get_the_author_meta( 'user_url', $author_id ) );
        if ( $author_site && filter_var( $author_site, FILTER_VALIDATE_URL ) ) {
            $author_node['sameAs'] = [ $author_site ];
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
        // GEO 增强：把净化后的正文直接喂给结构化数据，AI 无需解析 HTML 即得全文；
        // inLanguage 显式声明语种（多语言站点的 AI 索引关键），isAccessibleForFree 声明非付费墙。
        $article_body = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        if ( mb_strlen( $article_body, 'UTF-8' ) > 5000 ) {
            $article_body = mb_substr( $article_body, 0, 5000, 'UTF-8' ) . '…';
        }
        $article['articleBody']         = $article_body;
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
            $data[] = [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                'name'     => $post->post_title,
                'step'     => $steps,
            ];
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
    return '<div class="jinyu-faq">' . do_shortcode($c) . '</div>';
};
$jinyu_faq_item = function($atts, $c=''){
    $a = shortcode_atts(['q'=>''],$atts);
    return '<details class="jinyu-faq-item"><summary>' . esc_html($a['q']) . '</summary><div class="jinyu-faq-ans">' . do_shortcode($c) . '</div></details>';
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