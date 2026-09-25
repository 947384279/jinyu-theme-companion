<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * 后台：第三方登录（社交登录）设置面板
 * 作为配套插件设置面板「第三方登录」分区（pane-social）渲染。
 * 不单独挂菜单、不自带表单：复用主设置表单（#jyc-form）的 nonce 与整表提交，
 * 配置写入本模块自有选项 JINYU_SL_OPT。保存逻辑由 jinyu_sl_process_post() 提供，
 * 在主设置保存处理函数 jinyu_companion_handle_save() 内被调用。
 * ======================================================================== */

// 隐私政策提示：第三方登录会向对应平台共享用户标识/邮箱等数据（.org 隐私合规要求）。
add_action( 'admin_init', 'jinyu_sl_register_privacy' );
function jinyu_sl_register_privacy(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = sprintf(
		'<p>%s</p><ul>%s</ul>',
		esc_html__( '启用「第三方登录」后，用户使用以下平台账号登录本站时，本站会依据对应平台的 OAuth 协议向该平台发送并取回必要的用户标识信息（始终经用户显式授权）：', 'jinyu-theme-companion' ),
		'<li>GitHub（github.com）：用户 ID、公开昵称、头像、已验证邮箱（如用户授权）。隐私政策：https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement</li>'
		. '<li>Gitee（gitee.com）：用户 ID、昵称、头像。隐私政策：https://gitee.com/terms/privacy</li>'
		. '<li>QQ（graph.qq.com）：用户 OpenID、昵称、头像。隐私政策：https://privacy.qq.com/</li>'
		. '<li>Apple（appleid.apple.com）：用户 ID、昵称、邮箱（可选择隐藏邮箱并使用中继地址）。隐私政策：https://www.apple.com/legal/privacy/</li>'
	);
	wp_add_privacy_policy_content( 'Jinyu Theme Companion', wp_kses_post( $content ) );
}

/**
 * 整表保存时由 jinyu_companion_handle_save() 调用：处理社交登录配置。
 * 复用主表单 nonce（jinyu_companion_nonce），不在本函数内重定向 / exit。
 * 逻辑与历史私有插件保持一致（含占位掩码与旧密文沿用），保证已存加密密钥无缝接管。
 */
