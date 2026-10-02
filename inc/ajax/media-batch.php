<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 水印批量任务与自选处理（单张 / 媒体库勾选 / 一键并发生成 / 一键还原）。
 *
 * 任务状态挂在用户级 transient（走 Memcached dropin，不落 wp_options），
 * 单个任务只存待处理附件 ID 列表与计数。并发默认 1（弱机安全），
 * 调大后走 admin-ajax 子请求（curl_multi），子请求承载 1 个附件。
 *
 * 注意：批量改写的是本地文件，历史图处理后云端副本不会自动更新，
 * 需要到「存储」分区重新执行一键推送才能把水印版同步到 CDN/对象存储。
 */

define( 'JINYU_WM_MAX_CONCURRENCY', 8 );
define( 'JINYU_WM_STEP', 5 );

/** 任务 transient 键（按用户隔离，避免多管理员互相踩进度）。 */
function jinyu_companion_wm_task_key(): string {
	return 'jinyu_wm_task_' . (int) get_current_user_id();
}

/** 简易限流：单用户每分钟最多发起 N 次水印请求，防刷。 */
function jinyu_companion_wm_throttle(): bool {
	$key = 'jinyu_wm_rl_' . get_current_user_id();
	$n   = (int) get_transient( $key );
	if ( $n >= 60 ) {
		return false;
	}
	set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
	return true;
}

/** 取任务快照。 */
function jinyu_companion_wm_task(): array {
	$raw = get_transient( jinyu_companion_wm_task_key() );
	if ( ! is_array( $raw ) ) {
		return array(
			'status' => 'idle',
			'done' => 0,
			'total' => 0,
			'errors' => 0,
			'message' => '',
		);
	}
	return $raw;
}

/** 写任务快照。 */
function jinyu_companion_wm_set_task( array $task ): void {
	if ( $task['status'] === 'idle' || $task['status'] === 'done' ) {
		delete_transient( jinyu_companion_wm_task_key() );
		return;
	}
	set_transient( jinyu_companion_wm_task_key(), $task, HOUR_IN_SECONDS );
}

/**
 * 解析面板提交的附件 ID。
 *
 * 面板（admin.js）用 FormData.set('ids', '12,34,56') 提交，收到的是**字符串**；
 * 媒体库批量操作走 ids[]= 形式，收到的是数组。两种都要认：只判 is_array 时
 * 字符串会被静默当成「用户没填」，进而回落到全库扫描（上限 5000 张）把整站
 * 媒体库重写一遍，界面上却显示一切正常。
 *
 * @param mixed $raw $_POST['ids'] 原始值。
 * @return int[] 去重后的正整数 ID。
 */
function jinyu_companion_wm_parse_ids( $raw ): array {
	return jinyu_companion_parse_ids( $raw );
}

/**
 * 建立任务。
 *
 * @param array $ids 指定附件 ID；为空则扫描媒体库全部未打水印的图片。
 */
function jinyu_companion_wm_start( array $ids, string $mode = 'apply' ): array {
	$sig = Jinyu_Watermark::signature();
	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) {
		global $wpdb;
		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment'
					 AND post_mime_type IN ('image/jpeg','image/png','image/webp')
					 AND post_status = 'inherit'
					 ORDER BY ID DESC LIMIT %d",
					intval( apply_filters( 'jinyu_wm_scan_limit', 5000 ) )
				)
			)
		);
	}
	if ( 'apply' === $mode ) {
		// 已按当前签名处理过的不再进队；-1 表示强制重建
		$ids = array_filter(
			$ids,
			static function ( $id ) use ( $sig ) {
				return (string) get_post_meta( $id, Jinyu_Watermark::META, true ) !== $sig;
			}
		);
	} elseif ( empty( $ids ) ) {
		// 还原模式全库扫描：只挑登记过水印的附件。没有 meta 说明这张从来没处理过，
		// 遍历它们没有意义，只会让进度条空转上千次。用户手动指定的 ID 一律照单执行。
		global $wpdb;
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s", Jinyu_Watermark::META )
			)
		);
	}
	$task = array(
		'mode'    => $mode,
		'ids'     => array_values( $ids ),
		'done'    => 0,
		'errors'  => 0,
		'status'  => empty( $ids ) ? 'done' : 'running',
		'message' => '',
	);
	jinyu_companion_wm_set_task( $task );
	return $task;
}

