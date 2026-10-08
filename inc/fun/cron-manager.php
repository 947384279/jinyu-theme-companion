<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 计划任务管理器。
 *
 * 在设置面板列出 WP-Cron 全部计划事件，支持：
 *  - 立即运行（触发一次钩子回调，不影响原调度）
 *  - 暂停 / 恢复（整钩名遮蔽，经 pre_schedule_event 拦截）
 *  - 删除（wp_unschedule_event 移除指定时间戳的事件）
 *  - 新增自定义事件（存插件私有选项，不污染 WP 核心调度表）
 *  - 系统 cron 切换提示（仅给命令，不自动改写 wp-config）
 *
 * 所有写操作经 manage_options + nonce 守卫；暂停仅记录钩名，不修改核心数据。
 *
 * 配置键（jinyu_companion_settings）：
 *  cron_enable       总开关（默认开）
 *  cron_use_system   是否改用系统 cron（仅提示，不自动改 wp-config）
 *  cron_custom       array 自定义事件（hook/interval/first/args）
 */

if ( ! defined( 'JINYU_CRON_PAUSED_OPT' ) ) {
	define( 'JINYU_CRON_PAUSED_OPT', 'jinyu_cron_paused' );
}

if ( ! defined( 'JINYU_CRON_CUSTOM_OPT' ) ) {
	define( 'JINYU_CRON_CUSTOM_OPT', 'jinyu_cron_custom' );
}

/**
 * 是否启用计划任务管理器。
 *
 * @return bool
 */
function jinyu_cron_manager_enabled(): bool {
	return jinyu_companion_is_checked( 'cron_enable', true );
}

/**
 * 读取暂停列表。
 *
 * @return string[]
 */
function jinyu_cron_paused_hooks(): array {
	$h = get_option( JINYU_CRON_PAUSED_OPT, array() );
	return is_array( $h ) ? $h : array();
}

/**
 * 读取自定义事件配置。
 *
 * @return array<int,array>
 */
function jinyu_cron_custom_events(): array {
	$c = get_option( JINYU_CRON_CUSTOM_OPT, array() );
	return is_array( $c ) ? $c : array();
}

/**
 * 已知 WP-Cron 钩子名 → 中文解释。
 *
 * 覆盖 WordPress 核心定时任务与常见插件（WooCommerce / Yoast SEO）事件；
 * 未列出的钩名（主题/其他插件/自定义）回退到通用说明，避免误导。
 *
 * @return array<string,string>
 */
function jinyu_cron_hook_descriptions(): array {
	return array(
		'wp_scheduled_delete'                   => __( '定时清理回收站中超过设定天数的文章（设置→媒体→保留回收站天数）。', 'jinyu-theme-companion' ),
		'wp_scheduled_auto_draft_delete'        => __( '清理超过 7 天未保存的自动草稿（auto-draft）。', 'jinyu-theme-companion' ),
		'wp_version_check'                      => __( '向 WordPress.org 检查核心是否有新版本（后台更新提示用）。', 'jinyu-theme-companion' ),
		'wp_update_plugins'                     => __( '检查已安装插件是否有可用更新。', 'jinyu-theme-companion' ),
		'wp_update_themes'                      => __( '检查已安装主题是否有可用更新。', 'jinyu-theme-companion' ),
		'wp_update_core'                        => __( '检查 WordPress 核心更新（与版本检查联动）。', 'jinyu-theme-companion' ),
		'wp_privacy_delete_old_export_files'    => __( '删除过期的隐私数据导出文件（默认 3 天后）。', 'jinyu-theme-companion' ),
		'wp_privacy_cleanup_personal_data_export_page' => __( '清理过期的个人数据导出页面与令牌。', 'jinyu-theme-companion' ),
		'recovery_mode_clean_expired_keys'      => __( '清理恢复模式（死机保护）过期的退出密钥。', 'jinyu-theme-companion' ),
		'delete_expired_transients'             => __( '清理数据库中已过期的瞬时缓存（transient）。', 'jinyu-theme-companion' ),
		'action_scheduler_run_queue'            => __( 'WooCommerce Action Scheduler 处理后台队列任务。', 'jinyu-theme-companion' ),
		'wc_update_product_lookup_tables'        => __( 'WooCommerce 刷新产品查询表（统计/报表用）。', 'jinyu-theme-companion' ),
		'wpseo_cron_indexation'                 => __( 'Yoast SEO 重建索引表（indexable）。', 'jinyu-theme-companion' ),
		'wpseo_onpage_fetch'                     => __( 'Yoast SEO 抓取首页以评估 SEO 评分。', 'jinyu-theme-companion' ),
		'wpseo_indexable_indexation'            => __( 'Yoast SEO 批量索引内容。', 'jinyu-theme-companion' ),
	);
}

