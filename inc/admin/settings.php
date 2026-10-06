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

	// 导入分支：复用本表单的提交与 nonce，但完全走另一条数据通道（文件 / 粘贴 JSON）。
	// 处理完直接返回，绝不落「整表保存」——避免把导入前的表单字段一起写进 options。
	if ( isset( $_POST['jinyu_import'] ) ) {
		jinyu_companion_handle_import();
		// 导入后停在「配置备份」分区，让结果提示与用户视线重合。
		jinyu_companion_active_pane( 'io' );
		return;
	}

	jinyu_companion_apply_saved_settings();
}

/**
 * 落库主体：把 $_POST 按白名单 sanitize 后写入 jinyu_companion_settings，并同步第三方登录选项。
 * 抽成独立函数供两条通道复用：整页 POST 与悬浮保存的 admin-ajax 端点。
 * 全站只有这一份 sanitize 实现，不存在两套逻辑漂移的可能。
 *
 * @return void
 */
function jinyu_companion_apply_saved_settings(): void {
	// nonce 与权限由两条调用通道各自验证（整页 POST 走 check_admin_referer，admin-ajax 走
	// wp_verify_nonce + wp_send_json），本函数不可重复验证，否则会破坏 AJAX 通道的 JSON 错误响应。
	// phpcs 无法跨函数追踪调用方，且 (int) 强转 / 自定义白名单闭包 / 加密入口不在其 sanitize 白名单内，
	// 故此处对这两类误报做函数级豁免；函数内所有 $_POST 均经 sanitize / 强转 / 白名单处理。
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$settings = get_option( 'jinyu_companion_settings', [] );
	if ( ! is_array( $settings ) ) {
		$settings = [];
	}

	// 布尔开关：勾选存 '1'，未勾存 '0'
	foreach ( [ 'seo_open', 'seo_content_h1_fix', 'twitter_card_enable', 'og_article_meta', 'llms_enable', 'auto_link_enable', 'indexnow_enable', 'close_comments_old', 'page_cache_enable', 'speculation_enable', 'img_alt_enable', 'img_dim_enable', 'ld_json_enable', 'no_category_enable', 'sitemap_enable', 'seo_keywords_enable', 'storage_auto_upload', 'storage_delete_local', 'storage_sync_extra', 'comment_notify_reply', 'comment_notify_blocked', 'comment_notify_approved', 'comment_freq_enable', 'wechat_share_enable', 'wechat_share_debug' ] as $k ) {
		$settings[ $k ] = isset( $_POST[ $k ] ) ? '1' : '0';
	}

	// 去除 /category/ 前缀开关状态变化时需刷新重写规则（开启/关闭都要 flush 一次才能生效/还原）
	$no_cat_changed = jinyu_companion_is_checked( 'no_category_enable', false ) !== ( '1' === ( $settings['no_category_enable'] ?? '' ) );

	// 文本 / URL / 颜色
	$settings['og_image']        = isset( $_POST['og_image'] ) ? esc_url_raw( wp_unslash( $_POST['og_image'] ) ) : '';
	$settings['og_site_name']    = isset( $_POST['og_site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['og_site_name'] ) ) : '';
	$settings['og_image_alt']    = isset( $_POST['og_image_alt'] ) ? sanitize_text_field( wp_unslash( $_POST['og_image_alt'] ) ) : '';
	// Twitter 账号名：去 @ 与空白，只留安全字符
	$settings['twitter_site'] = isset( $_POST['twitter_site'] ) ? ltrim( sanitize_text_field( wp_unslash( $_POST['twitter_site'] ) ), '@' ) : '';
	// 对象存储同步排除项：逗号 / 换行 / 空格分隔的目录名或文件后缀（不含点）
	$settings['storage_exclude_dirs'] = isset( $_POST['storage_exclude_dirs'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_exclude_dirs'] ) ) : '';
	$settings['storage_exclude_exts'] = isset( $_POST['storage_exclude_exts'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_exclude_exts'] ) ) : '';
	$settings['twitter_creator'] = isset( $_POST['twitter_creator'] ) ? ltrim( sanitize_text_field( wp_unslash( $_POST['twitter_creator'] ) ), '@' ) : '';
	// Facebook App ID：纯数字（非数字直接丢弃，避免脏数据进 meta）
	$settings['fb_app_id'] = isset( $_POST['fb_app_id'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['fb_app_id'] ) ) : '';
	// 微信分享：AppID 仅安全字符；AppSecret 留空保留原值，非空则加密入库（jinyu_enc2::）。
	$settings['wechat_appid'] = isset( $_POST['wechat_appid'] ) ? sanitize_text_field( wp_unslash( $_POST['wechat_appid'] ) ) : '';
	if ( isset( $_POST['wechat_appsecret'] ) && '' !== (string) wp_unslash( $_POST['wechat_appsecret'] ) ) {
		$settings['wechat_appsecret'] = jinyu_companion_encrypt( sanitize_text_field( wp_unslash( $_POST['wechat_appsecret'] ) ) );
	}
	// 全站 SEO 默认值（覆盖 tagline / 作为文章 / 分类兜底前的基准）
	$settings['seo_keywords']    = isset( $_POST['seo_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_keywords'] ) ) : '';
	$settings['seo_desc']        = isset( $_POST['seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ) ) : '';
	// 结构化数据：组织 / 品牌 sameAs 链接（每行一个 URL）
	$settings['entity_sameas'] = isset( $_POST['entity_sameas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['entity_sameas'] ) ) : '';
	// 组织 Logo：留空则依次回退主题自定义 Logo、站点图标
	$settings['org_logo_url'] = isset( $_POST['org_logo_url'] ) ? esc_url_raw( wp_unslash( $_POST['org_logo_url'] ) ) : '';
	// 作者身份档案：作者本人的站外主页，每行一个（同域 URL 会被自动剔除）
	$settings['author_sameas']   = isset( $_POST['author_sameas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['author_sameas'] ) ) : '';
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
	// 边缘缓存（Edge Mode）：模式 / 服务器 / 缓存目录
	$settings['page_cache_mode'] = isset( $_POST['page_cache_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['page_cache_mode'] ) ) : 'simple';
	$settings['page_cache_mode'] = in_array( $settings['page_cache_mode'], [ 'simple', 'edge' ], true ) ? $settings['page_cache_mode'] : 'simple';
	$settings['page_cache_edge_server'] = isset( $_POST['page_cache_edge_server'] ) ? sanitize_text_field( wp_unslash( $_POST['page_cache_edge_server'] ) ) : 'auto';
	$settings['page_cache_edge_server'] = in_array( $settings['page_cache_edge_server'], [ 'auto', 'nginx', 'apache' ], true ) ? $settings['page_cache_edge_server'] : 'auto';
	$settings['page_cache_edge_path'] = isset( $_POST['page_cache_edge_path'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['page_cache_edge_path'] ) ) ) : '';
	// 自动内链：单篇总链接数上限（1-20，默认 5）
	$settings['auto_link_limit'] = isset( $_POST['auto_link_limit'] ) ? max( 1, min( 20, (int) wp_unslash( $_POST['auto_link_limit'] ) ) ) : 5;
	// 浏览量冷却已写死为常量（见 theme-compat.php），不再作为用户设置；清掉历史残留键。
	unset( $settings['views_wait_seconds'] );

	// 站点验证元标签（仅保留安全字符，去除可能的注入内容）
	foreach ( [ 'verify_google', 'verify_bing', 'verify_baidu', 'verify_yandex', 'verify_360' ] as $vk ) {
		$settings[ $vk ] = isset( $_POST[ $vk ] ) ? sanitize_text_field( wp_unslash( $_POST[ $vk ] ) ) : '';
	}
	// Speculation Rules：模式（预取 / 预渲染）+ 急切度
	$settings['speculation_mode']     = jinyu_companion_post_enum( 'speculation_mode', [ 'prefetch', 'prerender' ], 'prefetch' );
	$settings['speculation_eagerness'] = jinyu_companion_post_enum( 'speculation_eagerness', [ 'conservative', 'moderate', 'eager' ], 'conservative' );

	// 站点地图排除文章 ID：只保留正整数字段（逗号 / 换行 / 空格分隔），其余字符丢弃。
	// 与页面 noindex 配套：noindex 只挡搜索结果展示，管不住 XML 站点地图，
	// 低质页一样会躺在 sitemap.xml 里。留空表示不额外排除。
	$settings['sitemap_exclude_ids'] = isset( $_POST['sitemap_exclude_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['sitemap_exclude_ids'] ) ) : '';

	// 验证码策略
	$settings['captcha_policy'] = jinyu_companion_post_enum( 'captcha_policy', [ 'smart', 'always', 'off' ], 'smart' );

	// SMTP
	$settings['smtp_host'] = isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '';
	// 端口最小 1：清空提交存 0 会让 PHPMailer Port=0，发信静默失败
	$settings['smtp_port']   = isset( $_POST['smtp_port'] ) ? max( 1, (int) wp_unslash( $_POST['smtp_port'] ) ) : 0;
	$settings['smtp_secure'] = jinyu_companion_post_enum( 'smtp_secure', [ 'ssl', 'tls', 'none' ], 'ssl' );
	$settings['smtp_user']   = isset( $_POST['smtp_user'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_user'] ) ) : '';
	$settings['smtp_from']   = isset( $_POST['smtp_from'] ) ? sanitize_email( wp_unslash( $_POST['smtp_from'] ) ) : '';
	// 是否接管全站发信。关闭时仅保留「发送测试邮件」用于验证通道，不影响 wp_mail 走默认 mail()。
	// 该键由迁移从主题 jinyu_options 的 smtp_enable 继承，缺省视作开启（填了 SMTP 就是要接管）。
	$settings['smtp_enable'] = isset( $_POST['smtp_enable'] ) ? '1' : '0';
	// 发件人名称：显示在收件箱的署名，留空回退站点名称
	$settings['smtp_from_name'] = isset( $_POST['smtp_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_from_name'] ) ) : '';
	// 密码留空则保留原值（避免保存时误清空）；非空则加密入库，与 storage_secret 同一策略
	// （jinyu_enc2:: AES-256-CBC + HMAC，读取侧 jinyu_companion_decrypt 对明文原样返回）。
	if ( isset( $_POST['smtp_pwd'] ) && '' !== (string) wp_unslash( $_POST['smtp_pwd'] ) ) {
		$settings['smtp_pwd'] = jinyu_companion_encrypt( sanitize_text_field( wp_unslash( $_POST['smtp_pwd'] ) ) );
	}

	// 对象存储（CDN）：配置存本插件独立选项，与主题设置完全隔离
	$settings['storage_provider'] = jinyu_companion_post_enum( 'storage_provider', [ '', 'upyun', 's3' ], '' );
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

	// 图片水印：全部字段集中处理，避免散落在各处漏 sanitize
	$settings['img_wm_enable']    = isset( $_POST['img_wm_enable'] ) ? '1' : '0';
	$settings['img_wm_on_upload'] = isset( $_POST['img_wm_on_upload'] ) ? '1' : '0';
	$settings['img_wm_text']      = isset( $_POST['img_wm_text'] ) ? sanitize_text_field( wp_unslash( $_POST['img_wm_text'] ) ) : '';
	$settings['img_wm_logo']      = isset( $_POST['img_wm_logo'] ) ? esc_url_raw( wp_unslash( $_POST['img_wm_logo'] ) ) : '';
	$settings['img_wm_size']      = isset( $_POST['img_wm_size'] ) ? min( 400, max( 8, (int) wp_unslash( $_POST['img_wm_size'] ) ) ) : 18;
	// 颜色交给 WP 核心校验（#rgb / #rrggbb），非法值 sanitize_hex_color 直接返回空串，回落到默认白
	$settings['img_wm_color']     = function_exists( 'sanitize_hex_color' )
		? ( sanitize_hex_color( wp_unslash( $_POST['img_wm_color'] ?? '#ffffff' ) ) ?: '#ffffff' )
		: '#ffffff';
	$settings['img_wm_margin']    = isset( $_POST['img_wm_margin'] ) ? min( 0.2, max( 0, (float) wp_unslash( $_POST['img_wm_margin'] ) ) ) : 0.02;
	$settings['img_wm_opacity']   = isset( $_POST['img_wm_opacity'] ) ? min( 100, max( 10, (int) wp_unslash( $_POST['img_wm_opacity'] ) ) ) : 60;
	$settings['img_wm_quality']   = isset( $_POST['img_wm_quality'] ) ? min( 100, max( 40, (int) wp_unslash( $_POST['img_wm_quality'] ) ) ) : 82;
	$settings['img_wm_min_w']     = isset( $_POST['img_wm_min_w'] ) ? min( 4000, max( 0, (int) wp_unslash( $_POST['img_wm_min_w'] ) ) ) : 400;
	// 面板按「百万像素 / MB」填，落库换算成引擎要的绝对量
	$settings['img_wm_max_px']    = isset( $_POST['img_wm_max_px'] ) ? min( 40, max( 1, (int) wp_unslash( $_POST['img_wm_max_px'] ) ) ) * 1000000 : 8000000;
	$settings['img_wm_max_bytes'] = isset( $_POST['img_wm_max_bytes'] ) ? min( 64, max( 1, (int) wp_unslash( $_POST['img_wm_max_bytes'] ) ) ) * 1048576 : 5242880;
	$wm_pos                       = isset( $_POST['img_wm_pos'] ) ? (int) wp_unslash( $_POST['img_wm_pos'] ) : 9;
	$settings['img_wm_pos']       = ( $wm_pos >= 1 && $wm_pos <= 9 ) ? $wm_pos : 9;
	$wm_cc                        = isset( $_POST['img_wm_concurrency'] ) ? (int) wp_unslash( $_POST['img_wm_concurrency'] ) : 1;
	$settings['img_wm_concurrency'] = in_array( $wm_cc, array( 1, 2, 4, 8 ), true ) ? $wm_cc : 1;
	// 尺寸多选：full 恒驻（原图必要），其余按勾选存
	$wm_sizes = array( 'full' );
	foreach ( array( 'thumbnail', 'medium', 'medium_large', 'large' ) as $s ) {
		if ( isset( $_POST[ 'img_wm_size_' . $s ] ) ) {
			$wm_sizes[] = $s;
		}
	}
	$settings['img_wm_sizes'] = array_values( array_unique( $wm_sizes ) );

	// 唯一写入口：落库 + 同步刷新请求内缓存，保证本次渲染立刻读到新值。
	jinyu_companion_save_settings( $settings );
	jinyu_companion_settings_saved( true );
	// 记录提交时所在分区，保存后停留原页（默认概览）。
	if ( isset( $_POST['jinyu_active_pane'] ) ) {
		jinyu_companion_active_pane( sanitize_key( wp_unslash( $_POST['jinyu_active_pane'] ) ) );
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
	// phpcs:enable
}

/*
--------------------------------------------------------------------------
 * 悬浮保存通道（脏检测浮条调用）
 *
 * 与上方的整页 POST 共用同一落库函数，区别只在「是否整页刷新」：本端点返回 JSON，
 * 前端保留滚动位置与全部 JS 状态。任何一侧的 sanitize 改动都同时生效，不存在两套实现。
 *
 * 注意：上方 jinyu_companion_handle_save() 首行的 wp_doing_ajax() 早退必须保持原样 ——
 * 它拦的是「测试连接 / 测试邮件 / 数据库优化等按钮携带 jinyu_companion_save=1 的整表单请求」，
 * 本端点靠专属标志位 jinyu_companion_ajax 与之区分，故它不受该早退影响。
 * ------------------------------------------------------------------------ */
add_action( 'wp_ajax_jinyu_companion_save', 'jinyu_companion_ajax_save' );
function jinyu_companion_ajax_save(): void {
	// 全部失败分支也返回 HTTP 200 + success:false —— 服务器 nginx 的 error_page 会拦截 admin-ajax 的
	// 4xx 响应体并换成 HTML 错误页，前端拿到的就不是 JSON 了：既看不到真实原因，也会被误判成网络故障。
	// 业务失败用 success 字段表达，HTTP 状态码只表示「请求是否抵达 PHP」。
	//
	// 专属标志位：表单里已有的 jinyu_companion_save=1 会随「立即优化」「发送测试邮件」等
	// admin-ajax 请求一起提交，单独用它当开关会把「测试」当成「保存」。
	if ( empty( $_POST['jinyu_companion_ajax'] ) ) {
		wp_send_json(
            [
				'success' => false,
				'msg' => __( '请求来源非法', 'jinyu-theme-companion' ),
			]
        );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json(
            [
				'success' => false,
				'msg' => __( '权限不足', 'jinyu-theme-companion' ),
			]
        );
	}
	// 用 wp_verify_nonce 而非 check_admin_referer：后者校验失败会输出 wp_nonce_ays() 的完整拒绝页。
	if ( empty( $_POST['jinyu_companion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jinyu_companion_nonce'] ) ), 'jinyu_companion_settings' ) ) {
		wp_send_json(
            [
				'success' => false,
				'msg' => __( '安全校验失败，请刷新页面后重试', 'jinyu-theme-companion' ),
			]
        );
	}

	jinyu_companion_apply_saved_settings();

	wp_send_json(
        [
			'success' => true,
			'msg' => __( '设置已保存', 'jinyu-theme-companion' ),
		]
    );
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
		return [ 'seo', 'content', 'perf', 'perfcenter', 'comment', 'smtp', 'storage', 'social', 'wechat', 'io', 'media' ];
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
		} elseif ( isset( $_GET['pane'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 仅读取面板路由参数（sanitize_key + 白名单校验），无任何写操作
			// GET 优先于默认：刷新 / 书签直达时保持当前分区，避免每次刷新跳回概览。
			$g = sanitize_key( wp_unslash( $_GET['pane'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 同上：路由参数，已 sanitize + 白名单
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
	$org_logo_url   = jinyu_companion_get_option( 'org_logo_url', '' );
	$author_sameas  = jinyu_companion_get_option( 'author_sameas', '' );
	$auto_link    = jinyu_companion_get_option( 'auto_link_enable', '0' );
	$indexnow     = jinyu_companion_get_option( 'indexnow_enable', '0' );
	$baidu_token  = jinyu_companion_get_option( 'baidu_submit_token', '' );
	$captcha      = jinyu_companion_get_option( 'captcha_policy', 'smart' );
	$spam_words   = jinyu_companion_get_option( 'anti_spam_words', '彩票,色情,赌博,代写,刷量' );
	$close_old    = jinyu_companion_get_option( 'close_comments_old', '0' );
	$close_days   = jinyu_companion_get_option( 'close_comments_days', 30 );
	$freq_enable  = jinyu_companion_get_option( 'comment_freq_enable', '1' );
	$freq_window  = jinyu_companion_get_option( 'comment_freq_window', 10 );
	$freq_max     = jinyu_companion_get_option( 'comment_freq_max', 5 );
	$poster_color = jinyu_companion_get_option( 'style_color_primary', '#FF6B35' );
	$smtp_enable  = jinyu_companion_is_checked( 'smtp_enable', true ) ? '1' : '0';
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
	$page_cache_mode          = jinyu_companion_get_option( 'page_cache_mode', 'simple' );
	$page_cache_edge_server   = jinyu_companion_get_option( 'page_cache_edge_server', 'auto' );
	$page_cache_edge_path     = jinyu_companion_get_option( 'page_cache_edge_path', '' );
	// HTTP 传输体检：只读已缓存结果，页面加载绝不发请求（未体检过就显示空态，等用户点击）。
	$tp_data = function_exists( 'jinyu_transport_cached' ) ? jinyu_transport_cached() : array(
		'rows' => array(),
		'at' => 0,
		'error' => '',
	);
	$tp_html = function_exists( 'jinyu_transport_render_rows' ) ? jinyu_transport_render_rows( $tp_data ) : '';
	$tp_ago  = ( ! empty( $tp_data['rows'] ) && function_exists( 'jinyu_transport_ago' ) ) ? jinyu_transport_ago( (int) $tp_data['at'] ) : '';

	// 图片水印：取值带默认值；引擎能力探测做 function_exists 守卫，面板可脱离引擎单独渲染。
	$wm_enable    = jinyu_companion_is_checked( 'img_wm_enable', false ) ? '1' : '0';
	$wm_on_upload = jinyu_companion_is_checked( 'img_wm_on_upload', true ) ? '1' : '0';
	$wm_text      = (string) jinyu_companion_get_option( 'img_wm_text', '' );
	$wm_logo      = (string) jinyu_companion_get_option( 'img_wm_logo', '' );
	$wm_size      = (int) jinyu_companion_get_option( 'img_wm_size', 18 );
	$wm_color     = (string) jinyu_companion_get_option( 'img_wm_color', '#ffffff' );
	$wm_margin    = (float) jinyu_companion_get_option( 'img_wm_margin', 0.02 );
	$wm_opacity   = (int) jinyu_companion_get_option( 'img_wm_opacity', 60 );
	$wm_quality   = (int) jinyu_companion_get_option( 'img_wm_quality', 82 );
	$wm_min_w     = (int) jinyu_companion_get_option( 'img_wm_min_w', 400 );
	$wm_max_px_m  = max( 1, (int) round( ( (int) jinyu_companion_get_option( 'img_wm_max_px', 8000000 ) / 1000000 ) ) );
	$wm_max_mb    = max( 1, (int) round( ( (int) jinyu_companion_get_option( 'img_wm_max_bytes', 5242880 ) / 1048576 ) ) );
	$wm_pos       = (int) jinyu_companion_get_option( 'img_wm_pos', 9 );
	$wm_cc        = (int) jinyu_companion_get_option( 'img_wm_concurrency', 1 );
	$wm_sizes     = (array) jinyu_companion_get_option( 'img_wm_sizes', array( 'full', 'large' ) );
	$wm_font      = function_exists( 'jinyu_companion_find_font' ) ? (string) jinyu_companion_find_font() : '';
	$wm_supported = class_exists( 'Jinyu_Watermark' ) ? Jinyu_Watermark::supported() : false;

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
	$img_alt_enable     = jinyu_companion_get_option( 'img_alt_enable', '1' );
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
			<input type="hidden" name="jinyu_active_pane" id="jyc-activePane" value="<?php echo esc_attr( $active_pane ); ?>">

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
								<button type="button" class="jyc-nav-item<?php echo 'io' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="io">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
									<span>配置备份</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'wechat' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="wechat">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 13a4.5 4.5 0 0 1-4.5-4.5A4.5 4.5 0 0 1 8 4c2 0 3.7 1.2 4.4 3"/><path d="M16 19a4 4 0 0 1-4-4 4 4 0 0 1 4-4 4 4 0 0 1 4 4v.5a3 3 0 0 1-3 3h-.5a2 2 0 0 0-1.5.7L13 21l2-2.5c.5.1 1 .4 1.6.4z"/></svg>
									<span>微信分享</span>
								</button>
								<button type="button" class="jyc-nav-item<?php echo 'media' === $active_pane ? ' jyc-active' : ''; ?>" data-mod="media">
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="m21 16-5-5-9 9"/></svg>
									<span>图片水印</span>
								</button>
								</nav>
							<button type="button" class="jyc-nav-edge jyc-nav-edge-right" aria-label="<?php echo esc_attr__( '向右滚动', 'jinyu-theme-companion' ); ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
						</div>
						<div class="jyc-top-actions">
							<button class="jyc-theme-tog" id="jyc-themeTog" type="button" title="<?php echo esc_attr__( '切换深色 / 浅色', 'jinyu-theme-companion' ); ?>">
								<svg id="jyc-themeIco" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
							</button>
							<button class="jyc-btn jyc-btn-primary jyc-btn-icon" type="submit"
									title="<?php echo esc_attr__( '保存更改', 'jinyu-theme-companion' ); ?>"
									aria-label="<?php echo esc_attr__( '保存更改', 'jinyu-theme-companion' ); ?>">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
								<span class="jyc-sr"><?php echo esc_html__( '保存更改', 'jinyu-theme-companion' ); ?></span>
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
									<div class="jyc-v jyc-num" id="jyc-sTodo">
                                    <?php
										echo esc_html(
											sprintf(
												/* translators: %d: 待处理项数 */
												__( '%d 项', 'jinyu-theme-companion' ),
												count( $ov_open )
											)
                                        );
									?>
                                    </div>
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
							<div class="jyc-pc-head">
								<div class="jyc-pc-title">
									<?php echo esc_html__( 'Feature Adoption', 'jinyu-theme-companion' ); ?>
									<h3><?php echo esc_html__( '功能启用度明细', 'jinyu-theme-companion' ); ?></h3>
									<p class="jyc-pc-sub"><?php echo esc_html__( '插件全部可配置能力的开启概况。点击任一功能可直接跳转对应设置面板。', 'jinyu-theme-companion' ); ?></p>
								</div>
								<div class="jyc-pc-metric">
									<div class="jyc-pc-big"><span class="on" id="jyc-pc-on">0</span><span class="sep">/</span><span id="jyc-pc-total">0</span></div>
									<span class="jyc-pc-pill" id="jyc-pct">0%</span>
								</div>
							</div>
							<div class="jyc-pc-meter" id="jyc-meter" role="img" aria-label="<?php echo esc_attr__( '功能启用度分段图', 'jinyu-theme-companion' ); ?>"></div>
							<p class="jyc-pc-meter-cap"><?php echo esc_html__( '每一格代表一项功能，蓝色为已启用', 'jinyu-theme-companion' ); ?></p>
							<div class="jyc-checks" id="jyc-checks"></div>
							<div class="jyc-pc-legend">
								<span><i class="jyc-dot-on"></i><?php echo esc_html__( '已开启', 'jinyu-theme-companion' ); ?></span>
								<span><i class="jyc-dot-off"></i><?php echo esc_html__( '未开启', 'jinyu-theme-companion' ); ?></span>
							</div>
						</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/></svg></span><?php echo esc_html__( '内置恒启能力', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '插件常驻 · 无需配置', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-muted" style="font-size:13px;line-height:1.9">
										<?php echo esc_html__( '关注 / 消息系统 · 站点访问统计 · 相关文章 · 系列文章 · 快讯 (Moments) · 短代码与短代码 UI · 对象存储引擎。以上能力随插件常驻并自动启用，无需配置即可使用；若需整体停用，停用本插件即可。', 'jinyu-theme-companion' ); ?>
									</div>
								</div>
							</div>
						</section>

						<!-- ===================== SEO / SOCIAL ===================== -->
						<section id="pane-seo" class="jyc-pane<?php echo 'seo' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( 'SEO / 社交分享', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '控制社交平台分享卡片与 AI 爬虫可发现性。关闭 SEO 后社交分享退化为纯文本链接。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-seo-bento">
	<div class="jyc-seo-main">
<div class="jyc-panel">
	<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg></span><?php echo esc_html__( '呈现与抓取', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '核心默认开启', 'jinyu-theme-companion' ); ?></span></div>
	<div class="jyc-panel-b">
		<div class="jyc-tog-grid">
			<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="seo_open" <?php checked( $seo_open, '1' ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( 'SEO / Open Graph', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '输出 SEO 元标签与 Open Graph（微信 / QQ 分享卡片）。', 'jinyu-theme-companion' ); ?></div></div></div>
			<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="seo_content_h1_fix" <?php checked( jinyu_companion_is_checked( 'seo_content_h1_fix', true ), true ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( '正文标题规范化', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '将正文里嵌的 h1 标题降级为 h2（页面主标题已由模板输出 h1）。', 'jinyu-theme-companion' ); ?></div></div></div>
			<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="twitter_card_enable" <?php checked( $twitter, '1' ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( 'Twitter 卡片', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '输出 Twitter Card 标签，适配 X / 海外社交分享。', 'jinyu-theme-companion' ); ?></div></div></div>
			<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="sitemap_enable" <?php checked( jinyu_companion_is_checked( 'sitemap_enable', true ), true ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( 'XML 站点地图', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '补足收录规则：剔除附件与密码保护文章，<lastmod> 取最后修改时间。', 'jinyu-theme-companion' ); ?></div></div></div>
			<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="thin_archive_noindex_enable" <?php checked( jinyu_companion_is_checked( 'thin_archive_noindex_enable', true ), true ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( '薄归档页不进索引', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '标签页 <3 篇、分类页 <2 篇时自动 noindex 并移出站点地图。', 'jinyu-theme-companion' ); ?></div></div></div>
			<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="geo_signals_enable" <?php checked( jinyu_companion_is_checked( 'geo_signals_enable', true ), true ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( 'GEO 机器可读信号', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '补作者署名 meta，并按选择器输出 speakable 供语音助手朗读。', 'jinyu-theme-companion' ); ?></div></div></div>
		</div>
		<details class="jyc-adv">
			<summary><svg class="jyc-adv-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg><?php echo esc_html__( '高级抓取选项', 'jinyu-theme-companion' ); ?></summary>
			<div class="jyc-adv-b">
				<div class="jyc-tog-grid">
					<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="seo_keywords_enable" <?php checked( jinyu_companion_is_checked( 'seo_keywords_enable', false ), true ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( '输出 keywords 标签', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( 'legacy <meta name="keywords">，主流引擎已不以此排序，默认关闭。', 'jinyu-theme-companion' ); ?></div></div></div>
					<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="llms_enable" <?php checked( $llms, '1' ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( 'llms.txt 发现链接', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '输出 llms.txt 发现链接，便于大模型理解站点。', 'jinyu-theme-companion' ); ?></div></div></div>
					<div class="jyc-tog"><label class="jyc-switch"><input type="checkbox" name="no_category_enable" <?php checked( jinyu_companion_is_checked( 'no_category_enable', false ), true ); ?>><span class="jyc-track"></span></label><div class="jyc-tog-txt"><div class="jyc-tog-name"><?php echo esc_html__( '去除 /category/ 前缀', 'jinyu-theme-companion' ); ?></div><div class="jyc-tog-desc"><?php echo esc_html__( '分类链接不再带 /category/ 前缀；开启前确认固定链接规则不含分类名。', 'jinyu-theme-companion' ); ?></div></div></div>
				</div>
				<label class="jyc-fl" style="margin-top:14px"><?php echo esc_html__( '站点地图排除文章 ID', 'jinyu-theme-companion' ); ?>
					<input class="jyc-inp jyc-inp-mono" type="text" name="sitemap_exclude_ids" value="<?php echo esc_attr( jinyu_companion_get_option( 'sitemap_exclude_ids', '' ) ); ?>" placeholder="1018,964,1634">
					<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '指定文章不进 XML 站点地图（逗号 / 换行分隔）。noindex 只挡搜索结果、管不住站点地图；非插件目录读不到此设置，可改挂 jinyu_sitemap_exclude_ids 过滤器。', 'jinyu-theme-companion' ); ?></span>
				</label>
				<label class="jyc-fl" style="margin-top:10px"><?php echo esc_html__( '薄归档页保留分类项 ID', 'jinyu-theme-companion' ); ?>
					<input class="jyc-inp jyc-inp-mono" type="text" name="thin_archive_keep_term_ids" value="<?php echo esc_attr( jinyu_companion_get_option( 'thin_archive_keep_term_ids', '' ) ); ?>" placeholder="留空表示无例外">
					<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '承担导航枢纽价值的分类项 ID（逗号分隔），始终保留索引。', 'jinyu-theme-companion' ); ?></span>
				</label>
				<label class="jyc-fl" style="margin-top:10px"><?php echo esc_html__( '正文容器选择器（speakable 用）', 'jinyu-theme-companion' ); ?>
					<input class="jyc-inp jyc-inp-mono" type="text" name="geo_speakable_selector" value="<?php echo esc_attr( jinyu_companion_get_option( 'geo_speakable_selector', '' ) ); ?>" placeholder=".jinyu-article-content">
					<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '主题正文容器的 CSS 选择器（不带点，多个空格分隔）。留空则不输出 speakable。', 'jinyu-theme-companion' ); ?></span>
				</label>
				<label class="jyc-fl" style="margin-top:8px"><?php echo esc_html__( '朗读最短正文长度', 'jinyu-theme-companion' ); ?>
					<input class="jyc-inp" type="number" min="0" step="50" name="geo_speakable_min_length" value="<?php echo esc_attr( (string) jinyu_companion_get_option( 'geo_speakable_min_length', 400 ) ); ?>" style="max-width:120px">
					<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '字数少于此值的文章不输出 speakable。0 表示不做长度限制。', 'jinyu-theme-companion' ); ?></span>
				</label>
			</div>
		</details>
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
											<input class="jyc-inp" type="text" name="twitter_site" value="<?php echo esc_attr( $tw_site ); ?>" placeholder="example.com">
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
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z"/></svg></span><?php echo esc_html__( '结构化数据 (JSON-LD)', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认开启', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="ld_json_enable" <?php checked( $ld_json_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用结构化数据 JSON-LD', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '输出 WebSite / Article / FAQ / HowTo 等结构化数据，提升搜索富摘要与 AI（GEO）可发现性。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '组织 Logo 地址', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '填 Logo 图片绝对地址（建议 600×60 以上）。留空则依次回退「主题自定义 Logo」与「站点图标」，三者皆空则不输出，避免出现空的 logo 字段。', 'jinyu-theme-companion' ); ?></div></div>
										</div>
										<div class="jyc-frow">
											<label class="jyc-fl" style="flex:1 1 320px"><?php echo esc_html__( 'Logo URL', 'jinyu-theme-companion' ); ?>
												<input class="jyc-inp" type="url" name="org_logo_url" value="<?php echo esc_attr( $org_logo_url ); ?>" placeholder="https://example.com/logo.png" inputmode="url">
											</label>
										</div>
										<div class="jyc-frow">
											<label class="jyc-fl" style="flex:1 1 320px"><?php echo esc_html__( '作者身份档案（每行一个 URL）', 'jinyu-theme-companion' ); ?>
												<textarea class="jyc-inp" name="author_sameas" rows="3" placeholder="https://github.com/yourname&#10;https://www.zhihu.com/people/yourname"><?php echo esc_textarea( $author_sameas ); ?></textarea>
											</label>
										</div>
										<div class="jyc-frow">
											<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '作者 sameAs 填写说明', 'jinyu-theme-companion' ); ?></div>
												<div class="jyc-fdesc"><?php echo esc_html__( '这里填作者本人的站外身份页（GitHub / 知乎 / X 等）。指向本站自己的 URL 会被自动剔除——人与站同形，搜索与 AI 分不清作者和组织，等于白填。', 'jinyu-theme-companion' ); ?></div></div>
										</div>
										<?php // sameAs 已从 UI 收回（冷门字段，普通用户无感）；隐藏字段保住已存值不被整表提交清空，开发者可用 jinyu_seo_entity_sameas 过滤器注入。 ?>
										<input type="hidden" name="entity_sameas" value="<?php echo esc_attr( $entity_sameas ); ?>">
									</div>
							</div>	</div>
	<div class="jyc-seo-side">
<div class="jyc-panel jyc-panel--lite jyc-seo-pv">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span><?php echo esc_html__( '分享预览', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '随输入实时联动', 'jinyu-theme-companion' ); ?></span></div>
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

<div class="jyc-panel jyc-panel--lite">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span><?php echo esc_html__( '爬虫放行清单', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '只读 · 与站点根 robots.txt 保持一致', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<div class="jyc-grow">
											<div class="jyc-crawlers">
												<?php
												$jinyu_ai_rows   = jinyu_ai_crawl_stats_rows();
												$jinyu_ai_brands = array();
												foreach ( $jinyu_ai_rows as $jinyu_ai_row ) {
													$jinyu_b = $jinyu_ai_row['brand'];
													if ( ! isset( $jinyu_ai_brands[ $jinyu_b ] ) ) {
														$jinyu_ai_brands[ $jinyu_b ] = array(
															'n' => 0,
															'last' => '',
														);
													}
													$jinyu_ai_brands[ $jinyu_b ]['n'] += $jinyu_ai_row['n'];
													if ( $jinyu_ai_row['last'] > $jinyu_ai_brands[ $jinyu_b ]['last'] ) {
														$jinyu_ai_brands[ $jinyu_b ]['last'] = $jinyu_ai_row['last'];
													}
												}
												foreach ( jinyu_geo_crawler_groups() as $jinyu_region => $jinyu_groups ) :
													$jinyu_ua_count = 0;
													foreach ( $jinyu_groups as $jinyu_group ) {
														$jinyu_ua_count += count( $jinyu_group['crawlers'] );
													}
													?>
													<details class="jyc-csect">
														<summary class="jyc-csect-h">
															<svg class="jyc-csect-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
															<span class="jyc-csect-name"><?php echo esc_html( jinyu_geo_crawler_region_label( $jinyu_region ) ); ?></span>
															<span class="jyc-csect-meta">
                                                            <?php
																/* translators: 1: number of brands, 2: number of user agents. */
																echo esc_html( sprintf( __( '%1$d 个品牌 · %2$d 条 UA', 'jinyu-theme-companion' ), count( $jinyu_groups ), $jinyu_ua_count ) );
															?>
                                                            </span>
														</summary>
														<div class="jyc-csect-b">
															<?php foreach ( $jinyu_groups as $jinyu_group ) : ?>
																<div class="jyc-cgroup">
																	<span class="jyc-brand">
																		<svg class="jyc-brand-ico" viewBox="0 0 24 24" aria-hidden="true">
																			<circle cx="12" cy="12" r="12" fill="<?php echo esc_attr( $jinyu_group['color'] ); ?>"></circle>
																			<text x="12" y="12" text-anchor="middle" dominant-baseline="central" fill="#fff" font-size="<?php echo esc_attr( preg_match_all( '/./u', $jinyu_group['mark'] ) > 1 ? 9 : 12 ); ?>" font-weight="700"><?php echo esc_html( $jinyu_group['mark'] ); ?></text>
																		</svg>
																		<span class="jyc-brand-name"><?php echo esc_html( $jinyu_group['label'] ); ?></span>
																	</span>
																	<span class="jyc-ua-list">
																		<?php foreach ( $jinyu_group['crawlers'] as $jinyu_ua ) : ?>
																			<code class="jyc-ua"><?php echo esc_html( $jinyu_ua ); ?></code>
																		<?php endforeach; ?>
																	</span>
																	<?php if ( isset( $jinyu_ai_brands[ $jinyu_group['label'] ] ) ) : ?>
																		<span class="jyc-aistat">
                                                                        <?php
																			$jinyu_ai_b = $jinyu_ai_brands[ $jinyu_group['label'] ];
																			/* translators: 1: visit count, 2: last visit time. */
																			echo esc_html( sprintf( __( '%1$s 次到访 · 最近 %2$s', 'jinyu-theme-companion' ), number_format_i18n( $jinyu_ai_b['n'] ), $jinyu_ai_b['last'] ) );
																		?>
                                                                        </span>
																	<?php else : ?>
																		<span class="jyc-aistat jyc-aistat--none"><?php echo esc_html__( '近期无到访', 'jinyu-theme-companion' ); ?></span>
																	<?php endif; ?>
																</div>
															<?php endforeach; ?>
														</div>
													</details>
												<?php endforeach; ?>
											</div>
										</div>
									</div>
									<div class="jyc-frow">
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '说明', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '本站的 robots.txt 由站点根目录的文件提供（而非插件生成），插件侧不接管输出。本清单仅供随时核对：若你调整过 robots.txt，请让这里与实际内容保持一致。注意两种部署的差别——根目录有静态 robots.txt 时，Web 服务器直接返回它、插件改不动；若你的站点由 WordPress 生成 robots.txt，规则同样不由本插件输出。迁移或重建站点时，请照此内容原样写回新的 robots.txt，否则 AI 与社交抓取会被拦截。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<div class="jyc-grow">
											<div class="jyc-fname"><?php echo esc_html__( 'AI 爬虫到访统计', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '按上方清单的 UA 识别并聚合计数（只记品牌、次数与最后到访时间，不记录 IP 与完整访问日志），明细显示在上方各品牌行内。清零后从下一次到访重新累计。', 'jinyu-theme-companion' ); ?></div>
											<?php if ( $jinyu_ai_rows ) : ?>
												<div id="jyc-aiCrawlStatBox" style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
													<span style="font-size:13px">
														<?php
														$jinyu_ai_total = 0;
														foreach ( $jinyu_ai_rows as $jinyu_ai_row ) {
															$jinyu_ai_total += $jinyu_ai_row['n'];
														}
														/* translators: 1: brand count, 2: total visit count. */
														echo esc_html( sprintf( __( '已记录 %1$d 个品牌、共 %2$s 次到访。', 'jinyu-theme-companion' ), count( $jinyu_ai_brands ), number_format_i18n( $jinyu_ai_total ) ) );
														?>
													</span>
													<button type="button" class="jyc-btn jyc-btn-ghost jyc-btn-sm" id="jyc-aiCrawlReset" onclick="jycAiCrawlReset(this)"><?php echo esc_html__( '清零统计', 'jinyu-theme-companion' ); ?></button>
												</div>
											<?php else : ?>
												<div id="jyc-aiCrawlStatBox" style="font-size:13px;color:var(--ink-3);margin-top:6px"><?php echo esc_html__( '暂无记录——AI 爬虫到访后，计数会出现在上方对应品牌行内。', 'jinyu-theme-companion' ); ?></div>
											<?php endif; ?>
										</div>
									</div>
								</div>
							</div>

							

							<div class="jyc-panel jyc-panel--lite">
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

<div class="jyc-perf-right">
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
									<div id="jyc-tpResult"><?php echo $tp_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控/对外原始输出（内部构造 HTML），无需转义 ?></div>
									<div class="jyc-test-row" style="margin-top:12px">
										<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-tpProbe" data-loading="<?php echo esc_attr__( '体检中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycTransportProbe(this)"><?php echo esc_html__( '立即体检', 'jinyu-theme-companion' ); ?></button>
										<span class="jyc-muted" style="font-size:12px" id="jyc-tpAgo"><?php echo '' !== $tp_ago ? esc_html( sprintf( /* translators: %s: relative time */ __( '上次 %s', 'jinyu-theme-companion' ), $tp_ago ) ) : ''; ?></span>
									</div>
									<div class="jyc-muted" style="font-size:12px;margin-top:10px"><?php echo esc_html__( '压缩、静态资源缓存、HTML 缓存头与 HTTP/3 都由服务器或 CDN 决定，站内配置改不到这一层。这里只做检测并指出该改哪一侧（nginx 配置或 CDN 控制台）；结果缓存 10 分钟，点一次才发两个请求。', 'jinyu-theme-companion' ); ?></div>
								</div>
							</div>
</div>
							<div class="jyc-panel jyc-panel--pc">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/></svg></span><?php echo esc_html__( '整页缓存', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认关闭', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
	<div class="jyc-frow">
		<label class="jyc-switch"><input type="checkbox" name="page_cache_enable" <?php checked( $page_cache_enable, '1' ); ?>><span class="jyc-track"></span></label>
		<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用整页缓存', 'jinyu-theme-companion' ); ?></div>
			<div class="jyc-fdesc"><?php echo esc_html__( '为未登录访客缓存整页 HTML，显著提升匿名访问性能。', 'jinyu-theme-companion' ); ?></div></div>
	</div>

	<div class="jyc-fsep"><?php echo esc_html__( '缓存模式', 'jinyu-theme-companion' ); ?></div>
	<div class="jyc-mode-grid">
		<input type="radio" class="jyc-mode-input" id="jycModeSimple" name="page_cache_mode" value="simple" <?php checked( $page_cache_mode, 'simple' ); ?> onchange="window.jycToggleCacheMode()">
		<label class="jyc-mode-card" for="jycModeSimple">
			<span class="jyc-mode-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M3 14h18"/></svg></span>
			<span class="jyc-mode-body">
				<span class="jyc-mode-t"><?php echo esc_html__( '简单模式', 'jinyu-theme-companion' ); ?><span class="jyc-mode-flag"><?php esc_html_e( '当前模式', 'jinyu-theme-companion' ); ?></span></span>
				<span class="jyc-mode-d"><?php echo esc_html__( '零服务器配置，插件自管磁盘缓存，全平台通用。', 'jinyu-theme-companion' ); ?></span>
			</span>
			<span class="jyc-mode-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
		</label>
		<input type="radio" class="jyc-mode-input" id="jycModeEdge" name="page_cache_mode" value="edge" <?php checked( $page_cache_mode, 'edge' ); ?> onchange="window.jycToggleCacheMode()">
		<label class="jyc-mode-card" for="jycModeEdge">
			<span class="jyc-mode-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7z"/></svg></span>
			<span class="jyc-mode-body">
				<span class="jyc-mode-t"><?php echo esc_html__( '边缘模式', 'jinyu-theme-companion' ); ?><span class="jyc-mode-flag"><?php esc_html_e( '当前模式', 'jinyu-theme-companion' ); ?></span></span>
				<span class="jyc-mode-d"><?php echo esc_html__( '由 Nginx/Apache 在 PHP 之前缓存，支持过期先吐旧页（SWR），更快但需手动粘贴配置。', 'jinyu-theme-companion' ); ?></span>
			</span>
			<span class="jyc-mode-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
		</label>
	</div>

	<?php
	$jpc = function_exists( 'jinyu_page_cache_status' ) ? jinyu_page_cache_status() : null;
	if ( $jpc && $jpc['enabled'] ) :
		if ( 'edge' === $page_cache_mode ) :
			$edge_srv = isset( $jpc['edge_server'] ) ? $jpc['edge_server'] : 'nginx';
			?>
			<div class="jyc-cache-note is-info">
				<?php
				// translators: 1: Web 服务器类型（NGINX / APACHE）.
				printf( esc_html__( '边缘模式已启用（%1$s）：缓存由 Web 服务器写入下方「边缘缓存目录」字段所指定的路径（插件自身不写盘）。复制下方配置片段粘贴到服务器即可；保存设置会触发一次全量刷新。', 'jinyu-theme-companion' ), esc_html( strtoupper( $edge_srv ) ) );
				?>
			</div>
			<?php
		else :
			$jpc_ready = (bool) $jpc['ready'];
			$jpc_last  = $jpc['last_write'] > 0 ? human_time_diff( $jpc['last_write'] ) . __( '前', 'jinyu-theme-companion' ) : __( '刚刚', 'jinyu-theme-companion' );
			?>
			<div class="jyc-cache-note <?php echo $jpc_ready ? 'is-ok' : 'is-warn'; ?>">
				<?php if ( $jpc_ready ) : ?>
					<?php
					// translators: 1: 缓存文件数量；2: 最近写入时间（相对时间）.
					printf( esc_html__( '缓存目录就绪：%1$s 个缓存文件，最近写入 %2$s。', 'jinyu-theme-companion' ), esc_html( number_format_i18n( (int) $jpc['files'] ) ), esc_html( $jpc_last ) );
					?>
				<div class="jyc-cache-sub">
					<?php
					if ( function_exists( 'jinyu_page_cache_etag_hint' ) ) {
						echo wp_kses( jinyu_page_cache_etag_hint(), array( 'code' => array() ) ); }
					?>
                </div>
			<?php else : ?>
				<strong><?php esc_html_e( '整页缓存未生效', 'jinyu-theme-companion' ); ?></strong>
				<div class="jyc-cache-sub"><?php echo wp_kses( jinyu_page_cache_hint(), array( 'code' => array() ) ); // 返回值内部已 esc_html 参数，此处仅放行 <code> 标签. ?></div>
				<?php endif; ?>
			</div>
			<?php
		endif;
	endif;
	?>

	<div class="jyc-fsep"><?php echo esc_html__( '缓存有效期', 'jinyu-theme-companion' ); ?></div>
	<div class="jyc-fl">
		<div class="jyc-slider-wrap">
			<input type="range" name="page_cache_ttl" min="60" max="86400" step="60" value="<?php echo esc_attr( $page_cache_ttl ); ?>" id="jyc-ttlRange">
			<div class="jyc-slider-val"><span id="jyc-ttlVal"><?php echo esc_html( $_ttl_h ); ?></span><small id="jyc-ttlSec"><?php echo esc_html( $_ttl ); ?> 秒</small></div>
		</div>
		<span class="jyc-fnote"><?php echo esc_html__( '范围 60 秒 ～ 24 小时，建议 1 小时。', 'jinyu-theme-companion' ); ?></span>
	</div>

	<div class="jyc-fsep"><?php echo esc_html__( '例外规则', 'jinyu-theme-companion' ); ?></div>
	<label class="jyc-fl">
		<span class="jyc-fname-sm"><?php echo esc_html__( '不缓存的路径', 'jinyu-theme-companion' ); ?></span>
		<textarea class="jyc-inp" name="page_cache_exclude_paths" rows="2" placeholder="/random&#10;/go/&#10;/member/*"><?php echo esc_textarea( $page_cache_exclude_paths ); ?></textarea>
		<span class="jyc-fnote"><?php echo esc_html__( '每行一条 URI 路径，命中即不读也不写缓存。支持目录前缀（/go 命中 /go/123）与 * 通配；# 开头为注释。', 'jinyu-theme-companion' ); ?></span>
	</label>
	<div class="jyc-fpair">
		<label class="jyc-fl">
			<span class="jyc-fname-sm"><?php echo esc_html__( '忽略的参数（提升命中率）', 'jinyu-theme-companion' ); ?></span>
			<textarea class="jyc-inp" name="page_cache_ignore_params" rows="2" placeholder="utm_source, utm_medium, gclid"><?php echo esc_textarea( $page_cache_ignore_params ); ?></textarea>
			<span class="jyc-fnote"><?php echo esc_html__( '不参与缓存 key：同一页面的不同推广参数共用一份缓存。', 'jinyu-theme-companion' ); ?></span>
		</label>
		<label class="jyc-fl">
			<span class="jyc-fname-sm"><?php echo esc_html__( '不缓存的参数', 'jinyu-theme-companion' ); ?></span>
			<textarea class="jyc-inp" name="page_cache_exclude_params" rows="2" placeholder="preview, preview_id"><?php echo esc_textarea( $page_cache_exclude_params ); ?></textarea>
			<span class="jyc-fnote"><?php echo esc_html__( '出现任一参数即跳过缓存，用于动态 / 个性化页面。', 'jinyu-theme-companion' ); ?></span>
		</label>
	</div>

	<div class="jyc-edge-box" id="jycEdgeBox"<?php echo 'edge' === $page_cache_mode ? '' : ' hidden'; ?>>
		<div class="jyc-fpair">
			<label class="jyc-fl">
				<span class="jyc-fname-sm"><?php echo esc_html__( '服务器类型', 'jinyu-theme-companion' ); ?></span>
				<select class="jyc-inp" name="page_cache_edge_server">
					<option value="auto" <?php selected( $page_cache_edge_server, 'auto' ); ?>><?php esc_html_e( '自动识别', 'jinyu-theme-companion' ); ?></option>
					<option value="nginx" <?php selected( $page_cache_edge_server, 'nginx' ); ?>><?php esc_html_e( 'Nginx', 'jinyu-theme-companion' ); ?></option>
					<option value="apache" <?php selected( $page_cache_edge_server, 'apache' ); ?>><?php esc_html_e( 'Apache', 'jinyu-theme-companion' ); ?></option>
				</select>
				<span class="jyc-fnote"><?php echo esc_html__( '决定清理缓存时使用的精确/全量策略，以及展示哪段配置。', 'jinyu-theme-companion' ); ?></span>
			</label>
			<label class="jyc-fl">
				<span class="jyc-fname-sm"><?php echo esc_html__( '边缘缓存目录', 'jinyu-theme-companion' ); ?></span>
				<input class="jyc-inp" type="text" name="page_cache_edge_path" value="<?php echo esc_attr( $page_cache_edge_path ); ?>" placeholder="<?php echo esc_attr( WP_CONTENT_DIR . '/cache/jinyu/edge' ); ?>">
				<span class="jyc-fnote"><?php echo esc_html__( 'Nginx/Apache 写入缓存文件的位置，须与下方片段一致且 PHP 进程可写。', 'jinyu-theme-companion' ); ?></span>
			</label>
		</div>
		<?php
		$edge_nginx  = function_exists( 'jinyu_page_cache_edge_snippet' ) ? jinyu_page_cache_edge_snippet( 'nginx' ) : '';
		$edge_apache = function_exists( 'jinyu_page_cache_edge_snippet' ) ? jinyu_page_cache_edge_snippet( 'apache' ) : '';
		// 片段查看器默认展示的服务器：跟随已保存的设置；auto 时按当前 Web 服务器探测结果取其一。
		$edge_default = ( 'apache' === $page_cache_edge_server ) ? 'apache' : 'nginx';
		$edge_snippets = array(
			'nginx'  => $edge_nginx,
			'apache' => $edge_apache,
		);
		?>
		<div class="jyc-collapse" id="jycEdgeSnippet">
			<button type="button" class="jyc-collapse-head" aria-expanded="false" aria-controls="jycEdgeSnippetBody" onclick="window.jycEdgeSnippetToggle(this)">
				<span class="jyc-collapse-title"><?php echo esc_html__( '边缘模式配置', 'jinyu-theme-companion' ); ?></span>
				<span class="jyc-collapse-sub"><?php echo esc_html__( '查看并复制 Nginx / Apache 部署片段', 'jinyu-theme-companion' ); ?></span>
				<svg class="jyc-collapse-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
			</button>
			<div class="jyc-collapse-body" id="jycEdgeSnippetBody" hidden>
			<div class="jyc-code-viewer" id="jycCodeViewer" data-active="<?php echo esc_attr( $edge_default ); ?>" data-snippets="<?php echo esc_attr( wp_json_encode( $edge_snippets ) ); ?>">
				<div class="jyc-code-head">
					<div class="jyc-code-tabs" role="tablist">
						<button type="button" class="jyc-code-tab" data-code="nginx" onclick="window.jycEdgeCodeTab('nginx')"><?php esc_html_e( 'Nginx', 'jinyu-theme-companion' ); ?></button>
						<button type="button" class="jyc-code-tab" data-code="apache" onclick="window.jycEdgeCodeTab('apache')"><?php esc_html_e( 'Apache', 'jinyu-theme-companion' ); ?></button>
					</div>
					<button type="button" class="jyc-btn jyc-btn-soft jyc-code-copy" onclick="window.jycEdgeCodeCopy(this)"><?php esc_html_e( '复制片段', 'jinyu-theme-companion' ); ?></button>
				</div>
				<span class="jyc-code-hint" data-code="nginx"><?php esc_html_e( '分三处粘贴：http 段 / PHP 的 location 内 / server 段', 'jinyu-theme-companion' ); ?></span>
				<span class="jyc-code-hint" data-code="apache"><?php esc_html_e( '只能放 vhost 配置，放 .htaccess 会 500', 'jinyu-theme-companion' ); ?></span>
				<textarea class="jyc-inp jyc-code" id="jycCodeText" rows="16" readonly spellcheck="false"></textarea>
			</div>
			<span class="jyc-fnote"><?php echo esc_html__( '切换标签查看对应服务器配置；插件不会自动写入服务器配置，粘贴后保存设置会触发一次全量刷新。', 'jinyu-theme-companion' ); ?></span>
			</div>
		</div>
	</div>
	</div>
</div>
						</section>

						<!-- ===================== PERF CENTER（性能优化中心，自主题迁入） ===================== -->
						<section id="pane-perfcenter" class="jyc-pane<?php echo 'perfcenter' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head jyc-mod-flex">
								<div class="jyc-mod-title">
									<h1><?php echo esc_html__( '性能中心', 'jinyu-theme-companion' ); ?></h1>
									<?php jinyu_perf_render_headside(); ?>
								</div>
								<div class="jyc-sub"><?php echo esc_html__( '服务器运行时运维：OPcache / Memcached 实时看板、可逆优化开关、多层缓存清理与一键优化。整页缓存等前台配置在「前台加速」。', 'jinyu-theme-companion' ); ?></div></div>
							<?php jinyu_perf_render_pane(); ?>
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
											<button type="button" data-v="smart" class="<?php echo 'smart' === $captcha ? 'jyc-active' : ''; ?>"><?php echo esc_html__( '智能', 'jinyu-theme-companion' ); ?></button>
											<button type="button" data-v="always" class="<?php echo 'always' === $captcha ? 'jyc-active' : ''; ?>"><?php echo esc_html__( '始终启用', 'jinyu-theme-companion' ); ?></button>
											<button type="button" data-v="off" class="<?php echo 'off' === $captcha ? 'jyc-active' : ''; ?>"><?php echo esc_html__( '关闭', 'jinyu-theme-companion' ); ?></button>
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
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="smtp_enable" <?php checked( $smtp_enable, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '接管全站发信', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '开启后全站系统邮件（评论回复、找回密码、注册通知等）均走 SMTP。关闭时配置保留，仅「发送测试邮件」可用，wp_mail 退回服务器默认发信方式。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
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

						<!-- ===================== SOCIAL LOGIN（第三方登录） ===================== -->
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
										<li><?php echo esc_html__( 'JS 接口安全域名：在公众号后台填你自己的域名主域（仅主域，不带 http/www），并上传验证文件到站点根目录。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( 'IP 白名单：在公众号后台「基本配置」加入服务器出口 IP，否则获取 access_token 报 40164。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( '分享图：直接复用「分享素材」里的 og:image（1200×630），微信好友卡会中心裁成方形缩略图。', 'jinyu-theme-companion' ); ?></li>
									</ul>
								</div>
							</div>
						</section>

						<!-- ===================== 图片水印 ===================== -->
						<section id="pane-media" class="jyc-pane<?php echo 'media' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '图片水印', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '在上传与批量处理时把水印写进图片文件本身（不是 CSS 叠加），另存 / 爬虫都拿不到无水印原图。原图以 rename 方式留一份 -jywmo 备份，随时可一键还原，磁盘占用不增加。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel jyc-wm-main">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.8"/><path d="m21 16-5-5-9 9"/></svg></span><?php echo esc_html__( '水印内容与样式', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '随表单保存生效', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-wm-set">
										<div class="jyc-frow">
											<label class="jyc-switch"><input type="checkbox" name="img_wm_enable" <?php checked( $wm_enable, '1' ); ?>><span class="jyc-track"></span></label>
											<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '启用图片水印', 'jinyu-theme-companion' ); ?></div>
												<div class="jyc-fdesc"><?php echo esc_html__( '总开关。关闭后不再对新图与批量任务生效；已处理的文件需单独执行「一键还原」。', 'jinyu-theme-companion' ); ?></div></div>
										</div>
										<div class="jyc-frow">
											<label class="jyc-switch"><input type="checkbox" name="img_wm_on_upload" <?php checked( $wm_on_upload, '1' ); ?>><span class="jyc-track"></span></label>
											<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '新上传的图片自动打水印', 'jinyu-theme-companion' ); ?></div>
												<div class="jyc-fdesc"><?php echo esc_html__( '发生在元数据生成阶段，早于对象存储上云，保证云端拿到的也是水印图。', 'jinyu-theme-companion' ); ?></div></div>
										</div>

										<div class="jyc-fl jyc-wm-posfield"><?php echo esc_html__( '水印位置（九宫格）', 'jinyu-theme-companion' ); ?>
											<input type="hidden" name="img_wm_pos" id="jyc-wmPos" value="<?php echo esc_attr( (string) $wm_pos ); ?>">
											<div class="jyc-wm-grid" id="jyc-wmGrid" role="radiogroup" aria-label="<?php echo esc_attr__( '水印位置', 'jinyu-theme-companion' ); ?>">
												<?php for ( $i = 1; $i <= 9; $i++ ) : ?>
													<button type="button" class="jyc-wm-cell<?php echo $i === $wm_pos ? ' jyc-on' : ''; ?>" data-pos="<?php echo esc_attr( (string) $i ); ?>" role="radio" aria-checked="<?php echo $i === $wm_pos ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( (string) $i ); ?>"><i class="jyc-wm-dot"></i></button>
												<?php endfor; ?>
											</div>
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '默认右下角。小图建议居中或右下，避免水印占比过大。', 'jinyu-theme-companion' ); ?></span>
										</div>
									</div>

									<div class="jyc-wm-shape">
										<div class="jyc-wm-prev">
											<div class="jyc-wm-prev-h"><span><?php echo esc_html__( '实时预览', 'jinyu-theme-companion' ); ?></span>
												<span class="jyc-hint" id="jyc-wmSizeHint"></span></div>
											<canvas id="jyc-wmCanvas" class="jyc-wm-canvas" width="1200" height="800" role="img" aria-label="<?php echo esc_attr__( '水印效果预览', 'jinyu-theme-companion' ); ?>"></canvas>
										</div>
									</div>

									<div class="jyc-field-grid jyc-wm-fields">
										<label class="jyc-fl"><?php echo esc_html__( '水印文字', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp" type="text" name="img_wm_text" value="<?php echo esc_attr( $wm_text ); ?>" placeholder="www.example.com">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '留空则改用下方「图片水印」；两者都为空时批量任务会直接跳过。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '图片水印（PNG 水印图 URL）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-inp-mono" type="url" name="img_wm_logo" value="<?php echo esc_attr( $wm_logo ); ?>" placeholder="https://example.com/logo.png">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '填了就优先用图片水印（带透明通道的 PNG 效果最好），忽略文字。', 'jinyu-theme-companion' ); ?></span>
										</label>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M3 12h18"/><circle cx="12" cy="12" r="9"/></svg></span><?php echo esc_html__( '处理范围与保护', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '弱机保护，避免 OOM / 超时', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-field-grid jyc-wm-params">
									<label class="jyc-fl"><?php echo esc_html__( '水印字号（px）', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-num" type="number" name="img_wm_size" id="jyc-wmSize" value="<?php echo esc_attr( (string) $wm_size ); ?>" min="8" max="400" step="1">
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '上方预览即实际效果。图太小时自动缩到短边 18% 以内，不会糊满。', 'jinyu-theme-companion' ); ?></span>
									</label>
										<span class="jyc-fl"><?php echo esc_html__( '文字颜色', 'jinyu-theme-companion' ); ?>
										<span class="jyc-wm-colorrow">
											<input type="color" class="jyc-wm-color" name="img_wm_color" id="jyc-wmColor" value="<?php echo esc_attr( $wm_color ); ?>" aria-label="<?php echo esc_attr__( '选取颜色', 'jinyu-theme-companion' ); ?>">
											<input type="text" class="jyc-inp jyc-wm-colorhex" id="jyc-wmColorHex" value="<?php echo esc_attr( $wm_color ); ?>" maxlength="7" spellcheck="false" autocomplete="off" aria-label="<?php echo esc_attr__( '颜色十六进制值', 'jinyu-theme-companion' ); ?>">
										</span>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '取色或直接填 #rgb / #RRGGBB，两框双向同步。只作用于文字水印，深色描边保留。', 'jinyu-theme-companion' ); ?></span>
									</span>
									<label class="jyc-fl"><?php echo esc_html__( '不透明度（%）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="img_wm_opacity" value="<?php echo esc_attr( (string) $wm_opacity ); ?>" min="10" max="100" step="1">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '越低越淡，压图越轻；建议 50~70。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '边距占短边比例', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="img_wm_margin" value="<?php echo esc_attr( number_format( $wm_margin, 2 ) ); ?>" min="0" max="0.2" step="0.01">
										</label>
										<label class="jyc-fl"><?php echo esc_html__( 'JPEG 输出质量', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="img_wm_quality" value="<?php echo esc_attr( (string) $wm_quality ); ?>" min="40" max="100" step="1">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '合成后按此质量重编码，通常比原图略小。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '跳过过窄的图（宽度小于）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="img_wm_min_w" value="<?php echo esc_attr( (string) $wm_min_w ); ?>" min="0" max="4000" step="50">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '默认 400px：缩略图类小图不打，省空间也避免糊。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '像素上限（百万像素）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="img_wm_max_px" value="<?php echo esc_attr( (string) $wm_max_px_m ); ?>" min="1" max="40" step="1">
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '超过则跳过，防大图把内存打爆。', 'jinyu-theme-companion' ); ?></span>
										</label>
										<label class="jyc-fl"><?php echo esc_html__( '文件大小上限（MB）', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="img_wm_max_bytes" value="<?php echo esc_attr( (string) $wm_max_mb ); ?>" min="1" max="64" step="1">
										</label>
									</div>
									<label class="jyc-fl jyc-full" style="margin-top:16px"><?php echo esc_html__( '处理哪些尺寸', 'jinyu-theme-companion' ); ?>
										<div class="jyc-checks jyc-wm-sizes" id="jyc-wmSizes">
											<?php
											foreach (
												array(
													'thumbnail'     => __( '缩略图 thumbnail', 'jinyu-theme-companion' ),
													'medium'        => __( '中等 medium', 'jinyu-theme-companion' ),
													'medium_large'  => __( '中大 medium_large', 'jinyu-theme-companion' ),
													'large'         => __( '大图 large', 'jinyu-theme-companion' ),
												) as $s => $label
											) :
												?>
												<label class="jyc-check">
													<input type="checkbox" name="img_wm_size_<?php echo esc_attr( $s ); ?>" value="1" <?php checked( in_array( $s, $wm_sizes, true ), true ); ?>>
													<span class="jyc-dot<?php echo in_array( $s, $wm_sizes, true ) ? ' jyc-on' : ''; ?>"></span>
													<span class="jyc-check-label"><?php echo esc_html( $label ); ?></span>
												</label>
											<?php endforeach; ?>
											<label class="jyc-check">
												<input type="checkbox" name="img_wm_size_full" value="1" checked disabled>
												<span class="jyc-dot jyc-on"></span>
												<span class="jyc-check-label"><?php echo esc_html__( '原图 full（恒选，不可取消）', 'jinyu-theme-companion' ); ?></span>
											</label>
										</div>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '全库批量时只处理这些已存在的尺寸文件；未注册的尺寸不会新建。处理越多占用越多，thumbnail(150px) 建议不勾选。', 'jinyu-theme-companion' ); ?></span>
									</label>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></svg></span><?php echo esc_html__( '批量处理与状态', 'jinyu-theme-companion' ); ?></h2><span class="jyc-opstate <?php echo $wm_supported ? 'is-on' : 'is-off'; ?>" id="jyc-wmState"><?php echo $wm_supported ? esc_html__( '引擎可用', 'jinyu-theme-companion' ) : esc_html__( '环境不支持图像处理', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<?php if ( '' === $wm_font ) : ?>
										<div class="jyc-wm-warn"><?php echo esc_html__( '未检测到可用字体：服务器缺少支持中文的字体，中文水印会渲染成方块。英文 / 数字可用；中文需安装 fonts-wqy-zenhei 或把开源字体放进插件 assets/fonts/。', 'jinyu-theme-companion' ); ?></div>
									<?php endif; ?>
									<div class="jyc-actions">
										<div class="jyc-opcard">
											<div class="jyc-opcard-h"><span class="jyc-opcard-t"><?php echo esc_html__( '全库批量', 'jinyu-theme-companion' ); ?></span></div>
											<div class="jyc-opcard-b">
												<button class="jyc-btn jyc-btn-primary jyc-wm-btn" type="button" data-wm="all" data-mode="apply" data-label="<?php echo esc_attr__( '开始加水印', 'jinyu-theme-companion' ); ?>" onclick="window.jycWmAction(this)"><?php echo esc_html__( '一键加水印', 'jinyu-theme-companion' ); ?></button>
												<button class="jyc-btn jyc-btn-ghost jyc-wm-btn" type="button" data-wm="all" data-mode="remove" data-label="<?php echo esc_attr__( '开始还原', 'jinyu-theme-companion' ); ?>" onclick="window.jycWmAction(this)"><?php echo esc_html__( '一键还原', 'jinyu-theme-companion' ); ?></button>
											</div>
											<div class="jyc-opcard-d"><?php echo esc_html__( '只处理「当前配置签名」下尚未处理过的图片（已处理的不重复跑）；「还原」把备份原图改回原路径。批量改写的是本地文件，改完请到「对象存储」重新推送，云端才会同步。', 'jinyu-theme-companion' ); ?></div>
										</div>
										<div class="jyc-opcard">
											<div class="jyc-opcard-h"><span class="jyc-opcard-t"><?php echo esc_html__( '并发数', 'jinyu-theme-companion' ); ?></span></div>
											<div class="jyc-opcard-b">
												<select class="jyc-inp" name="img_wm_concurrency" id="jyc-wmCc">
													<?php
                                                    foreach ( array(
														1 => __( '1（串行，最稳）', 'jinyu-theme-companion' ),
														2 => __( '2', 'jinyu-theme-companion' ),
														4 => __( '4', 'jinyu-theme-companion' ),
														8 => __( '8（最快，弱机勿用）', 'jinyu-theme-companion' ),
													) as $v => $t ) :
														?>
														<option value="<?php echo esc_attr( (string) $v ); ?>" <?php selected( $wm_cc, $v ); ?>><?php echo esc_html( $t ); ?></option>
													<?php endforeach; ?>
												</select>
											</div>
											<div class="jyc-opcard-d"><?php echo esc_html__( '大于 1 时走多子请求并发，需要站点支持 admin-ajax；改完请先保存设置再启动任务。', 'jinyu-theme-companion' ); ?></div>
										</div>
										<div class="jyc-opcard jyc-opcard-wide">
											<div class="jyc-opcard-h"><span class="jyc-opcard-t"><?php echo esc_html__( '自选附件', 'jinyu-theme-companion' ); ?></span></div>
											<textarea id="jyc-wmIds" class="jyc-inp jyc-inp-mono jyc-opcard-full" rows="2" placeholder="<?php echo esc_attr__( '媒体库附件 ID，逗号或空格分隔，可多行。也可以去「媒体库」用批量操作「添加水印 / 去除水印」，处理完自动跳回本页。', 'jinyu-theme-companion' ); ?>"></textarea>
											<div class="jyc-opcard-b">
												<button class="jyc-btn jyc-btn-soft jyc-wm-btn" type="button" data-wm="ids" data-mode="apply" data-label="<?php echo esc_attr__( '处理所选', 'jinyu-theme-companion' ); ?>" onclick="window.jycWmAction(this)"><?php echo esc_html__( '处理所选', 'jinyu-theme-companion' ); ?></button>
												<button class="jyc-btn jyc-btn-ghost jyc-wm-btn" type="button" data-wm="ids" data-mode="remove" data-label="<?php echo esc_attr__( '还原所选', 'jinyu-theme-companion' ); ?>" onclick="window.jycWmAction(this)"><?php echo esc_html__( '还原所选', 'jinyu-theme-companion' ); ?></button>
											</div>
										</div>
									</div>

									<div id="jyc-wmProg" hidden>
										<div class="jyc-pbar"><i id="jyc-wmFill" style="width:0%"></i></div>
										<span class="jyc-muted jyc-num" id="jyc-wmMsg" style="display:block;margin-top:2px;font-size:12.5px"></span>
									</div>

									<div class="jyc-wm-stat">
										<span class="jyc-muted jyc-num" id="jyc-wmStatTxt"><?php echo esc_html__( '媒体库图片 —，已处理 —', 'jinyu-theme-companion' ); ?></span>
										<button class="jyc-btn jyc-btn-ghost" type="button" id="jyc-wmStatBtn" onclick="window.jycWmStats(this)"><?php echo esc_html__( '刷新统计', 'jinyu-theme-companion' ); ?></button>
									</div>
									<ul class="jyc-note-list">
										<li><?php echo esc_html__( '图片文件会被真实改写：原图改名为 xxx-jywmo.ext 留在同目录，水印版占原路径，磁盘占用基本不变。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( '修改水印样式后，历史图不会自动重建；点「一键加水印」时签名不匹配的图片会按新样式重做，绝不会在水印图上叠加。', 'jinyu-theme-companion' ); ?></li>
										<li><?php echo esc_html__( 'GIF / SVG / PDF 不处理；超过像素或体积上限的大图直接跳过（不计入失败）。', 'jinyu-theme-companion' ); ?></li>
									</ul>
								</div>
							</div>
						</section>

						<!-- ===================== 配置备份（设置导入 / 导出） ===================== -->
						<section id="pane-io" class="jyc-pane<?php echo 'io' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '配置备份', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '把本插件的全部配置导出为 JSON，在另一站点导入复用；也可作为改坏设置前的手存快照。', 'jinyu-theme-companion' ); ?></div></div>

							<?php
							$io_res = jinyu_companion_import_result();
							if ( ! empty( $io_res['message'] ) ) {
								?>
								<div class="jyc-panel jyc-span jyc-io-result <?php echo $io_res['ok'] ? 'is-ok' : 'is-bad'; ?>">
									<div class="jyc-panel-b">
										<div class="jyc-io-head">
											<span class="jyc-io-ico" aria-hidden="true">
												<?php if ( $io_res['ok'] ) { ?>
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
												<?php } else { ?>
													<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
												<?php } ?>
											</span>
											<div class="jyc-io-msg"><?php echo esc_html( $io_res['message'] ); ?></div>
										</div>
										<?php if ( $io_res['ok'] ) { ?>
											<?php
											$io_chips = array(
												'added'   => __( '新增', 'jinyu-theme-companion' ),
												'updated' => __( '覆盖', 'jinyu-theme-companion' ),
												'ignored' => __( '忽略未知', 'jinyu-theme-companion' ),
												'skipped' => __( '凭据跳过', 'jinyu-theme-companion' ),
											);
											?>
											<div class="jyc-io-chips">
												<?php foreach ( $io_chips as $io_k => $io_label ) : ?>
													<?php if ( isset( $io_res[ $io_k ] ) && (int) $io_res[ $io_k ] > 0 ) : ?>
														<span class="jyc-io-chip is-<?php echo esc_attr( $io_k ); ?>"><?php echo esc_html( $io_label ); ?> <b><?php echo (int) $io_res[ $io_k ]; ?></b></span>
													<?php endif; ?>
												<?php endforeach; ?>
											</div>
											<div class="jyc-io-note"><?php echo esc_html__( '凭据类配置（SMTP 密码 / 微信 AppSecret / 对象存储 Secret / 社交登录密钥）不会随文件迁移，需在新站点重新填写。', 'jinyu-theme-companion' ); ?></div>
										<?php } ?>
									</div>
								</div>
								<?php
							}
							?>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></span><?php echo esc_html__( '导出配置', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '不含任何密码与密钥', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-io-lists">
										<div class="jyc-io-list is-in">
											<div class="jyc-io-list-h"><?php echo esc_html__( '包含', 'jinyu-theme-companion' ); ?></div>
											<ul>
												<li><?php echo esc_html__( '主设置全部开关与配置项', 'jinyu-theme-companion' ); ?></li>
												<li><?php echo esc_html__( '性能中心优化项', 'jinyu-theme-companion' ); ?></li>
												<li><?php echo esc_html__( '第三方登录（非凭据字段）', 'jinyu-theme-companion' ); ?></li>
												<li><?php echo esc_html__( 'IndexNow 验证密钥', 'jinyu-theme-companion' ); ?></li>
											</ul>
										</div>
										<div class="jyc-io-list is-out">
											<div class="jyc-io-list-h"><?php echo esc_html__( '剔除', 'jinyu-theme-companion' ); ?></div>
											<ul>
												<li><?php echo esc_html__( 'SMTP 密码', 'jinyu-theme-companion' ); ?></li>
												<li><?php echo esc_html__( '微信 AppSecret', 'jinyu-theme-companion' ); ?></li>
												<li><?php echo esc_html__( '对象存储 Secret', 'jinyu-theme-companion' ); ?></li>
												<li><?php echo esc_html__( '社交登录密钥', 'jinyu-theme-companion' ); ?></li>
											</ul>
										</div>
									</div>
									<div class="jyc-io-note"><?php echo esc_html__( '剔除的凭据在库内本就是加密密文，跨站迁移后也解不开；导出的 JSON 可直接交给他人，不含可登录的凭据。', 'jinyu-theme-companion' ); ?></div>
									<label class="jyc-checkline is-warn">
										<input type="checkbox" id="jyc-export-full" value="1">
										<span><?php echo esc_html__( '凭据一并导出（高风险）：勾选后备份将包含 SMTP 密码、微信 AppSecret、对象存储 Secret、社交登录密钥的加密密文，仅用于本站快照恢复；备份文件请妥善保管，不要交给他人。', 'jinyu-theme-companion' ); ?></span>
									</label>
									<div class="jyc-io-actions">
										<?php
										$io_url_safe = jinyu_companion_io_export_url( false );
										$io_url_full = jinyu_companion_io_export_url( true );
										?>
										<a class="jyc-btn jyc-btn-soft" id="jyc-export-btn" href="<?php echo esc_url( $io_url_safe ); ?>" data-href-safe="<?php echo esc_url( $io_url_safe ); ?>" data-href-full="<?php echo esc_url( $io_url_full ); ?>"><?php echo esc_html__( '下载 JSON 备份', 'jinyu-theme-companion' ); ?></a>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span><?php echo esc_html__( '导入配置', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '合并覆盖，不清空现有配置', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-drop" id="jyc-drop" tabindex="0" role="button" aria-label="<?php echo esc_attr__( '点击选择或拖入备份 JSON 文件', 'jinyu-theme-companion' ); ?>">
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 16 12 20 8 16"/><line x1="12" y1="20" x2="12" y2="4"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 4 16.3"/></svg>
										<p class="jyc-drop-t"><?php echo wp_kses( __( '拖入备份文件，或 <b>点击选择</b>', 'jinyu-theme-companion' ), array( 'b' => array() ) ); ?></p>
										<p class="jyc-drop-sub"><?php echo esc_html__( '支持 .json（本插件导出的备份文件）', 'jinyu-theme-companion' ); ?></p>
									</div>
									<input class="jyc-sr" type="file" name="jinyu_import_file" id="jyc-import-file" accept=".json,application/json" autocomplete="off">
									<div class="jyc-drop-file" id="jyc-drop-file" hidden>
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
										<span class="jyc-drop-fname" id="jyc-drop-name"></span>
										<span class="jyc-drop-fsize" id="jyc-drop-size"></span>
										<button type="button" class="jyc-drop-fclear" id="jyc-drop-clear" aria-label="<?php echo esc_attr__( '移除已选文件', 'jinyu-theme-companion' ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
									</div>
									<details class="jyc-io-adv" open>
										<summary><?php echo esc_html__( '高级：直接粘贴 JSON', 'jinyu-theme-companion' ); ?></summary>
										<textarea class="jyc-inp jyc-inp-area" name="jinyu_import_text" rows="5" placeholder='{"plugin":"jinyu-theme-companion","settings":{...}}'></textarea>
									</details>
									<label class="jyc-checkline">
										<input type="checkbox" name="jinyu_import_confirm" value="1">
										<span><?php echo esc_html__( '我已确认：导入会用备份文件里的同名配置项覆盖当前设置，未出现的配置保持现状。', 'jinyu-theme-companion' ); ?></span>
									</label>
									<div class="jyc-io-actions">
										<button type="submit" class="jyc-btn jyc-btn-primary" name="jinyu_import" value="1" id="jyc-import-submit"><?php echo esc_html__( '导入并覆盖同名配置', 'jinyu-theme-companion' ); ?></button>
									</div>
								</div>
							</div>
						</section>

					</main>
				</div>

				<!-- 悬浮保存浮条：与主题「有未保存的更改」同一交互。DOM 必须挂在 .jyc-app 内 ——
					配色走 .jyc-app 的 CSS 变量，且与 .jyc-main 同级（避开 .jyc-pane 进场动画的
					transform 形成 fixed 包含块，导致浮条贴到分区顶部而不是视口底部）。 -->
				<div class="jyc-dirtybar" id="jyc-dirtybar" role="status" aria-live="polite" hidden>
					<span class="jyc-db-dot" aria-hidden="true"></span>
					<span class="jyc-db-txt" id="jyc-db-txt">
						<?php echo esc_html__( '有未保存的更改', 'jinyu-theme-companion' ); ?>
						<span class="jyc-db-count" id="jyc-db-count" hidden></span>
					</span>
					<span class="jyc-db-actions">
						<button type="button" class="jyc-db-btn jyc-db-btn--ghost" id="jyc-db-discard"><?php echo esc_html__( '放弃更改', 'jinyu-theme-companion' ); ?></button>
						<button type="button" class="jyc-db-btn jyc-db-btn--primary" id="jyc-db-save" data-loading="<?php echo esc_attr__( '保存中…', 'jinyu-theme-companion' ); ?>"><?php echo esc_html__( '保存', 'jinyu-theme-companion' ); ?></button>
					</span>
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
	$root = dirname( dirname( __DIR__ ) ); // .../wp-content/plugins/jinyu-theme-companion
	$main = $root . '/jinyu-theme-companion.php';       // plugins_url 第 2 参数须为插件根下真实文件（目录会被多剥一层）
	$fallback = defined( 'JINYU_COMPANION_VER' ) ? JINYU_COMPANION_VER : '1.0.1';
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
