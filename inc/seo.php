<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 中文友好的描述截断：按字符数（CJK 记 1），超出加省略号。
if ( ! function_exists( 'jinyu_truncate_desc' ) ) {
	function jinyu_truncate_desc( $text, $len = 150 ) {
		// 先解码 HTML 实体再剥标签：旧站导入的文章摘要可能存的是转义后的
		// HTML 源码（&lt;div…），不解码则 strip 不掉，描述又长又脏。
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( function_exists( 'mb_strimwidth' ) ) {
			return mb_strimwidth( $text, 0, $len, '…', 'UTF-8' );
		}
		$cut = mb_substr( $text, 0, $len, 'UTF-8' );
		return $cut . ( mb_strlen( $text, 'UTF-8' ) > $len ? '…' : '' );
	}
}

// 从正文提取首段纯文本，作为 meta description 的兜底来源（防止回退到全站统一描述）。
if ( ! function_exists( 'jinyu_first_para_text' ) ) {
	function jinyu_first_para_text( $content ) {
		$content = preg_replace( '/\[[^\]]+\]/', '', (string) $content );
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content, $m ) ) {
			$text = $m[1];
		} else {
			$parts = preg_split( '/\n\s*\n/', wp_strip_all_tags( $content ), 2 );
			$text  = $parts[0] ?? '';
		}
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	}
}

if (!jinyu_companion_is_checked('seo_open', true)) return;

// 站点已装主流 SEO 插件时让位，避免与插件重复输出 description / og / twitter 标签
if (
    defined('WPSEO_VERSION')        // Yoast SEO
    || defined('RANK_MATH_VERSION') // Rank Math
    || defined('AIOSEO_VERSION')    // All in One SEO
    || defined('SEOPRESS_VERSION')  // SEOPress
    || class_exists('The_SEO_Framework\\Load') // TSF
) {
    return;
}

// 主题 SEO 接管 canonical：移除 WP 核心的 rel_canonical，避免同一页输出两个 canonical
remove_action('wp_head', 'rel_canonical');

// 注册 wx 为合法查询参数，避免微信分享用的 ?wx=1 被 redirect_canonical 剥离。
add_filter('query_vars', function ($vars) {
    $vars[] = 'wx';
    return $vars;
});

// 取 OG 图片真实宽高（媒体库附件）；非媒体库 / 解析失败返回 false。
if (!function_exists('jinyu_og_image_dims')) {
    function jinyu_og_image_dims($url)
    {
        if (empty($url)) {
            return false;
        }
        $id = function_exists('attachment_url_to_postid') ? attachment_url_to_postid($url) : 0;
        if ($id) {
            $meta = wp_get_attachment_metadata($id);
            if (!empty($meta['width']) && !empty($meta['height'])) {
                return [(int)$meta['width'], (int)$meta['height']];
            }
        }
        return false;
    }
}

// 分享图尺寸声明：优先媒体库真实尺寸，取不到再回退建议比例 1200×630
// （声明错尺寸比不声明更糟，故只在无法解析时才用建议值）。
if (!function_exists('jinyu_og_image_size')) {
    function jinyu_og_image_size($url)
    {
        $d = jinyu_og_image_dims($url);
        return $d ? [(int) $d[0], (int) $d[1]] : [1200, 630];
    }
}

// 分享图 MIME：按 URL 扩展名推断，供 og:image:type 声明（部分平台据此决定抓取方式）。
if (!function_exists('jinyu_og_image_mime')) {
    function jinyu_og_image_mime($url)
    {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $map  = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
        ];
        return isset($map[$ext]) ? $map[$ext] : '';
    }
}

// Twitter 账号名规范化：允许用户只填裸名，统一补 @ 前缀。
if (!function_exists('jinyu_og_twitter_handle')) {
    function jinyu_og_twitter_handle($handle)
    {
        $handle = ltrim(trim((string) $handle), '@');
        return $handle ? '@' . $handle : '';
    }
}