/**
 * 取单个钩子的解释；未知钩名回退到通用说明。
 *
 * @param string $hook 钩子名。
 * @return string
 */
function jinyu_cron_hook_desc( string $hook ): string {
	$map = jinyu_cron_hook_descriptions();
	if ( isset( $map[ $hook ] ) ) {
		return $map[ $hook ];
	}
	return __( '第三方或主题/插件注册的自定义定时任务。', 'jinyu-theme-companion' );
}

/**
 * 解析 WP 调度表为可渲染的事件列表。
 *
 * @return array<int,array{hook:string,time:int,args:array,schedule:string,interval:int}>
 */
function jinyu_cron_list_events(): array {
	$crons = get_option( 'cron', array() );
	if ( ! is_array( $crons ) ) {
		return array();
	}
	$schedules = wp_get_schedules();
	$events    = array();
	$paused    = jinyu_cron_paused_hooks();
	foreach ( $crons as $timestamp => $hooks ) {
		if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) {
			continue;
		}
		foreach ( $hooks as $hook => $data ) {
			foreach ( $data as $sig => $event ) {
				if ( ! is_array( $event ) ) {
					continue;
				}
				$schedule = isset( $event['schedule'] ) ? (string) $event['schedule'] : '';
				$interval = isset( $event['interval'] ) ? (int) $event['interval'] : 0;
				if ( '' !== $schedule && isset( $schedules[ $schedule ] ) ) {
					$interval = (int) $schedules[ $schedule ]['interval'];
				}
				$events[] = array(
					'hook'     => $hook,
					'time'     => (int) $timestamp,
					'args'     => isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array(),
					'schedule' => $schedule,
					'interval' => $interval,
					'paused'   => in_array( $hook, $paused, true ),
				);
			}
		}
	}
	usort(
		$events,
		static function ( $a, $b ) {
			return $a['time'] <=> $b['time'];
		}
	);
	return $events;
}

/**
 * 暂停钩名经 pre_schedule_event 拦截（整钩遮蔽）。
 *
 * @param object|bool $event 待调度事件。
 * @return object|bool
 */
function jinyu_cron_block_paused( $event ) {
	if ( ! is_object( $event ) || ! isset( $event->hook ) ) {
		return $event;
	}
	$paused = jinyu_cron_paused_hooks();
	if ( in_array( $event->hook, $paused, true ) ) {
		return false;
	}
	return $event;
}
add_filter( 'pre_schedule_event', 'jinyu_cron_block_paused', 10, 1 );

/**
 * 新增自定义事件后，确保已注册调度（init 阶段调用）。
 */
function jinyu_cron_register_custom(): void {
	if ( ! jinyu_cron_manager_enabled() ) {
		return;
	}
	$events = jinyu_cron_custom_events();
	foreach ( $events as $e ) {
		if ( empty( $e['hook'] ) || empty( $e['interval'] ) ) {
			continue;
		}
		$interval = (int) $e['interval'];
		$first    = isset( $e['first'] ) && $e['first'] ? (int) $e['first'] : time() + $interval;
		$args     = isset( $e['args'] ) && is_array( $e['args'] ) ? $e['args'] : array();
		if ( ! wp_next_scheduled( $e['hook'], $args ) ) {
			wp_schedule_event( $first, $interval, $e['hook'], $args );
		}
	}
}
add_action( 'init', 'jinyu_cron_register_custom', 30 );

