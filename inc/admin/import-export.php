<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 设置导入 / 导出（面板分区「配置备份」）。
 * --------------------------------------------------------------------------
 * 设计要点：
 *  - 导出的是插件独立选项 jinyu_companion_settings，与主题 jinyu_options 无关，
 *    主题侧「导入 / 重置」不会连带影响本文件产物。
 *  - 敏感凭据（SMTP 密码 / 微信 AppSecret / 对象存储 Secret）导出时一律剔除：
 *   库内是加密密文，跨站迁移后密钥不匹配也解不开，带过去只有误导；
 *   导入侧同样拒绝接收这三个键，双向保持「凭据必须在新站点重新填写」。
 *  - 导入走白名单 + 类型规格（bool / int / text / url），未知键与类型不符的值直接丢弃，
 *    不信任导入文件里的任何内容，不把任意键写进 options 表。
 *  - 导入是「合并覆盖」语义：仅处理文件内出现的键，缺省键保持现有值，不做全量替换，
 *    避免一次导入把新站点上手工调整过的其它开关打回原样。
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! function_exists( 'jinyu_companion_io_spec' ) ) {
	/**
	 * 可导入的设置键与类型规格（唯一事实源）。
	 *
	 * bool 只接受 '1'/'0'（与其它模块 jinyu_companion_is_checked 的判定口径一致）；
	 * int  落库前强制取整；url 走 esc_url_raw；text 走 sanitize_textarea_field
	 * （textarea 版对换行友好，短文本同样安全）。
	 *
	 * @return array<string,string> key => bool|int|text|url
	 */
	function jinyu_companion_io_spec(): array {
		return array(
			// 核心呈现开关
			'seo_open'                => 'bool',
			'seo_content_h1_fix'      => 'bool',
			'twitter_card_enable'     => 'bool',
			'og_article_meta'         => 'bool',
			'llms_enable'             => 'bool',
			// 内容增强
			'auto_link_enable'        => 'bool',
			'auto_link_limit'         => 'int',
			'indexnow_enable'         => 'bool',
			// 前台加速
			'page_cache_enable'       => 'bool',
			'page_cache_ttl'          => 'int',
			'page_cache_exclude_paths'   => 'text',
			'page_cache_ignore_params'   => 'text',
			'page_cache_exclude_params'  => 'text',
			'speculation_enable'      => 'bool',
			'speculation_mode'        => 'text',
			'speculation_eagerness'   => 'text',
			// SEO / 社交文本
			'og_image'                => 'url',
			'og_site_name'            => 'text',
			'og_image_alt'            => 'text',
			'twitter_site'            => 'text',
			'twitter_creator'         => 'text',
			'fb_app_id'               => 'text',
			'entity_sameas'           => 'text',
			'org_logo_url'            => 'url',
			'author_sameas'           => 'text',
			'seo_keywords'            => 'text',
			'seo_desc'                => 'text',
			'no_category_enable'      => 'bool',
			'ld_json_enable'          => 'bool',
			'style_color_primary'     => 'text',
			// 站点验证
			'verify_google'           => 'text',
			'verify_bing'             => 'text',
			'verify_baidu'            => 'text',
			'verify_yandex'           => 'text',
			'verify_360'              => 'text',
			// 推送
			'baidu_submit_token'      => 'url',
			// 评论 / 反垃圾
			'close_comments_old'      => 'bool',
			'close_comments_days'     => 'int',
			'comment_freq_enable'     => 'bool',
			'comment_freq_max'        => 'int',
			'comment_freq_window'     => 'int',
			'captcha_policy'          => 'text',
			'comment_notify_reply'    => 'bool',
			'comment_notify_blocked'  => 'bool',
			'comment_notify_approved' => 'bool',
			'comment_notify_author'   => 'bool',
			'anti_spam_words'         => 'text',
			// 邮件
			'smtp_host'               => 'text',
			'smtp_port'               => 'int',
			'smtp_secure'             => 'text',
			'smtp_user'               => 'text',
			'smtp_from'               => 'text',
			'smtp_from_name'          => 'text',
			// 图片 SEO
			'img_alt_enable'          => 'bool',
			'img_dim_enable'          => 'bool',
			// 对象存储
			'storage_provider'        => 'text',
			'storage_bucket'          => 'text',
			'storage_region'          => 'text',
			'storage_endpoint'        => 'text',
			'storage_access_key'      => 'text',
			'storage_domain'          => 'url',
			'storage_prefix'          => 'text',
			'storage_rewrite'         => 'bool',
			'storage_auto_upload'     => 'bool',
			'storage_delete_local'    => 'bool',
			'storage_sync_extra'      => 'bool',
			'storage_exclude_dirs'    => 'text',
			'storage_exclude_exts'    => 'text',
			// 微信分享
			'wechat_share_enable'     => 'bool',
			'wechat_share_debug'      => 'bool',
			'wechat_appid'            => 'text',
		);
	}
}

