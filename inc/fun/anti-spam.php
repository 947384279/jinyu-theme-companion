<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter('pre_comment_approved', 'jinyu_anti_spam', 99, 2);
function jinyu_anti_spam($approved, $commentdata)
{
    // 评论防垃圾已内置常开（仅管理员评论不过滤）；关键词/频率/长度过滤下方执行
    if (current_user_can('manage_options')) return $approved;

    $text = $commentdata['comment_content'] ?? '';

    // 长度/空值快路径：先拦明显垃圾，跳过关键词扫描
    $len = mb_strlen($text);
    if (! is_user_logged_in() && $len < 2) return 'spam';
    if ($len > 2000) return 'spam';

    // 关键词过滤
    $words = jinyu_get_option('anti_spam_words', defined('JINYU_DEFAULT_SPAM_WORDS') ? JINYU_DEFAULT_SPAM_WORDS : '彩票,色情,赌博,代写,刷量');
    foreach (explode(',', $words) as $w) {
        $w = trim($w);
        if ($w && stripos($text, $w) !== false) return 'spam';
    }

    // 频率限制 (10分钟内同 IP 超过 5 条)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $transient = 'jy_comment_ips_' . md5($ip);
    $count = get_transient($transient) ?: 0;
    if ($count >= 5) return 'spam';
    set_transient($transient, $count + 1, 600);

    return $approved;
}

// 自动关闭超过 30 天文章的评论
add_action('init', function(){
    if (jinyu_get_option('close_comments_old', true)) {
        add_filter('comments_open', function($open, $pid){
            $days = jinyu_get_option('close_comments_days', 30);
            $post = get_post($pid);
            if ($post && (time() - strtotime($post->post_date)) > $days * 86400) return false;
            return $open;
        }, 10, 2);
    }
});