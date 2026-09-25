<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 评论邮件通知（统一经 inc/fun/email.php 的 SMTP 通道发信）
 *
 *   回复通知      comment_notify_reply     默认开   收件人：父评论作者
 *   作者通知      comment_notify_author    默认开   收件人：文章作者
 *   拦截提醒      comment_notify_blocked   默认关   收件人：站长（待审 / 垃圾，15 分钟冷却合并）
 *   审核通过通知  comment_notify_approved  默认关   收件人：评论者（待审 / 垃圾 → 已通过）
 *
 * 实现要点：do_action( 'wp_insert_comment', $id, $comment ) 的第二参是 WP_Comment 对象，
 * 不是「审核状态值」。历史实现把它当 '1' 比较，恒不相等 → 整条链路在第一步就早退，从未发过信。
 * 现统一经 jinyu_cn_comment() 归一化后再判 comment_approved。
 */

/**
 * 归一化评论参数：兼容「传 ID」与「传 WP_Comment 对象」两种调用来源。
 *
 * @param int         $comment_id 评论 ID。
 * @param object|null $comment    评论对象（wp_insert_comment / transition_comment_status 回调实参）。
 * @return object|null
 */
function jinyu_cn_comment( $comment_id, $comment ) {
	if ( is_object( $comment ) && isset( $comment->comment_ID ) ) {
		return $comment;
	}
	$comment = get_comment( (int) $comment_id );
	return $comment ?: null;
}

/** pingback / trackback 属系统回链，不参与任何邮件通知。 */
function jinyu_cn_is_system( $comment ): bool {
	return in_array( (string) $comment->comment_type, [ 'pingback', 'trackback' ], true );
}

/**
 * 统一发信：补站点名前缀 + HTML 头，收件人非法直接跳过（不发空信）。
 *
 * @param string $to      收件人。
 * @param string $subject 主题（自动加 [站点名]）。
 * @param string $body    HTML 正文。
 */
function jinyu_cn_mail( $to, $subject, $body ): bool {
	if ( ! $to || ! is_email( $to ) ) {
		return false;
	}
	return (bool) wp_mail(
		$to,
		sprintf( '[%s] %s', get_bloginfo( 'name' ), $subject ),
		$body,
		[ 'Content-Type: text/html; charset=UTF-8' ]
	);
}

/** 正文尾部按钮链接。 */
function jinyu_cn_link( $url, $text ): string {
	return '<p><a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></p>';
}

/* ----------------------------- 回复通知 / 作者通知 ----------------------------- */

add_action( 'wp_insert_comment', 'jinyu_cn_notify_comment', 10, 2 );
function jinyu_cn_notify_comment( $comment_id, $comment ) {
	$comment = jinyu_cn_comment( $comment_id, $comment );
	// 仅通知「已通过」的评论；未通过的走下方「拦截提醒」与「审核通过通知」。
	if ( ! $comment || 1 !== (int) $comment->comment_approved || jinyu_cn_is_system( $comment ) ) {
		return;
	}

	$notify_reply  = jinyu_companion_is_checked( 'comment_notify_reply', true );
	$notify_author = jinyu_companion_is_checked( 'comment_notify_author', true );
	if ( ! $notify_reply && ! $notify_author ) {
		return;
	}

	$post = get_post( $comment->comment_post_ID );
	if ( ! $post ) {
		return;
	}

	$subject = __( '有新的评论回复', 'jinyu-theme-companion' );
	$content = esc_html( $comment->comment_content );
	$author  = esc_html( $comment->comment_author );

	// 有父评论 → 通知父评论作者（自己回自己不通知）
	if ( $notify_reply && (int) $comment->comment_parent > 0 ) {
		$parent = get_comment( $comment->comment_parent );
		if ( $parent && $parent->comment_author_email && $parent->comment_author_email !== $comment->comment_author_email ) {
			$body = '<p>' . sprintf(
				/* translators: %s: 文章标题 */
				esc_html__( '你在《%s》下的评论有了新回复', 'jinyu-theme-companion' ),
				esc_html( $post->post_title )
			) . '</p>'
				. '<blockquote>' . esc_html( $parent->comment_content ) . '</blockquote>'
				. '<p><strong>' . $author . '：</strong>' . $content . '</p>'
				. jinyu_cn_link( get_comment_link( $comment->comment_ID ), __( '查看回复', 'jinyu-theme-companion' ) );
			jinyu_cn_mail( $parent->comment_author_email, $subject, $body );
		}
	}

	// 通知文章作者（作者本人评论不通知）
	if ( $notify_author ) {
		$post_author = get_userdata( $post->post_author );
		$to          = $post_author ? $post_author->user_email : '';
		if ( $to && $to !== $comment->comment_author_email ) {
			$body = '<p>' . sprintf(
				/* translators: %s: 文章标题 */
				esc_html__( '你的文章《%s》有新评论', 'jinyu-theme-companion' ),
				esc_html( $post->post_title )
			) . '</p>'
				. '<p><strong>' . $author . '：</strong>' . $content . '</p>'
				. jinyu_cn_link( get_permalink( $post->ID ), __( '查看文章', 'jinyu-theme-companion' ) );
			jinyu_cn_mail( $to, $subject, $body );
		}
	}
}

