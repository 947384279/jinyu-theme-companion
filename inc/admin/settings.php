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
	foreach ( [ 'seo_open', 'twitter_card_enable', 'llms_enable', 'auto_link_enable', 'indexnow_enable', 'close_comments_old', 'page_cache_enable', 'speculation_enable', 'img_alt_enable', 'ld_json_enable', 'no_category_enable', 'storage_auto_upload', 'storage_delete_local' ] as $k ) {
		$settings[ $k ] = isset( $_POST[ $k ] ) ? '1' : '0';
	}

	// 去除 /category/ 前缀开关状态变化时需刷新重写规则（开启/关闭都要 flush 一次才能生效/还原）
	$no_cat_changed = jinyu_companion_get_option( 'no_category_enable', '1' ) !== $settings['no_category_enable'];

	// 文本 / URL / 颜色
	$settings['og_image']        = isset( $_POST['og_image'] ) ? esc_url_raw( wp_unslash( $_POST['og_image'] ) ) : '';
	$settings['og_site_name']    = isset( $_POST['og_site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['og_site_name'] ) ) : '';
	// 全站 SEO 默认值（覆盖 tagline / 作为文章 / 分类兜底前的基准）
	$settings['seo_keywords']    = isset( $_POST['seo_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['seo_keywords'] ) ) : '';
	$settings['seo_desc']        = isset( $_POST['seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['seo_desc'] ) ) : '';
	// 结构化数据：组织 / 品牌 sameAs 链接（每行一个 URL）
	$settings['entity_sameas']   = isset( $_POST['entity_sameas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['entity_sameas'] ) ) : '';
	$settings['baidu_submit_token'] = isset( $_POST['baidu_submit_token'] ) ? esc_url_raw( wp_unslash( $_POST['baidu_submit_token'] ) ) : '';
	$settings['anti_spam_words'] = isset( $_POST['anti_spam_words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['anti_spam_words'] ) ) : '';
	$settings['style_color_primary'] = isset( $_POST['style_color_primary'] ) ? sanitize_hex_color( wp_unslash( $_POST['style_color_primary'] ) ) : '';
	$settings['close_comments_days'] = isset( $_POST['close_comments_days'] ) ? (int) wp_unslash( $_POST['close_comments_days'] ) : 0;
	$settings['page_cache_ttl'] = isset( $_POST['page_cache_ttl'] ) ? max( 60, (int) wp_unslash( $_POST['page_cache_ttl'] ) ) : 3600;

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

if ( ! function_exists( 'jinyu_companion_active_pane' ) ) {
	/**
	 * 当前激活的设置分区（标签页）。保存后停留原分区，避免每次保存都跳回概览。
	 *
	 * @param string|null $set 传入分区名置位（仅接受白名单值）。
	 * @return string
	 */
	function jinyu_companion_active_pane( ?string $set = null ): string {
		static $pane = 'overview';
		$valid       = [ 'overview', 'seo', 'content', 'perf', 'perfcenter', 'comment', 'smtp', 'storage' ];
		if ( null !== $set && in_array( $set, $valid, true ) ) {
			$pane = $set;
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
	$poster_color = jinyu_companion_get_option( 'style_color_primary', '#FF6B35' );
	$smtp_host    = jinyu_companion_get_option( 'smtp_host', '' );
	$smtp_port    = jinyu_companion_get_option( 'smtp_port', 465 );
	$smtp_secure  = jinyu_companion_get_option( 'smtp_secure', 'ssl' );
	$smtp_user    = jinyu_companion_get_option( 'smtp_user', '' );
	$smtp_from    = jinyu_companion_get_option( 'smtp_from', '' );
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
	$storage_has_secret = '' !== (string) jinyu_companion_get_option( 'storage_secret', '' );
	$page_cache_enable = jinyu_companion_get_option( 'page_cache_enable', '0' );
	$page_cache_ttl   = jinyu_companion_get_option( 'page_cache_ttl', '3600' );

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
							<div class="jyc-brand-mark">金</div>
							<div class="jyc-brand-txt"><b>金玉 · 增强控制台</b><span>配套插件设置</span></div>
						</div>
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
						</nav>
						<div class="jyc-top-actions">
							<button class="jyc-btn jyc-btn-primary" type="submit">
								<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
								<?php echo esc_html__( '保存更改', 'jinyu-theme-companion' ); ?>
							</button>
							<button class="jyc-theme-tog" id="jyc-themeTog" type="button" title="<?php echo esc_attr__( '切换深色 / 浅色', 'jinyu-theme-companion' ); ?>">
								<svg id="jyc-themeIco" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
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
										<div class="jyc-hs"><span class="jyc-v jyc-num">8</span><span class="jyc-k"><?php echo esc_html__( '功能分区', 'jinyu-theme-companion' ); ?></span></div>
										<div class="jyc-hs"><span class="jyc-v jyc-num" id="jyc-hsOn">0</span><span class="jyc-k"><?php echo esc_html__( '已启用开关', 'jinyu-theme-companion' ); ?></span></div>
										<div class="jyc-hs"><span class="jyc-v jyc-num" id="jyc-hsFields">—</span><span class="jyc-k"><?php echo esc_html__( '可配置字段', 'jinyu-theme-companion' ); ?></span></div>
										<div class="jyc-hs"><span class="jyc-v jyc-num">独立</span><span class="jyc-k"><?php echo esc_html__( '存储隔离', 'jinyu-theme-companion' ); ?></span></div>
									</div>
								</div>
							</div>

							<div class="jyc-bento">
								<div class="jyc-tile jyc-wide"><div class="jyc-k"><i style="background:var(--brand)"></i><?php echo esc_html__( '功能启用度', 'jinyu-theme-companion' ); ?></div>
									<div class="jyc-v jyc-num" id="jyc-bentoPct">0%</div><div class="jyc-n"><?php echo esc_html__( '全部功能开关平均开启比例', 'jinyu-theme-companion' ); ?></div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:var(--ok)"></i>SEO 呈现</div><div class="jyc-v jyc-num" id="jyc-sSeo">—</div><div class="jyc-n">OG / Twitter / llms</div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:#0ea5e9"></i>主动推送</div><div class="jyc-v jyc-num" id="jyc-sPush">—</div><div class="jyc-n">IndexNow / 百度</div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:#64748b"></i>缓存命中</div><div class="jyc-v jyc-num" id="jyc-sCache">关</div><div class="jyc-n"><?php echo esc_html__( '整页缓存状态', 'jinyu-theme-companion' ); ?></div></div>
								<div class="jyc-tile"><div class="jyc-k"><i style="background:var(--brand-2)"></i>评论防护</div><div class="jyc-v jyc-num" id="jyc-sCap">智能</div><div class="jyc-n"><?php echo esc_html__( '验证码策略', 'jinyu-theme-companion' ); ?></div></div>
							</div>

							<div class="jyc-progress-card">
								<div class="jyc-pc-head"><h3><?php echo esc_html__( '功能启用度明细', 'jinyu-theme-companion' ); ?></h3><span class="jyc-pct" id="jyc-pct">0%</span></div>
								<div class="jyc-pbar"><i id="jyc-pbarFill" style="width:0%"></i></div>
								<div class="jyc-checks" id="jyc-checks"></div>
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
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></span><?php echo esc_html__( '分享素材', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<label class="jyc-fl"><?php echo esc_html__( '默认分享图 (og:image)', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp" type="url" name="og_image" value="<?php echo esc_attr( $og_image ); ?>" placeholder="https://example.com/og-default.png">
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '文章无封面时用作分享缩略图；留空回退站点 Logo。建议 1200×630。', 'jinyu-theme-companion' ); ?></span>
									</label>
									<label class="jyc-fl"><?php echo esc_html__( '站点名称 (og:site_name)', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp" type="text" name="og_site_name" value="<?php echo esc_attr( $og_site ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
									</label>
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
							<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg></span><?php echo esc_html__( '站点验证（搜索引擎归属）', 'jinyu-theme-companion' ); ?></h2></div>
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
									<label class="jyc-fl"><?php echo esc_html__( '组织 / 品牌 sameAs 链接', 'jinyu-theme-companion' ); ?>
										<textarea class="jyc-inp" name="entity_sameas" rows="2" placeholder="https://twitter.com/yourname&#10;https://www.zhihu.com/people/yourname"><?php echo esc_textarea( $entity_sameas ); ?></textarea>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '每行一个 URL（官网 / 社交主页 / 维基等），写入结构化数据的 Organization.sameAs，强化品牌实体关联。', 'jinyu-theme-companion' ); ?></span>
									</label>
								</div>
							</div>
						</section>

						<!-- ===================== CONTENT ===================== -->
						<section id="pane-content" class="jyc-pane<?php echo 'content' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '内容增强', 'jinyu-theme-companion' ); ?></h1>
								<div class="jyc-sub"><?php echo esc_html__( '自动内链、主动推送与 AI 海报主色。提升内链权重与收录速度。', 'jinyu-theme-companion' ); ?></div></div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h7l-1 8 10-12h-7z"/></svg></span><?php echo esc_html__( '自动化', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '默认关闭，按需开启', 'jinyu-theme-companion' ); ?></span></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="auto_link_enable" <?php checked( $auto_link, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '自动内链', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '自动为文章关键词添加内链，强化站内权重传递。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="indexnow_enable" <?php checked( $indexnow, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( 'IndexNow 推送', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '发布 / 更新文章时向 IndexNow 提交 URL，加速收录。', 'jinyu-theme-companion' ); ?></div></div>
									</div>
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"/><path d="M4.9 19.1a10 10 0 0 1 0-14.2M19.1 4.9a10 10 0 0 1 0 14.2M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4"/></svg></span><?php echo esc_html__( '主动推送与品牌色', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<label class="jyc-fl"><?php echo esc_html__( '百度主动推送接口', 'jinyu-theme-companion' ); ?>
										<input class="jyc-inp jyc-inp-mono" type="url" name="baidu_submit_token" value="<?php echo esc_attr( $baidu_token ); ?>" placeholder="https://push.api.baidu.com/...token=">
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '百度搜索资源平台「主动推送」接口地址（含 token）。留空则不推送。', 'jinyu-theme-companion' ); ?></span>
									</label>
									<div class="jyc-fl"><?php echo esc_html__( 'AI 海报主色', 'jinyu-theme-companion' ); ?>
										<div class="jyc-color-row">
											<input type="color" name="style_color_primary" value="<?php echo esc_attr( $poster_color ); ?>">
											<span class="jyc-chip" id="jyc-colorChip"><?php echo esc_html( strtoupper( $poster_color ) ); ?></span>
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( 'AI 生成分享海报时的主色调。', 'jinyu-theme-companion' ); ?></span>
										</div>
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
									<div class="jyc-fl" style="margin-top:6px"><?php echo esc_html__( '缓存有效期', 'jinyu-theme-companion' ); ?>
										<div class="jyc-slider-wrap">
											<input type="range" name="page_cache_ttl" min="60" max="86400" step="60" value="<?php echo esc_attr( $page_cache_ttl ); ?>" id="jyc-ttlRange">
											<div class="jyc-slider-val"><span id="jyc-ttlVal"><?php echo esc_html( $_ttl_h ); ?></span><small id="jyc-ttlSec"><?php echo esc_html( $_ttl ); ?> 秒</small></div>
										</div>
										<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '范围 60 秒 ～ 24 小时，建议 1 小时。', 'jinyu-theme-companion' ); ?></span>
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
								</div>
							</div>
						</section>

						<!-- ===================== PERF CENTER（性能优化中心，自主题迁入） ===================== -->
						<section id="pane-perfcenter" class="jyc-pane<?php echo 'perfcenter' === $active_pane ? ' jyc-shown' : ''; ?>">
							<div class="jyc-mod-head"><h1><?php echo esc_html__( '性能中心', 'jinyu-theme-companion' ); ?></h1>
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
								</div>
							</div>

							<div class="jyc-panel">
								<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><?php echo esc_html__( '旧文评论', 'jinyu-theme-companion' ); ?></h2></div>
								<div class="jyc-panel-b">
									<div class="jyc-frow">
										<label class="jyc-switch"><input type="checkbox" name="close_comments_old" <?php checked( $close_old, '1' ); ?>><span class="jyc-track"></span></label>
										<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '自动关闭旧文评论', 'jinyu-theme-companion' ); ?></div>
											<div class="jyc-fdesc"><?php echo esc_html__( '超过设定天数后自动关闭评论，减少垃圾评论入口。', 'jinyu-theme-companion' ); ?></div></div>
										<label class="jyc-fl" style="margin:0;min-width:140px"><?php echo esc_html__( '天数', 'jinyu-theme-companion' ); ?>
											<input class="jyc-inp jyc-num" type="number" name="close_comments_days" value="<?php echo esc_attr( $close_days ); ?>" min="0" step="1">
										</label>
									</div>
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
									<div class="jyc-actions">
										<div class="jyc-actions-t"><?php echo esc_html__( '快捷操作', 'jinyu-theme-companion' ); ?></div>
										<div class="jyc-test-row">
											<button class="jyc-btn jyc-btn-soft" type="button" id="jyc-testStorage" data-loading="<?php echo esc_attr__( '测试中…', 'jinyu-theme-companion' ); ?>" onclick="window.jycTestStorage(this)"><?php echo esc_html__( '测试存储连接', 'jinyu-theme-companion' ); ?></button>
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '上传并删除一个测试文件验证连通性；可先于「保存」直接测试刚填的配置。', 'jinyu-theme-companion' ); ?></span>
										</div>
										<div class="jyc-test-row">
											<button class="jyc-btn jyc-btn-primary" type="button" onclick="window.jycStorageDomain(this,'jinyu_storage_apply_domain')"><?php echo esc_html__( '一键替换为 CDN 链接', 'jinyu-theme-companion' ); ?></button>
											<button class="jyc-btn jyc-btn-ghost" type="button" onclick="window.jycStorageDomain(this,'jinyu_storage_unapply_domain')"><?php echo esc_html__( '复原为本地链接', 'jinyu-theme-companion' ); ?></button>
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '按左侧「加速域名」当前填写值即时切换全部附件链接（含清缓存，无需先保存）；复原=清空域名回退本地，可随时切回。', 'jinyu-theme-companion' ); ?></span>
										</div>
										<div class="jyc-test-row">
											<button class="jyc-btn jyc-btn-ghost jyc-batch-btn" type="button" data-batch="push" data-label="<?php echo esc_attr__( '全量上传到云端', 'jinyu-theme-companion' ); ?>" onclick="window.jycStorageBatch(this,'push')"><?php echo esc_html__( '全量上传到云端', 'jinyu-theme-companion' ); ?></button>
											<button class="jyc-btn jyc-btn-ghost jyc-batch-btn" type="button" data-batch="pull" data-label="<?php echo esc_attr__( '拉回本地', 'jinyu-theme-companion' ); ?>" onclick="window.jycStorageBatch(this,'pull')"><?php echo esc_html__( '拉回本地', 'jinyu-theme-companion' ); ?></button>
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '批量把本地 uploads 推上桶 / 从桶拉回本地；自动分批续跑，中途关闭页面后再次进入会自动接着跑。', 'jinyu-theme-companion' ); ?></span>
										</div>
										<div class="jyc-test-row" id="jyc-batchProg" hidden>
											<div class="jyc-pbar" style="flex:1;min-width:180px"><i id="jyc-batchFill" style="width:0%"></i></div>
											<span class="jyc-muted jyc-num" id="jyc-batchMsg" style="font-size:12.5px"></span>
											<button class="jyc-btn jyc-btn-ghost" type="button" id="jyc-batchStop" hidden onclick="window.jycStorageStop()"><?php echo esc_html__( '停止任务', 'jinyu-theme-companion' ); ?></button>
										</div>
										<label class="jyc-fl" style="margin:0"><?php echo esc_html__( '同步指定资源', 'jinyu-theme-companion' ); ?>
											<textarea id="jyc-syncPaths" class="jyc-inp jyc-inp-mono" rows="3" placeholder="<?php echo esc_attr__( 'uploads 相对路径，每行一个，如 2026/09/photo.webp（也支持粘贴本站完整图片 URL）', 'jinyu-theme-companion' ); ?>"></textarea>
										</label>
										<div class="jyc-test-row">
											<button class="jyc-btn jyc-btn-soft jyc-sync-btn" type="button" data-batch="sync" data-label="<?php echo esc_attr__( '同步所选资源', 'jinyu-theme-companion' ); ?>" onclick="window.jycStorageSyncSelected(this)"><?php echo esc_html__( '同步所选资源', 'jinyu-theme-companion' ); ?></button>
											<span class="jyc-muted" style="font-size:12px"><?php echo esc_html__( '把指定的一个或多个文件并发推上云端（16 线程，量大自动分批、可中断续跑）；若开启「推送成功后删除本地原件」会同步删除本地。', 'jinyu-theme-companion' ); ?></span>
										</div>
									</div>
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
}
