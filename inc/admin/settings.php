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
		__( 'jinyu-theme-companion', 'jinyu-theme-companion' ),
		__( 'jinyu-theme-companion', 'jinyu-theme-companion' ),
		'manage_options',
		'jinyu-theme-companion',
		'jinyu_companion_settings_page_html',
		'dashicons-admin-generic',
		60
	);
}

add_action( 'admin_init', 'jinyu_companion_handle_save' );
function jinyu_companion_handle_save(): void {
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
	foreach ( [ 'seo_open', 'twitter_card_enable', 'llms_enable', 'auto_link_enable', 'indexnow_enable', 'close_comments_old', 'page_cache_enable' ] as $k ) {
		$settings[ $k ] = isset( $_POST[ $k ] ) ? '1' : '0';
	}

	// 文本 / URL / 颜色
	$settings['og_image']        = isset( $_POST['og_image'] ) ? esc_url_raw( wp_unslash( $_POST['og_image'] ) ) : '';
	$settings['og_site_name']    = isset( $_POST['og_site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['og_site_name'] ) ) : '';
	$settings['baidu_submit_token'] = isset( $_POST['baidu_submit_token'] ) ? esc_url_raw( wp_unslash( $_POST['baidu_submit_token'] ) ) : '';
	$settings['anti_spam_words'] = isset( $_POST['anti_spam_words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['anti_spam_words'] ) ) : '';
	$settings['style_color_primary'] = isset( $_POST['style_color_primary'] ) ? sanitize_hex_color( wp_unslash( $_POST['style_color_primary'] ) ) : '';
	$settings['close_comments_days'] = isset( $_POST['close_comments_days'] ) ? (int) wp_unslash( $_POST['close_comments_days'] ) : 0;
	$settings['page_cache_ttl'] = isset( $_POST['page_cache_ttl'] ) ? max( 60, (int) wp_unslash( $_POST['page_cache_ttl'] ) ) : 3600;

	// 验证码策略
	$allowed_policy = [ 'smart', 'always', 'off' ];
	$settings['captcha_policy'] = isset( $_POST['captcha_policy'] ) && in_array( $_POST['captcha_policy'], $allowed_policy, true )
		? sanitize_key( wp_unslash( $_POST['captcha_policy'] ) )
		: 'smart';

	// SMTP
	$settings['smtp_host']   = isset( $_POST['smtp_host'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_host'] ) ) : '';
	$settings['smtp_port']   = isset( $_POST['smtp_port'] ) ? (int) wp_unslash( $_POST['smtp_port'] ) : 0;
	$settings['smtp_secure'] = isset( $_POST['smtp_secure'] ) && in_array( $_POST['smtp_secure'], [ 'ssl', 'tls', 'none' ], true )
		? sanitize_key( wp_unslash( $_POST['smtp_secure'] ) )
		: 'ssl';
	$settings['smtp_user']   = isset( $_POST['smtp_user'] ) ? sanitize_text_field( wp_unslash( $_POST['smtp_user'] ) ) : '';
	$settings['smtp_from']   = isset( $_POST['smtp_from'] ) ? sanitize_email( wp_unslash( $_POST['smtp_from'] ) ) : '';
	// 密码留空则保留原值（避免保存时误清空）
	if ( isset( $_POST['smtp_pwd'] ) && '' !== (string) wp_unslash( $_POST['smtp_pwd'] ) ) {
		$settings['smtp_pwd'] = sanitize_text_field( wp_unslash( $_POST['smtp_pwd'] ) );
	}

	update_option( 'jinyu_companion_settings', $settings );
	add_settings_error( 'jinyu_companion', 'saved', __( '设置已保存', 'jinyu-theme-companion' ), 'updated' );
}

function jinyu_companion_settings_page_html(): void {
	$seo_open     = jinyu_companion_get_option( 'seo_open', '1' );
	$twitter      = jinyu_companion_get_option( 'twitter_card_enable', '1' );
	$llms         = jinyu_companion_get_option( 'llms_enable', '1' );
	$og_image     = jinyu_companion_get_option( 'og_image', '' );
	$og_site      = jinyu_companion_get_option( 'og_site_name', '' );
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
	$page_cache_enable = jinyu_companion_get_option( 'page_cache_enable', '0' );
	$page_cache_ttl   = jinyu_companion_get_option( 'page_cache_ttl', '3600' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'jinyu-theme-companion', 'jinyu-theme-companion' ); ?></h1>
		<p><?php echo esc_html__( '金玉主题配套插件的统一设置面板。所有开关与配置均存于插件独立 option，不受主题设置导入 / 重置影响。可脱离金玉主题独立运行。', 'jinyu-theme-companion' ); ?></p>
		<?php settings_errors( 'jinyu_companion' ); ?>
		<form method="post">
			<?php wp_nonce_field( 'jinyu_companion_settings', 'jinyu_companion_nonce' ); ?>
			<input type="hidden" name="jinyu_companion_save" value="1">

			<h2><?php echo esc_html__( 'SEO / 社交分享', 'jinyu-theme-companion' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( 'SEO / Open Graph', 'jinyu-theme-companion' ); ?></th>
					<td>
						<label><input type="checkbox" name="seo_open" value="1" <?php checked( $seo_open, '1' ); ?>>
						<?php echo esc_html__( '启用 SEO 元标签与 Open Graph（微信 / QQ 分享卡片）', 'jinyu-theme-companion' ); ?></label>
						<p class="description"><?php echo esc_html__( '关闭后将不再输出 OG / description 标签，社交分享退化为纯文本链接。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Twitter 卡片', 'jinyu-theme-companion' ); ?></th>
					<td><label><input type="checkbox" name="twitter_card_enable" value="1" <?php checked( $twitter, '1' ); ?>>
						<?php echo esc_html__( '输出 Twitter Card 标签', 'jinyu-theme-companion' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'llms.txt 发现链接', 'jinyu-theme-companion' ); ?></th>
					<td><label><input type="checkbox" name="llms_enable" value="1" <?php checked( $llms, '1' ); ?>>
						<?php echo esc_html__( '在头部输出 llms.txt 发现链接', 'jinyu-theme-companion' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '默认分享图 (og:image)', 'jinyu-theme-companion' ); ?></th>
					<td>
						<input type="url" name="og_image" value="<?php echo esc_attr( $og_image ); ?>" class="regular-text"
							placeholder="https://example.com/og-default.png">
						<p class="description"><?php echo esc_html__( '文章无封面时用作分享缩略图；留空回退站点 Logo / 站点图标。建议 1200×630。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '站点名称 (og:site_name)', 'jinyu-theme-companion' ); ?></th>
					<td>
						<input type="text" name="og_site_name" value="<?php echo esc_attr( $og_site ); ?>" class="regular-text"
							placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						<p class="description"><?php echo esc_html__( '留空使用站点标题。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( '内容增强', 'jinyu-theme-companion' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( '自动内链', 'jinyu-theme-companion' ); ?></th>
					<td><label><input type="checkbox" name="auto_link_enable" value="1" <?php checked( $auto_link, '1' ); ?>>
						<?php echo esc_html__( '自动为文章关键词添加内链', 'jinyu-theme-companion' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'IndexNow 推送', 'jinyu-theme-companion' ); ?></th>
					<td>
						<label><input type="checkbox" name="indexnow_enable" value="1" <?php checked( $indexnow, '1' ); ?>>
						<?php echo esc_html__( '发布 / 更新文章时向 IndexNow 提交 URL', 'jinyu-theme-companion' ); ?></label>
						<p class="description"><?php echo esc_html__( '需先在「设置 › 固定链接 / 索引」配置 IndexNow 密钥（存于 WP 选项 jinyu_indexnow_key）。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '百度主动推送', 'jinyu-theme-companion' ); ?></th>
					<td>
						<input type="url" name="baidu_submit_token" value="<?php echo esc_attr( $baidu_token ); ?>" class="regular-text"
							placeholder="https://push.api.baidu.com/...token=">
						<p class="description"><?php echo esc_html__( '百度搜索资源平台「主动推送」接口地址（含 token）。留空则不推送。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'AI 海报主色', 'jinyu-theme-companion' ); ?></th>
					<td>
						<input type="color" name="style_color_primary" value="<?php echo esc_attr( $poster_color ); ?>">
						<p class="description"><?php echo esc_html__( 'AI 生成分享海报时的主色调。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( '性能 / 整页缓存', 'jinyu-theme-companion' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( '启用整页缓存', 'jinyu-theme-companion' ); ?></th>
					<td>
						<label><input type="checkbox" name="page_cache_enable" value="1" <?php checked( $page_cache_enable, '1' ); ?>>
						<?php echo esc_html__( '为未登录访客缓存整页 HTML（显著提升匿名访问性能）', 'jinyu-theme-companion' ); ?></label>
						<p class="description"><?php echo esc_html__( '默认关闭。启用后缓存命中时来源统计将自动补偿。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '缓存有效期', 'jinyu-theme-companion' ); ?></th>
					<td>
						<input type="number" name="page_cache_ttl" value="<?php echo esc_attr( $page_cache_ttl ); ?>" min="60" step="60" class="small-text">
						<?php echo esc_html__( '秒', 'jinyu-theme-companion' ); ?>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( '评论与互动', 'jinyu-theme-companion' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( '验证码策略', 'jinyu-theme-companion' ); ?></th>
					<td>
						<select name="captcha_policy">
							<option value="smart" <?php selected( $captcha, 'smart' ); ?>><?php echo esc_html__( '智能（失败过多时启用）', 'jinyu-theme-companion' ); ?></option>
							<option value="always" <?php selected( $captcha, 'always' ); ?>><?php echo esc_html__( '始终启用', 'jinyu-theme-companion' ); ?></option>
							<option value="off" <?php selected( $captcha, 'off' ); ?>><?php echo esc_html__( '关闭', 'jinyu-theme-companion' ); ?></option>
						</select>
						<p class="description"><?php echo esc_html__( '登录 / 评论场景的验证码触发策略。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '垃圾评论关键词', 'jinyu-theme-companion' ); ?></th>
					<td>
						<textarea name="anti_spam_words" rows="2" class="large-text"><?php echo esc_textarea( $spam_words ); ?></textarea>
						<p class="description"><?php echo esc_html__( '逗号分隔，命中即判为垃圾评论。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '自动关闭旧文评论', 'jinyu-theme-companion' ); ?></th>
					<td>
						<label><input type="checkbox" name="close_comments_old" value="1" <?php checked( $close_old, '1' ); ?>>
						<?php echo esc_html__( '超过以下天数后自动关闭评论', 'jinyu-theme-companion' ); ?></label>
						<input type="number" name="close_comments_days" value="<?php echo esc_attr( $close_days ); ?>" min="0" step="1" class="small-text">
						<?php echo esc_html__( '天', 'jinyu-theme-companion' ); ?>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( '邮件 SMTP', 'jinyu-theme-companion' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php echo esc_html__( 'SMTP 主机', 'jinyu-theme-companion' ); ?></th>
					<td><input type="text" name="smtp_host" value="<?php echo esc_attr( $smtp_host ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '端口', 'jinyu-theme-companion' ); ?></th>
					<td><input type="number" name="smtp_port" value="<?php echo esc_attr( $smtp_port ); ?>" min="0" step="1" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '加密方式', 'jinyu-theme-companion' ); ?></th>
					<td>
						<select name="smtp_secure">
							<option value="ssl" <?php selected( $smtp_secure, 'ssl' ); ?>>SSL</option>
							<option value="tls" <?php selected( $smtp_secure, 'tls' ); ?>>TLS</option>
							<option value="none" <?php selected( $smtp_secure, 'none' ); ?>><?php echo esc_html__( '不加密', 'jinyu-theme-companion' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '用户名', 'jinyu-theme-companion' ); ?></th>
					<td><input type="text" name="smtp_user" value="<?php echo esc_attr( $smtp_user ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '密码', 'jinyu-theme-companion' ); ?></th>
					<td>
						<input type="password" name="smtp_pwd" value="" class="regular-text" autocomplete="new-password">
						<p class="description"><?php echo esc_html__( '留空表示不修改（保留已保存的密码）。', 'jinyu-theme-companion' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '发件地址', 'jinyu-theme-companion' ); ?></th>
					<td><input type="email" name="smtp_from" value="<?php echo esc_attr( $smtp_from ); ?>" class="regular-text"></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