/**
 * 推进一批。$concurrency > 1 时用 curl_multi 并发子请求。
 *
 * ## 并发保护
 *
 * 任务存在 transient 里（非 DB），没法像 storage 那样用 `UPDATE ... WHERE done = X`
 * 做原子推进，因此这里用对象缓存的**互斥锁**把整个「取任务 → 处理 → 写回」串行化。
 *
 * 不加锁的真实故障：两个进程同时 `array_splice` 同一份 ids，各自取走**相同的 5 个 ID**，
 * 同一张图被并发处理两次。而 apply_attachment() 内部是「备份原图 → 重写 → 失败则
 * rename 回滚」，并发下两个进程会同时操作同一个备份文件——典型结果是
 * 「rename 失败 → 原图丢失」或「备份被覆盖 → 无法回滚」。这不是理论风险，
 * 前端 600ms 轮询在响应慢时就会重叠，多标签页更是必然重叠。
 *
 * 锁用 wp_cache_add（原子 add，仅成功时返回 true），无持久对象缓存时退化为
 * transient 存储，同样是 add 语义。锁 TTL 短（30s）——宁可极端情况下锁过期
 * 让人工重跑，也不要因为进程异常退出把任务永久锁死。
 */
function jinyu_companion_wm_step(): array {
	$task = jinyu_companion_wm_task();
	if ( empty( $task['ids'] ) || 'running' !== $task['status'] ) {
		return $task;
	}

	$lock_key = jinyu_companion_wm_task_key() . '_lock';
	if ( ! wp_cache_add( $lock_key, 1, 'jinyu_wm_lock', 30 ) ) {
		// 另一个进程正在推进本批：原样返回当前进度让前端下一轮重试。
		// 绝不能在此处也去取任务——那正是重复处理的根源。
		return $task;
	}

	try {
		// 抢到锁后**重新读一次**任务：等锁期间上一个进程可能已推进并写回，
		// 继续用抢锁前读到的旧快照会基于过期的 ids 数组工作。
		$task = jinyu_companion_wm_task();
		if ( empty( $task['ids'] ) || 'running' !== $task['status'] ) {
			return $task;
		}

		$mode     = isset( $task['mode'] ) && 'remove' === $task['mode'] ? 'remove' : 'apply';
		$c        = max( 1, min( JINYU_WM_MAX_CONCURRENCY, (int) jinyu_companion_get_option( 'img_wm_concurrency', 1 ) ) );
		$batch    = array_splice( $task['ids'], 0, ( $c > 1 ? 1 : JINYU_WM_STEP ) * $c );
		$errors   = 0;
		$blocked  = 0;

		// 并发走 admin-ajax 子请求；无 curl 扩展则退化为串行，避免静默「全成功」的假进度
		if ( $c > 1 && function_exists( 'curl_multi_init' ) ) {
			$errors = jinyu_companion_wm_worker_pool( $batch, $c, $mode );
		} else {
			foreach ( $batch as $id ) {
				$r = ( 'remove' === $mode )
					? Jinyu_Watermark::remove_attachment( (int) $id )
					: Jinyu_Watermark::apply_attachment( (int) $id );
				$errors  += (int) $r['errors'];
				$blocked += (int) ( $r['blocked'] ?? 0 );
			}
		}

		$task['done']    = (int) $task['done'] + count( $batch );
		$task['errors']  = (int) $task['errors'] + (int) $errors;
		// ?? 0 很关键：任务数组由 jinyu_companion_wm_task() 建立，未必预置 blocked 键，
		// 直接读会让 CLI/后端日志刷 "Undefined array key blocked"。
		$task['blocked'] = (int) ( $task['blocked'] ?? 0 ) + (int) $blocked;
		$task['status'] = empty( $task['ids'] ) ? 'done' : 'running';
		$task['message'] = sprintf(
			/* translators: 1: 已完成数 2: 总数 3: 失败数 */
			__( '已处理 %1$d/%2$d，失败 %3$d', 'jinyu-theme-companion' ),
			$task['done'],
			// 总数 = 已完成 + 剩余。任务数组由 wm_start 建立时并未存 total
			// （ids 会被逐步 splice 消耗，存下来的 total 反而会与剩余量不一致），
			// 所以这里按剩余量实时算，与 splice 后的真实进度一致。
			(int) $task['done'] + count( $task['ids'] ),
			$task['errors']
		);
		jinyu_companion_wm_set_task( $task );
		return $task;
	} finally {
		// 无论成功、异常还是提前 return，都必须释放锁，否则任务会被锁死 30s。
		wp_cache_delete( $lock_key, 'jinyu_wm_lock' );
	}
}