// Facebook / Meta 应用 ID：社区卡片调试与 Insights 归属（未配置则不输出）。
if (!function_exists('jinyu_og_fb_app_id')) {
    function jinyu_og_fb_app_id()
    {
        $app_id = trim((string) jinyu_companion_get_option('fb_app_id', ''));
        if ($app_id) {
            echo '<meta property="fb:app_id" content="' . esc_attr($app_id) . '">' . PHP_EOL;
        }
    }
}

// 文章页时效类标签：只对标准文章输出（page 等无时效语义），可开关。
if (!function_exists('jinyu_og_article_meta')) {
    function jinyu_og_article_meta()
    {
        if (!jinyu_companion_is_checked('og_article_meta', true)) {
            return;
        }
        if ('post' !== get_post_type()) {
            return;
        }
        $pub = get_the_date('c');
        $mod = get_the_modified_date('c');
        if ($mod) {
            echo '<meta property="og:updated_time" content="' . esc_attr($mod) . '">' . PHP_EOL;
            echo '<meta property="article:modified_time" content="' . esc_attr($mod) . '">' . PHP_EOL;
        }
        if ($pub) {
            echo '<meta property="article:published_time" content="' . esc_attr($pub) . '">' . PHP_EOL;
        }
        $author_id = (int) get_post_field('post_author', get_the_ID());
        if ($author_id) {
            echo '<meta property="article:author" content="' . esc_url(get_author_posts_url($author_id)) . '">' . PHP_EOL;
        }
        $cats = get_the_category();
        if (!empty($cats)) {
            echo '<meta property="article:section" content="' . esc_attr($cats[0]->name) . '">' . PHP_EOL;
        }
        $tags = get_the_tags();
        if (!empty($tags)) {
            foreach (array_slice($tags, 0, 5) as $tag) {
                echo '<meta property="article:tag" content="' . esc_attr($tag->name) . '">' . PHP_EOL;
            }
        }
    }
}

// 图片补充标签：og:image:type / og:image:alt（替代文本，无障碍与卡片可读性）。
if (!function_exists('jinyu_og_image_meta')) {
    function jinyu_og_image_meta($img, $alt)
    {
        $mime = jinyu_og_image_mime($img);
        if ($mime) {
            echo '<meta property="og:image:type" content="' . esc_attr($mime) . '">' . PHP_EOL;
        }
        if ($alt) {
            echo '<meta property="og:image:alt" content="' . esc_attr($alt) . '">' . PHP_EOL;
        }
    }
}

// Twitter 卡片标签（三分支共用）：card / title / description / image / image:alt / site / creator。
if (!function_exists('jinyu_og_twitter_meta')) {
    function jinyu_og_twitter_meta($title, $desc, $img, $alt)
    {
        if (!jinyu_companion_is_checked('twitter_card_enable', true)) {
            return;
        }
        echo '<meta name="twitter:card" content="summary_large_image">' . PHP_EOL;
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . PHP_EOL;
        if ($desc) {
            echo '<meta name="twitter:description" content="' . esc_attr($desc) . '">' . PHP_EOL;
        }
        if ($img) {
            echo '<meta name="twitter:image" content="' . esc_url($img) . '">' . PHP_EOL;
            if ($alt) {
                echo '<meta name="twitter:image:alt" content="' . esc_attr($alt) . '">' . PHP_EOL;
            }
        }
        $site = jinyu_og_twitter_handle(jinyu_companion_get_option('twitter_site', ''));
        if ($site) {
            echo '<meta name="twitter:site" content="' . esc_attr($site) . '">' . PHP_EOL;
        }
        $creator = jinyu_og_twitter_handle(jinyu_companion_get_option('twitter_creator', ''));
        if ($creator) {
            echo '<meta name="twitter:creator" content="' . esc_attr($creator) . '">' . PHP_EOL;
        }
    }
}

