<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 配套插件设置面板：顶级菜单「jinyu-theme-companion」，写入独立 option jinyu_companion_settings。
// 与主题 jinyu_options 互不干扰，主题设置页的导入 / 重置不会清掉本面板配置。
// 本面板是插件所有开关的唯一入口（解耦自主题设置）。
add_action( 'admin_menu', 'jinyu_companion_register_settings_page' );
function jinyu_companion_register_settings_page(): void {
	add_menu_page(
		__( '金玉增强', 'jinyu-theme-companion' ),
		__( '金玉增强', 'jinyu-theme-companion' ),
		'manage_options',
		'jinyu-theme-companion',
		'jinyu_companion_settings_page_html',
		'dashicons-admin-generic',
		60
	);
}

add_action( 'admin_init', 'jinyu_companion_handle_save' );
function jinyu_companion_handle_save(): void {
	// admin-ajax.php 也会触发 admin_init：测试连接 / 测试邮件按钮整表单提交（含 jinyu_companion_save=1），
	// 若不排除，"测试"会把用户刚填但未确认保存的配置直接落库。AJAX 内永不走整表保存。
	if ( wp_doing_ajax() ) {
		return;
	}
	if ( ! isset( $_POST['jinyu_companion_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( '权限不足', 'jinyu-theme-companion' ) );
	}
	check_admin_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );

	$settings = get_option( 'jinyu_companion_settings', [] );
	if ( ! is_array( $settings ) ) {
		$settings = [];
	}

	// 布尔开关：勾选存 '1'，未勾存 '0'
	foreach ( [ 'seo_open', 'seo_content_h1_fix', 'twitter_card_enable', 'og_article_meta', 'llms_enable', 'auto_link_enable', 'indexnow_enable', 'close_comments_old', 'page_cache_enable', 'speculation_enable', 'img_alt_enable', 'img_dim_enable', 'ld_json_enable', 'no_category_enable', 'storage_auto_upload', 'storage_delete_local', 'storage_sync_extra', 'comment_notify_reply', 'comment_notify_blocked', 'comment_notify_approved', 'comment_freq_enable', 'wechat_share_enable', 'wechat_share_debug' ] as $k ) {
		$settings[ $k ] = isset( $_POST[ $k ] ) ? '1' : '0';
	}

	// 去除 /category/ 前缀开关状态变化时需刷新重写规则（开启/关闭都要 flush 一次才能生效/还原）
	$no_cat_changed = jinyu_companion_get_option( 'no_category_enable', '1' ) !== $settings['no_category_enable'];

	// 文本 / URL / 颜色
	$settings['og_image']        = isset( $_POST['og_image'] ) ? esc_url_raw( wp_unslash( $_POST['og_image'] ) ) : '';
	$settings['og_site_name']    = isset( $_POST['og_site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['og_site_name'] ) ) : '';
	$settings['og_image_alt']    = isset( $_POST['og_image_alt'] ) ? sanitize_text_field( wp_unslash( $_POST['og_image_alt'] ) ) : '';
	// Twitter 账号名：去 @ 与空白，只留安全字符
	$settings['twitter_site']    = isset( $_POST['twitter_site'] ) ? ltrim( sanitize_text_field( wp_unslash( $_POST['twitter_site'] ) ), '@' ) : '';
	// 对象存储同步排除项：逗号 / 换行 / 空格分隔的目录名或文件后缀（不含点）
	$settings['storage_exclude_dirs'] = isset( $_POST['storage_exclude_dirs'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_exclude_dirs'] ) ) : '';
	$settings['storage_exclude_exts'] = isset( $_POST['storage_exclude_exts'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_exclude_exts'] ) ) : '';
	$settings['twitter_creator'] = isset( $_POST['twitter_creator'] ) ? ltrim( sanitize_text_field( wp_unslash( $_POST['twitter_creator'] ) ), '@' ) : '';
	// Facebook App ID：纯数字（非数字直接丢弃，避免脏数据进 meta）
	$settings['fb_app_id']       = isset( $_POST['fb_app_id'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['fb_app_id'] ) ) : '';
	// 微信分享：AppID 仅安全字符；AppSecret 留空保留原值，非空则加密入库（jinyu_enc2::）。
	$settings['wechat_appid'] = isset( $_POST['wechat_appid'] ) ? sanitize_text_field( wp_unslash( $_POST['wechat_appid'] ) ) : '';
	if ( isset( $_POST['wechat_appsecret'] ) && '' !== (string) wp_unslash( $_POST['wechat_appsecret'] ) ) {
		$settings['wechat_appsecret'] = jinyu_companion_encrypt( sanitize_text_field( wp_unslash( $_POST['wechat_appsecret'] ) ) );
	}
	// 全站 SEO 默认值（覆盖 tagline / 作为文章 / 分类兜底前的基准）
	$settings['seo_keywords']    = isset( $_POST['seo_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_keywords'] ) ) : '';
	$settings['seo_desc']        = isset( $_POST['seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ) ) : '';
	// 结构化数据：组织 / 品牌 sameAs 链接（每行一个 URL）
	$settings['entity_sameas']   = isset( $_POST['entity_sameas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['entity_sameas'] ) ) : '';
	$settings['baidu_submit_token'] = isset( $_POST['baidu_submit_token'] ) ? esc_url_raw( wp_unslash( $_POST['baidu_submit_token'] ) ) : '';
	$settings['anti_spam_words'] = isset( $_POST['anti_spam_words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['anti_spam_words'] ) ) : '';
	$settings['style_color_primary'] = isset( $_POST['style_color_primary'] ) ? sanitize_hex_color( wp_unslash( $_POST['style_color_primary'] ) ) : '';
	$settings['close_comments_days'] = isset( $_POST['close_comments_days'] ) ? (int) wp_unslash( $_POST['close_comments_days'] ) : 0;
	$settings['comment_freq_window'] = isset( $_POST['comment_freq_window'] ) ? max( 1, (int) wp_unslash( $_POST['comment_freq_window'] ) ) : 10;
	$settings['comment_freq_max']    = isset( $_POST['comment_freq_max'] ) ? max( 1, (int) wp_unslash( $_POST['comment_freq_max'] ) ) : 5;
	$settings['page_cache_ttl'] = isset( $_POST['page_cache_ttl'] ) ? max( 60, (int) wp_unslash( $_POST['page_cache_ttl'] ) ) : 3600;
	// 整页缓存例外规则：排除路径（每行一条）+ 两组查询参数名（逗号 / 空格分隔，仅保留安全字符）
	$settings['page_cache_exclude_paths'] = isset( $_POST['page_cache_exclude_paths'] ) ? sanitize_textarea_field( wp_unslash( $_POST['page_cache_exclude_paths'] ) ) : '';
	$sanitize_cache_params                = static function ( $v ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-,\s]/', '', (string) $v ) );
	};
	$settings['page_cache_ignore_params']  = isset( $_POST['page_cache_ignore_params'] ) ? $sanitize_cache_params( wp_unslash( $_POST['page_cache_ignore_params'] ) ) : '';
	$settings['page_cache_exclude_params'] = isset( $_POST['page_cache_exclude_params'] ) ? $sanitize_cache_params( wp_unslash( $_POST['page_cache_exclude_params'] ) ) : '';
	// 自动内链：单篇总链接数上限（1-20，默认 5）
	$settings['auto_link_limit'] = isset( $_POST['auto_link_limit'] ) ? max( 1, min( 20, (int) wp_unslash( $_POST['auto_link_limit'] ) ) ) : 5;

	// 站点验证元标签（仅保留安全字符，去除可能的注入内容）
	foreach ( [ 'verify_google', 'verify_bing', 'verify_baidu', 'verify_yandex', 'verify_360' ] as $vk ) {
		$settings[ $vk ] = isset( $_POST[ $vk ] ) ? sanitize_text_field( wp_unslash( $_POST[ $vk ] ) ) : '';
	}
	// Speculation Rules：模式（预取 / 预渲染）+ 急切度
	$settings['speculation_mode'] = isset( $_POST['speculation_mode'] ) && in_array( $_POST['speculation_mode'], [ 'prefetch', 'prerender' ], true )
		? sanitize_key( wp_unslash( $_POST['speculation_mode'] ) )
		: 'prefetch';
	$settings['speculation_eagerness'] = isset( $_POST['speculation_eagerness'] ) && in_array( $_POST['speculation_eagerness'], [ 'conservative', 'moderate', 'eager' ], true )
		? sanitize_key( wp_unslash( $_POST['speculation_eagerness'] ) )
		: 'conservative';

	// 验证码策略
	$allowed_policy = [ 'smart', 'always', 'off' ];
	$settings['captcha_policy'] = isset( $_POST['captcha_policy'] ) && in_array( $_POST['captcha_policy'], $allowed_policy, true )
		? sanitize_key( wp_unslash( $_POST['captcha_policy'] ) )
		: 'smart';

	// SMTP
	$settings['smtp_host']   = isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '';
	// 端口最小 1：清空提交存 0 会让 PHPMailer Port=0，发信静默失败
	$settings['smtp_port']   = isset( $_POST['smtp_port'] ) ? max( 1, (int) wp_unslash( $_POST['smtp_port'] ) ) : 0;
	$settings['smtp_secure'] = isset( $_POST['smtp_secure'] ) && in_array( $_POST['smtp_secure'], [ 'ssl', 'tls', 'none' ], true )
		? sanitize_key( wp_unslash( $_POST['smtp_secure'] ) )
		: 'ssl';
	$settings['smtp_user']   = isset( $_POST['smtp_user'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_user'] ) ) : '';
	$settings['smtp_from']   = isset( $_POST['smtp_from'] ) ? sanitize_email( wp_unslash( $_POST['smtp_from'] ) ) : '';
	// 发件人名称：显示在收件箱的署名，留空回退站点名称
	$settings['smtp_from_name'] = isset( $_POST['smtp_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_from_name'] ) ) : '';
	// 密码留空则保留原值（避免保存时误清空）
	if ( isset( $_POST['smtp_pwd'] ) && '' !== (string) wp_unslash( $_POST['smtp_pwd'] ) ) {
		$settings['smtp_pwd'] = sanitize_text_field( wp_unslash( $_POST['smtp_pwd'] ) );
	}

	// 对象存储（CDN）：配置存本插件独立选项，与主题设置完全隔离
	$settings['storage_provider'] = isset( $_POST['storage_provider'] ) && in_array( $_POST['storage_provider'], [ '', 'upyun', 's3' ], true )
		? sanitize_key( wp_unslash( $_POST['storage_provider'] ) )
		: '';
	$settings['storage_bucket']     = isset( $_POST['storage_bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_bucket'] ) ) : '';
	$settings['storage_region']     = isset( $_POST['storage_region'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_region'] ) ) : '';
	$settings['storage_endpoint']   = isset( $_POST['storage_endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_endpoint'] ) ) : '';
	$settings['storage_access_key'] = isset( $_POST['storage_access_key'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_access_key'] ) ) : '';
	$settings['storage_domain']     = isset( $_POST['storage_domain'] ) ? esc_url_raw( wp_unslash( $_POST['storage_domain'] ) ) : '';
	$settings['storage_prefix']     = isset( $_POST['storage_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_prefix'] ) ) : '';
	// URL 重写开关：表单不提供控件，普通保存保留现值（仅由「一键替换 / 复原」按钮切换）
	$settings['storage_rewrite'] = jinyu_companion_is_checked( 'storage_rewrite', true ) ? '1' : '0';
	// Secret 留空则保留原值；非空则加密入库（jinyu_enc2:: AES-256-CBC + HMAC）
	if ( isset( $_POST['storage_secret'] ) && '' !== (string) wp_unslash( $_POST['storage_secret'] ) ) {
		$settings['storage_secret'] = jinyu_companion_encrypt( sanitize_text_field( wp_unslash( $_POST['storage_secret'] ) ) );
	}

	// 唯一写入口：落库 + 同步刷新请求内缓存，保证本次渲染立刻读到新值。
	jinyu_companion_save_settings( $settings );
	jinyu_companion_settings_saved( true );
	// 记录提交时所在分区，保存后停留原页（默认概览）。
	if ( isset( $_POST['jyc_active_pane'] ) ) {
		jinyu_companion_active_pane( sanitize_key( wp_unslash( $_POST['jyc_active_pane'] ) ) );
	}
	// 分类前缀开关切换后立即刷新重写规则（成本极低，仅在状态实际变化时触发一次）
	if ( $no_cat_changed ) {
		flush_rewrite_rules();
	}
	// 注：重写规则由 llms.php / indexnow.php 在 init 阶段按版本号自愈，此处不再做昂贵的全量 flush。

	// 第三方登录（社交登录）配置：复用本表单 nonce，写入模块自有选项 JINYU_SL_OPT。
	if ( function_exists( 'jinyu_sl_process_post' ) ) {
		jinyu_sl_process_post();
	}
}

if ( ! function_exists( 'jinyu_companion_settings_saved' ) ) {
	/**
	 * 本次请求是否刚保存成功，用于渲染「已保存」提示。
	 * 由保存处理函数置位，避免在渲染层直接读 $_POST。
	 *
	 * @param bool|null $set 传入 true 置位。
	 * @return bool
	 */
	function jinyu_companion_settings_saved( ?bool $set = null ): bool {
		static $saved = false;
		if ( null !== $set ) {
			$saved = $set;
		}
		return $saved;
	}
}

if ( ! function_exists( 'jinyu_companion_panes' ) ) {
	/**
	 * 功能分区清单（不含概览）。导航、分区渲染白名单、概览统计共用同一份，防止新增分区漏改或数字写死漂移。
	 *
	 * @return string[]
	 */
	function jinyu_companion_panes(): array {
		return [ 'seo', 'content', 'perf', 'perfcenter', 'comment', 'smtp', 'storage', 'social', 'wechat' ];
	}
}

if ( ! function_exists( 'jinyu_companion_active_pane' ) ) {
	/**
	 * 当前激活的设置分区（标签页）。保存后停留原分区，避免每次保存都跳回概览。
	 *
	 * @param string|null $set 传入分区名置位（仅接受白名单值）。
	 * @return string
	 */
	function jinyu_companion_active_pane( ?string $set = null ): string {
		static $pane = 'overview';
		$valid       = array_merge( [ 'overview' ], jinyu_companion_panes() );
		if ( null !== $set ) {
			// 保存提交时由调用方传入（POST 优先级最高），保证保存后停留原页。
			if ( in_array( $set, $valid, true ) ) {
				$pane = $set;
			}
		} elseif ( isset( $_GET['pane'] ) ) {
			// GET 优先于默认：刷新 / 书签直达时保持当前分区，避免每次刷新跳回概览。
			$g = sanitize_key( wp_unslash( $_GET['pane'] ) );
			if ( in_array( $g, $valid, true ) ) {
				$pane = $g;
			}
		}
		return $pane;
	}
}

function jinyu_companion_settings_page_html(): void {
	$active_pane  = jinyu_companion_active_pane();
	$seo_open     = jinyu_companion_get_option( 'seo_open', '1' );
	$twitter      = jinyu_companion_get_option( 'twitter_card_enable', '1' );
	$llms         = jinyu_companion_get_option( 'llms_enable', '1' );
	$og_image     = jinyu_companion_get_option( 'og_image', '' );
	$og_site      = jinyu_companion_get_option( 'og_site_name', '' );
	$og_alt       = jinyu_companion_get_option( 'og_image_alt', '' );
	$tw_site      = jinyu_companion_get_option( 'twitter_site', '' );
	$tw_creator   = jinyu_companion_get_option( 'twitter_creator', '' );
	$fb_app_id    = jinyu_companion_get_option( 'fb_app_id', '' );
	$og_article   = jinyu_companion_get_option( 'og_article_meta', '1' );
	// 微信分享：启用开关 / AppID / 调试开关 / 是否已存 AppSecret（决定占位提示）。
	$wechat_enable    = jinyu_companion_get_option( 'wechat_share_enable', '0' );
	$wechat_appid     = jinyu_companion_get_option( 'wechat_appid', '' );
	$wechat_debug     = jinyu_companion_get_option( 'wechat_share_debug', '0' );
	$wechat_has_secret = '' !== (string) jinyu_companion_get_option( 'wechat_appsecret', '' );
	// 分享卡片预览（首页口径）：图片与站点名随输入实时联动，描述 / 域名取当前站点信息。
	$pv_desc = trim( (string) get_bloginfo( 'description' ) );
	$pv_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	// 实际输出的分享标签清单：[标签, 是否已配置, 说明, 联动来源]。让「空」变成可见的生效状态。
	// 联动来源形如 text:<input name> / check:<input name>，供 admin.js 在未保存时也实时反映。
	$og_tw_on   = jinyu_companion_is_checked( 'twitter_card_enable', '1' );
	$og_ld_on   = jinyu_companion_is_checked( 'ld_json_enable', '1' );
	$og_tag_map = [
		[ 'og:title', true, __( '文章 / 站点标题', 'jinyu-theme-companion' ), '' ],
		[ 'og:type', true, __( 'article / website 自动切换', 'jinyu-theme-companion' ), '' ],
		[ 'og:url', true, __( '规范链接', 'jinyu-theme-companion' ), '' ],
		[ 'og:site_name', true, $og_site ? '' : __( '回退站点名称', 'jinyu-theme-companion' ), '' ],
		[ 'og:locale', true, __( '按 WP 语言自动', 'jinyu-theme-companion' ), '' ],
		[ 'og:description', true, __( '摘要 / 正文首段兜底', 'jinyu-theme-companion' ), '' ],
		[ 'og:image', (bool) $og_image, $og_image ? '' : __( '未设置，将回退封面 / Logo', 'jinyu-theme-companion' ), 'text:og_image' ],
		[ 'og:image:alt', true, $og_alt ? '' : __( '自动用标题兜底', 'jinyu-theme-companion' ), '' ],
		[ 'og:image:type', true, __( '按扩展名推断', 'jinyu-theme-companion' ), '' ],
		[ 'fb:app_id', (bool) $fb_app_id, $fb_app_id ? '' : __( '未填写', 'jinyu-theme-companion' ), 'text:fb_app_id' ],
		[ 'twitter:card', (bool) $og_tw_on, $og_tw_on ? '' : __( 'Twitter 卡片已关闭', 'jinyu-theme-companion' ), 'check:twitter_card_enable' ],
		[ 'twitter:site', (bool) ( $tw_site && $og_tw_on ), $tw_site ? '' : __( '未填写', 'jinyu-theme-companion' ), 'text:twitter_site' ],
		[ 'article:*', (bool) $og_article, $og_article ? __( '发布 / 更新时间等', 'jinyu-theme-companion' ) : __( '已关闭', 'jinyu-theme-companion' ), 'check:og_article_meta' ],
		[ 'JSON-LD', (bool) $og_ld_on, __( '结构化数据', 'jinyu-theme-companion' ), 'check:ld_json_enable' ],
	];
	$seo_keywords = jinyu_companion_get_option( 'seo_keywords', '' );
	$seo_desc     = jinyu_companion_get_option( 'seo_desc', '' );
	// 结构化数据 JSON-LD：总开关（默认开）+ 组织 sameAs 链接
	$ld_json_enable = jinyu_companion_get_option( 'ld_json_enable', '1' );
	$entity_sameas  = jinyu_companion_get_option( 'entity_sameas', '' );
	$auto_link    = jinyu_companion_get_option( 'auto_link_enable', '0' );
	$indexnow     = jinyu_companion_get_option( 'indexnow_enable', '0' );
	$baidu_token  = jinyu_companion_get_option( 'baidu_submit_token', '' );
	$captcha      = jinyu_companion_get_option( 'captcha_policy', 'smart' );
	$spam_words   = jinyu_companion_get_option( 'anti_spam_words', '彩票,色情,赌博,代写,刷量' );
	$close_old    = jinyu_companion_get_option( 'close_comments_old', '1' );
	$close_days   = jinyu_companion_get_option( 'close_comments_days', 30 );
	$freq_enable  = jinyu_companion_get_option( 'comment_freq_enable', '1' );
	$freq_window  = jinyu_companion_get_option( 'comment_freq_window', 10 );
	$freq_max     = jinyu_companion_get_option( 'comment_freq_max', 5 );
	$poster_color = jinyu_companion_get_option( 'style_color_primary', '#FF6B35' );
	$smtp_host    = jinyu_companion_get_option( 'smtp_host', '' );
	$smtp_port    = jinyu_companion_get_option( 'smtp_port', 465 );
	$smtp_secure  = jinyu_companion_get_option( 'smtp_secure', 'ssl' );
	$smtp_user    = jinyu_companion_get_option( 'smtp_user', '' );
	$smtp_from    = jinyu_companion_get_option( 'smtp_from', '' );
	$smtp_from_name = jinyu_companion_get_option( 'smtp_from_name', '' );
	// 评论邮件通知开关（前两项默认开，保持既有行为；后两项默认关，避免上线即发信）
	$notify_reply    = jinyu_companion_get_option( 'comment_notify_reply', '1' );
	$notify_author   = jinyu_companion_get_option( 'comment_notify_author', '1' );
	$notify_blocked  = jinyu_companion_get_option( 'comment_notify_blocked', '0' );
	$notify_approved = jinyu_companion_get_option( 'comment_notify_approved', '0' );
	// 自动内链上限 + IndexNow 密钥（init 阶段自动生成，面板可见可复制）
	$auto_link_limit = (int) jinyu_companion_get_option( 'auto_link_limit', 5 );
	$indexnow_key    = (string) get_option( 'jinyu_indexnow_key', '' );
	// 对象存储（CDN）
	$storage_provider   = jinyu_companion_get_option( 'storage_provider', '' );
	$storage_bucket     = jinyu_companion_get_option( 'storage_bucket', '' );
	$storage_region     = jinyu_companion_get_option( 'storage_region', '' );
	$storage_endpoint   = jinyu_companion_get_option( 'storage_endpoint', '' );
	$storage_access_key = jinyu_companion_get_option( 'storage_access_key', '' );
	$storage_domain     = jinyu_companion_get_option( 'storage_domain', '' );
	$storage_prefix     = jinyu_companion_get_option( 'storage_prefix', '' );
	$storage_auto       = jinyu_companion_get_option( 'storage_auto_upload', '0' );
	$storage_del        = jinyu_companion_get_option( 'storage_delete_local', '0' );
	$storage_sync_extra = jinyu_companion_get_option( 'storage_sync_extra', '0' );
	$storage_exclude_dirs = jinyu_companion_get_option( 'storage_exclude_dirs', '' );
	$storage_exclude_exts = jinyu_companion_get_option( 'storage_exclude_exts', '' );
	$storage_has_secret = '' !== (string) jinyu_companion_get_option( 'storage_secret', '' );
	$page_cache_enable = jinyu_companion_get_option( 'page_cache_enable', '0' );
	$page_cache_ttl   = jinyu_companion_get_option( 'page_cache_ttl', '3600' );
	// 整页缓存例外规则：排除路径 / 忽略参数（不进 key）/ 排除参数（不缓存）
	$page_cache_exclude_paths  = jinyu_companion_get_option( 'page_cache_exclude_paths', '' );
	$page_cache_ignore_params  = jinyu_companion_get_option( 'page_cache_ignore_params', 'utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid' );
	$page_cache_exclude_params = jinyu_companion_get_option( 'page_cache_exclude_params', '' );
	// HTTP 传输体检：只读已缓存结果，页面加载绝不发请求（未体检过就显示空态，等用户点击）。
	$tp_data = function_exists( 'jinyu_transport_cached' ) ? jinyu_transport_cached() : array( 'rows' => array(), 'at' => 0, 'error' => '' );
	$tp_html = function_exists( 'jinyu_transport_render_rows' ) ? jinyu_transport_render_rows( $tp_data ) : '';
	$tp_ago  = ( ! empty( $tp_data['rows'] ) && function_exists( 'jinyu_transport_ago' ) ) ? jinyu_transport_ago( (int) $tp_data['at'] ) : '';

	// 性能：Speculation Rules
	$speculation_enable   = jinyu_companion_get_option( 'speculation_enable', '0' );
	$speculation_mode     = jinyu_companion_get_option( 'speculation_mode', 'prefetch' );
	$speculation_eager    = jinyu_companion_get_option( 'speculation_eagerness', 'conservative' );
	// SEO：站点验证 + 站点地图 + 图片 alt
	$verify_google = jinyu_companion_get_option( 'verify_google', '' );
	$verify_bing   = jinyu_companion_get_option( 'verify_bing', '' );
	$verify_baidu  = jinyu_companion_get_option( 'verify_baidu', '' );
	$verify_yandex = jinyu_companion_get_option( 'verify_yandex', '' );
	$verify_360    = jinyu_companion_get_option( 'verify_360', '' );
	$img_alt_enable     = jinyu_companion_get_option( 'img_alt_enable', '0' );
	$img_dim_enable     = jinyu_companion_get_option( 'img_dim_enable', '1' );

	// TTL 人类可读（与前端 JS 同算法，避免首屏闪烁）
	$_ttl = (int) $page_cache_ttl;
	if ( $_ttl < 3600 ) {
		$_ttl_h = round( $_ttl / 60 ) . ' 分钟';
	} elseif ( $_ttl < 86400 ) {
		$_ttl_h = ( $_ttl % 3600 ? number_format( $_ttl / 3600, 1 ) : intval( $_ttl / 3600 ) ) . ' 小时';
	} else {
		$_ttl_h = number_format( $_ttl / 86400, 1 ) . ' 天';
	}
	?>
	<div class="wrap jyc-wrap">
		<?php
		/*
		 * 不调用 settings_errors()：WP 核心 common.js 会把 .notice 搬到「.wrap 内首个 h1/h2 之后」，
		 * 而本页首个 h1 是 Hero 标题 —— 通知条会被塞进深蓝渐变里，文字对比度崩掉。
		 * 这里改用自带 toast（固定定位、玻璃底、深色字）承载「已保存」提示。
		 * 另放一个 .wp-header-end 作锚点，让其它插件的原生通知落在内容区顶部而非 Hero 内。
		 */
		?>
		<hr class="wp-header-end">

		<form method="post" id="jyc-form">
			<?php wp_nonce_field( 'jinyu_companion_settings', 'jinyu_companion_nonce' ); ?>
			<input type="hidden" name="jinyu_companion_save" value="1">
			<input type="hidden" name="jyc_active_pane" id="jyc-activePane" value="<?php echo esc_attr( $active_pane ); ?>">

			<div class="jyc-app" data-theme="light">
				<!-- ===================== TOP NAV ===================== -->
				<div class="jyc-main">
					<header class="jyc-topnav">
						<div class="jyc-brand">
							<div class="jyc-brand-mark" aria-hidden="true"><svg viewBox="0 0 36 36" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="18" cy="18" r="11" stroke="#FFFFFF" stroke-width="4"/><circle cx="25.8" cy="10.2" r="3.4" fill="#F5B942"/></svg></div>
							<div class="jyc-brand-txt"><b>金玉 · 增强控制台</b><span>配套插件设置</span></div>
						</div>
						<div class="jyc-nav-wrap">
							<button type="button" class="jyc-nav-edge jyc-nav-edge-left" aria-label="<?php echo esc_attr__( '向左滚动', 'jinyu-theme-companion' ); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
							<nav class="jyc-nav" id="jyc-nav">
								<button type="button" class="jyc-nav-item<?php echo 'overview' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="overview">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>
								<span>概览</span>
							</button>
								<button type="button" class="jyc-nav-item<?php echo 'seo' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="seo">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
									<span>SEO / 社交</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'content' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="content">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
									<span>内容增强</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'perf' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="perf">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7z"/></svg>
									<span>前台加速</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'perfcenter' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="perfcenter">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2.5-6 4 12 2.5-6H21"/></svg>
									<span>性能中心</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'comment' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="comment">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.3 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.7A8.5 8.5 0 0 1 12 3a8.38 8.38 0 0 1 9 8.5z"/></svg>
									<span>评论与互动</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'smtp' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="smtp">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg>
									<span>邮件 SMTP</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'storage' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="storage">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/></svg>
									<span>对象存储</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'social' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="social">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
									<span>第三方登录</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'wechat' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="wechat">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 13a4.5 4.5 0 0 1-4.5-4.5A4.5 4.5 0 0 1 8 4c2 0 3.7 1.2 4.4 3"/><path d="M16 19a4 4 0 0 1-4-4 4 4 0 0 1 4-4 4 4 0 0 1 4 4v.5a3 3 0 0 1-3 3h-.5a2 2 0 0 0-1.5.7L13 21l2-2.5c.5.1 1 .4 1.6.4z"/></svg>
									<span>微信分享</span>
								</button>
								</nav>
							<button type="button" class="jyc-nav-edge jyc-nav-edge-right" aria-label="<?php echo esc_attr__( '向右滚动', 'jinyu-theme-companion' ); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
						</div>
						<div class="jyc-top-actions">
							<button class="jyc-theme-tog" id="jyc-themeTog" type="button" title="<?php echo esc_attr__( '切换深色 / 浅色', 'jinyu-theme-companion' ); ?>">
								<svg id="jyc-themeIco" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
							</button>
							<button class="jyc-btn jyc-btn-primary" type="submit">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
								<?php echo esc_html__( '保存更改', 'jinyu-theme-companion' ); ?>
							</button>
						</div>
					</header>

					<main class="jyc-content">
						<!-- ===================== OVERVIEW ===================== -->
						<section id="pane-overview" class="jyc-pane<?php echo 'overview' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-hero jyc-glass">
								<div class="jyc-aurora"><i></i></div>
								<div class="jyc-hero-inner">
									<h1><?php echo esc_html__( '金玉增强控制台', 'jinyu-theme-companion' ); ?></h1>
									<p><?php echo esc_html__( '统一管理主题的 SEO、内容增强、整页缓存、评论防护与邮件投递。所有配置存于插件独立选项，不受主题导入 / 重置影响，可脱离主题独立运行。', 'jinyu-theme-companion' ); ?></p>
									<div class="jyc-hero-stats">
										<div class="jyc-hs"><span class="jyc-v jyc-num"><?php echo (int) count( jinyu_companion_panes() ); ?></span><span class="jyc-k"><?php echo esc_html__( '功能分区', 'jinyu-theme-companion' ); ?></span></div>
										<div class="jyc-hs"><span class="jyc-v jyc-num" id="jyc-hsOn">0</span><span class="jyc-k"><?php echo esc_html__( '已启用开关', 'jinyu-theme-companion' ); ?></span></div>
										<div class="jyc-hs"><span class="jyc-v jyc-num" id="jyc-hsFields">—</span><span class="jyc-k"><?php echo esc_html__( '可配置字段', 'jinyu-theme-companion' ); ?></span></div>
										<div class="jyc-hs"><span class="jyc-v jyc-num">独立</span><span class="jyc-k"><?php echo esc_html__( '存储隔离', 'jinyu-theme-companion' ); ?></span></div>
									</div>
								</div>
							</div>

							<?php
							// 概览磁贴数据：状态点色由语义（健康 / 待办 / 实际指标评级）决定，不写死。
							$ov_vitals = jinyu_companion_overview_vitals();
							$ov_todos  = jinyu_companion_overview_todos();
							$ov_db     = jinyu_companion_overview_db();
							$ov_open   = array_values(
								array_filter(
									$ov_todos,
									static function ( $t ) {
										return ! $t['ok'];
									}
								)
							);
							// 下发前端的待办规格：只留前端判定所需字段（n/t/p/l），ok 与默认值 d 是服务端专用。
							$ov_spec   = array_map(
								static function ( $t ) {
									unset( $t['ok'], $t['d'] );
									return $t;
								},
								$ov_todos
							);
							$ov_dot    = static function ( string $rate ): string {
								$map = array(
									'ok'   => 'var(--ok)',
									'good' => 'var(--ok)',
									'mid'  => 'var(--warn)',
									'warn' => 'var(--warn)',
									'poor' => 'var(--danger)',
								);
								return $map[ $rate ] ?? '#94a3b8';
							};
							?>
							<div class="jyc-bento">
								<div class="jyc-tile"><div class="jyc-k"><i style="background:var(--brand)"></i><?php echo esc_html__( '功能启用度', 'jinyu-theme-companion' ); ?></div>
									<div class="jyc-v jyc-num" id="jyc-bentoPct">0%</div><div class="jyc-n"><?php echo esc_html__( '全部功能开关平均开启比例', 'jinyu-theme-companion' ); ?></div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:var(--ok)"></i>SEO 呈现</div><div class="jyc-v jyc-num" id="jyc-sSeo">—</div><div class="jyc-n">OG / Twitter / llms</div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:#0ea5e9"></i>主动推送</div><div class="jyc-v jyc-num" id="jyc-sPush">—</div><div class="jyc-n">IndexNow / 百度</div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:#64748b"></i><?php echo esc_html__( '整页缓存', 'jinyu-theme-companion' ); ?></div><div class="jyc-v jyc-num" id="jyc-sCache">关</div><div class="jyc-n"><?php echo esc_html__( '前台静态化状态', 'jinyu-theme-companion' ); ?></div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:var(--brand-2)"></i>评论防护</div><div class="jyc-v jyc-num" id="jyc-sCap">智能</div><div class="jyc-n"><?php echo esc_html__( '验证码策略', 'jinyu-theme-companion' ); ?></div></div>
								<div class="jyc-tile jyc-tile-link" role="button" tabindex="0" data-jyc-goto="perfcenter">
									<span class="jyc-goto" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg></span>
									<div class="jyc-k"><i style="background:<?php echo esc_attr( $ov_dot( $ov_vitals['rate'] ) ); ?>"></i><?php echo esc_html__( '加载性能', 'jinyu-theme-companion' ); ?></div>
									<div class="jyc-v jyc-num"><?php echo esc_html( $ov_vitals['main'] ); ?></div>
									<div class="jyc-n"><?php echo esc_html( $ov_vitals['note'] ); ?></div>
								</div>
								<div class="jyc-tile jyc-tile-link" role="button" tabindex="0" id="jyc-tileTodo" data-jyc-goto="<?php echo esc_attr( $ov_open ? $ov_open[0]['p'] : 'perfcenter' ); ?>" data-todos="<?php echo esc_attr( (string) wp_json_encode( $ov_spec ) ); ?>">
									<span class="jyc-goto" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg></span>
									<div class="jyc-k"><i style="background:<?php echo esc_attr( $ov_open ? 'var(--warn)' : 'var(--ok)' ); ?>"></i><?php echo esc_html__( '优化待办', 'jinyu-theme-companion' ); ?></div>
									<div class="jyc-v jyc-num" id="jyc-sTodo"><?php
										echo esc_html(
											sprintf(
												/* translators: %d: 待处理项数 */
												__( '%d 项', 'jinyu-theme-companion' ),
												count( $ov_open )
											)
										);
									?></div>
									<div class="jyc-n" id="jyc-sTodoNote"><?php echo esc_html( $ov_open ? $ov_open[0]['l'] : __( '暂无优化建议', 'jinyu-theme-companion' ) ); ?></div>
								</div>
								<div class="jyc-tile jyc-tile-link" role="button" tabindex="0" data-jyc-goto="perfcenter">
									<span class="jyc-goto" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7M9 7h8v8"/></svg></span>
									<div class="jyc-k"><i style="background:<?php echo esc_attr( $ov_dot( $ov_db['rate'] ) ); ?>"></i><?php echo esc_html__( '数据库健康', 'jinyu-theme-companion' ); ?></div>
									<div class="jyc-v jyc-num"><?php echo esc_html( $ov_db['main'] ); ?></div>
									<div class="jyc-n"><?php echo esc_html( $ov_db['note'] ); ?></div>
								</div>
							</div>

							<div class="jyc-progress-card">
								<div class="jyc-pc-head"><h3><?php echo esc_html__( '功能启用度明细', 'jinyu-theme-companion' ); ?></h3><span class="jyc-pct" id="jyc-pct">0%</span></div>
								<div class="jyc-pbar"><i id="jyc-pbarFill" style="width:0%"></i></div>
								<div class="jyc-checks" id="jyc-checks"></div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/></svg></span><?php echo esc_html__( '内置恒启能力', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '与主题模板深度集成，始终随主题运行', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-muted" style="font-size:13px;line-height:1.9">
										<?php echo esc_html__( '关注 / 消息系统 · 站点访问统计 · 相关文章 · 系列文章 · 快讯 (Moments) · 短代码与短代码 UI · 对象存储引擎。以上能力由主题模板直接调用，无需配置即可使用；若需整体停用，停用本插件即可（主题经 function_exists 守卫自动降级）。', 'jinyu-theme-companion' ); ?>
									</div>
								</div>
							</div>
						</section>

						<!-- ===================== SEO / SOCIAL ===================== -->
						<section id="pane-seo" class="jyc-pane<?php echo 'seo' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( 'SEO / 社交分享', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '控制社交平台分享卡片与 AI 爬虫可发现性。关闭 SEO 后社交分享退化为纯文本链接。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></span><?php echo esc_html__( '呈现开关', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认开启核心呈现', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="seo_open" <?php checked( $seo_open, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( 'SEO / Open Graph', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '输出 SEO 元标签与 Open Graph（微信 / QQ 分享卡片）。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="seo_content_h1_fix" <?php checked( jinyu_companion_is_checked( 'seo_content_h1_fix', true ), true ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '正文标题规范化', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '将正文里嵌的 h1 标题降级为 h2（页面主标题已由模板输出 h1）。仅影响前台输出，不改动文章内容，可通过过滤器 jinyu_seo_demote_content_h1_skip 跳过。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="twitter_card_enable" <?php checked( $twitter, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( 'Twitter 卡片', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '输出 Twitter Card 标签，适配 X / 海外社交分享。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="llms_enable" <?php checked( $llms, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( 'llms.txt 发现链接', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '在头部输出 llms.txt 发现链接，便于大模型站点理解。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="no_category_enable" <?php checked( jinyu_companion_get_option( 'no_category_enable', '1' ), '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '去除 /category/ 前缀', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '分类链接不再带 /category/ 前缀（旧前缀链接 301 到新地址）；保存时自动刷新重写规则。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span><?php echo esc_html__( '分享素材', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( 'og:image / og:site_name / card', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-og-img">
										<div class="jyc-og-thumb<?php echo $og_image ? ' has-img' : ''; ?>" id="jyc-ogThumb">
											<img id="jyc-ogThumbImg" src="<?php echo esc_url( $og_image ); ?>" alt="">
											<span class="jyc-og-thumb-ph"><?php echo esc_html__( '1200×630', 'jinyu-theme-companion' ); ?></span>
										</div>
										<div class="jyc-og-img-main">
											<label class="jyc-fl"><?php echo esc_html__( '默认分享图 (og:image)', 'jinyu-theme-companion' ); ?>
												<input class="jyc-inp" type="url" name="og_image" id="jyc-ogImage" value="<?php echo esc_attr( $og_image ); ?>" placeholder="https://example.com/og-default.png" spellcheck="false" autocomplete="off">
											</label>
											<div class="jyc-og-acts">
												<button type="button" class="jyc-btn jyc-btn-soft" id="jyc-ogPick"><?php echo esc_html__( '从媒体库选择', 'jinyu-theme-companion' ); ?></button>
												<button type="button" class="jyc-btn jyc-btn-ghost" id="jyc-ogClear"><?php echo esc_html__( '清除', 'jinyu-theme-companion' ); ?></button>
												<span class="jyc-og-size" id="jyc-ogSize"><?php echo esc_html__( '建议 1200×630（微信 / 微博大图卡片比例）', 'jinyu-theme-companion' ); ?></span>
											</div>
										</div>
									</div>
									<label class="jyc-fl" style="margin-top:16px"><?php echo esc_html__( '图片替代文本 (og:image:alt)', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp" type="text" name="og_image_alt" value="<?php echo esc_attr( $og_alt ); ?>" placeholder="<?php echo esc_attr__( '留空自动用文章标题 / 站点名称', 'jinyu-theme-companion' ); ?>">
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '图片被读屏软件朗读的文本，微博 / X 等平台改版后也会取用；留空自动兜底。', 'jinyu-theme-companion' ); ?></span>
									</label>
									<div class="jyc-field-grid">
										<label class="jyc-fl"><?php echo esc_html__( '站点名称 (og:site_name)', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="text" name="og_site_name" id="jyc-ogSite" value="<?php echo esc_attr( $og_site ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'Facebook App ID (fb:app_id)', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="fb_app_id" value="<?php echo esc_attr( $fb_app_id ); ?>" placeholder="<?php echo esc_attr__( '可留空', 'jinyu-theme-companion' ); ?>" inputmode="numeric">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( 'Meta 开发者后台的 App ID，用于社区卡片调试与数据归属；不做海外分发可留空。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'Twitter 站点账号 (twitter:site)', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="text" name="twitter_site" value="<?php echo esc_attr( $tw_site ); ?>" placeholder="qicaiyun">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'Twitter 作者账号 (twitter:creator)', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="text" name="twitter_creator" value="<?php echo esc_attr( $tw_creator ); ?>" placeholder="<?php echo esc_attr__( '可留空', 'jinyu-theme-companion' ); ?>">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '只填账号名即可，插件自动补 @；随「Twitter 卡片」开关一起输出。', 'jinyu-theme-companion' ); ?></span>
										</label>
									</div>
									<div class="jyc-frow jyc-og-tog">
										<label class="jyc-switch"><input type="checkbox" name="og_article_meta" <?php checked( $og_article, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '文章时效标签 (article:*)', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '为文章页补充 article:published_time / modified_time / author / section / tag 与 og:updated_time，供 Facebook / LinkedIn / 聚合器识别发布时间与栏目。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span><?php echo esc_html__( '分享卡片预览', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '首页口径 · 随左侧输入实时联动', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-pv-wrap">
									<div class="jyc-pv">
										<div class="jyc-pv-img<?php echo $og_image ? ' has-img' : ''; ?>" id="jyc-pvImgBox">
											<img id="jyc-pvImg" src="<?php echo esc_url( $og_image ); ?>" alt="">
											<span class="jyc-pv-img-ph"><?php echo esc_html__( '未设置分享图', 'jinyu-theme-companion' ); ?><br><small><?php echo esc_html__( '将回退站点 Logo', 'jinyu-theme-companion' ); ?></small></span>
										</div>
										<div class="jyc-pv-body">
											<div class="jyc-pv-title" id="jyc-pvTitle" data-default="<?php echo esc_attr( $og_site ?: get_bloginfo( 'name' ) ); ?>"><?php echo esc_html( $og_site ?: get_bloginfo( 'name' ) ); ?></div>
											<div class="jyc-pv-desc"><?php echo esc_html( $pv_desc ?: __( '站点副标题为空，建议在「设置 → 常规」填写，或在上方填全站默认描述。', 'jinyu-theme-companion' ) ); ?></div>
											<div class="jyc-pv-site"><span class="jyc-pv-dot" aria-hidden="true"></span><?php echo esc_html( $pv_host ); ?></div>
										</div>
									</div>
									<div class="jyc-pv-side">
										<div class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '文章页卡片由文章封面与标题自动生成，无需在此配置；此处预览的是首页 / 归档页共用的兜底卡片。', 'jinyu-theme-companion' ); ?></div>
										<div class="jyc-og-tags-h"><?php echo esc_html__( '当前输出的标签', 'jinyu-theme-companion' ); ?></div>
										<div class="jyc-og-tags">
											<?php foreach ( $og_tag_map as $t ) : ?>
												<span class="jyc-og-tag<?php echo $t[1] ? ' is-on' : ' is-off'; ?>" title="<?php echo esc_attr( $t[2] ); ?>"<?php echo $t[3] ? ' data-dep="' . esc_attr( $t[3] ) . '"' : ''; ?>><i aria-hidden="true"></i><?php echo esc_html( $t[0] ); ?></span>
											<?php endforeach; ?>
										</div>
										<div class="jyc-muted jyc-og-tag-legend"><?php echo esc_html__( '● 蓝色 = 已输出　○ 灰色 = 未配置（可留空，不影响卡片生成）', 'jinyu-theme-companion' ); ?></div>
									</div>
									</div>
								</div>
							</div>
						<div class="jyc-panel">
							<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 12h16M4 17h10"/></svg></span><?php echo esc_html__( '全站 SEO 默认值', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '留空则自动兜底', 'jinyu-theme-companion' ); ?></span></div>
							<div class="jyc-panel-b">
								<label class="jyc-fl"><?php echo esc_html__( '全站默认描述 (meta description)', 'jinyu-theme-companion' ); ?>
									<textarea class="jyc-inp" name="seo_desc" rows="2" placeholder="<?php echo esc_attr( get_bloginfo( 'description' ) ); ?>"><?php echo esc_textarea( $seo_desc ); ?></textarea>
									<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '留空时：首页用站点副标题、文章用摘要 / 正文首段兜底。', 'jinyu-theme-companion' ); ?></span>
								</label>
								<label class="jyc-fl"><?php echo esc_html__( '全站默认关键词 (meta keywords)', 'jinyu-theme-companion' ); ?>
									<textarea class="jyc-inp" name="seo_keywords" rows="2" placeholder="关键词1,关键词2,关键词3"><?php echo esc_textarea( $seo_keywords ); ?></textarea>
									<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '英文逗号分隔；文章 / 分类页会覆盖此项。中文引擎（百度 / 360 等）仍会参考。', 'jinyu-theme-companion' ); ?></span>
								</label>
							</div>
						</div>

						<div class="jyc-panel">
							<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg></span><?php echo esc_html__( '站点验证（搜索引擎归属）', 'jinyu-theme-companion' ); ?></h2>
								<button type="button" class="jyc-info-btn" data-pop="jyc-pop-verify" aria-expanded="false">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg><?php echo esc_html__( '使用说明', 'jinyu-theme-companion' ); ?>
								</button>
								<div class="jyc-pop" id="jyc-pop-verify" hidden role="tooltip">
									<ol>
										<li><?php echo esc_html__( '到站长平台（Bing / Google / 百度等）添加站点，验证方式选择「HTML 元标签」。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( '平台给出形如 <meta name="msvalidate.01" content="XXXX" /> 的标签，只需复制 content=" 引号里的那串码（不含标签和引号）。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( '粘贴到下方对应输入框并保存，回到平台点「验证」即可。', 'jinyu-theme-companion' ); ?></li>
									</ol>
									<p><?php echo esc_html__( '找码示例：Bing 为站点下拉 → ⋯ → 「验证代码」，或左侧「配置我的网站 → 验证所有权」；Google 在 Search Console 的「设置 → 所有权验证」。', 'jinyu-theme-companion' ); ?></p>
								</div>
							</div>
							<div class="jyc-panel-b">
								<div class="jyc-field-grid">
										<label class="jyc-fl"><?php echo esc_html__( 'Google 验证代码', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="verify_google" value="<?php echo esc_attr( $verify_google ); ?>" placeholder="google-site-verification 内容">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'Bing 验证代码', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="verify_bing" value="<?php echo esc_attr( $verify_bing ); ?>" placeholder="msvalidate.01 内容">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '百度验证代码', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="verify_baidu" value="<?php echo esc_attr( $verify_baidu ); ?>" placeholder="baidu-site-verification 内容">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'Yandex 验证代码', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="verify_yandex" value="<?php echo esc_attr( $verify_yandex ); ?>" placeholder="yandex-verification 内容">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '360 搜索验证代码', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="verify_360" value="<?php echo esc_attr( $verify_360 ); ?>" placeholder="360-site-verification 内容">
										</label>
										<span class="jyc-muted jyc-full" style="font-size:12px"><?php echo esc_html__( '填入各站长平台提供的验证元标签内容。已安装 Yoast / Rank Math 等 SEO 插件时本项自动让位。', 'jinyu-theme-companion' ); ?></span>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg></span><?php echo esc_html__( '图片 SEO', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="img_alt_enable" <?php checked( $img_alt_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '图片缺失 alt 自动补全', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '正文里完全没有 alt 的站内图片，自动按附件标题 / 文件名补 alt，改善 SEO 与无障碍。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="img_dim_enable" <?php checked( $img_dim_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '图片尺寸自动补齐（改善 CLS）', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '正文里缺 width/height 的站内图片，自动按媒体库真实尺寸补齐，浏览器可提前占位，减少页面加载时的布局跳动（CLS）。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></span><?php echo esc_html__( 'SEO 诊断', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '只读诊断，不改数据', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-test-row">
										<button class="jyc-btn jyc-btn-ghost jyc-fold-tog" type="button" id="jyc-imgAuditBtn" data-loading="<?php echo esc_attr__( '体检中…', 'jinyu-theme-companion' ); ?>" aria-expanded="false" onclick="window.jycImgAudit(this)"><span class="jyc-fold-dot" aria-hidden="true"></span><span class="jyc-fold-txt"><?php echo esc_html__( '图片 SEO 体检', 'jinyu-theme-companion' ); ?></span><span class="jyc-fold-ch" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></button>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '扫描全站正文，统计缺 alt / 缺尺寸的图片分布，对症补内容。', 'jinyu-theme-companion' ); ?></span>
									</div>
									<div id="jyc-imgAuditResult" class="jyc-fold" style="margin-top:10px" aria-live="polite"><div class="jyc-fold-in"><div id="jyc-imgAuditInner"></div></div></div>
									<div style="border-top:1px solid var(--line);margin:16px 0 14px"></div>
									<div class="jyc-test-row">
										<button class="jyc-btn jyc-btn-ghost jyc-fold-tog" type="button" id="jyc-seoDiag" data-loading="<?php echo esc_attr__( '诊断中…', 'jinyu-theme-companion' ); ?>" aria-expanded="false" onclick="window.jycSeoDiag(this)"><span class="jyc-fold-dot" aria-hidden="true"></span><span class="jyc-fold-txt"><?php echo esc_html__( '内容 SEO 诊断', 'jinyu-theme-companion' ); ?></span><span class="jyc-fold-ch" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></button>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '找出标题过短（&lt;15 字）与摘要过短（&lt;60 字）的已发布文章，对症补写内容；对应 Bing 报告的「标题过短 / 描述过短」。', 'jinyu-theme-companion' ); ?></span>
									</div>
									<div id="jyc-seoDiagResult" class="jyc-fold" style="margin-top:10px" aria-live="polite"><div class="jyc-fold-in"><div id="jyc-seoDiagInner"></div></div></div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z"/></svg></span><?php echo esc_html__( '结构化数据 (JSON-LD)', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认开启', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="ld_json_enable" <?php checked( $ld_json_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用结构化数据 JSON-LD', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '输出 WebSite / Article / FAQ / HowTo 等结构化数据，提升搜索富摘要与 AI（GEO）可发现性。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<?php // sameAs 已从 UI 收回（冷门字段，普通用户无感）；隐藏字段保住已存值不被整表提交清空，开发者可用 jinyu_seo_entity_sameas 过滤器注入。 ?>
									<input type="hidden" name="entity_sameas" value="<?php echo esc_attr( $entity_sameas ); ?>">
								</div>
							</div>
						</section>

						<!-- ===================== CONTENT ===================== -->
						<section id="pane-content" class="jyc-pane<?php echo 'content' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '内容增强', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '自动内链、主动推送与 AI 分享海报，提升内链权重、收录速度与传播体验。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7z"/></svg></span><?php echo esc_html__( '自动化', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认关闭，按需开启', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="auto_link_enable" <?php checked( $auto_link, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '自动内链', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '自动为文章关键词添加内链，强化站内权重传递。', 'jinyu-theme-companion' ); ?></div></div>
										<label class="jyc-fl jyc-fnum"><?php echo esc_html__( '单篇上限', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="auto_link_limit" value="<?php echo esc_attr( $auto_link_limit ); ?>" min="1" max="20" step="1">
										</label>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="indexnow_enable" <?php checked( $indexnow, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( 'IndexNow 推送', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '发布 / 更新文章时向 IndexNow 提交 URL，加速收录。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span><?php echo esc_html__( 'IndexNow 密钥', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '自动生成，无需手动配置', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-sl-redirect-ctrl" style="width:100%">
										<input class="jyc-inp jyc-inp-mono" type="text" readonly value="<?php echo esc_attr( $indexnow_key ); ?>" id="jyc-indexnow-key" spellcheck="false" autocomplete="off">
										<button type="button" class="jyc-sl-copy" data-copy="jyc-indexnow-key"><?php echo esc_html__( '复制', 'jinyu-theme-companion' ); ?></button>
									</div>
									<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '验证文件位于站点根目录；把此地址加入 Bing 等平台后即可主动推送：', 'jinyu-theme-companion' ); ?> <code id="jyc-indexnow-loc" style="user-select:all"><?php echo esc_html( home_url( '/' . $indexnow_key . '.txt' ) ); ?></code></span>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"/><path d="M4.9 19.1a10 10 0 0 1 0-14.2M19.1 4.9a10 10 0 0 1 0 14.2M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4"/></svg></span><?php echo esc_html__( '百度主动推送', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<label class="jyc-fl"><?php echo esc_html__( '百度主动推送接口', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-inp-mono" type="url" name="baidu_submit_token" value="<?php echo esc_attr( $baidu_token ); ?>" placeholder="https://push.api.baidu.com/...token=">
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '百度搜索资源平台「主动推送」接口地址（含 token）。留空则不推送。', 'jinyu-theme-companion' ); ?></span>
									</label>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg></span><?php echo esc_html__( 'AI 分享海报', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<div class="jyc-fl"><?php echo esc_html__( 'AI 海报主色', 'jinyu-theme-companion' ); ?>
										<div class="jyc-color-row">
											<input type="color" name="style_color_primary" value="<?php echo esc_attr( $poster_color ); ?>">
											<span class="jyc-chip" id="jyc-colorChip"><?php echo esc_html( strtoupper( $poster_color ) ); ?></span>
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( 'AI 生成分享海报时的主色调。', 'jinyu-theme-companion' ); ?></span>
										</div>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg></span><?php echo esc_html__( '全量补推', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '提交存量已发布内容', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-test-row">
									<button class="jyc-btn jyc-btn-ghost jyc-fold-tog" type="button" id="jyc-bulkPush" data-loading="<?php echo esc_attr__( '推送中…', 'jinyu-theme-companion' ); ?>" aria-expanded="false" onclick="window.jycBulkPush(this)"><span class="jyc-fold-dot" aria-hidden="true"></span><span class="jyc-fold-txt"><?php echo esc_html__( '提交全部已发布文章', 'jinyu-theme-companion' ); ?></span><span class="jyc-fold-ch" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></button>
									<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '把历史文章/页面批量提交给已启用的 IndexNow 与百度，弥补仅发布时推送的收录盲区。', 'jinyu-theme-companion' ); ?></span>
								</div>
								<div id="jyc-bulkPushResult" class="jyc-fold" style="margin-top:10px" aria-live="polite"><div class="jyc-fold-in"><div id="jyc-bulkPushInner"></div></div></div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></span><?php echo esc_html__( '推送记录', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '最近 8 条', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div id="jyc-pushLogBody" aria-live="polite"><?php jinyu_push_log_render( 8 ); ?></div>
									<div class="jyc-test-row" style="margin-top:12px">
										<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-pushLogRefresh" data-loading="<?php echo esc_attr__( '刷新中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycPushLogRefresh(this)"><?php echo esc_html__( '刷新', 'jinyu-theme-companion' ); ?></button>
										<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-pushLogClear" data-loading="<?php echo esc_attr__( '清空中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycPushLogClear(this)"><?php echo esc_html__( '清空记录', 'jinyu-theme-companion' ); ?></button>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '单篇发布 / 更新的推送是非阻塞请求，读不到接口响应，记为「已提交（异步）」；全量补推为阻塞请求，记录真实成功 / 失败与响应码。', 'jinyu-theme-companion' ); ?></span>
									</div>
								</div>
							</div>
						</section>

						<!-- ===================== PERFORMANCE ===================== -->
						<section id="pane-perf" class="jyc-pane<?php echo 'perf' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '前台加速', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '配置整页缓存、预取加速与数据库维护。服务器缓存（OPcache / Memcached）看板与清理请前往「性能中心」。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/></svg></span><?php echo esc_html__( '整页缓存', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认关闭', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="page_cache_enable" <?php checked( $page_cache_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用整页缓存', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '为未登录访客缓存整页 HTML，显著提升匿名访问性能。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<?php
									// 整页缓存后端状态：目录不可用时明确写出来。
									// 否则「开关已开但缓存从未生效」是静默的，用户无从察觉。
									$jpc = function_exists( 'jinyu_page_cache_status' ) ? jinyu_page_cache_status() : null;
									if ( $jpc && $jpc['enabled'] ) :
										$jpc_ready = (bool) $jpc['ready'];
										$jpc_last  = $jpc['last_write'] > 0
											? human_time_diff( $jpc['last_write'] ) . __( '前', 'jinyu-theme-companion' )
											: __( '刚刚', 'jinyu-theme-companion' );
										?>
										<div style="margin-top:8px;font-size:12px;line-height:1.7;<?php echo $jpc_ready ? 'color:#1f7a3d' : 'color:#b3261e'; ?>">
											<?php if ( $jpc_ready ) : ?>
												<?php
												                                printf(
                                    /* translators: 1: 缓存文件数；2: 最近写入时间 */
                                    esc_html__( '缓存目录就绪：%1$s 个缓存文件，最近写入 %2$s。', 'jinyu-theme-companion' ),
                                    number_format_i18n( (int) $jpc['files'] ),
                                    esc_html( $jpc_last )
                                );
                                ?>
                                <div style="margin:10px 0 4px;color:#5b6472;font-size:12px;line-height:1.7">
                                    <?php
                                    // 内含 <code> 标记，输出处不做转义（与 jinyu_page_cache_hint 一致）
                                    if ( function_exists( 'jinyu_page_cache_etag_hint' ) ) {
                                        echo jinyu_page_cache_etag_hint();
                                    }
                                    ?>
                                </div>
											<?php else : ?>
												<strong><?php esc_html_e( '整页缓存未生效', 'jinyu-theme-companion' ); ?></strong>
												<?php echo jinyu_page_cache_hint(); // 内含 esc_html 处理过的路径，非转义输出 ?>
											<?php endif; ?>
										</div>
									<?php endif; ?>
									<div class="jyc-fl" style="margin-top:6px"><?php echo esc_html__( '缓存有效期', 'jinyu-theme-companion' ); ?>
										<div class="jyc-slider-wrap">
											<input type="range" name="page_cache_ttl" min="60" max="86400" step="60" value="<?php echo esc_attr( $page_cache_ttl ); ?>" id="jyc-ttlRange">
											<div class="jyc-slider-val"><span id="jyc-ttlVal"><?php echo esc_html( $_ttl_h ); ?></span><small id="jyc-ttlSec"><?php echo esc_html( $_ttl ); ?> 秒</small></div>
										</div>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '范围 60 秒 ～ 24 小时，建议 1 小时。', 'jinyu-theme-companion' ); ?></span>
									</div>
									<div class="jyc-fsep"><?php echo esc_html__( '例外规则', 'jinyu-theme-companion' ); ?></div>
									<label class="jyc-fl"><?php echo esc_html__( '不缓存的路径', 'jinyu-theme-companion' ); ?>
										<textarea class="jyc-inp" name="page_cache_exclude_paths" rows="3" placeholder="/random&#10;/go/&#10;/member/*"><?php echo esc_textarea( $page_cache_exclude_paths ); ?></textarea>
										<span class="jyc-muted" style="font-weight:400"><?php echo esc_html__( '每行一条 URI 路径，命中即不读也不写缓存。支持目录前缀（/go 命中 /go/123）与 * 通配；# 开头为注释。', 'jinyu-theme-companion' ); ?></span>
									</label>
									<div class="jyc-fpair">
										<label class="jyc-fl"><?php echo esc_html__( '忽略的参数（提升命中率）', 'jinyu-theme-companion' ); ?>
											<textarea class="jyc-inp" name="page_cache_ignore_params" rows="2" placeholder="utm_source, utm_medium, gclid"><?php echo esc_textarea( $page_cache_ignore_params ); ?></textarea>
											<span class="jyc-muted" style="font-weight:400"><?php echo esc_html__( '不参与缓存 key：同一页面的不同推广参数共用一份缓存。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '不缓存的参数', 'jinyu-theme-companion' ); ?>
											<textarea class="jyc-inp" name="page_cache_exclude_params" rows="2" placeholder="preview, preview_id"><?php echo esc_textarea( $page_cache_exclude_params ); ?></textarea>
											<span class="jyc-muted" style="font-weight:400"><?php echo esc_html__( '出现任一参数即跳过缓存，用于动态 / 个性化页面。', 'jinyu-theme-companion' ); ?></span>
										</label>
									</div>
								</div>
							</div>
							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7z"/></svg></span><?php echo esc_html__( '预取加速 (Speculation Rules)', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认关闭', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="speculation_enable" <?php checked( $speculation_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用 Speculation Rules', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( 'WP 6.8+ 原生：对站内链接预取 / 预渲染，降低跳转体感延迟。需 WordPress 6.8 及以上。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<label class="jyc-fl" style="margin-top:6px"><?php echo esc_html__( '模式', 'jinyu-theme-companion' ); ?>
										<select class="jyc-inp" name="speculation_mode">
											<option value="prefetch" <?php selected( $speculation_mode, 'prefetch' ); ?>><?php echo esc_html__( '预取 Prefetch（保守，推荐）', 'jinyu-theme-companion' ); ?></option>
											<option value="prerender" <?php selected( $speculation_mode, 'prerender' ); ?>><?php echo esc_html__( '预渲染 Prerender（更快，更耗资源）', 'jinyu-theme-companion' ); ?></option>
										</select>
									</label>
									<label class="jyc-fl" style="margin-top:6px"><?php echo esc_html__( '急切度', 'jinyu-theme-companion' ); ?>
										<select class="jyc-inp" name="speculation_eagerness">
											<option value="conservative" <?php selected( $speculation_eager, 'conservative' ); ?>><?php echo esc_html__( '保守 Conservative', 'jinyu-theme-companion' ); ?></option>
											<option value="moderate" <?php selected( $speculation_eager, 'moderate' ); ?>><?php echo esc_html__( '适中 Moderate', 'jinyu-theme-companion' ); ?></option>
											<option value="eager" <?php selected( $speculation_eager, 'eager' ); ?>><?php echo esc_html__( '激进 Eager', 'jinyu-theme-companion' ); ?></option>
										</select>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '控制预取 / 预渲染触发时机；保守最安全，激进体感最快。', 'jinyu-theme-companion' ); ?></span>
									</label>
								</div>
							</div>
							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/></svg></span><?php echo esc_html__( '数据库优化', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '一次性维护工具', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-test-row">
										<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-dbOptimize" data-loading="<?php echo esc_attr__( '优化中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycDbOptimize(this)"><?php echo esc_html__( '立即优化', 'jinyu-theme-companion' ); ?></button>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '清理文章修订版本、自动草稿、垃圾评论、孤立元数据与过期瞬态，并优化数据表；不影响正常文章 / 评论 / 用户。', 'jinyu-theme-companion' ); ?></span>
									</div>
									<div class="jyc-test-row" style="margin-top:10px">
										<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-autoloadScan" data-loading="<?php echo esc_attr__( '扫描中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycAutoloadScan(this)"><?php echo esc_html__( '扫描 Autoload 体积', 'jinyu-theme-companion' ); ?></button>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '找出超过 128KB 的自动加载选项并改为按需加载（autoload=no），降低每次请求的内存与冷启动开销。核心必需选项受保护，单个选项可随时恢复。', 'jinyu-theme-companion' ); ?></span>
									</div>
									<div id="jyc-autoloadResult" style="margin-top:10px" hidden></div>
								</div>
							</div>
							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span><?php echo esc_html__( '传输体检', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '服务器 / CDN 层', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div id="jyc-tpResult"><?php echo $tp_html; ?></div>
									<div class="jyc-test-row" style="margin-top:12px">
										<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-tpProbe" data-loading="<?php echo esc_attr__( '体检中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycTransportProbe(this)"><?php echo esc_html__( '立即体检', 'jinyu-theme-companion' ); ?></button>
										<span class="jyc-muted" style="font-size:12px" id="jyc-tpAgo"><?php echo '' !== $tp_ago ? esc_html( sprintf( /* translators: %s: relative time */ __( '上次 %s', 'jinyu-theme-companion' ), $tp_ago ) ) : ''; ?></span>
									</div>
									<div class="jyc-muted" style="font-size:12px;margin-top:10px"><?php echo esc_html__( '压缩、静态资源缓存、HTML 缓存头与 HTTP/3 都由服务器或 CDN 决定，站内配置改不到这一层。这里只做检测并指出该改哪一侧（nginx 配置或 CDN 控制台）；结果缓存 10 分钟，点一次才发两个请求。', 'jinyu-theme-companion' ); ?></div>
								</div>
							</div>
						</section>

						<!-- ===================== PERF CENTER（性能优化中心，自主题迁入） ===================== -->
						<section id="pane-perfcenter" class="jyc-pane<?php echo 'perfcenter' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head jyc-mod-flex">
								<div class="jyc-mod-title">
									<h1><?php echo esc_html__( '性能中心', 'jinyu-theme-companion' ); ?></h1>
									<?php jyc_perf_render_headside(); ?>
								</div>
								<div class="jyc-sub"><?php echo esc_html__( '服务器运行时运维：OPcache / Memcached 实时看板、可逆优化开关、多层缓存清理与一键优化。整页缓存等前台配置在「前台加速」。', 'jinyu-theme-companion' ); ?></div></div>
							<?php jyc_perf_render_pane(); ?>
						</section>

						<!-- ===================== COMMENT ===================== -->
						<section id="pane-comment" class="jyc-pane<?php echo 'comment' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '评论与互动', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '验证码触发策略、垃圾评论过滤与旧文自动关评。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span><?php echo esc_html__( '防护策略', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<div class="jyc-fl"><?php echo esc_html__( '验证码策略', 'jinyu-theme-companion' ); ?>
										<div class="jyc-seg" id="jyc-captchaSeg" role="radiogroup" aria-label="<?php echo esc_attr__( '验证码策略', 'jinyu-theme-companion' ); ?>">
											<button type="button" data-v="smart" class="<?php echo $captcha === 'smart' ? 'jyc-active' : ''; ?>"><?php echo esc_html__( '智能', 'jinyu-theme-companion' ); ?></button>
											<button type="button" data-v="always" class="<?php echo $captcha === 'always' ? 'jyc-active' : ''; ?>"><?php echo esc_html__( '始终启用', 'jinyu-theme-companion' ); ?></button>
											<button type="button" data-v="off" class="<?php echo $captcha === 'off' ? 'jyc-active' : ''; ?>"><?php echo esc_html__( '关闭', 'jinyu-theme-companion' ); ?></button>
										</div>
										<input type="hidden" name="captcha_policy" value="<?php echo esc_attr( $captcha ); ?>">
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '智能：失败过多时自动启用；始终：每次均验证；关闭：不验证。', 'jinyu-theme-companion' ); ?></span>
									</div>
									<label class="jyc-fl"><?php echo esc_html__( '垃圾评论关键词', 'jinyu-theme-companion' ); ?>
										<textarea class="jyc-inp" name="anti_spam_words" rows="2" placeholder="逗号分隔"><?php echo esc_textarea( $spam_words ); ?></textarea>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '逗号分隔，命中即判为垃圾评论。', 'jinyu-theme-companion' ); ?></span>
									</label>
									<div class="jyc-frow">
									<label class="jyc-switch"><input type="checkbox" name="comment_freq_enable" <?php checked( $freq_enable, '1' ); ?>><span class="jyc-track"></span></label>
									<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '评论频率限制', 'jinyu-theme-companion' ); ?></div>
										<div class="jyc-fdesc"><?php echo esc_html__( '同一 IP 在设定时间内评论超过上限即判为垃圾，防刷屏。', 'jinyu-theme-companion' ); ?></div></div>
									<label class="jyc-fl jyc-fnum"><?php echo esc_html__( '分钟', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-num" type="number" name="comment_freq_window" value="<?php echo esc_attr( $freq_window ); ?>" min="1" step="1">
									</label>
									<label class="jyc-fl jyc-fnum"><?php echo esc_html__( '条', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-num" type="number" name="comment_freq_max" value="<?php echo esc_attr( $freq_max ); ?>" min="1" step="1">
									</label>
								</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="close_comments_old" <?php checked( $close_old, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '自动关闭旧文评论', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '超过设定天数后自动关闭评论，减少垃圾评论入口。', 'jinyu-theme-companion' ); ?></div></div>
										<label class="jyc-fl jyc-fnum"><?php echo esc_html__( '天数', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="close_comments_days" value="<?php echo esc_attr( $close_days ); ?>" min="0" step="1">
										</label>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg></span><?php echo esc_html__( '评论邮件通知', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '经 SMTP 通道发信，可分别开关', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="comment_notify_reply" <?php checked( $notify_reply, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '回复通知', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '有人回复评论时，邮件通知父评论作者。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="comment_notify_author" <?php checked( $notify_author, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '作者通知', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '文章有新评论时，邮件通知文章作者。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="comment_notify_blocked" <?php checked( $notify_blocked, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '拦截提醒', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '评论被判为垃圾或待审核时，邮件提醒站长处理（15 分钟内合并为一封）。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="comment_notify_approved" <?php checked( $notify_approved, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '审核通过通知', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '评论由待审 / 垃圾转为已通过时，邮件通知评论者。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
								</div>
							</div>

							<div class="jyc-panel jyc-span">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></span><?php echo esc_html__( '历史垃圾评论清理', 'jinyu-theme-companion' ); ?></h2><div class="jyc-ph-right"><span class="jyc-hint"><?php echo esc_html__( '扫描 + 人工确认后删除，可先备份', 'jinyu-theme-companion' ); ?></span></div></div>
								<div class="jyc-panel-b">
									<div class="jyc-muted" style="font-size:12px;margin-bottom:10px"><?php echo esc_html__( '对已批准评论做加权评分（外链数、垃圾词、模板灌水、重复内容、同邮箱多评等），按风险降序展示疑似项；注册用户与可信邮箱自动排除。勾选确认后再删除，支持先备份到独立表。', 'jinyu-theme-companion' ); ?></div>
									<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
										<button type="button" class="jyc-btn jyc-btn-primary" data-loading="<?php echo esc_attr__( '扫描中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycCcScan(this)"><?php echo esc_html__( '扫描疑似垃圾评论', 'jinyu-theme-companion' ); ?></button>
										<label class="jyc-switch"><input type="checkbox" id="jyc-ccBackup" checked><span class="jyc-track"></span></label>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '删除前备份到 wp_comments_cleanup_bak', 'jinyu-theme-companion' ); ?></span>
									</div>
									<div id="jyc-ccResult" hidden style="margin-top:14px"></div>
								</div>
							</div>
</section>

						<!-- ===================== SMTP ===================== -->
						<section id="pane-smtp" class="jyc-pane<?php echo 'smtp' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '邮件 SMTP', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '配置站点发信通道。密码留空表示保留已保存值，不会在保存时被清空。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg></span><?php echo esc_html__( 'SMTP 服务', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '推荐使用 SSL/TLS', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-smtp-grid">
										<label class="jyc-fl"><?php echo esc_html__( 'SMTP 主机', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="smtp_host" value="<?php echo esc_attr( $smtp_host ); ?>" placeholder="smtp.example.com">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '端口', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="smtp_port" value="<?php echo esc_attr( $smtp_port ); ?>" min="0" step="1">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '加密方式', 'jinyu-theme-companion' ); ?>
											<select class="jyc-inp" name="smtp_secure">
												<option value="ssl" <?php selected( $smtp_secure, 'ssl' ); ?>>SSL</option>
												<option value="tls" <?php selected( $smtp_secure, 'tls' ); ?>>TLS</option>
												<option value="none" <?php selected( $smtp_secure, 'none' ); ?>><?php echo esc_html__( '不加密', 'jinyu-theme-companion' ); ?></option>
											</select>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '用户名', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="text" name="smtp_user" value="<?php echo esc_attr( $smtp_user ); ?>" placeholder="user@example.com">
										</label>
										<label class="jyc-fl jyc-full"><?php echo esc_html__( '密码', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="password" name="smtp_pwd" value="" autocomplete="new-password" placeholder="<?php echo esc_attr__( '留空则不修改', 'jinyu-theme-companion' ); ?>">
										</label>
										<label class="jyc-fl jyc-full"><?php echo esc_html__( '发件地址', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="email" name="smtp_from" value="<?php echo esc_attr( $smtp_from ); ?>" placeholder="no-reply@example.com">
										</label>
										<label class="jyc-fl jyc-full"><?php echo esc_html__( '发件人名称', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="text" name="smtp_from_name" value="<?php echo esc_attr( $smtp_from_name ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '收件箱里显示的署名，留空使用站点名称。', 'jinyu-theme-companion' ); ?></span>
										</label>
									</div>
									<div class="jyc-test-row">
										<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-testSmtp" data-loading="<?php echo esc_attr__( '发送中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycTestSmtp(this)"><?php echo esc_html__( '发送测试邮件', 'jinyu-theme-companion' ); ?></button>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '收件人为当前登录邮箱；可先于「保存」直接测试刚填的配置。', 'jinyu-theme-companion' ); ?></span>
									</div>
								</div>
							</div>
						</section>

						<!-- ===================== STORAGE ===================== -->
						<section id="pane-storage" class="jyc-pane<?php echo 'storage' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '对象存储 (CDN)', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '附件自动上传云端，前台图片经加速域名分发。配置存于插件独立选项，不受主题导入 / 重置影响。Secret 留空表示保留已保存值。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/></svg></span><?php echo esc_html__( '存储服务', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( 'Secret 加密入库', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<label class="jyc-fl"><?php echo esc_html__( '加速域名 (CDN)', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-inp-mono" type="url" name="storage_domain" value="<?php echo esc_attr( $storage_domain ); ?>" placeholder="https://cdn.example.com">
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '前台附件 URL 重写为「加速域名 + 前缀 + 相对路径」；留空回退本地 uploads。右侧按钮可即时切换 / 复原。', 'jinyu-theme-companion' ); ?></span>
										<?php if ( $storage_domain && ! jinyu_storage_rewrite_active() ) : ?>
										<span class="jyc-muted" style="font-size:12px;color:#d23f3f"><?php echo esc_html__( '当前已暂停 URL 重写（此前点过「复原为本地链接」），域名配置已保留；点「一键替换为 CDN 链接」即可恢复。', 'jinyu-theme-companion' ); ?></span>
										<?php endif; ?>
									</label>
									<div class="jyc-smtp-grid">
										<label class="jyc-fl"><?php echo esc_html__( '服务商', 'jinyu-theme-companion' ); ?>
											<select class="jyc-inp" name="storage_provider">
												<option value="" <?php selected( $storage_provider, '' ); ?>><?php echo esc_html__( '关闭（图片走本地）', 'jinyu-theme-companion' ); ?></option>
												<option value="upyun" <?php selected( $storage_provider, 'upyun' ); ?>><?php echo esc_html__( '又拍云', 'jinyu-theme-companion' ); ?></option>
												<option value="s3" <?php selected( $storage_provider, 's3' ); ?>><?php echo esc_html__( 'S3 兼容（阿里云 OSS / 腾讯云 COS / 七牛 / 华为 OBS）', 'jinyu-theme-companion' ); ?></option>
											</select>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '存储桶 (Bucket)', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="storage_bucket" value="<?php echo esc_attr( $storage_bucket ); ?>" placeholder="my-bucket">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'AccessKey / 操作员账号', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="storage_access_key" value="<?php echo esc_attr( $storage_access_key ); ?>" autocomplete="off">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'Secret / 操作员密码', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="password" name="storage_secret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $storage_has_secret ? __( '已保存（留空则不修改）', 'jinyu-theme-companion' ) : __( '留空则不修改', 'jinyu-theme-companion' ) ); ?>">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '地域 (Region，S3 兼容用)', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="storage_region" value="<?php echo esc_attr( $storage_region ); ?>" placeholder="ap-shanghai / oss-cn-hangzhou">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'Endpoint（S3 兼容用）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="storage_endpoint" value="<?php echo esc_attr( $storage_endpoint ); ?>" placeholder="oss-cn-hangzhou.aliyuncs.com">
										</label>
										<label class="jyc-fl jyc-full"><?php echo esc_html__( '远程路径前缀', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="storage_prefix" value="<?php echo esc_attr( $storage_prefix ); ?>" placeholder="wp-content/">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '远端 key = 前缀 + uploads 相对路径；加速域名与桶内文件按此一一对应。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl jyc-full"><?php echo esc_html__( '排除目录（不同步）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="storage_exclude_dirs" value="<?php echo esc_attr( $storage_exclude_dirs ); ?>" placeholder="cache, tmp">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '按目录名排除，使用英文逗号（,）分隔（中文逗号「，」亦可）；命中的目录及其全部子文件不会被同步（如 cache）。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl jyc-full"><?php echo esc_html__( '排除文件后缀（不同步）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="text" name="storage_exclude_exts" value="<?php echo esc_attr( $storage_exclude_exts ); ?>" placeholder="pdf, docx">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '额外排除的后缀（不含点），使用英文逗号（,）分隔；叠加在默认安全黑名单之上，优先级最高。', 'jinyu-theme-companion' ); ?></span>
										</label>
									</div>
							</div>
						</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7z"/></svg></span><?php echo esc_html__( '自动化与快捷操作', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '开关随表单保存生效', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="storage_auto_upload" <?php checked( $storage_auto, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '新附件自动同步云端', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '上传媒体时自动把原图与全部缩略尺寸推送到存储桶。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="storage_delete_local" <?php checked( $storage_del, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '推送成功后删除本地原件', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '节省本地磁盘；请确认桶内文件完整后再开启。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="storage_sync_extra" <?php checked( $storage_sync_extra, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '同步文档 / 音视频 / 压缩包等非媒体静态资源', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '默认仅同步图片、字体、CSS/JS。勾选后额外同步音视频、办公文档、压缩包、数据文件等。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-actions">
										<div class="jyc-opcard">
											<div class="jyc-opcard-h"><span class="jyc-opcard-t"><?php echo esc_html__( '连通性自检', 'jinyu-theme-companion' ); ?></span></div>
											<div class="jyc-opcard-b">
												<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-testStorage" data-loading="<?php echo esc_attr__( '测试中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycTestStorage(this)"><?php echo esc_html__( '测试存储连接', 'jinyu-theme-companion' ); ?></button>
											</div>
											<div class="jyc-opcard-d"><?php echo esc_html__( '上传并删除一个测试文件验证连通性；可直接测试刚填写、尚未保存的配置。', 'jinyu-theme-companion' ); ?></div>
										</div>
										<div class="jyc-opcard">
											<div class="jyc-opcard-h">
												<span class="jyc-opcard-t"><?php echo esc_html__( '链接切换', 'jinyu-theme-companion' ); ?></span>
												<?php $rw_on = function_exists( 'jinyu_storage_rewrite_active' ) ? jinyu_storage_rewrite_active() : true; ?>
												<span class="jyc-opstate <?php echo $rw_on ? 'is-on' : 'is-off'; ?>" id="jycDomainState"><?php echo $rw_on ? esc_html__( 'CDN 加速中', 'jinyu-theme-companion' ) : esc_html__( '本地直连', 'jinyu-theme-companion' ); ?></span>
											</div>
											<div class="jyc-opcard-b">
												<button class="jyc-btn <?php echo $rw_on ? 'jyc-btn-ghost' : 'jyc-btn-primary'; ?>" type="button" id="jycDomainToggle" data-action="<?php echo $rw_on ? 'jinyu_storage_unapply_domain' : 'jinyu_storage_apply_domain'; ?>" onclick="window.jycStorageDomain(this)"><?php echo $rw_on ? esc_html__( '复原为本地链接', 'jinyu-theme-companion' ) : esc_html__( '一键替换为 CDN 链接', 'jinyu-theme-companion' ); ?></button>
											</div>
											<div class="jyc-opcard-d"><?php echo esc_html__( '按「加速域名」当前填写值一键切换全站附件链接（含清缓存）；按钮随状态自动变换，点击即反向切换，域名配置保留。', 'jinyu-theme-companion' ); ?></div>
										</div>
										<div class="jyc-opcard">
											<div class="jyc-opcard-h"><span class="jyc-opcard-t"><?php echo esc_html__( '批量传输', 'jinyu-theme-companion' ); ?></span></div>
											<div class="jyc-opcard-b">
												<button class="jyc-btn jyc-btn-ghost jyc-batch-btn" type="button" data-batch="push" data-label="<?php echo esc_attr__( '全量上传到云端', 'jinyu-theme-companion' ); ?>" title="<?php echo esc_attr__( '首次迁移：把整个本地图库一次性搬上云。日常新增附件会自动同步，不建议频繁点击。', 'jinyu-theme-companion' ); ?>" onclick="window.jycBatchAction(this,'push')"><?php echo esc_html__( '全量上传到云端', 'jinyu-theme-companion' ); ?></button>
												<button class="jyc-btn jyc-btn-ghost jyc-batch-btn" type="button" data-batch="pull" data-label="<?php echo esc_attr__( '拉回本地', 'jinyu-theme-companion' ); ?>" onclick="window.jycBatchAction(this,'pull')"><?php echo esc_html__( '拉回本地', 'jinyu-theme-companion' ); ?></button>
											</div>
											<div id="jyc-batchProg" hidden>
												<div class="jyc-pbar"><i id="jyc-batchFill" style="width:0%"></i></div>
												<span class="jyc-muted jyc-num" id="jyc-batchMsg" style="display:block;margin-top:2px;font-size:12.5px"></span>
											</div>
											<div class="jyc-opcard-d"><?php echo esc_html__( '「全量上传到云端」用于首次把已有本地图库整体迁移上云（一次性）；日常新增附件已自动同步，个别文件可用「同步指定资源」。整库互传自动分批续跑，关闭页面再进入自动接着跑，运行中按钮变「停止任务」。', 'jinyu-theme-companion' ); ?></div>
										</div>
										<div class="jyc-opcard">
											<div class="jyc-opcard-h"><span class="jyc-opcard-t"><?php echo esc_html__( '同步指定资源', 'jinyu-theme-companion' ); ?></span></div>
											<textarea id="jyc-syncPaths" class="jyc-inp jyc-inp-mono jyc-opcard-full" rows="2" placeholder="<?php echo esc_attr__( 'uploads 相对路径或本站图片 URL，每行一个，如 2026/09/photo.webp', 'jinyu-theme-companion' ); ?>"></textarea>
											<div class="jyc-opcard-b">
												<button class="jyc-btn jyc-btn-soft jyc-sync-btn" type="button" data-batch="sync" data-label="<?php echo esc_attr__( '同步所选资源', 'jinyu-theme-companion' ); ?>" onclick="window.jycBatchAction(this,'sync')"><?php echo esc_html__( '同步所选资源', 'jinyu-theme-companion' ); ?></button>
												<span class="jyc-opcard-d" style="flex:1;min-width:140px"><?php echo esc_html__( '并发推上云端（16 线程），可中断续跑；若开启「推送后删本地原件」会同步删除。', 'jinyu-theme-companion' ); ?></span>
											</div>
										</div>
									</div>
								</div>
							</div>
						</section>

						<!-- ===================== SOCIAL LOGIN（第三方登录，自私有插件迁入） ===================== -->
						<section id="pane-social" class="jyc-pane<?php echo 'social' === $active_pane ? ' jyc-shown' : ''; ?>">
							<?php jinyu_sl_settings_pane(); ?>
						</section>

						<!-- ===================== 微信 JS-SDK 分享 ===================== -->
						<section id="pane-wechat" class="jyc-pane<?php echo 'wechat' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '微信分享', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '接入微信 JS-SDK，让文章 / 页面在微信内转发给好友、分享到朋友圈时显示自定义标题、描述与缩略图。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 13a4.5 4.5 0 0 1-4.5-4.5A4.5 4.5 0 0 1 8 4c2 0 3.7 1.2 4.4 3"/><path d="M16 19a4 4 0 0 1-4-4 4 4 0 0 1 4-4 4 4 0 0 1 4 4v.5a3 3 0 0 1-3 3h-.5a2 2 0 0 0-1.5.7L13 21l2-2.5c.5.1 1 .4 1.6.4z"/></svg></span><?php echo esc_html__( '分享配置', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( 'AppSecret 加密存储', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="wechat_share_enable" <?php checked( $wechat_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用微信分享', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '开启后前台注入 wx.config 与分享数据；需认证服务号，个人订阅号调用会 config:fail。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<label class="jyc-fl"><?php echo esc_html__( '公众号 AppID', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-inp-mono" type="text" name="wechat_appid" value="<?php echo esc_attr( $wechat_appid ); ?>" placeholder="wx1234567890abcdef" autocomplete="off">
									</label>
									<label class="jyc-fl"><?php echo esc_html__( '公众号 AppSecret', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-inp-mono" type="password" name="wechat_appsecret" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $wechat_has_secret ? __( '已保存（留空则不修改）', 'jinyu-theme-companion' ) : __( '留空则不修改', 'jinyu-theme-companion' ) ); ?>">
									</label>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="wechat_share_debug" <?php checked( $wechat_debug, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '调试模式', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '开启后微信内分享时弹窗显示 config 结果，便于排查签名 / 域名问题；正式上线请关闭。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg></span><?php echo esc_html__( '接入前置条件', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<ul class="jyc-note-list">
										<li><?php echo esc_html__( '账号：必须是「已微信认证的服务号」（个人订阅号无分享接口）。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( 'JS 接口安全域名：在公众号后台填 qicaiyun.top（仅主域，不带 http/www），并上传验证文件到站点根目录。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( 'IP 白名单：在公众号后台「基本配置」加入服务器出口 IP 175.24.138.28，否则获取 access_token 报 40164。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( '分享图：直接复用「分享素材」里的 og:image（1200×630），微信好友卡会中心裁成方形缩略图。', 'jinyu-theme-companion' ); ?></li>
									</ul>
								</div>
							</div>
						</section>

					</main>
				</div>

				<!-- Toast 必须在 .jyc-app 作用域内：配色全部依赖 .jyc-app 上的 CSS 变量，
				     放外面变量失效 → 白底/绿条/绿勾全丢，退化成透明底黑勾（实测踩坑）。 -->
				<div class="jyc-toast jyc-glass<?php echo jinyu_companion_settings_saved() ? ' jyc-show' : ''; ?>" id="jyc-toast" role="status" aria-live="polite">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
					<span id="jyc-toastMsg"><?php echo esc_html__( '设置已保存', 'jinyu-theme-companion' ); ?></span>
				</div>
			</div>
		</form>
	</div>

	<?php
}

// 仅在配套插件设置页挂载面板样式与脚本（屏幕 ID：toplevel_page_jinyu-theme-companion）。
add_action( 'admin_enqueue_scripts', 'jinyu_companion_admin_assets' );
function jinyu_companion_admin_assets( string $hook ): void {
	if ( 'toplevel_page_jinyu-theme-companion' !== $hook ) {
		return;
	}
	$root = dirname( dirname( dirname( __FILE__ ) ) ); // .../wp-content/plugins/jinyu-theme-companion
	$main = $root . '/jinyu-theme-companion.php';       // plugins_url 第 2 参数须为插件根下真实文件（目录会被多剥一层）
	$fallback = defined( 'JINYU_CUR_VER' ) ? JINYU_CUR_VER : '1.0.1';
	// 版本号用文件 mtime：改动 admin.css / admin.js 后自动失效浏览器缓存，避免用户仍看到旧样式/旧脚本。
	$css_file = $root . '/assets/admin.css';
	$js_file  = $root . '/assets/admin.js';
	$vc = file_exists( $css_file ) ? (string) filemtime( $css_file ) : $fallback;
	$vj = file_exists( $js_file ) ? (string) filemtime( $js_file ) : $fallback;
	wp_enqueue_style( 'jinyu-companion-admin', plugins_url( 'assets/admin.css', $main ), array(), $vc );
	wp_enqueue_script( 'jinyu-companion-admin', plugins_url( 'assets/admin.js', $main ), array(), $vj, true );
	// 分享素材「从媒体库选择」需要 WP 媒体弹窗（仅本页需要，不全局加载）。
	wp_enqueue_media();
}
