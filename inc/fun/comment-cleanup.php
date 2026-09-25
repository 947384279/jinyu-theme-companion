<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 历史垃圾评论清理（设置面板「评论与互动」分区「历史垃圾评论清理」入口）
 *
 * 设计原则（对照此前手动清理经验）：
 *  - 仅"扫描 + 人工确认后删除"，绝不自动硬删已批准评论（误杀风险高，如常青技术文被误归导航类）。
 *  - 删除前可先备份到独立表（{prefix}comments_cleanup_bak），可恢复。
 *  - 删除走 WP 原生 wp_delete_comment()，自动维护文章评论计数与缓存，比裸 SQL 更规范。
 *  - 校验：jinyu_companion_settings nonce + manage_options 权限。
 *
 * 检测算法（v2，加权评分引擎）：
 *  不再用"有外链=垃圾"的二值判断，改为对每条已批准评论计算 0~100 的垃圾评分，
 *  综合多个独立信号（外部链接数、关键词命中、模板灌水、重复内容、同邮箱多评、
 *  作者名推广词、非中文带链、过短带链等），按分值降序返回，便于人工由重到轻处置。
 *  评分是启发式（无外部 API / 无 ML），是离线自包含场景下的最佳实用方案；
 *  若需更强能力可后续接入 Akismet 等服务做二次校验。
 */

add_action( 'wp_ajax_jinyu_comment_cleanup_scan', 'jinyu_comment_cleanup_scan' );
add_action( 'wp_ajax_jinyu_comment_cleanup_delete', 'jinyu_comment_cleanup_delete' );

/**
 * 扫描：对已批准评论做加权评分，返回按风险降序的疑似清单。
 */
