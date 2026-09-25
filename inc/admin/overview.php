<?php
/**
 * 设置面板「概览」分区磁贴的数据层（加载性能 / 优化待办 / 数据库健康）。
 *
 * 三张磁贴全部复用既有模块的数据，不新增采集、不新增存储：
 *  - 加载性能   ← jyc_perf_web_vitals_stats()（前台 web-vitals 聚合）
 *  - 优化待办   ← 各分区开关 / 配置项（规格同时下发给前端，未保存时也能实时重算）
 *  - 数据库健康 ← jyc_perf_status_snapshot()（autoload / transient / 表碎片）
 *
 * @package jinyu-theme-companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'jinyu_companion_overview_vitals' ) ) {
	/**
	 * 加载性能磁贴：真实用户 Core Web Vitals（近 7 天聚合）。
	 *
	 * 主值取 LCP 均值（最直观的「快不快」），小字给综合评分与样本数；
	 * 无样本 / 数据过期都如实呈现，不伪造成「优秀」。
	 *
	 * @return array{rate:string,main:string,note:string}
	 */
	function jinyu_companion_overview_vitals(): array {
		$empty = array(
			'rate' => 'none',
			'main' => '—',
			'note' => __( '暂无样本 · 等待前台访问', 'jinyu-theme-companion' ),
		);

		foreach ( array( 'jyc_perf_web_vitals_stats', 'jyc_perf_wv_meta', 'jyc_perf_wv_rate' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				return $empty;
			}
		}

		$wv = jyc_perf_web_vitals_stats();
		$lcp = (int) ( $wv['avg']['lcp'] ?? 0 );
		if ( empty( $wv['n'] ) || $lcp <= 0 ) {
			return $empty;
		}

		$meta = jyc_perf_wv_meta();
		$note = sprintf(
			/* translators: 1: 综合评分 2: 样本数 */
			__( '综合 %1$d 分 · %2$s 样本', 'jinyu-theme-companion' ),
			function_exists( 'jyc_perf_wv_score' ) ? (int) jyc_perf_wv_score( $wv, $meta )['score'] : 0,
			number_format_i18n( (int) $wv['n'] )
		);
		if ( empty( $wv['fresh'] ) ) {
			$note .= __( ' · 数据已过期', 'jinyu-theme-companion' );
		}

		return array(
			'rate' => jyc_perf_wv_rate( (float) $lcp, $meta['lcp'] ),
			'main' => $lcp >= 1000 ? number_format( $lcp / 1000, 1 ) . 's' : $lcp . 'ms',
			'note' => $note,
		);
	}
}

if ( ! function_exists( 'jinyu_companion_overview_todos' ) ) {
	/**
	 * 优化待办规格：服务端唯一事实源。
	 *
	 * 同一份规格既用于服务端渲染初值，也 JSON 下发给 assets/admin.js 做未保存时的实时重算，
	 * 避免「开关清单」在 PHP / JS 各写一份导致漂移。
	 *
	 * 每项：n=字段名，t=判定类型（check=须勾选 / filled=须填值 / not:xx=不得等于 xx），
	 *       p=所属分区（点击跳转目标），l=待办文案，d=选项默认值（与渲染层取值口径一致），
	 *       ok=当前是否已满足。
	 *
	 * @return array<int,array{n:string,t:string,p:string,l:string,d:string,ok:bool}>
	 */
	function jinyu_companion_overview_todos(): array {
		$defs = array(
			array( 'page_cache_enable', 'check', 'perf', __( '整页缓存未开启', 'jinyu-theme-companion' ), '0' ),
			array( 'smtp_host', 'filled', 'smtp', __( 'SMTP 未配置，通知邮件回落 PHP mail()', 'jinyu-theme-companion' ), '' ),
			array( 'storage_provider', 'filled', 'storage', __( '对象存储未启用，图片仍走本地', 'jinyu-theme-companion' ), '' ),
			array( 'indexnow_enable', 'check', 'content', __( 'IndexNow 主动推送未开启', 'jinyu-theme-companion' ), '0' ),
			array( 'seo_open', 'check', 'seo', __( 'SEO / OG 分享卡片已关闭', 'jinyu-theme-companion' ), '1' ),
			array( 'captcha_policy', 'not:off', 'comment', __( '评论验证码已关闭', 'jinyu-theme-companion' ), 'smart' ),
		);

		$out = array();
		foreach ( $defs as $def ) {
			$out[] = array(
				'n'  => $def[0],
				't'  => $def[1],
				'p'  => $def[2],
				'l'  => $def[3],
				'd'  => $def[4],
				'ok' => jinyu_companion_overview_todo_ok( $def[0], $def[1], $def[4] ),
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'jinyu_companion_overview_todo_ok' ) ) {
	/**
	 * 单项待办是否已满足。判定口径需与 assets/admin.js 的 todoOpen() 一致。
	 *
	 * @param string $name    选项名。
	 * @param string $type    判定类型：check / filled / not:xx。
	 * @param string $default 选项默认值。
	 * @return bool
	 */
	function jinyu_companion_overview_todo_ok( string $name, string $type, string $default = '' ): bool {
		$val = (string) jinyu_companion_get_option( $name, $default );

		if ( 'check' === $type ) {
			return '1' === $val;
		}
		if ( 'filled' === $type ) {
			return '' !== trim( $val );
		}
		if ( 0 === strpos( $type, 'not:' ) ) {
			return substr( $type, 4 ) !== $val;
		}
		return true;
	}
}

if ( ! function_exists( 'jinyu_companion_overview_db' ) ) {
	/**
	 * 数据库健康磁贴：过期缓存条目 + 表碎片。
	 *
	 * 复用性能中心的状态快照（同请求内与「性能中心」分区共享一次查询），主值取最需处理的
	 * 过期缓存条目数（0 即「干净」），小字补充表碎片与 transient 总量。
	 *
	 * @return array{rate:string,main:string,note:string}
	 */
	function jinyu_companion_overview_db(): array {
		if ( ! function_exists( 'jyc_perf_status_snapshot' ) && ! function_exists( 'jyc_perf_status' ) ) {
			return array(
				'rate' => 'none',
				'main' => '—',
				'note' => __( '性能中心未加载', 'jinyu-theme-companion' ),
			);
		}

		$st       = function_exists( 'jyc_perf_status_snapshot' ) ? jyc_perf_status_snapshot() : jyc_perf_status();
		$expired  = (int) ( $st['transient_expired'] ?? 0 );
		$total    = (int) ( $st['transient_total'] ?? 0 );
		$overhead = (float) ( $st['table_overhead'] ?? 0 );

		return array(
			'rate' => ( $expired > 0 || $overhead > 20 * MB_IN_BYTES ) ? 'warn' : 'ok',
			'main' => $expired > 0
				? sprintf(
					/* translators: %s: 过期缓存条目数 */
					__( '%s 条', 'jinyu-theme-companion' ),
					number_format_i18n( $expired )
				)
				: __( '干净', 'jinyu-theme-companion' ),
			'note' => sprintf(
				/* translators: 1: 表碎片体积 2: transient 条目总数 */
				__( '过期缓存 · 表碎片 %1$s · 缓存 %2$s 条', 'jinyu-theme-companion' ),
				size_format( $overhead, 1 ),
				number_format_i18n( $total )
			),
		);
	}
}
