<?php
/**
 * GEO 放行清单：AI 爬虫与社交抓取器的 User-agent 白名单（按品牌分组）。
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
 *
 * 【口径分级（每条注释末尾的标记）】
 * robots.txt 只是给「愿意遵守协议的爬虫」看的声明，拦不住防火墙 / WAF，也拦不住伪造 UA 的抓取器。
 * 因此清单只收录有可查口径的 UA，按可信度分三级：
 *   [官方] 厂商官方文档 / 政策页明确公布；
 *   [档案] 无厂商文档，但主流第三方爬虫档案收录；
 *   [实测] 行业实测汇总，厂商未正式公布。
 *
 * 【国内组为什么含传统搜索爬虫】
 * 国内 AI 答案大量复用传统搜索索引（搜狗之于元宝 / Kimi、神马之于通义、360 之于纳米 AI、百度之于文心），
 * 拦掉索引源爬虫等于从这些模型的答案里消失，故与 AI 侧 UA 一并显式放行。
 * 智谱清言、讯飞星火、MiniMax、秘塔等暂无公开可查的 UA 标识符，暂不收录，厂商公布后再补。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 放行清单：按「区域 → 品牌 → UA」三级组织，供面板按品牌渲染标签。
 *
 * 结构：array<region, array<slug, array{label: string, mark: string, color: string, crawlers: array<int,string>}>>
 *  - label    品牌 / 模型名（面板标签文案）
 *  - mark     品牌图标内的缩写字符，1 个字符用 12px、2 个及以上用 9px
 *  - color    品牌主色，仅用于面板图标底色
 *  - crawlers 该品牌对应的 User-agent 列表，必须与站点根 robots.txt 一致
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function jinyu_geo_crawler_groups(): array {
	return array(
		'cn'     => array(
			'baidu'    => array(
				'label'    => '百度 · 文心一言',
				'mark'     => '文',
				'color'    => '#2932E1',
				'crawlers' => array(
					'Baiduspider',        // 搜索主爬虫，文心一言与百度 AI 搜索复用其索引 [官方]
					'Baiduspider-render', // 渲染器，AI 搜索抓动态页面时使用 [官方]
					'YiyanBot',           // 文心一言助手抓取 [档案]
					'ErnieBot',           // 文心一言（ERNIE）补充标识 [实测]
					'BaiduSpider-AI',     // 百度 AI 专项抓取标识 [实测]
				),
			),
			'bytedance' => array(
				'label'    => '字节跳动 · 豆包',
				'mark'     => '豆',
				'color'    => '#4C6FFF',
				'crawlers' => array(
					'Bytespider',    // 字节通用爬虫，豆包 / 即梦等全系 AI 复用 [官方]
					'Doubaobot',     // 豆包 AI 专用抓取 [实测]
					'ToutiaoSpider', // 今日头条爬虫，豆包内容池的重要来源 [官方]
				),
			),
			'deepseek' => array(
				'label'    => 'DeepSeek',
				'mark'     => 'DS',
				'color'    => '#4D6BFE',
				'crawlers' => array(
					'DeepSeekBot', // 训练与联网检索；官方未公布文档 [档案]
				),
			),
			'kimi'     => array(
				'label'    => 'Kimi · 月之暗面',
				'mark'     => 'K',
				'color'    => '#111827',
				'crawlers' => array(
					'KimiBot',        // 训练抓取 [档案]
					'Kimi-SearchBot', // 搜索索引，屏蔽即等于从 Kimi 搜索结果消失 [档案]
					'KimiCrawler',    // 通用抓取 [档案]
					'Kimi-User',      // 用户提问时的实时页面抓取 [档案]
					'Kimi-Agent',     // 浏览器代理 [档案]
					'MoonshotBot',    // Moonshot AI 数据抓取 [档案]
					'MoonSpider',     // Moonshot AI 训练与研究抓取 [档案]
				),
			),
			'alibaba'  => array(
				'label'    => '阿里 · 通义千问',
				'mark'     => '通',
				'color'    => '#FF6A00',
				'crawlers' => array(
					'TongyiBot',   // 通义千问助手抓取 [档案]
					'QwenBot',     // Qwen 检索标识 [实测]
					'YisouSpider', // 神马搜索，通义千问复用其索引 [官方]
				),
			),
			'tencent'  => array(
				'label'    => '腾讯 · 元宝',
				'mark'     => '元',
				'color'    => '#0052D9',
				'crawlers' => array(
					'TencentBot',      // 元宝生态抓取 [实测]
					'TencentAIspider', // 腾讯 AI 抓取标识 [实测]
					'Sogou web spider', // 搜狗网页爬虫（腾讯系），Kimi / 元宝复用其索引 [官方]
					'Sogou inst spider', // 搜狗即时爬虫 [官方]
				),
			),
			'360'      => array(
				'label'    => '360 · 纳米 AI',
				'mark'     => '360',
				'color'    => '#1EC163',
				'crawlers' => array(
					'360Spider', // 360 搜索，360 智脑 / 纳米 AI 复用其索引 [官方]
				),
			),
			'huawei'   => array(
				'label'    => '华为 · 盘古',
				'mark'     => '华',
				'color'    => '#CF0A2C',
				'crawlers' => array(
					'PetalBot', // 花瓣搜索，含 AI 摘要与推荐 [官方]
					'PanguBot', // 盘古大模型抓取 [档案]
				),
			),
		),
		'intl'   => array(
			'openai'      => array(
				'label'    => 'OpenAI · ChatGPT',
				'mark'     => 'AI',
				'color'    => '#10A37F',
				'crawlers' => array(
					'GPTBot',        // 训练爬虫 [官方]
					'OAI-SearchBot', // ChatGPT Search 索引 [官方]
					'ChatGPT-User',  // 用户触发的实时抓取 [官方]
				),
			),
			'anthropic'   => array(
				'label'    => 'Anthropic · Claude',
				'mark'     => 'C',
				'color'    => '#D97757',
				'crawlers' => array(
					'ClaudeBot',   // 训练爬虫 [官方]
					'anthropic-ai', // 旧版 UA [官方]
				),
			),
			'google'      => array(
				'label'    => 'Google · Gemini',
				'mark'     => 'G',
				'color'    => '#4285F4',
				'crawlers' => array(
					'Google-Extended', // AI 训练扩展，不抓取则等价于 Disallow [官方]
				),
			),
			'perplexity'  => array(
				'label'    => 'Perplexity',
				'mark'     => 'P',
				'color'    => '#20808D',
				'crawlers' => array(
					'PerplexityBot', // 实时搜索与索引 [官方]
				),
			),
			'apple'       => array(
				'label'    => 'Apple Intelligence',
				'mark'     => 'A',
				'color'    => '#8E8E93',
				'crawlers' => array(
					'Applebot-Extended', // AI 训练扩展 [官方]
				),
			),
			'commoncrawl' => array(
				'label'    => 'Common Crawl',
				'mark'     => 'CC',
				'color'    => '#6B7280',
				'crawlers' => array(
					'CCBot', // 开放语料，多家模型训练数据来源 [官方]
				),
			),
			'cohere'      => array(
				'label'    => 'Cohere',
				'mark'     => 'Co',
				'color'    => '#39594D',
				'crawlers' => array(
					'cohere-ai', // Cohere 训练抓取 [官方]
				),
			),
			'meta'        => array(
				'label'    => 'Meta AI',
				'mark'     => 'M',
				'color'    => '#0866FF',
				'crawlers' => array(
					'Meta-ExternalAgent',   // AI 训练 [官方]
					'Meta-ExternalFetcher', // 用户触发的实时抓取 [官方]
				),
			),
			'mistral'     => array(
				'label'    => 'Mistral AI',
				'mark'     => 'Mi',
				'color'    => '#FA520F',
				'crawlers' => array(
					'MistralAI-User',    // Le Chat 实时抓取 [官方]
					'Mistral-SearchBot', // 搜索索引 [官方]
				),
			),
		),
		'social' => array(
			'facebook' => array(
				'label'    => 'Facebook / Meta',
				'mark'     => 'f',
				'color'    => '#1877F2',
				'crawlers' => array( 'facebookexternalhit' ), // 分享卡片抓取 [官方]
			),
			'x'        => array(
				'label'    => 'X (Twitter)',
				'mark'     => 'X',
				'color'    => '#111827',
				'crawlers' => array( 'Twitterbot' ), // 分享卡片抓取 [官方]
			),
			'linkedin' => array(
				'label'    => 'LinkedIn',
				'mark'     => 'in',
				'color'    => '#0A66C2',
				'crawlers' => array( 'LinkedInBot' ), // 分享卡片抓取 [官方]
			),
			'whatsapp' => array(
				'label'    => 'WhatsApp',
				'mark'     => 'W',
				'color'    => '#25D366',
				'crawlers' => array( 'WhatsApp' ), // 分享卡片抓取 [官方]
			),
		),
	);
}

/**
 * 区域分区的展示名称。
 *
 * @param string $region 区域键：cn / intl / social。
 * @return string 已翻译的分区名，未知键返回空字符串。
 */
function jinyu_geo_crawler_region_label( string $region ): string {
	$labels = array(
		'cn'     => __( '国内 AI', 'jinyu-theme-companion' ),
		'intl'   => __( '海外 AI', 'jinyu-theme-companion' ),
		'social' => __( '社交分享', 'jinyu-theme-companion' ),
	);

	return $labels[ $region ] ?? '';
}