function jinyu_comment_cleanup_scan() {
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
	}

	global $wpdb;

	$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	// 内置垃圾词库（拉丁词统一转小写做不区分大小写匹配），与用户自定义 anti_spam_words 合并。
	$builtin = array(
		'代写', '论文', '博彩', '彩票', '赌博', '色情', '成人', 'casino', 'viagra', 'cialis',
		'porn', 'sex', 'essay', 'togel', 'bet', '折扣', '刷量', '外链', '引流', '招商', '加盟',
		'代刷', '手游', '私服', '比特币', '虚拟币', '投资理财', '伦敦金', '现货', '外汇跟单',
		'万博', '百家乐', '滚球', 'abet', 'crypto', 'loan', 'pharmacy', 'xxx', 'nude',
	);
	$user_words = jinyu_companion_get_option(
		'anti_spam_words',
		defined( 'JINYU_DEFAULT_SPAM_WORDS' ) ? JINYU_DEFAULT_SPAM_WORDS : '彩票,色情,赌博,代写,刷量'
	);
	$user_words  = array_filter( array_map( 'trim', explode( ',', (string) $user_words ) ) );
	$blacklist   = array_unique( array_merge( $builtin, $user_words ) );
	$blacklist_lc = array_map( 'strtolower', $blacklist );

	// 受信任邮箱（站点注册用户）：其评论直接判为可信，避免误伤站长 / 已知作者。
	$trusted = array();
	foreach ( get_users( array( 'fields' => array( 'user_email' ) ) ) as $u ) {
		if ( ! empty( $u->user_email ) ) {
			$trusted[ strtolower( $u->user_email ) ] = true;
		}
	}

	// 拉取全部已批准评论（上限 2000），在 PHP 内逐条评分；只返回评分 > 0 的疑似项。
	$rows = $wpdb->get_results(
		"SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_content, comment_date
		 FROM {$wpdb->comments}
		 WHERE comment_approved = '1'
		 ORDER BY comment_date DESC
		 LIMIT 2000"
	);
	$rows = (array) $rows;

	// 预统计：内容重复次数、同邮箱评论次数。
	$content_count = array();
	$email_count   = array();
	foreach ( $rows as $r ) {
		$ctext = trim( strtolower( wp_strip_all_tags( (string) $r->comment_content ) ) );
		if ( '' !== $ctext ) {
			$key = md5( $ctext );
			$content_count[ $key ] = ( $content_count[ $key ] ?? 0 ) + 1;
		}
		$em = strtolower( trim( (string) $r->comment_author_email ) );
		if ( '' !== $em ) {
			$email_count[ $em ] = ( $email_count[ $em ] ?? 0 ) + 1;
		}
	}

	// 英文模板灌水特征（出现即高度疑似机器人）。
	$templates = array(
		'thank you for another', 'thanks for sharing', 'nice post', 'great article',
		'very informative', 'i really appreciate', 'keep up the good work', 'this is a great blog',
		'useful information', 'i stumbled upon', 'hello mates', 'i every time used to',
	);

	$items = array();
	foreach ( $rows as $r ) {
		$score   = 0;
		$reasons = array();

		$author     = (string) $r->comment_author;
		$author_lc  = strtolower( $author );
		$email      = strtolower( trim( (string) $r->comment_author_email ) );
		$url        = trim( (string) $r->comment_author_url );
		$content    = (string) $r->comment_content;
		$content_lc = strtolower( $content );
		$text       = wp_strip_all_tags( $content );
		$len        = mb_strlen( $text );

		// ---- 受信任作者（站点注册用户）直接放行，不计入疑似清单 ----
		if ( '' !== $email && isset( $trusted[ $email ] ) ) {
			continue;
		}

		// 1) 作者主页含站外链接（指向自己站点不算）。
		if ( '' !== $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $host && $host !== $site_host ) {
				$score    += 30;
				$reasons[] = __( '作者主页含站外链接', 'jinyu-theme-companion' );
			}
		}

		// 2) 正文链接：统计 <a href> 中的站外链接，以及裸写的 http(s)（未包在标签里）。
		$ext_links     = array();
		$link_in_tags  = preg_match_all( '~<a\s[^>]*href=["\']?(https?://[^"\'\s>]+)["\']?~i', $content, $lm );
		if ( $link_in_tags ) {
			foreach ( array_unique( $lm[1] ) as $href ) {
				$h = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
				if ( '' !== $h && $h !== $site_host && count( $ext_links ) < 5 ) {
					$ext_links[] = $h;
				}
			}
		}
		$raw_links = 0;
		if ( preg_match_all( '~https?://~i', $content, $rm ) ) {
			$raw_links = count( $rm[0] );
		}
		$total_links = max( count( $ext_links ), $raw_links );
		if ( $total_links >= 1 ) {
			$score    += min( $total_links * 12, 40 );
			$reason    = sprintf( __( '正文含 %d 个站外链接', 'jinyu-theme-companion' ), $total_links );
			if ( $ext_links ) {
				$reason .= '（' . implode( ', ', $ext_links ) . '）';
			}
			$reasons[] = $reason;
		}

		// 3) 垃圾关键词命中（内置词库 + 用户自定义）。
		$hit = array();
		foreach ( $blacklist_lc as $w ) {
			if ( '' === $w ) {
				continue;
			}
			if ( stripos( $content_lc, $w ) !== false || stripos( $author_lc, $w ) !== false ) {
				$hit[] = $w;
			}
		}
		if ( $hit ) {
			$score    += min( count( $hit ) * 12, 35 );
			$reasons[] = sprintf( __( '命中垃圾词：%s', 'jinyu-theme-companion' ), implode( '、', array_slice( $hit, 0, 5 ) ) );
		}

		// 4) 作者名含链接或推广词。
		if ( preg_match( '~https?://~i', $author ) ) {
			$score    += 10;
			$reasons[] = __( '作者名含链接', 'jinyu-theme-companion' );
		}
		if ( preg_match( '~[一-龥]{0,3}(代写|博彩|彩票|赌博|色情|成人|代刷|引流|招商|加盟|私服|外链|seo)~i', $author ) ) {
			$score    += 12;
			$reasons[] = __( '作者名含推广词', 'jinyu-theme-companion' );
		}

		// 5) 英文模板灌水（机器人特征）。
		foreach ( $templates as $t ) {
			if ( stripos( $content_lc, $t ) !== false ) {
				$score    += 15;
				$reasons[] = __( '疑似模板化灌水（外文）', 'jinyu-theme-companion' );
				break;
			}
		}

		// 6) 内容重复出现（批量灌水）。
		$ck = md5( trim( strtolower( $text ) ) );
		if ( isset( $content_count[ $ck ] ) && $content_count[ $ck ] > 1 ) {
			$score    += 20;
			$reasons[] = sprintf( __( '内容重复出现（疑似批量灌水，%d 条）', 'jinyu-theme-companion' ), $content_count[ $ck ] );
		}

		// 7) 同一邮箱多次评论。
		if ( '' !== $email && isset( $email_count[ $email ] ) && $email_count[ $email ] >= 3 ) {
			$score    += 12;
			$reasons[] = sprintf( __( '同一邮箱多次评论（%d 条）', 'jinyu-theme-companion' ), $email_count[ $email ] );
		}

		// 8) 长度异常：过短且带链、或带链却异常冗长（填充）。
		if ( $total_links >= 1 && $len < 25 ) {
			$score    += 12;
			$reasons[] = __( '内容过短且含链接', 'jinyu-theme-companion' );
		} elseif ( $total_links >= 1 && $len > 1500 ) {
			$score += 6;
		}

		// 9) 非中文且带链（多为外文垃圾）。
		$cjk = preg_match_all( '~[一-鿿]~u', $text, $mm );
		if ( $len > 0 && $total_links >= 1 && 0 === $cjk && $len < 400 ) {
			$score    += 10;
			$reasons[] = __( '非中文内容且含链接', 'jinyu-theme-companion' );
		}

		if ( $score <= 0 ) {
			continue;
		}

		$level = $score >= 70 ? 'high' : ( $score >= 40 ? 'mid' : 'low' );

		$items[] = array(
			'id'      => (int) $r->comment_ID,
			'post_id' => (int) $r->comment_post_ID,
			'author'  => $author,
			'url'     => $url,
			'email'   => $email,
			'date'    => $r->comment_date,
			'excerpt' => wp_strip_all_tags( mb_substr( $text, 0, 160 ) ),
			'score'   => $score,
			'level'   => $level,
			'reasons' => $reasons,
		);
	}

	// 评分降序，优先处置高危。
	usort(
		$items,
		function ( $a, $b ) {
			return (int) $b['score'] - (int) $a['score'];
		}
	);

	$total = count( $items );
	if ( $total > 500 ) {
		$items = array_slice( $items, 0, 500 );
	}

	wp_send_json_success(
		array(
			'msg'   => sprintf( __( '扫描完成：发现 %d 条疑似垃圾评论（已按风险评分降序排列）。请勾选确认后删除。', 'jinyu-theme-companion' ), $total ),
			'items' => $items,
			'total' => $total,
		)
	);
}