/**
 * 核心「设置 → 讨论 → 有人发表评论时邮件通知我」(comments_notify) 的收件人同样是文章作者，
 * 与本插件「作者通知」完全重合 —— 两者都开会一封评论发两封信。
 * 本插件接管时让核心静默（保持「一次评论一封」的唯一收件人语义）；
 * 关闭「作者通知」开关即恢复核心行为，不会两头都没有。
 */
add_filter( 'notify_post_author', 'jinyu_cn_own_author_notify', 10, 2 );
function jinyu_cn_own_author_notify( $notify, $comment_id ) {
	return jinyu_companion_is_checked( 'comment_notify_author', true ) ? false : $notify;
}

/* ----------------------------- 拦截提醒（站长） ----------------------------- */

add_action( 'wp_insert_comment', 'jinyu_cn_notify_blocked', 20, 2 );
function jinyu_cn_notify_blocked( $comment_id, $comment ) {
	if ( ! jinyu_companion_is_checked( 'comment_notify_blocked', false ) ) {
		return;
	}
	$comment = jinyu_cn_comment( $comment_id, $comment );
	if ( ! $comment || jinyu_cn_is_system( $comment ) ) {
		return;
	}
	$status = (string) $comment->comment_approved;
	if ( '1' === $status || 'trash' === $status || 'post-trashed' === $status ) {
		return;
	}

	// 降噪：15 分钟内只发一封（垃圾攻击场景），期间条数计入下一封；计数 1 小时自然过期，避免陈旧累加。
	$queued = (int) get_transient( 'jyc_cn_blocked_queued' );
	set_transient( 'jyc_cn_blocked_queued', $queued + 1, HOUR_IN_SECONDS );
	$last = (int) get_transient( 'jyc_cn_blocked_last' );
	if ( $last && ( time() - $last ) < 900 ) {
		return;
	}
	set_transient( 'jyc_cn_blocked_last', time(), DAY_IN_SECONDS );
	delete_transient( 'jyc_cn_blocked_queued' );

	$to = get_option( 'admin_email' );
	if ( ! $to || ! is_email( $to ) ) {
		return;
	}

	$is_spam    = 'spam' === $status;
	$post       = get_post( $comment->comment_post_ID );
	$post_title = $post ? $post->post_title : __( '（文章已删除）', 'jinyu-theme-companion' );

	$body = '<p>' . sprintf(
		/* translators: 1: 评论作者 2: 文章标题 3: 处理状态（被判定为垃圾 / 进入待审队列） */
		esc_html__( '%1$s 在《%2$s》提交的评论%3$s，请及时处理。', 'jinyu-theme-companion' ),
		esc_html( $comment->comment_author ? $comment->comment_author : __( '访客', 'jinyu-theme-companion' ) ),
		esc_html( $post_title ),
		$is_spam ? esc_html__( '被判定为垃圾', 'jinyu-theme-companion' ) : esc_html__( '进入待审队列', 'jinyu-theme-companion' )
	) . '</p>'
		. '<blockquote>' . esc_html( $comment->comment_content ) . '</blockquote>';

	$meta = [];
	if ( ! empty( $comment->comment_author_email ) ) {
		/* translators: %s: 评论者邮箱 */
		$meta[] = sprintf( esc_html__( '邮箱 %s', 'jinyu-theme-companion' ), esc_html( $comment->comment_author_email ) );
	}
	if ( ! empty( $comment->comment_author_IP ) ) {
		/* translators: %s: 评论者 IP */
		$meta[] = sprintf( esc_html__( 'IP %s', 'jinyu-theme-companion' ), esc_html( $comment->comment_author_IP ) );
	}
	if ( $queued > 0 ) {
		/* translators: %d: 冷却期内被合并的评论条数 */
		$meta[] = sprintf( esc_html__( '另有 %d 条同类评论被合并通知', 'jinyu-theme-companion' ), $queued );
	}
	if ( $meta ) {
		$body .= '<p style="color:#666;font-size:13px">' . implode( ' · ', $meta ) . '</p>';
	}

	$body .= jinyu_cn_link(
		admin_url( 'edit-comments.php' . ( $is_spam ? '?comment_status=spam' : '?comment_status=moderated' ) ),
		$is_spam ? __( '查看垃圾评论', 'jinyu-theme-companion' ) : __( '前往审核', 'jinyu-theme-companion' )
	);

	jinyu_cn_mail(
		$to,
		$is_spam ? __( '有评论被判为垃圾', 'jinyu-theme-companion' ) : __( '有评论等待审核', 'jinyu-theme-companion' ),
		$body
	);
}