add_action('wp_head', 'jinyu_seo_meta', 1);
function jinyu_seo_meta()
{
    // 微信分享缓存绕过：带 ?wx=1 分享时，让 canonical/og:url 也带上该参数，
    // 使微信按"新 URL"重新抓取生成卡片（默认域名已被微信缓存成无卡片，普通参数会被归一化回首页地址）。
    $wx_on = isset( $_GET['wx'] );

    // 规范链接（canonical）：消除分页归档 / 搜索 ?s= / 追踪参数等造成的重复内容，
    // 避免权重被稀释。仅在主题内置 SEO 启用且未装主流 SEO 插件时输出（与下方 meta 同一让位逻辑）。
    if (!is_404()) {
        $canonical = '';
        if (is_singular()) {
            $canonical = $wx_on ? add_query_arg( 'wx', '1', get_permalink() ) : get_permalink();
        } elseif (is_front_page() || is_home()) {
            $canonical = $wx_on ? add_query_arg( 'wx', '1', home_url( '/' ) ) : home_url( '/' );
        } elseif (is_category() || is_tag() || is_tax()) {
            $canonical = get_term_link(get_queried_object());
        } elseif (is_author()) {
            $canonical = get_author_posts_url(get_queried_object_id());
        } elseif (is_post_type_archive()) {
            $canonical = get_post_type_archive_link(get_post_type());
        } elseif (is_search()) {
            $canonical = home_url('/?s=' . rawurlencode(get_search_query()));
        }
        if ($canonical && !is_wp_error($canonical)) {
            // 分页页 canonical 须指向自身（带 /page/N/）：指向第 1 页会被搜索引擎视为重复内容压索引。
            $paged = max( 1, (int) get_query_var('paged'), (int) get_query_var('page') );
            if ( ! is_singular() && $paged > 1 ) {
                $canonical = get_pagenum_link( $paged );
            }
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . PHP_EOL;
        }
    }

    $desc = jinyu_companion_get_option('seo_desc', '');
    $keys = jinyu_companion_get_option('seo_keywords', '');

    if (is_singular()) {
        global $post;
        $custom_desc = get_post_meta(get_the_ID(), 'jinyu_seo_desc', true);
        if ($custom_desc) {
            $desc = $custom_desc;
        } elseif (!empty($post->post_excerpt)) {
            $desc = wp_strip_all_tags($post->post_excerpt);
        } else {
            // 兜底：自动取正文首段，避免回退到全站统一描述导致所有文章摘要雷同
            $desc = jinyu_first_para_text($post->post_content);
        }
        $desc = jinyu_truncate_desc($desc);

        $tags = wp_get_post_tags(get_the_ID(), ['fields' => 'names']);
        if (!empty($tags)) $keys = implode(',', $tags);
        $custom_keys = get_post_meta(get_the_ID(), 'jinyu_seo_keys', true);
        if ($custom_keys) $keys = $custom_keys;
    } elseif (is_category() || is_tag() || is_tax()) {
        $term_id   = get_queried_object_id();
        $term_keys = get_term_meta($term_id, 'jinyu_seo_cat_keywords', true);
        $term_desc = get_term_meta($term_id, 'jinyu_seo_cat_desc', true);
        $term_obj  = get_queried_object();
        $term_count = ($term_obj && isset($term_obj->count)) ? (int) $term_obj->count : 0;
        if ($term_keys) {
            $keys = $term_keys;
        }
        if ($term_desc) {
            // 自定义描述同样截断，防止手工填写过长被判「描述过长」。
            $desc = jinyu_truncate_desc($term_desc);
        } else {
            $td = term_description($term_id);
            if ($td) {
                $desc = $td;
            } elseif (is_category() || is_tag()) {
                // 兜底：动态生成该 term 专属描述，避免回退全站默认描述
                // （所有 term 页雷同会被判重复，且长度不受控——Bing 报「描述过短/重复」）。
                $label = is_category() ? __('分类', 'jinyu-theme-companion') : __('标签', 'jinyu-theme-companion');
                /* translators: 1: term name, 2: label, 3: post count */
                $desc = sprintf(__('「%1$s」%2$s下的 %3$d 篇文章合集，持续更新中。', 'jinyu-theme-companion'), $term_obj ? $term_obj->name : '', $label, $term_count);
            }
        }
    } elseif (is_author()) {
        $author = get_queried_object();
        if ($author && !empty($author->description)) $desc = jinyu_truncate_desc($author->description);
    } elseif (is_front_page() || is_home()) {
        // 首页：未单独配置 seo_desc 时用站点副标题兜底，确保首页也有 <meta name="description">（JY-12）。
        if ($desc === '') {
            $desc = get_bloginfo('description');
        }
        $desc = jinyu_truncate_desc($desc);
    }

    if ($desc) echo '<meta name="description" content="' . esc_attr($desc) . '">' . PHP_EOL;
    if ($keys) echo '<meta name="keywords" content="' . esc_attr($keys) . '">' . PHP_EOL;

    if (is_singular()) {
        $title = get_the_title();
        $cover = jinyu_get_post_cover(get_the_ID(), 'large', false);
        $og_img = jinyu_companion_get_option('og_image', '');
        $og_image = $cover ?: $og_img;
        if (!$og_image && function_exists('jinyu_get_option')) $og_image = jinyu_get_option('web_logo', '');
        $site_name = jinyu_companion_get_option('og_site_name', '');
        if (!$site_name) $site_name = get_bloginfo('name');
        // 分享图替代文本：全局配置优先，留空用文章标题兜底。
        $og_alt = jinyu_companion_get_option('og_image_alt', '');
        if (!$og_alt) $og_alt = $title;
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . PHP_EOL;
        echo '<meta property="og:type" content="article">' . PHP_EOL;
        echo '<meta property="og:url" content="' . esc_url( $wx_on ? add_query_arg( 'wx', '1', get_permalink() ) : get_permalink() ) . '">' . PHP_EOL;
        echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . PHP_EOL;
        echo '<meta property="og:locale" content="' . str_replace( '_', '-', get_locale() ) . '">' . PHP_EOL;
        if ($desc) echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . PHP_EOL;
        if ($og_image) {
            $og_wh = jinyu_og_image_size($og_image);
            echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . PHP_EOL;
            jinyu_og_image_meta($og_image, $og_alt);
            echo '<meta property="og:image:width" content="' . (int)$og_wh[0] . '">' . PHP_EOL;
            echo '<meta property="og:image:height" content="' . (int)$og_wh[1] . '">' . PHP_EOL;
        }
        jinyu_og_article_meta();
        jinyu_og_fb_app_id();
        jinyu_og_twitter_meta($title, $desc, $og_image, $og_alt);
    } elseif (is_front_page() || is_home()) {
        // 首页：让用户分享站点门面链接（微信群/朋友圈）也能出卡片。
        $site_name = jinyu_companion_get_option('og_site_name', '');
        if (!$site_name) $site_name = get_bloginfo('name');
        $home_desc = $desc ?: get_bloginfo('description');
        // 优先用标准分享大图，其次站点 Logo，再次站点图标。
        $home_img  = jinyu_companion_get_option('og_image', '');
        if (!$home_img && function_exists('has_custom_logo') && has_custom_logo()) {
            $home_img = wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'full');
        }
        if (!$home_img) $home_img = get_site_icon_url();
        if (!$home_img && function_exists('jinyu_get_option')) $home_img = jinyu_get_option('web_logo', '');
        // 首页分享图替代文本：全局配置优先，留空用站点名称。
        $home_alt = jinyu_companion_get_option('og_image_alt', '');
        if (!$home_alt) $home_alt = $site_name;
        echo '<meta property="og:title" content="' . esc_attr($site_name) . '">' . PHP_EOL;
        echo '<meta property="og:type" content="website">' . PHP_EOL;
        echo '<meta property="og:url" content="' . esc_url( $wx_on ? add_query_arg( 'wx', '1', home_url( '/' ) ) : home_url( '/' ) ) . '">' . PHP_EOL;
        echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . PHP_EOL;
        echo '<meta property="og:locale" content="' . str_replace( '_', '-', get_locale() ) . '">' . PHP_EOL;
        if ($home_desc) echo '<meta property="og:description" content="' . esc_attr($home_desc) . '">' . PHP_EOL;
        if ($home_img) {
            $home_wh = jinyu_og_image_size($home_img);
            echo '<meta property="og:image" content="' . esc_url($home_img) . '">' . PHP_EOL;
            jinyu_og_image_meta($home_img, $home_alt);
            echo '<meta property="og:image:width" content="' . (int)$home_wh[0] . '">' . PHP_EOL;
            echo '<meta property="og:image:height" content="' . (int)$home_wh[1] . '">' . PHP_EOL;
        }
        jinyu_og_fb_app_id();
        jinyu_og_twitter_meta($site_name, $home_desc, $home_img, $home_alt);
    } elseif (is_archive()) {
        // 归档页（分类/标签/作者/日期）：分享到微信 / QQ 也能出卡片。
        $site_name = jinyu_companion_get_option('og_site_name', '');
        if (!$site_name) $site_name = get_bloginfo('name');
        $arc_title = wp_strip_all_tags(get_the_archive_title());
        $arc_desc  = '';
        if (function_exists('get_the_archive_description')) {
            $arc_desc = wp_strip_all_tags((string) get_the_archive_description());
        }
        if (!$arc_desc) $arc_desc = get_bloginfo('description');
        // 归档链接：术语 / 作者走标准函数，其余（日期等）按当前请求路径兜底。
        $queried = get_queried_object();
        if ($queried instanceof WP_Term) {
            $arc_url = get_term_link($queried);
            if (is_wp_error($arc_url)) $arc_url = '';
        } elseif ($queried instanceof WP_User) {
            $arc_url = get_author_posts_url($queried->ID);
        } else {
            global $wp;
            $arc_url = home_url(user_trailingslashit($wp->request));
        }
        $arc_img = jinyu_companion_get_option('og_image', '');
        if (!$arc_img && function_exists('has_custom_logo') && has_custom_logo()) {
            $arc_img = wp_get_attachment_image_url(get_theme_mod('custom_logo'), 'full');
        }
        if (!$arc_img) $arc_img = get_site_icon_url();
        if (!$arc_img && function_exists('jinyu_get_option')) $arc_img = jinyu_get_option('web_logo', '');
        // 归档分页页 og:url 指向自身（带 /page/N/），与 canonical 口径一致
        $arc_paged = max( 1, (int) get_query_var('paged'), (int) get_query_var('page') );
        if ( $arc_paged > 1 && $arc_url && ! is_wp_error( $arc_url ) ) {
            $arc_url = get_pagenum_link( $arc_paged );
            if ( is_wp_error( $arc_url ) ) $arc_url = '';
        }
        // 归档分享图替代文本：全局配置优先，留空用归档标题。
        $arc_alt = jinyu_companion_get_option('og_image_alt', '');
        if (!$arc_alt) $arc_alt = $arc_title;
        if ($arc_url) {
            echo '<meta property="og:title" content="' . esc_attr($arc_title) . '">' . PHP_EOL;
            echo '<meta property="og:type" content="website">' . PHP_EOL;
            echo '<meta property="og:url" content="' . esc_url( $wx_on ? add_query_arg( 'wx', '1', $arc_url ) : $arc_url ) . '">' . PHP_EOL;
            echo '<meta property="og:site_name" content="' . esc_attr($site_name) . '">' . PHP_EOL;
            echo '<meta property="og:locale" content="' . str_replace('_', '-', get_locale()) . '">' . PHP_EOL;
            if ($arc_desc) echo '<meta property="og:description" content="' . esc_attr($arc_desc) . '">' . PHP_EOL;
            if ($arc_img) {
                $arc_wh = jinyu_og_image_size($arc_img);
                echo '<meta property="og:image" content="' . esc_url($arc_img) . '">' . PHP_EOL;
                jinyu_og_image_meta($arc_img, $arc_alt);
                echo '<meta property="og:image:width" content="' . (int)$arc_wh[0] . '">' . PHP_EOL;
                echo '<meta property="og:image:height" content="' . (int)$arc_wh[1] . '">' . PHP_EOL;
            }
            jinyu_og_fb_app_id();
            jinyu_og_twitter_meta($arc_title, $arc_desc, $arc_img, $arc_alt);
        }
    }

}

