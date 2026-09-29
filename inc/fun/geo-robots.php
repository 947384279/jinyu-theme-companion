<?php
/**
 * GEO 放行清单：AI 爬虫与社交抓取器的 User-agent 白名单。
 *
 * 【为什么只是「清单」而不是「输出」】
 * 多数部署下站点根 robots.txt 由 Web 服务器直接返回静态文件，WordPress 收不到 /robots.txt 请求，
 * robots_txt 过滤器永不执行——白名单若改由插件输出，在这些部署下是一条死路径，会造成
 * 「面板明明开着，规则却没生效」的假象。故插件一律不接管 robots 输出，此处只做清单镜像。
 *
 * 【这份清单的用途】
 * 1. 设置面板「SEO」页只读展示，维护者随时可核对线上实际放行范围；
 * 2. 迁移 / 重建环境时，这是该静态文件里必须原样保留的内容，可照此重建。
 *
 * 【维护约定】
 * 本清单是站点根 robots.txt 中放行规则的镜像，改动需与该文件同步，否则面板显示与实际不一致。
 * 若将来改为由 Nginx 回退到 WordPress（try_files 让 /robots.txt 落到 index.php），
 * 则可删去本文件，改由插件真正输出。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI 爬虫（GEO）：显式放行，避免被主机防火墙或误配的 robots 规则挡在门外。
 *
 * @return array<int, string> User-agent 字符串列表。
 */
function jinyu_geo_ai_crawlers(): array {
	return array(
		'GPTBot',        // OpenAI 爬虫
		'OAI-SearchBot', // OpenAI 站内搜索
		'ChatGPT-User',  // ChatGPT 用户实时抓取
		'ClaudeBot',     // Anthropic 爬虫
		'anthropic-ai',  // Anthropic 旧版 UA
		'Google-Extended', // Google AI 训练扩展（不抓取则等价于 Disallow）
	);
}

/**
 * 社交抓取器：放行以便分享卡片正常生成（og / twitter card 依赖其抓取）。
 *
 * @return array<int, string> User-agent 字符串列表。
 */
function jinyu_geo_social_crawlers(): array {
	return array(
		'facebookexternalhit', // Facebook / Meta 分享抓取
		'Twitterbot',          // X 分享抓取
		'LinkedInBot',         // LinkedIn 分享抓取
		'WhatsApp',            // WhatsApp 分享抓取
	);
}