/**
 * 渲染计划任务列表（供设置面板 pane 调用）。
 *
 * @return string HTML。
 */
function jinyu_cron_render_list(): string {
	if ( ! jinyu_cron_manager_enabled() ) {
		return '<p class="jyc-muted">' . esc_html__( '计划任务管理器已关闭。', 'jinyu-theme-companion' ) . '</p>';
	}
	$events = jinyu_cron_list_events();
	if ( empty( $events ) ) {
		return '<p class="jyc-muted">' . esc_html__( '当前没有计划中的事件。', 'jinyu-theme-companion' ) . '</p>';
	}
	$rows = '';
	// 常见调度键 → 中文徽章文案。
	$sched_labels = array(
		'hourly'     => __( '每小时', 'jinyu-theme-companion' ),
		'twicedaily' => __( '每日两次', 'jinyu-theme-companion' ),
		'daily'      => __( '每天', 'jinyu-theme-companion' ),
		'weekly'     => __( '每周', 'jinyu-theme-companion' ),
		'monthly'    => __( '每月', 'jinyu-theme-companion' ),
	);
	$now = time();
	foreach ( $events as $ev ) {
		$hook_raw = $ev['hook'];
		$hook     = esc_html( $hook_raw );
		$desc     = jinyu_cron_hook_desc( $hook_raw );

		// 计划列：中文徽章 + 间隔提示。
		$key = $ev['schedule'];
		if ( '' === $key ) {
			$badge = '<span class="jyc-cron-badge is-single">' . esc_html__( '单次', 'jinyu-theme-companion' ) . '</span>';
		} else {
			$lbl = isset( $sched_labels[ $key ] ) ? $sched_labels[ $key ] : $key;
			$itv = (int) $ev['interval'];
			if ( $itv >= DAY_IN_SECONDS && 0 === $itv % DAY_IN_SECONDS ) {
				$itv_txt = sprintf( /* translators: %d: 天数 */ __( '每 %d 天', 'jinyu-theme-companion' ), (int) ( $itv / DAY_IN_SECONDS ) );
			} elseif ( $itv >= HOUR_IN_SECONDS && 0 === $itv % HOUR_IN_SECONDS ) {
				$itv_txt = sprintf( /* translators: %d: 小时数 */ __( '每 %d 小时', 'jinyu-theme-companion' ), (int) ( $itv / HOUR_IN_SECONDS ) );
			} else {
				$itv_txt = sprintf( /* translators: %d: 分钟数 */ __( '每 %d 分钟', 'jinyu-theme-companion' ), max( 1, (int) round( $itv / MINUTE_IN_SECONDS ) ) );
			}
			$badge = '<span class="jyc-cron-badge" title="' . esc_attr( $itv_txt ) . '">' . esc_html( $lbl ) . '</span>';
		}

		// 下次执行：相对时间为主，绝对时间为辅。
		$ts  = (int) $ev['time'];
		$rel = $ts > $now
			? sprintf( /* translators: %s: 时间间隔 */ __( '%s后', 'jinyu-theme-companion' ), human_time_diff( $now, $ts ) )
			: __( '即将执行', 'jinyu-theme-companion' );
		$next_cell = '<span class="jyc-cron-next">' . esc_html( $rel ) . '</span>'
			. '<span class="jyc-cron-next-abs">' . esc_html( wp_date( 'Y-m-d H:i', $ts ) ) . '</span>';

		$paused   = $ev['paused'] ? ' jyc-paused' : '';
		$pause_lbl = $ev['paused'] ? esc_attr__( '恢复', 'jinyu-theme-companion' ) : esc_attr__( '暂停', 'jinyu-theme-companion' );
		$hook_cell = '<code>' . $hook . '</code>'
			. '<span class="jyc-cron-hook-desc">' . esc_html( $desc ) . '</span>'
			. '<button type="button" class="jyc-cron-tip" aria-label="' . esc_attr__( '说明', 'jinyu-theme-companion' ) . '" data-tip="' . esc_attr( $desc ) . '">?</button>';
		$rows    .= sprintf(
			'<tr class="jyc-cron-row%s"><td class="jyc-cron-cell-hk">%s</td><td data-label="%s">%s</td><td data-label="%s">%s</td>'
			. '<td class="jyc-cron-act" data-label="%s">'
			. '<button type="button" class="jyc-btn jyc-btn-ghost jyc-btn-xs" data-act="run" data-hook="%s" data-time="%d">%s</button>'
			. '<button type="button" class="jyc-btn jyc-btn-ghost jyc-btn-xs" data-act="pause" data-hook="%s">%s</button>'
			. '<button type="button" class="jyc-btn jyc-btn-ghost jyc-btn-xs jyc-cron-del" data-act="delete" data-hook="%s" data-time="%d">%s</button>'
			. '</td></tr>',
			$paused,
			$hook_cell,
			esc_attr__( '计划', 'jinyu-theme-companion' ),
			$badge,
			esc_attr__( '下次执行', 'jinyu-theme-companion' ),
			$next_cell,
			esc_attr__( '操作', 'jinyu-theme-companion' ),
			esc_attr( $ev['hook'] ),
			(int) $ev['time'],
			esc_html__( '运行', 'jinyu-theme-companion' ),
			esc_attr( $ev['hook'] ),
			$pause_lbl,
			esc_attr( $ev['hook'] ),
			(int) $ev['time'],
			esc_html__( '删除', 'jinyu-theme-companion' )
		);
	}
	$nonce = wp_create_nonce( 'jinyu_cron_action' );
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控 HTML，nonce 用隐藏 input
	return '<input type="hidden" id="jyc-cron-nonce" value="' . $nonce . '">'
		. '<div class="jyc-cron-tablewrap"><table class="jyc-cron-table"><thead><tr>'
		. '<th title="' . esc_attr__( '触发回调的钩子名（动作标识），如 wp_version_check。', 'jinyu-theme-companion' ) . '">' . esc_html__( 'Hook', 'jinyu-theme-companion' ) . '</th>'
		. '<th title="' . esc_attr__( '重复周期：daily / hourly 等，或「单次」。', 'jinyu-theme-companion' ) . '">' . esc_html__( '计划', 'jinyu-theme-companion' ) . '</th>'
		. '<th title="' . esc_attr__( '该事件下一次预计运行的时间。', 'jinyu-theme-companion' ) . '">' . esc_html__( '下次执行', 'jinyu-theme-companion' ) . '</th>'
		. '<th title="' . esc_attr__( '运行：立即触发一次；暂停：记录钩名拦截后续；删除：移除该时间戳事件。', 'jinyu-theme-companion' ) . '">' . esc_html__( '操作', 'jinyu-theme-companion' ) . '</th>'
		. '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
	// phpcs:enable
}