/* ----------------------------- 审核通过通知（评论者） ----------------------------- */

add_action( 'transition_comment_status', 'jinyu_cn_notify_approved', 10, 3 );
function jinyu_cn_notify_approved( $new_status, $old_status, $comment ) {
	if ( 'approved' !== $new_status || 'approved' === $old_status ) {
		return;
	}
	if ( ! jinyu_companion_is_checked( 'comment_notify_approved', false ) ) {
		return;
	}
	$comment = jinyu_cn_comment( is_object( $comment ) ? ( $comment->comment_ID ?? 0 ) : 0, $comment );
	if ( ! $comment || jinyu_cn_is_system( $comment ) ) {
		return;
	}
	// 能审核的账号（站长 / 编辑）不需要被通知自己的评论已通过
	if ( ! empty( $comment->user_id ) && user_can( (int) $comment->user_id, 'moderate_comments' ) ) {
		return;
	}
	// 幂等：同一条评论只通知一次（避免「通过 → 待审 → 再通过」重复发信）
	if ( get_comment_meta( $comment->comment_ID, 'jyc_cn_approved_sent', true ) ) {
		return;
	}
	$to = $comment->comment_author_email;
	if ( ! $to || ! is_email( $to ) ) {
		return;
	}
	update_comment_meta( $comment->comment_ID, 'jyc_cn_approved_sent', 1 );

	$post = get_post( $comment->comment_post_ID );
	$body = '<p>' . sprintf(
		/* translators: %s: 文章标题 */
		esc_html__( '你在《%s》下的评论已通过审核并显示。', 'jinyu-theme-companion' ),
		esc_html( $post ? $post->post_title : '' )
	) . '</p>'
		. '<blockquote>' . esc_html( $comment->comment_content ) . '</blockquote>'
		. jinyu_cn_link( get_comment_link( $comment->comment_ID ), __( '查看评论', 'jinyu-theme-companion' ) );

	jinyu_cn_mail( $to, __( '你的评论已通过审核', 'jinyu-theme-companion' ), $body );
}

// SMTP 接管统一由 inc/fun/email.php 的 Jinyu_SmtpConfig 在 phpmailer_init 钩子完成，
// 此处不再重复注册，避免两个钩子相互覆盖（尤其会冲掉测试邮件的临时覆盖配置）。