function jinyu_sl_process_post(): void {
	if ( ! isset( $_POST['jinyu_companion_save'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_POST['jinyu_companion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jinyu_companion_nonce'] ) ), 'jinyu_companion_settings' ) ) {
		return;
	}

	$enable = ! empty( $_POST['jinyu_sl_enable'] );

	// 回调地址：可自定义（多域名/子目录/CDN 场景）。空或==默认视为用内置固值；非法保留原值。
	$default_redirect = jinyu_sl_default_redirect_uri();
	$old_opt          = jinyu_sl_get_option();

	// 新用户注册闸门（勾选=允许；历史安装未存过该键时渲染层默认勾选）+ 新用户角色
	$allow_register = ! empty( $_POST['jinyu_sl_allow_register'] );
	$role           = isset( $_POST['jinyu_sl_role'] ) && in_array( $_POST['jinyu_sl_role'], [ 'subscriber', 'contributor', 'author' ], true )
		? sanitize_key( wp_unslash( $_POST['jinyu_sl_role'] ) )
		: ( $old_opt['role'] ?? 'subscriber' );
	$old_ruri         = ! empty( $old_opt['redirect_uri'] ) ? $old_opt['redirect_uri'] : '';
	$ruri             = isset( $_POST['jinyu_sl_redirect_uri'] )
		? trim( sanitize_text_field( wp_unslash( $_POST['jinyu_sl_redirect_uri'] ) ) )
		: $old_ruri;
	if ( '' === $ruri || $ruri === $default_redirect ) {
		$ruri = '';
	} elseif ( ! filter_var( $ruri, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $ruri ) ) {
		$ruri = $old_ruri;
	}

	$accounts = [];

	foreach ( jinyu_sl_providers() as $p => $prov ) {
		$post_id  = $_POST[ 'client_id_' . $p ] ?? '';
		$post_sec = $_POST[ 'client_secret_' . $p ] ?? '';
		$old_enc  = $_POST[ 'client_secret_old_' . $p ] ?? '';

		$row = [ 'client_id' => sanitize_text_field( $post_id ) ];
		// 掩码 •••••••• 不是真实密钥：提交它等价于「不修改」，须沿用原密文；
		// 否则会把占位符当密钥加密存储（曾导致 QQ 登录报 client secret is illegal）。
		if ( '' !== $post_sec && ! jinyu_sl_is_placeholder( $post_sec ) ) {
			$row['client_secret'] = jinyu_sl_encrypt( $post_sec );
		} elseif ( '' !== $old_enc ) {
			$row['client_secret'] = $old_enc; // 沿用原密文
		} else {
			$row['client_secret'] = '';
		}

		foreach ( $prov->config_fields() as $f ) {
			$fid = $f['id'];
			if ( 'client_secret' === $fid ) {
				continue;
			}
			$val = $_POST[ $fid . '_' . $p ] ?? '';
			if ( 'private_key' === $fid ) {
				$old_pk = $_POST[ 'private_key_old_' . $p ] ?? '';
				if ( '' !== $val && ! jinyu_sl_is_placeholder( $val ) ) {
					$row[ $fid ] = jinyu_sl_encrypt( $val );
				} elseif ( '' !== $old_pk ) {
					$row[ $fid ] = $old_pk;
				} else {
					$row[ $fid ] = '';
				}
			} else {
				$row[ $fid ] = sanitize_text_field( $val );
			}
		}

		$has_cred = ! empty( $row['client_secret'] ) || ! empty( $row['private_key'] );
		if ( '' !== $row['client_id'] || $has_cred ) {
			$accounts[ $p ] = $row;
		}
	}

	update_option( JINYU_SL_OPT, [ 'enable' => $enable, 'accounts' => $accounts, 'redirect_uri' => $ruri, 'allow_register' => $allow_register, 'role' => $role ] );
}

/** 判定提交值是否为「已设置，留空保持不变」的掩码占位（••••••••…）。 */
function jinyu_sl_is_placeholder( string $v ): bool {
	return '' !== $v && str_starts_with( (string) $v, '••••••••' );
}

/**
 * 品牌色派生色阶：#rrggbb + 透明度 → rgba()。
 * 用于卡片顶部描线 / 品牌淡底等「由平台色派生」的样式，统一服务端算好内联下传，
 * 前端只吃 token —— 规避 CSS color-mix() 在旧内核（Chrome <111 / Safari <16.2）静默失效的问题。
 */
function jinyu_sl_hex_rgba( string $hex, float $alpha ): string {
	$h = ltrim( trim( $hex ), '#' );
	if ( 3 === strlen( $h ) ) {
		$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
	}
	if ( 6 !== strlen( $h ) || ! ctype_xdigit( $h ) ) {
		return $hex;
	}
	return sprintf(
		'rgba(%d, %d, %d, %s)',
		hexdec( substr( $h, 0, 2 ) ),
		hexdec( substr( $h, 2, 2 ) ),
		hexdec( substr( $h, 4, 2 ) ),
		rtrim( rtrim( number_format( $alpha, 3, '.', '' ), '0' ), '.' )
	);
}