if ( ! function_exists( 'jinyu_companion_io_secret_keys' ) ) {
	/**
	 * 凭据类键：导出剔除、导入拒绝。
	 *
	 * @return string[]
	 */
	function jinyu_companion_io_secret_keys(): array {
		return array( 'smtp_pwd', 'wechat_appsecret', 'storage_secret' );
	}
}

if ( ! function_exists( 'jinyu_companion_io_max_upload' ) ) {
	/**
	 * 导入文件体积上限（字节）。设置总量很小，512KB 足够覆盖异常大文本域。
	 *
	 * @return int
	 */
	function jinyu_companion_io_max_upload(): int {
		return 512 * 1024;
	}
}

if ( ! function_exists( 'jinyu_companion_io_export_url' ) ) {
	/**
	 * 导出下载链接（内置 nonce）。
	 *
	 * @param bool $full true 为含凭据完整备份（前端在用户勾选风险确认后才切换到此链接）。
	 * @return string
	 */
	function jinyu_companion_io_export_url( bool $full = false ): string {
		$args = array(
			'page'       => 'jinyu-theme-companion',
			'jinyu_export' => '1',
		);
		if ( $full ) {
			$args['full'] = '1';
		}
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		return wp_nonce_url( $url, 'jinyu_companion_export' );
	}
}

if ( ! function_exists( 'jinyu_companion_io_perf_export' ) ) {
	/**
	 * 性能中心开关导出（独立 option jinyu_perf_options，全部为 0/1 非敏感开关）。
	 *
	 * @return array
	 */
	function jinyu_companion_io_perf_export(): array {
		if ( ! function_exists( 'jinyu_perf_get_options' ) ) {
			return array();
		}
		// 解析后的完整开关集（含默认值），保证新站点导入即得一致行为。
		return jinyu_perf_get_options();
	}
}