// GEO 可发现性：在 <head> 主动提示 /llms.txt 位置（新近约定，部分 AI 工具会读取）。
// 独立于 SEO 插件让位逻辑——即便装了 Yoast/RankMath，GEO 提示仍应保留。
add_action('wp_head', 'jinyu_llms_head_link', 2);
function jinyu_llms_head_link()
{
    if (!jinyu_companion_is_checked('llms_enable', true)) {
        return;
    }
    echo '<link rel="llms.txt" href="' . esc_url(home_url('/llms.txt')) . '">' . PHP_EOL;
}

// GEO 兜底：显式允许主流 AI 爬虫的规则已写入站点根静态 robots.txt（由 Web 服务器直出），
// 故此处不再挂载 robots_txt 过滤器——虚拟 robots 已被静态文件架空、永不执行，挂载只会误
// 导维护者以为 WP 控制 robots.txt。如需调整 AI 放行清单，直接改根目录 robots.txt。

// 归档页（分类/标签/作者/日期/自定义文章类型）自定义标题，利于 SEO 与可读性。
// 仅在主题内置 SEO 启用时生效（已安装 Yoast/RankMath 等插件时由插件接管标题）。
add_filter('document_title_parts', 'jinyu_archive_title_parts');
function jinyu_archive_title_parts($parts)
{
    if (is_admin()) return $parts;

    if (is_category() || is_tag() || is_tax() || is_author() || is_date() || is_post_type_archive()) {
        if (is_category()) {
            // 带文章数：避免「短分类名 分类 – 站名」过短被判「标题过短」（Bing SEO 报告）
            $t = get_queried_object();
            $parts['title'] = sprintf(
                /* translators: 1: term name, 2: post count */
                __('%1$s 分类 · %2$d 篇文章合集', 'jinyu-theme-companion'),
                single_term_title('', false),
                ($t && isset($t->count)) ? (int) $t->count : 0
            );
        } elseif (is_tag()) {
            $t = get_queried_object();
            $parts['title'] = sprintf(
                /* translators: 1: term name, 2: post count */
                __('%1$s 标签 · %2$d 篇文章合集', 'jinyu-theme-companion'),
                single_term_title('', false),
                ($t && isset($t->count)) ? (int) $t->count : 0
            );
        } elseif (is_tax()) {
            $parts['title'] = single_term_title('', false);
        } elseif (is_author()) {
            $parts['title'] = sprintf(__('%s 的全部文章', 'jinyu-theme-companion'), get_the_author_meta('display_name'));
        } elseif (is_date()) {
            $parts['title'] = get_the_archive_title('', false);
        } elseif (is_post_type_archive()) {
            $parts['title'] = post_type_archive_title('', false);
        }
        // 分页（第 N 页）由 WordPress 自动追加到标题，无需处理
    }
    return $parts;
}