/** 社交登录配置面板（配套插件设置面板 pane-social 分区内渲染，无独立表单）。 */
function jinyu_sl_settings_pane(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opt      = jinyu_sl_get_option();
	$enable   = ! empty( $opt['enable'] );
	$accounts = $opt['accounts'] ?? [];
	$redirect = jinyu_sl_redirect_uri();
	$default  = jinyu_sl_default_redirect_uri();
	$provs    = jinyu_sl_providers();

	// 顶部统计：已完整配置（client_id + 密钥齐备）的平台数
	$ready = 0;
	foreach ( $provs as $p => $prov ) {
		$prov->set_config( $accounts[ $p ] ?? [] );
		if ( $prov->is_configured() ) {
			++$ready;
		}
	}
	?>
	<div class="jyc-mod-head"><h1><?php echo esc_html__( '第三方登录（社交登录）', 'jinyu-theme-companion' ); ?></h1>
		<div class="jyc-sub"><?php echo esc_html__( '启用后前台登录弹窗将显示对应入口。各平台密钥经 AES 加密存储于本站数据库，不会以明文落库。', 'jinyu-theme-companion' ); ?></div></div>

	<div class="jyc-sl-hero">
		<span class="jyc-sl-hero-aurora" aria-hidden="true"></span>
		<div class="jyc-sl-hero-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
		<div class="jyc-sl-hero-tx">
			<div class="jyc-sl-hero-t"><?php echo esc_html__( '第三方账号登录', 'jinyu-theme-companion' ); ?></div>
			<div class="jyc-sl-hero-d"><?php echo esc_html__( '开启后，访客可使用下方任一平台账号一键授权登录本站，无需记忆新密码。', 'jinyu-theme-companion' ); ?></div>
		</div>
		<div class="jyc-sl-hero-side">
			<div class="jyc-sl-hero-stat" title="<?php echo esc_attr__( '已完整填写凭据的平台数量', 'jinyu-theme-companion' ); ?>">
				<b class="jyc-num"><?php echo (int) $ready; ?></b>
				<span><?php echo esc_html( sprintf( __( '/ %d 已配置', 'jinyu-theme-companion' ), count( $provs ) ) ); ?></span>
			</div>
			<label class="jyc-switch jyc-switch-lg"><input type="checkbox" name="jinyu_sl_enable" <?php checked( $enable ); ?>><span class="jyc-track"></span></label>
		</div>
	</div>

	<div class="jyc-panel">
		<div class="jyc-panel-h"><h2><span class="jyc-section-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span><?php echo esc_html__( '新用户注册', 'jinyu-theme-companion' ); ?></h2><span class="jyc-hint"><?php echo esc_html__( '控制全新访客首次授权时的行为', 'jinyu-theme-companion' ); ?></span></div>
		<div class="jyc-panel-b">
			<div class="jyc-frow">
				<label class="jyc-switch"><input type="checkbox" name="jinyu_sl_allow_register" <?php checked( ! isset( $opt['allow_register'] ) || ! empty( $opt['allow_register'] ) ); ?>><span class="jyc-track"></span></label>
				<div class="jyc-grow"><div class="jyc-fname"><?php echo esc_html__( '允许自动注册', 'jinyu-theme-companion' ); ?></div>
					<div class="jyc-fdesc"><?php echo esc_html__( '关闭后，未绑定社交身份的新访客将被拒绝登录，需先注册本站账号再到个人中心绑定（已绑定用户不受影响）。', 'jinyu-theme-companion' ); ?></div></div>
			</div>
			<label class="jyc-fl" style="margin-top:6px"><?php echo esc_html__( '新用户默认角色', 'jinyu-theme-companion' ); ?>
				<select class="jyc-inp" name="jinyu_sl_role" style="max-width:320px">
					<option value="subscriber" <?php selected( $opt['role'] ?? 'subscriber', 'subscriber' ); ?>><?php echo esc_html__( '订阅者 Subscriber（推荐：仅能评论与维护个人资料）', 'jinyu-theme-companion' ); ?></option>
					<option value="contributor" <?php selected( $opt['role'] ?? '', 'contributor' ); ?>><?php echo esc_html__( '投稿者 Contributor（可投稿草稿，不可发布）', 'jinyu-theme-companion' ); ?></option>
					<option value="author" <?php selected( $opt['role'] ?? '', 'author' ); ?>><?php echo esc_html__( '作者 Author（可直接发布文章，慎选）', 'jinyu-theme-companion' ); ?></option>
				</select>
			</label>
		</div>
	</div>

	<div class="jyc-sl-redirect">
		<div class="jyc-sl-redirect-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
		<div class="jyc-sl-redirect-body">
			<div class="jyc-sl-redirect-head">
				<div class="jyc-fname"><?php echo esc_html__( '回调地址 (Redirect URI)', 'jinyu-theme-companion' ); ?></div>
				<div class="jyc-fdesc"><?php echo esc_html__( '在各平台开发者后台的「回调 / Redirect URI」处填写此地址（必须 HTTPS，Apple 需完全一致）。', 'jinyu-theme-companion' ); ?></div>
			</div>
			<div class="jyc-sl-redirect-ctrl">
				<input class="jyc-inp jyc-inp-mono" type="text" name="jinyu_sl_redirect_uri" value="<?php echo esc_attr( $redirect ); ?>" id="jyc-sl-redirect" data-default="<?php echo esc_attr( $default ); ?>" spellcheck="false" autocomplete="off">
				<button type="button" class="jyc-sl-reset" id="jyc-sl-reset-redirect" title="<?php echo esc_attr__( '恢复为默认地址', 'jinyu-theme-companion' ); ?>"><?php echo esc_html__( '恢复默认', 'jinyu-theme-companion' ); ?></button>
				<button type="button" class="jyc-sl-copy" data-copy="jyc-sl-redirect"><?php echo esc_html__( '复制', 'jinyu-theme-companion' ); ?></button>
			</div>
		</div>
	</div>

	<div class="jyc-sl-grid">
		<?php foreach ( $provs as $p => $prov ) : ?>
			<?php
				$cfg        = $accounts[ $p ] ?? [];
				$cid        = $cfg['client_id'] ?? '';
				$sec_enc    = $cfg['client_secret'] ?? '';
				$uses_cs    = $prov->uses_client_secret();
				$pk_enc     = $cfg['private_key'] ?? '';
				$pc         = $prov->color();
				$console    = $prov->console_url();
				$prov->set_config( $cfg );
				$configured = $prov->is_configured();
				$partial    = ! $configured && ( '' !== $cid || '' !== $sec_enc || '' !== $pk_enc );
				$status     = $configured ? 'is-ok' : ( $partial ? 'is-part' : 'is-wait' );
				$status_tx  = $configured
					? __( '已配置', 'jinyu-theme-companion' )
					: ( $partial ? __( '待完善', 'jinyu-theme-companion' ) : __( '未配置', 'jinyu-theme-companion' ) );

				// 字段栅格为两列：半宽字段总数为奇数时（如 Apple 的 client_id + Team ID + Key ID），
				// 让 client_id 独占一整行，否则行尾会留下一个空白格；字段总数 ≥4 的卡片在宽屏独占一整行。
				$fields_total = 1 + ( $uses_cs ? 1 : 0 ) + count( $prov->config_fields() );
				$textarea_num = 0;
				foreach ( $prov->config_fields() as $f ) {
					if ( 'textarea' === ( $f['type'] ?? '' ) ) {
						++$textarea_num;
					}
				}
				$cid_full = 1 === ( $fields_total - $textarea_num ) % 2;
				$wide     = $fields_total >= 4;
			?>
			<div class="jyc-sl-card<?php echo $wide ? ' jyc-sl-card-wide' : ''; ?>" style="--pc:<?php echo esc_attr( $pc ); ?>;--pc-l:<?php echo esc_attr( jinyu_sl_hex_rgba( $pc, 0.34 ) ); ?>;--pc-t:<?php echo esc_attr( jinyu_sl_hex_rgba( $pc, 0.13 ) ); ?>">
				<div class="jyc-sl-card-hd">
					<span class="jyc-sl-badge" aria-hidden="true"><?php echo jinyu_sl_icon_markup( $prov->icon() ); ?></span>
					<div class="jyc-sl-card-tx">
						<div class="jyc-sl-name"><?php echo esc_html( $prov->label() ); ?></div>
						<div class="jyc-sl-id"><?php echo esc_html( $prov->id() ); ?></div>
					</div>
					<span class="jyc-sl-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_tx ); ?></span>
				</div>

				<div class="jyc-sl-card-bd">
					<div class="jyc-sl-fields">
						<label class="jyc-fl<?php echo $cid_full ? ' jyc-full' : ''; ?>"><span class="jyc-sl-lb"><?php echo esc_html__( 'App ID / Client ID', 'jinyu-theme-companion' ); ?><?php if ( $configured || '' !== $cid ) : ?><em class="jyc-sl-saved"><?php echo esc_html__( '已填写', 'jinyu-theme-companion' ); ?></em><?php endif; ?></span>
							<input class="jyc-inp jyc-inp-mono" type="text" name="client_id_<?php echo esc_attr( $p ); ?>" value="<?php echo esc_attr( $cid ); ?>" placeholder="—" spellcheck="false" autocomplete="off">
						</label>
						<?php if ( $uses_cs ) : ?>
						<label class="jyc-fl"><span class="jyc-sl-lb"><?php echo esc_html__( 'App Key / Client Secret', 'jinyu-theme-companion' ); ?><?php if ( $sec_enc ) : ?><em class="jyc-sl-saved"><?php echo esc_html__( '已保存', 'jinyu-theme-companion' ); ?></em><?php endif; ?></span>
							<input class="jyc-inp" type="password" name="client_secret_<?php echo esc_attr( $p ); ?>" value="" autocomplete="new-password" placeholder="<?php echo $sec_enc ? esc_attr__( '••••••••（留空保持不变）', 'jinyu-theme-companion' ) : esc_attr__( '—', 'jinyu-theme-companion' ); ?>">
						</label>
						<?php if ( $sec_enc ) : ?>
							<input type="hidden" name="client_secret_old_<?php echo esc_attr( $p ); ?>" value="<?php echo esc_attr( $sec_enc ); ?>">
						<?php endif; ?>
						<?php endif; ?>

						<?php foreach ( $prov->config_fields() as $f ) : ?>
							<?php if ( 'client_secret' === $f['id'] ) continue; ?>
							<?php
								$is_pk = 'private_key' === $f['id'];
								$val   = $is_pk ? '' : ( $cfg[ $f['id'] ] ?? '' );
								// 多行文本框独占整行；单行字段走两列栅格（与上方 client_id 的奇偶规则配套）
								$f_full = 'textarea' === ( $f['type'] ?? '' );
							?>
							<label class="jyc-fl<?php echo $f_full ? ' jyc-full' : ''; ?>"><span class="jyc-sl-lb"><?php echo esc_html( $f['label'] ); ?><?php if ( $is_pk && $pk_enc ) : ?><em class="jyc-sl-saved"><?php echo esc_html__( '已保存', 'jinyu-theme-companion' ); ?></em><?php endif; ?></span>
								<?php if ( $is_pk ) : ?>
									<textarea class="jyc-inp" name="private_key_<?php echo esc_attr( $p ); ?>" rows="3" placeholder="<?php echo ! empty( $pk_enc ) ? esc_attr__( '••••••••（已设置，留空保持不变）', 'jinyu-theme-companion' ) : esc_attr__( '—', 'jinyu-theme-companion' ); ?>"></textarea>
									<span class="jyc-sl-hint"><?php echo esc_html__( '粘贴密钥文件完整内容，需包含首尾 BEGIN / END 行。', 'jinyu-theme-companion' ); ?></span>
								<?php else : ?>
									<input class="jyc-inp jyc-inp-mono" type="text" name="<?php echo esc_attr( $f['id'] . '_' . $p ); ?>" value="<?php echo esc_attr( $val ); ?>" placeholder="—" spellcheck="false" autocomplete="off">
								<?php endif; ?>
							</label>
							<?php if ( $is_pk && ! empty( $pk_enc ) ) : ?>
								<input type="hidden" name="private_key_old_<?php echo esc_attr( $p ); ?>" value="<?php echo esc_attr( $pk_enc ); ?>">
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="jyc-sl-card-ft">
					<span class="jyc-sl-lock"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><?php echo esc_html__( '凭据加密存储', 'jinyu-theme-companion' ); ?></span>
					<?php if ( '' !== $console ) : ?>
						<a class="jyc-sl-console" href="<?php echo esc_url( $console ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( '开发者后台', 'jinyu-theme-companion' ); ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