if ( ! function_exists( 'jinyu_companion_io_social_login_export' ) ) {
	/**
	 * 第三方登录导出（独立 option jinyu_social_login）。
	 * 默认仅含非凭据字段；$with_secrets=true 时（用户显式确认风险的完整备份）
	 * client_secret / private_key 的库内密文一并写入——同站恢复可直接写回，
	 * 跨站因密钥不同解不开，须重新填写。
	 *
	 * @param bool $with_secrets 是否包含凭据密文。
	 * @return array
	 */
	function jinyu_companion_io_social_login_export( bool $with_secrets = false ): array {
		if ( ! function_exists( 'jinyu_sl_get_option' ) || ! function_exists( 'jinyu_sl_providers' ) ) {
			return array();
		}
		$opt = jinyu_sl_get_option();
		$out = array(
			'enable'         => ! empty( $opt['enable'] ),
			'redirect_uri'   => isset( $opt['redirect_uri'] ) ? (string) $opt['redirect_uri'] : '',
			'allow_register' => ! empty( $opt['allow_register'] ),
			'role'           => isset( $opt['role'] ) ? (string) $opt['role'] : 'subscriber',
			'accounts'       => array(),
		);

		$accounts = isset( $opt['accounts'] ) && is_array( $opt['accounts'] ) ? $opt['accounts'] : array();
		foreach ( jinyu_sl_providers() as $p => $prov ) {
			$acct = $accounts[ $p ] ?? array();
			if ( ! is_array( $acct ) ) {
				continue;
			}
			$secret_field = $prov->uses_client_secret() ? 'client_secret' : 'private_key';
			$row          = array(
				'client_id' => isset( $acct['client_id'] ) ? (string) $acct['client_id'] : '',
			);
			foreach ( $prov->config_fields() as $f ) {
				$fid = $f['id'];
				if ( $fid === $secret_field && ! $with_secrets ) {
					continue; // 凭据字段剔除（完整备份除外）
				}
				$row[ $fid ] = isset( $acct[ $fid ] ) ? (string) $acct[ $fid ] : '';
			}
			if ( '' !== $row['client_id'] ) {
				$out['accounts'][ $p ] = $row;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'jinyu_companion_io_indexnow_export' ) ) {
	/**
	 * IndexNow 验证密钥导出（独立 option jinyu_indexnow_key，明文公开验证，非隐私）。
	 *
	 * @return string
	 */
	function jinyu_companion_io_indexnow_export(): string {
		return (string) get_option( 'jinyu_indexnow_key', '' );
	}
}

if ( ! function_exists( 'jinyu_companion_io_payload' ) ) {
	/**
	 * 导出数据体。默认凭据类键剔除；$with_secrets=true（用户在 UI 显式勾选确认风险）
	 * 时凭据密文一并写入，供同站快照恢复；跨站导入时密文解不开，须重新填写。
	 *
	 * @param bool $with_secrets 是否包含凭据密文。
	 * @return array{plugin:string,version:string,exported_at:string,site:string,includes_secrets:bool,settings:array,perf_options:array,social_login:array,indexnow_key:string}
	 */
	function jinyu_companion_io_payload( bool $with_secrets = false ): array {
		$settings = jinyu_companion_get_settings();
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( ! $with_secrets ) {
			foreach ( jinyu_companion_io_secret_keys() as $secret ) {
				unset( $settings[ $secret ] );
			}
		}

		$payload = array(
			'plugin'       => 'jinyu-theme-companion',
			'version'      => defined( 'JINYU_COMPANION_VER' ) ? (string) JINYU_COMPANION_VER : '1.0.0',
			'exported_at'  => gmdate( 'c' ),
			'site'         => get_bloginfo( 'url' ),
			'settings'     => $settings,
			'perf_options' => jinyu_companion_io_perf_export(),
			'social_login' => jinyu_companion_io_social_login_export( $with_secrets ),
			'indexnow_key' => jinyu_companion_io_indexnow_export(),
		);
		if ( $with_secrets ) {
			// 明确标记：导入侧据此走「凭据同站恢复」分支。
			$payload = array_merge(
				array_slice( $payload, 0, 4, true ),
				array( 'includes_secrets' => true ),
				array_slice( $payload, 4, null, true )
			);
		}
		return $payload;
	}
}

if ( ! function_exists( 'jinyu_companion_handle_export' ) ) {
	/**
	 * 导出响应：admin_init 上按需触发，直接吐 JSON 附件后终止请求。
	 * full=1 为「含凭据完整备份」：链接仅由前端在用户勾选风险确认后切换，
	 * nonce / capability 校验不变；能进后台改配置的管理员本就能读到库内配置，
	 * 故安全边界不因此放宽，文件名加 -full 便于识别。
	 */
	function jinyu_companion_handle_export(): void {
		if ( ! isset( $_GET['jinyu_export'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足', 'jinyu-theme-companion' ), 403 );
		}
		check_admin_referer( 'jinyu_companion_export' );

		$full = ( isset( $_GET['full'] ) && '1' === $_GET['full'] );

		$json = wp_json_encode(
			jinyu_companion_io_payload( $full ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( false === $json ) {
			wp_die( esc_html__( '导出失败：数据无法编码', 'jinyu-theme-companion' ) );
		}

		$filename  = 'jinyu-companion-settings' . ( $full ? '-full' : '' ) . '-' . gmdate( 'Ymd-His' ) . '.json';
		$timestamp = gmdate( 'D, d M Y H:i:s' ) . ' GMT';

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: ' . $timestamp );
			header( 'Content-Length: ' . (string) strlen( $json ) );
		}

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON 附件，非 HTML 上下文。
		exit;
	}
}

if ( ! function_exists( 'jinyu_companion_import_apply' ) ) {
	/**
	 * 把导入文件里的 setting 数组合并进当前设置（仅白名单键 + 类型收敛）。
	 *
	 * 返回值供 UI 展示「新增 / 覆盖 / 忽略」三段统计，不做静默处理。
	 *
	 * @param array $incoming 导入文件内的 settings。
	 * @return array{changed:array,added:int,updated:int,ignored:int,skipped:array}
	 */
	function jinyu_companion_import_apply( array $incoming ): array {
		if ( ! is_array( $incoming ) ) {
			$incoming = array();
		}

		$spec     = jinyu_companion_io_spec();
		$secrets  = jinyu_companion_io_secret_keys();
		$current  = jinyu_companion_get_settings();
		$next     = $current;
		$added    = 0;
		$updated  = 0;
		$ignored  = 0;
		$skipped  = array();

		foreach ( $incoming as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $spec[ $key ] ) ) {
				++$ignored;
				continue;
			}
			if ( in_array( $key, $secrets, true ) ) {
				// 凭据键：完整备份（includes_secrets）里是库内密文，同站恢复可原样写回
				// （不做 sanitize，避免破坏密文编码）；跨站因密钥不同解不开，等于无效但无害。
				if ( is_string( $value ) && '' !== trim( $value ) && (string) ( $current[ $key ] ?? '' ) !== $value ) {
					$next[ $key ] = $value;
					++$updated;
				} else {
					$skipped[] = $key;
				}
				continue;
			}

			$type     = $spec[ $key ];
			$cleaned  = '';
			if ( 'bool' === $type ) {
				$cleaned = ( '1' === (string) $value || true === $value || 1 === $value ) ? '1' : '0';
			} elseif ( 'int' === $type ) {
				$cleaned = (int) $value;
			} elseif ( 'url' === $type ) {
				$cleaned = is_string( $value ) ? esc_url_raw( trim( wp_unslash( $value ) ) ) : '';
			} else {
				$cleaned = is_string( $value ) ? sanitize_textarea_field( wp_unslash( $value ) ) : '';
			}

			if ( array_key_exists( $key, $current ) ) {
				if ( (string) $current[ $key ] === (string) $cleaned ) {
					continue; // 值未变，不写库
				}
				$next[ $key ] = $cleaned;
				++$updated;
				continue;
			}

			$next[ $key ] = $cleaned;
			++$added;
		}

		if ( $added > 0 || $updated > 0 ) {
			jinyu_companion_save_settings( $next );
		}

		return array(
			'changed'  => array_keys( $next ),
			'added'    => $added,
			'updated'  => $updated,
			'ignored'  => $ignored,
			'skipped'  => $skipped,
			'no_change' => 0 === $added && 0 === $updated,
		);
	}
}

if ( ! function_exists( 'jinyu_companion_import_perf' ) ) {
	/**
	 * 导入性能中心开关（白名单 key，仅写回 jinyu_perf_toggle_meta 列出的键）。
	 *
	 * @param mixed $incoming 导出文件内的 perf_options。
	 * @return array{updated:int,ignored:int}
	 */
	function jinyu_companion_import_perf( $incoming ): array {
		if ( ! is_array( $incoming ) || ! function_exists( 'jinyu_perf_toggle_meta' ) ) {
			return array(
				'updated' => 0,
				'ignored' => 0,
			);
		}
		$allowed = array_keys( jinyu_perf_toggle_meta() );
		$current = (array) get_option( 'jinyu_perf_options', array() );
		$next    = $current;
		$updated = 0;
		foreach ( $allowed as $k ) {
			if ( ! array_key_exists( $k, $incoming ) ) {
				continue;
			}
			$clean = ! empty( $incoming[ $k ] ) ? 1 : 0;
			if ( isset( $current[ $k ] ) && (int) $current[ $k ] === $clean ) {
				continue;
			}
			$next[ $k ] = $clean;
			++$updated;
		}
		if ( $updated > 0 ) {
			update_option( 'jinyu_perf_options', $next, false );
		}
		return array(
			'updated' => $updated,
			'ignored' => 0,
		);
	}
}

if ( ! function_exists( 'jinyu_companion_import_social_login' ) ) {
	/**
	 * 导入第三方登录配置。
	 * 默认仅非凭据字段（client_secret / private_key 留空，须重新填写）；
	 * $with_secrets=true（完整备份）时凭据密文原样写回，供同站恢复，
	 * 不做 sanitize 以免破坏密文编码；跨站密钥不同解不开，等价留空。
	 *
	 * @param mixed $incoming     导出文件内的 social_login。
	 * @param bool  $with_secrets 是否恢复凭据密文。
	 * @return array{changed:bool,accounts:int}
	 */
	function jinyu_companion_import_social_login( $incoming, bool $with_secrets = false ): array {
		if ( ! is_array( $incoming ) || ! function_exists( 'jinyu_sl_providers' ) || ! function_exists( 'jinyu_sl_get_option' ) || ! defined( 'JINYU_SL_OPT' ) ) {
			return array(
				'changed' => false,
				'accounts' => 0,
			);
		}
		$allowed_roles = array( 'subscriber', 'contributor', 'author' );
		$old           = jinyu_sl_get_option();
		$next          = $old;

		$next['enable']         = ! empty( $incoming['enable'] );
		$next['allow_register'] = ! empty( $incoming['allow_register'] );
		$next['role']           = isset( $incoming['role'] ) && in_array( $incoming['role'], $allowed_roles, true )
			? $incoming['role']
			: ( $old['role'] ?? 'subscriber' );

		$ruri = isset( $incoming['redirect_uri'] ) ? trim( (string) $incoming['redirect_uri'] ) : '';
		$next['redirect_uri'] = ( '' !== $ruri && filter_var( $ruri, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $ruri ) )
			? $ruri
			: ( $old['redirect_uri'] ?? '' );

		$old_accounts = isset( $old['accounts'] ) && is_array( $old['accounts'] ) ? $old['accounts'] : array();
		$accounts     = array();
		$in_accounts  = isset( $incoming['accounts'] ) && is_array( $incoming['accounts'] ) ? $incoming['accounts'] : array();
		foreach ( jinyu_sl_providers() as $p => $prov ) {
			$inc = $in_accounts[ $p ] ?? array();
			if ( ! is_array( $inc ) ) {
				continue;
			}
			$secret_field = $prov->uses_client_secret() ? 'client_secret' : 'private_key';
			$row          = array(
				'client_id' => isset( $inc['client_id'] ) ? sanitize_text_field( $inc['client_id'] ) : '',
			);
			foreach ( $prov->config_fields() as $f ) {
				$fid = $f['id'];
				if ( $fid === $secret_field ) {
					// 凭据：完整备份原样写回密文；普通备份回退平台旧凭据（若有）。
					if ( $with_secrets && isset( $inc[ $fid ] ) && is_string( $inc[ $fid ] ) && '' !== trim( $inc[ $fid ] ) ) {
						$row[ $fid ] = $inc[ $fid ];
					} elseif ( isset( $old_accounts[ $p ][ $fid ] ) && is_string( $old_accounts[ $p ][ $fid ] ) ) {
						$row[ $fid ] = $old_accounts[ $p ][ $fid ];
					}
					continue;
				}
				$row[ $fid ] = isset( $inc[ $fid ] ) ? sanitize_text_field( $inc[ $fid ] ) : '';
			}
			if ( '' !== $row['client_id'] ) {
				$accounts[ $p ] = $row;
			}
		}
		$next['accounts'] = $accounts;

		update_option( JINYU_SL_OPT, $next, true );
		return array(
			'changed' => true,
			'accounts' => count( $accounts ),
		);
	}
}

if ( ! function_exists( 'jinyu_companion_import_indexnow' ) ) {
	/**
	 * 导入 IndexNow 密钥（非隐私明文，跨站可用）。
	 *
	 * @param mixed $key 导出文件内的 indexnow_key。
	 * @return array{updated:int}
	 */
	function jinyu_companion_import_indexnow( $key ): array {
		if ( ! is_string( $key ) || '' === trim( $key ) ) {
			return array( 'updated' => 0 );
		}
		$key = sanitize_text_field( $key );
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
			return array( 'updated' => 0 );
		}
		if ( (string) get_option( 'jinyu_indexnow_key', '' ) === $key ) {
			return array( 'updated' => 0 );
		}
		update_option( 'jinyu_indexnow_key', $key );
		return array( 'updated' => 1 );
	}
}

if ( ! function_exists( 'jinyu_companion_import_result' ) ) {
	/**
	 * 本次请求的导入结果（供面板渲染提示，避免在渲染层读 $_POST / $_FILES）。
	 *
	 * @param array|null $set 置位结果。
	 * @return array{ok:bool,message:string,added:int,updated:int,ignored:int,skipped:int}
	 */
	function jinyu_companion_import_result( ?array $set = null ): array {
		static $result = null;

		if ( null !== $set ) {
			$result = array_merge(
				array(
					'ok'      => false,
					'message' => '',
					'added'   => 0,
					'updated' => 0,
					'ignored' => 0,
					'skipped' => 0,
				),
				$set
			);
		}

		return null === $result
			? array(
				'ok'      => false,
				'message' => '',
				'added'   => 0,
				'updated' => 0,
				'ignored' => 0,
				'skipped' => 0,
			)
			: $result;
	}
}

if ( ! function_exists( 'jinyu_companion_handle_import' ) ) {
	/**
	 * 导入处理：读文件 / 读粘贴文本 → 解析 → 应用 → 记录结果。
	 *
	 * 调用方（settings.php 的保存入口）已校验权限与 nonce；本函数只负责数据。
	 * 失败路径不 exit，交给面板把原因如实显示出来。
	 */
	function jinyu_companion_handle_import(): void {
		// 覆盖确认：误点「导入」不该静默改配置，必须显式勾选。
		if ( empty( $_POST['jinyu_import_confirm'] ) ) {
			jinyu_companion_import_result(
				array(
					'ok'      => false,
					'message' => __( '未确认：请勾选下方确认项后再导入。', 'jinyu-theme-companion' ),
				)
			);
			return;
		}

		$raw = '';

		// 优先文件：仅接受 .json，体积超限直接拒绝（不进解析阶段）。
		if ( isset( $_FILES['jinyu_import_file'] ) && ! empty( $_FILES['jinyu_import_file']['tmp_name'] ) ) {
			$file = wp_unslash( $_FILES['jinyu_import_file'] );
			$type = wp_check_filetype( $file['name'], array( 'json' => 'application/json' ) );
			if ( 'json' !== $type['ext'] ) {
				jinyu_companion_import_result(
					array(
						'ok'      => false,
						'message' => __( '导入失败：只接受 .json 文件。', 'jinyu-theme-companion' ),
					)
				);
				return;
			}
			if ( (int) $file['size'] > jinyu_companion_io_max_upload() ) {
				@wp_delete_file( $file['tmp_name'] );
				jinyu_companion_import_result(
					array(
						'ok'      => false,
						'message' => sprintf(
							/* translators: %s: 体积上限 */
							__( '导入失败：文件超过 %s。', 'jinyu-theme-companion' ),
							size_format( jinyu_companion_io_max_upload() )
						),
					)
				);
				return;
			}
			$raw = (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- 本地临时文件，无远程调用。
			@wp_delete_file( $file['tmp_name'] );
		} elseif ( ! empty( $_POST['jinyu_import_text'] ) ) {
			$raw = sanitize_textarea_field( wp_unslash( $_POST['jinyu_import_text'] ) );
		}

		if ( '' === trim( (string) $raw ) ) {
			jinyu_companion_import_result(
				array(
					'ok'      => false,
					'message' => __( '导入失败：没有读到任何内容，请选择文件或粘贴 JSON。', 'jinyu-theme-companion' ),
				)
			);
			return;
		}

		// $raw 来自上传文件原文或已 sanitize_textarea_field 过的文本域；
		// 解析出的结构在 jinyu_companion_import_apply() 中按白名单逐字段校验后才落库。
		$decoded = json_decode( $raw, true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 上方已 sanitize，JSON 结构由导入白名单校验
		if ( ! is_array( $decoded ) ) {
			jinyu_companion_import_result(
				array(
					'ok'      => false,
					'message' => __( '导入失败：不是合法的 JSON。', 'jinyu-theme-companion' ),
				)
			);
			return;
		}

		// 兼容两种包体：{settings:[...]} 或直接是 settings 数组。
		$incoming = isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ? $decoded['settings'] : $decoded;

		// 导入前基线：用于判断本次是否触碰了需刷新重写规则 / 整页缓存的设置。
		$no_cat_before = jinyu_companion_get_option( 'no_category_enable', '0' );
		$cache_before  = jinyu_companion_get_option( 'page_cache_enable', '0' );

		$stats = jinyu_companion_import_apply( $incoming );

		// 其余非 jinyu_companion_settings 区块（性能中心 / 社交登录 / IndexNow 密钥）。
		// 完整备份（includes_secrets=true）：凭据密文一并恢复（同站有效，跨站解不开）。
		$with_secrets = ! empty( $decoded['includes_secrets'] );
		$perf_stats   = jinyu_companion_import_perf( $decoded['perf_options'] ?? null );
		$social_stats = jinyu_companion_import_social_login( $decoded['social_login'] ?? null, $with_secrets );
		$in_key_stats = jinyu_companion_import_indexnow( $decoded['indexnow_key'] ?? null );

		$extra_updated = (int) $perf_stats['updated'] + (int) $social_stats['accounts'] + (int) $in_key_stats['updated'];
		$total_changed = ! $stats['no_change'] || $extra_updated > 0;

		if ( ! $total_changed ) {
			jinyu_companion_import_result(
				array(
					'ok'      => false,
					'message' => __( '导入完成，但没有需要更新的配置（与当前设置一致）。', 'jinyu-theme-companion' ),
					'ignored' => (int) $stats['ignored'],
					'skipped' => (int) count( $stats['skipped'] ),
				)
			);
			return;
		}

		// 副作用：影响重写规则与整页缓存的设置变化，落库后必须同步，否则前台仍是旧行为。
		if ( $no_cat_before !== jinyu_companion_get_option( 'no_category_enable', '0' ) ) {
			flush_rewrite_rules();
		}
		if ( $cache_before !== jinyu_companion_get_option( 'page_cache_enable', '0' ) && function_exists( 'jinyu_companion_cache_flush' ) ) {
			jinyu_companion_cache_flush();
		}

		$parts = array();
		if ( $stats['added'] > 0 || $stats['updated'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: 新增项数 2: 覆盖项数 */
				__( '主设置新增 %1$d 项、覆盖 %2$d 项', 'jinyu-theme-companion' ),
				(int) $stats['added'],
				(int) $stats['updated']
			);
		}
		if ( $perf_stats['updated'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: 性能中心覆盖项数 */
				__( '性能中心覆盖 %1$d 项', 'jinyu-theme-companion' ),
				(int) $perf_stats['updated']
			);
		}
		if ( $social_stats['changed'] && $social_stats['accounts'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: 社交登录恢复的平台数 */
				__( '社交登录恢复 %1$d 个平台（凭据需重新填写）', 'jinyu-theme-companion' ),
				(int) $social_stats['accounts']
			);
		}
		if ( $in_key_stats['updated'] > 0 ) {
			$parts[] = __( 'IndexNow 密钥已恢复', 'jinyu-theme-companion' );
		}

		jinyu_companion_import_result(
			array(
				'ok'      => true,
				'message' => __( '导入成功：', 'jinyu-theme-companion' )
					. implode( '；', $parts )
					. sprintf(
						/* translators: 1: 未知键忽略数 2: 凭据跳过数 */
						__( '；忽略未知键 %1$d 项，跳过凭据 %2$d 项。', 'jinyu-theme-companion' ),
						(int) $stats['ignored'],
						(int) count( $stats['skipped'] )
					),
				'added'   => (int) $stats['added'],
				'updated' => (int) $stats['updated'],
				'ignored' => (int) $stats['ignored'],
				'skipped' => (int) count( $stats['skipped'] ),
			)
		);
	}
}

add_action( 'admin_init', 'jinyu_companion_handle_export' );