/**
 * 并发池：把 batch 拆成 $concurrency 个子请求并发执行（每个子请求 1 个附件）。
 *
 * @return int 失败数
 */
function jinyu_companion_wm_worker_pool( array $batch, int $concurrency, string $mode = 'apply' ): int {
	$nonce = wp_create_nonce( 'jinyu_companion_wm' );
	$url   = admin_url( 'admin-ajax.php' );

	$chunks  = array_chunk( $batch, max( 1, $concurrency ) );
	$results = array();
	foreach ( $chunks as $chunk ) {
		$handles = array();
		foreach ( $chunk as $id ) {
			$body = array(
				'action' => 'jinyu_companion_wm_worker',
				'nonce'  => $nonce,
				'id'     => (int) $id,
				'mode'   => $mode,
			);
			$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- 站内 admin-ajax worker 并发处理，需转发会话 Cookie（wp_remote 无法并行且易丢会话）
			curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- 站内 admin-ajax worker 并发处理
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => http_build_query( $body ),
					CURLOPT_TIMEOUT        => 60,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_RETURNTRANSFER => true,
					// 必须转发登录 Cookie：裸 curl 无会话，current_user_can 恒 false，
					// 子请求全部 403，并发批量会「全军覆没」。
					CURLOPT_COOKIE         => isset( $_SERVER['HTTP_COOKIE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) ) : '',
					CURLOPT_REFERER        => admin_url(),
				)
			);
			$handles[] = $ch;
		}
		$mh = curl_multi_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- 站内 admin-ajax worker 并发处理
		foreach ( $handles as $ch ) {
			curl_multi_add_handle( $mh, $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle -- 站内 admin-ajax worker 并发处理
		}
		do {
			curl_multi_exec( $mh, $running ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_exec -- 站内 admin-ajax worker 并发处理
			curl_multi_select( $mh, 0.05 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_select -- 站内 admin-ajax worker 并发处理
		} while ( $running > 0 );
		foreach ( $handles as $ch ) {
			$results[] = curl_multi_getcontent( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent -- 站内 admin-ajax worker 并发处理
			curl_multi_remove_handle( $mh, $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle -- 站内 admin-ajax worker 并发处理
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- 站内 admin-ajax worker 并发处理
		}
		curl_multi_close( $mh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- 站内 admin-ajax worker 并发处理
	}

	$errors = 0;
	foreach ( $results as $raw ) {
		// 只认显式成功标记：子请求返回 403/500 或超时空串都要计入失败
		if ( ! is_string( $raw ) || false === strpos( $raw, '"ok":true' ) ) {
			++$errors;
		}
	}
	return $errors;
}

/** 媒体库图片总量 / 已处理量 / 环境能力，供面板「状态」卡片显示。 */
function jinyu_companion_wm_stats(): array {
	global $wpdb;
	// EXTS 是扩展名，SQL 里要的是 posts.post_mime_type
	$mimes = array( 'image/jpeg', 'image/png', 'image/webp' );
	$place = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
	$where = "post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ($place)";
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE $where", $mimes ) );
	$done  = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->posts p
			 INNER JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN ($place)",
			array_merge( array( Jinyu_Watermark::META ), $mimes )
		)
	);
	return array(
		'total'   => $total,
		'done'    => $done,
		'fonts'   => (string) jinyu_companion_find_font(),
		'editor'  => Jinyu_Watermark::supported(),
		'enabled' => Jinyu_Watermark::is_enabled(),
	);
}

/* ────────────────────────── AJAX 入口 ────────────────────────── */

/** 开始任务：面板一键批量 / 媒体库勾选（传 ids）。 */
add_action(
	'wp_ajax_jinyu_companion_wm_start',
	function () {
		jinyu_companion_guard( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		$mode = isset( $_POST['mode'] ) && 'remove' === $_POST['mode'] ? 'remove' : 'apply';
		// 还原只是把 -jywmo 备份 rename 回原路径，用不到水印开关，也用不到图像编辑器。
		// 若沿用 is_enabled 拦截，用户一关掉「启用图片水印」就再也还原不了历史图——
		// 而面板文案恰恰写着关掉之后仍能还原，两者自相矛盾。
		if ( 'remove' !== $mode && ! Jinyu_Watermark::is_enabled() ) {
			wp_send_json_error( __( '水印未启用或当前环境不支持图像处理', 'jinyu-theme-companion' ) );
		}
		if ( ! jinyu_companion_wm_throttle() ) {
			wp_send_json_error( __( '请求过于频繁，请稍后再试', 'jinyu-theme-companion' ) );
		}
		// ids 允许两种形态：数组（ids[]=1&ids[]=2）与逗号/空格分隔的字符串（ids=1,2）。
		// 面板前端走字符串形态，这里必须两种都认——否则 is_array 对字符串恒 false，
		// 用户填的 ID 会被当成「没填」，静默回落全库扫描（上限 5000 张）批量改写。
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$ids   = jinyu_companion_wm_parse_ids( wp_unslash( $_POST['ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parse_ids 内部逐项 absint + 去重 + 剔 0，非字符串无法通过
		if ( 'ids' === $scope && empty( $ids ) ) {
			// 用户明确选了「只处理指定 ID」，却一个都没解析出来：必须报错，
			// 绝不能让它掉进全库分支——那会把整站媒体库重写一遍且界面毫无提示。
			wp_send_json_error( __( '未解析到任何有效的附件 ID，请检查填写的数字', 'jinyu-theme-companion' ) );
		}
		$task = jinyu_companion_wm_start( $ids, $mode );
		wp_send_json_success(
			array(
				'total'  => count( $task['ids'] ),
				'status' => $task['status'],
			)
		);
	}
);

/** 推进一批（前端轮询）。 */
add_action(
	'wp_ajax_jinyu_companion_wm_step',
	function () {
		jinyu_companion_guard( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		wp_send_json_success( jinyu_companion_wm_step() );
	}
);

/** 任务快照，供前端页面加载时检测未完成任务并自动续跑。 */
add_action(
	'wp_ajax_jinyu_companion_wm_status',
	function () {
		jinyu_companion_guard( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		wp_send_json_success( jinyu_companion_wm_task() );
	}
);

/** 环境能力与处理量统计（面板「状态」卡片刷新用）。 */
add_action(
	'wp_ajax_jinyu_companion_wm_stats',
	function () {
		jinyu_companion_guard( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		wp_send_json_success( jinyu_companion_wm_stats() );
	}
);

/**
 * 并发子请求 Worker（由 curl_multi 拉起）。
 *
 * 这个端点由服务端自己请求自己，因此 nonce 由 jinyu_companion_wm_start 服务端签发
 * （见上方 wm 启动逻辑），而不是设置页主表单的 nonce。字段名沿用 'nonce'。
 * 走统一门卫，与其余端点保持一致。
 */
add_action(
	'wp_ajax_jinyu_companion_wm_worker',
	function () {
		jinyu_companion_guard( 'jinyu_companion_wm', 'nonce' );
		$id   = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$mode = isset( $_POST['mode'] ) && 'remove' === $_POST['mode'] ? 'remove' : 'apply';
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'ok' => false ) );
		}
		if ( 'remove' === $mode ) {
			$r = Jinyu_Watermark::remove_attachment( $id );
		} else {
			$r = Jinyu_Watermark::apply_attachment( $id );
		}
		wp_send_json_success(
			array(
				'ok'      => ( (int) $r['errors'] ) === 0 && (int) ( $r['blocked'] ?? 0 ) === 0,
				'errors'  => (int) $r['errors'],
				'blocked' => (int) ( $r['blocked'] ?? 0 ),
			)
		);
	}
);

/* ──────────────────── 媒体库：批量操作入口 ──────────────────── */

add_filter(
	'bulk_actions-upload',
	function ( $actions ) {
		$actions['jinyu_wm_apply']  = __( '添加水印', 'jinyu-theme-companion' );
		$actions['jinyu_wm_remove'] = __( '去除水印（还原原图）', 'jinyu-theme-companion' );
		return $actions;
	}
);

add_action(
	'handle_bulk_actions-upload',
	function ( $sendback, $action, array $post_ids ) {
		if ( 'jinyu_wm_apply' !== $action && 'jinyu_wm_remove' !== $action ) {
			return $sendback;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $sendback;
		}
		$task = jinyu_companion_wm_start( $post_ids, 'jinyu_wm_remove' === $action ? 'remove' : 'apply' );
		// 由设置面板的 media 分区轮询进度；媒体库页只负责跳转
		$sendback = add_query_arg(
			array(
				'page'   => 'jinyu-theme-companion',
				'pane'   => 'media',
				'jinyu_wm_started' => (int) count( $task['ids'] ),
			),
			admin_url( 'admin.php' )
		);
		return $sendback;
	},
	10,
	3
);