// 正文标题规范化：将正文里嵌的 <h1> 降级为 <h2>（页面标题 h1 已由模板输出）。
// 只在前台输出层替换，不改数据库；feed / REST / 后台不动；可在面板关闭或用过滤器跳过。
add_filter('the_content', 'jinyu_seo_demote_content_h1', 20);
function jinyu_seo_demote_content_h1($content)
{
    if (!jinyu_companion_is_checked('seo_content_h1_fix', true)) {
        return $content;
    }
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }
    if (apply_filters('jinyu_seo_demote_content_h1_skip', false)) {
        return $content;
    }
    if (!is_string($content) || stripos($content, '<h1') === false) {
        return $content;
    }
    $content = preg_replace('/<h1(\s|>)/i', '<h2$1', $content);
    return str_ireplace('</h1>', '</h2>', $content);
}

// 空标题文章（时光圈 / 动态类自定义文章类型）的 <title> 兜底：
// WP 核心对无标题文章只输出站名，标题过短。改用「文章类型名 · 日期」。
add_filter('document_title_parts', 'jinyu_singular_title_parts');
function jinyu_singular_title_parts($parts)
{
    if (is_admin() || !is_singular()) {
        return $parts;
    }
    $t = get_queried_object();
    if ($t instanceof WP_Post && '' === trim((string) $t->post_title)) {
        $pt = get_post_type_object($t->post_type);
        $label = $pt ? $pt->labels->singular_name : '';
        if ($label) {
            /* translators: 1: post type singular name, 2: publish date */
            $parts['title'] = sprintf(__('%1$s · %2$s', 'jinyu-theme-companion'), $label, get_the_date());
        }
    }
    return $parts;
}