/**
 * AJAX 入口：run / pause / delete / add。
 */
add_action( 'wp_ajax_jinyu_cron_action', 'jinyu_cron_ajax' );
function jinyu_cron_ajax(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json(
            array(
				'success' => false,
				'msg' => __( '权限不足', 'jinyu-theme-companion' ),
            )
        );
	}
	if ( empty( $_POST['jinyu_cron_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jinyu_cron_nonce'] ) ), 'jinyu_cron_action' ) ) {
		wp_send_json(
            array(
				'success' => false,
				'msg' => __( '安全校验失败', 'jinyu-theme-companion' ),
            )
        );
		return;
	}
	$act  = isset( $_POST['act'] ) ? sanitize_key( wp_unslash( $_POST['act'] ) ) : '';
	$hook = isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '';
	$time = isset( $_POST['time'] ) ? (int) wp_unslash( $_POST['time'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- (int) 强制转型即完全消毒，时间戳非字符串入参。

	switch ( $act ) {
		case 'run':
			if ( '' === $hook ) {
				wp_send_json(
                    array(
						'success' => false,
						'msg' => __( '参数缺失', 'jinyu-theme-companion' ),
                    )
                );
			}
			// 找到该 hook 的一次事件参数，触发一次（不影响原调度）。
			$args = array();
			foreach ( jinyu_cron_list_events() as $ev ) {
				if ( $ev['hook'] === $hook ) {
					$args = $ev['args'];
					break;
				}
			}
			do_action_ref_array( $hook, $args ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- 触发用户既有钩子，名称由后台指定、无法加插件前缀。
			wp_send_json(
                array(
					'success' => true,
					/* translators: %s: WP-Cron 钩子名。 */
					'msg' => sprintf( __( '已触发 %s', 'jinyu-theme-companion' ), $hook ),
                )
            );
			break;

		case 'pause':
		case 'resume':
			if ( '' === $hook ) {
				wp_send_json(
                    array(
						'success' => false,
						'msg' => __( '参数缺失', 'jinyu-theme-companion' ),
                    )
                );
			}
			$paused = jinyu_cron_paused_hooks();
			if ( 'pause' === $act ) {
				if ( ! in_array( $hook, $paused, true ) ) {
					$paused[] = $hook;
				}
				/* translators: %s: WP-Cron 钩子名。 */
				$msg = sprintf( __( '已暂停 %s', 'jinyu-theme-companion' ), $hook );
			} else {
				$paused = array_values( array_diff( $paused, array( $hook ) ) );
				/* translators: %s: WP-Cron 钩子名。 */
				$msg = sprintf( __( '已恢复 %s', 'jinyu-theme-companion' ), $hook );
			}
			update_option( JINYU_CRON_PAUSED_OPT, $paused );
			wp_send_json(
                array(
					'success' => true,
					'msg' => $msg,
					'html' => jinyu_cron_render_list(),
                )
            );
			break;

		case 'delete':
			if ( '' === $hook || 0 === $time ) {
				wp_send_json(
                    array(
						'success' => false,
						'msg' => __( '参数缺失', 'jinyu-theme-companion' ),
                    )
                );
			}
			// 优先按 args 精确移除；无 args 时按 hook+time 移除全部签名。
			$removed = false;
			foreach ( jinyu_cron_list_events() as $ev ) {
				if ( $ev['hook'] === $hook && (int) $ev['time'] === $time ) {
					wp_unschedule_event( $ev['time'], $ev['hook'], $ev['args'] );
					$removed = true;
				}
			}
			if ( ! $removed ) {
				wp_unschedule_event( $time, $hook );
			}
			wp_send_json(
                array(
					'success' => true,
					/* translators: %s: WP-Cron 钩子名。 */
					'msg' => sprintf( __( '已删除 %s', 'jinyu-theme-companion' ), $hook ),
					'html' => jinyu_cron_render_list(),
                )
            );
			break;

		case 'add':
			$interval = isset( $_POST['interval'] ) ? max( 1, (int) wp_unslash( $_POST['interval'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- (int) 强制转型即完全消毒，间隔为整数秒。
			if ( '' === $hook || 0 === $interval ) {
				wp_send_json(
                    array(
						'success' => false,
						'msg' => __( 'Hook 与间隔为必填', 'jinyu-theme-companion' ),
                    )
                );
			}
			$custom   = jinyu_cron_custom_events();
			$custom[] = array(
				'hook'     => $hook,
				'interval' => $interval,
				'first'    => time() + $interval,
				'args'     => array(),
			);
			update_option( JINYU_CRON_CUSTOM_OPT, $custom );
			jinyu_cron_register_custom();
			wp_send_json(
                array(
					'success' => true,
					/* translators: %s: WP-Cron 钩子名。 */
					'msg' => sprintf( __( '已新增 %s', 'jinyu-theme-companion' ), $hook ),
					'html' => jinyu_cron_render_list(),
                )
            );
			break;

		default:
			wp_send_json(
                array(
					'success' => false,
					'msg' => __( '未知操作', 'jinyu-theme-companion' ),
                )
            );
	}
}