/**
 * 删除：删除管理员勾选的评论；可先备份到 {prefix}comments_cleanup_bak。
 * 仅作用于确为"已批准"的评论，防止误删其它状态。
 */
function jinyu_comment_cleanup_delete() {
	check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
	}

	$ids_raw = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
	$ids     = array_filter( array_map( 'intval', explode( ',', $ids_raw ) ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( __( '未选择任何评论', 'jinyu-theme-companion' ) );
	}
	// 安全上限，避免超大批量
	if ( count( $ids ) > 2000 ) {
		$ids = array_slice( $ids, 0, 2000 );
	}

	global $wpdb;
	$backup = ! empty( $_POST['backup'] ) && '1' === (string) $_POST['backup'];

	// 仅作用于确为"已批准"的评论（与扫描口径一致），其余忽略
	$ph            = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$approved_ids  = $wpdb->get_col(
		$wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID IN ({$ph}) AND comment_approved = '1'", $ids )
	);
	if ( empty( $approved_ids ) ) {
		wp_send_json_error( __( '未找到可删除的已批准评论。', 'jinyu-theme-companion' ) );
	}

	$backed_up = 0;
	if ( $backup ) {
		$table = $wpdb->prefix . 'comments_cleanup_bak';
		// 与本站前缀一致，绝不越权；仅复制结构，不复制其它库表。
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} LIKE {$wpdb->comments}" );
		$bph        = implode( ',', array_fill( 0, count( $approved_ids ), '%d' ) );
		$backed_up = (int) $wpdb->query(
			$wpdb->prepare( "INSERT INTO {$table} SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ({$bph})", $approved_ids )
		);
	}

	// 走原生 API 删除：自动维护文章评论计数、触发钩子、清理缓存，比裸 SQL 更规范。
	$deleted = 0;
	foreach ( $approved_ids as $cid ) {
		if ( wp_delete_comment( (int) $cid, true ) ) {
			$deleted++;
		}
	}

	wp_send_json_success(
		array(
			'msg'     => $backup
				? sprintf( __( '已备份 %1$d 条并彻底删除 %2$d 条垃圾评论（备份表 %3$s，可经 SQL 恢复）。', 'jinyu-theme-companion' ), $backed_up, $deleted, $table )
				: sprintf( __( '已彻底删除 %d 条垃圾评论（未备份）。', 'jinyu-theme-companion' ), $deleted ),
			'deleted' => $deleted,
		)
	);
}