/* ───────────────────────── 内容 SEO 诊断 ───────────────────────── */

add_action('wp_ajax_jinyu_seo_diag', 'jinyu_seo_diag');

/**
 * 内容层 SEO 诊断（对应 Bing SEO 报告的「标题过短 / 描述过短」）：
 * 列出标题 <15 字、摘要 <60 字的已发布文章，供作者补内容。纯只读查询，不写库。
 * 说明：前台渲染的 title = 标题 + 站名后缀，诊断以文章标题本身长度为准。
 */
function jinyu_seo_diag()
{
    check_ajax_referer('jinyu_companion_settings', 'jinyu_companion_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('权限不足', 'jinyu-theme-companion'));
    }

    global $wpdb;

    $pub = "post_status = 'publish' AND post_type = 'post'";

    // 1) 标题过短（< 15 字，CHAR_LENGTH 按字符计，中文不误判）
    $short_title_total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$pub} AND CHAR_LENGTH(post_title) < 15"
    );
    $short_title = $wpdb->get_results(
        "SELECT ID, post_title, CHAR_LENGTH(post_title) AS len
         FROM {$wpdb->posts}
         WHERE {$pub} AND CHAR_LENGTH(post_title) < 15
         ORDER BY ID DESC LIMIT 20"
    );

    // 2) 摘要过短（< 60 字）且无自定义描述：前台 meta description 会用这个短摘要
    $no_custom = "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm
                 WHERE pm.post_id = {$wpdb->posts}.ID
                   AND pm.meta_key = 'jinyu_seo_desc' AND pm.meta_value != '')";
    $cond = "{$pub} AND post_excerpt != '' AND CHAR_LENGTH(post_excerpt) < 60 AND {$no_custom}";
    $short_desc_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$cond}");
    $short_desc = $wpdb->get_results(
        "SELECT ID, post_title, CHAR_LENGTH(post_excerpt) AS len
         FROM {$wpdb->posts}
         WHERE {$cond}
         ORDER BY ID DESC LIMIT 20"
    );

    $map = static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'    => (int) $r->ID,
                'title' => $r->post_title,
                'len'   => (int) $r->len,
                'edit'  => get_edit_post_link($r->ID, 'raw'),
            ];
        }
        return $out;
    };

    wp_send_json_success([
        'short_title'       => $map((array) $short_title),
        'short_title_total' => $short_title_total,
        'short_desc'        => $map((array) $short_desc),
        'short_desc_total'  => $short_desc_total,
    ]);
}