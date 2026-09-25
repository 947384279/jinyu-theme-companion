<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 微信 JS-SDK 分享（转发给好友 / 分享到朋友圈）
 * ------------------------------------------------------------------
 * - 仅对「认证服务号」开放分享接口；个人订阅号调分享接口会 config:fail，本功能自动跳过。
 * - AppSecret 加密入库（jinyu_companion_encrypt），前端永不出现明文。
 * - access_token / jsapi_ticket 服务端缓存（option + 过期时间戳），避免触发微信 2000/日调用上限。
 * - 前端 wx.config 签名所需 URL 取 $_SERVER 当前地址（与微信 JS 安全域名一致）；
 *   分享数据（title/desc/link/imgUrl）直接读 <head> 内已输出的 og:* meta，与 OG 卡片完全一致，零冗余。
 */

if ( ! function_exists( 'jinyu_wechat_share_enabled' ) ) {
	/**
	 * 是否启用微信分享：开关打开且已填 AppID 才生效。
	 */
	function jinyu_wechat_share_enabled() {
		return jinyu_companion_is_checked( 'wechat_share_enable', false )
			&& '' !== (string) jinyu_companion_get_option( 'wechat_appid', '' );
	}
}

if ( ! function_exists( 'jinyu_wechat_get_appid' ) ) {
	function jinyu_wechat_get_appid() {
		return (string) jinyu_companion_get_option( 'wechat_appid', '' );
	}
}

if ( ! function_exists( 'jinyu_wechat_get_appsecret' ) ) {
	/**
	 * 读解密后的 AppSecret。库内为 jinyu_enc2:: 密文，解密失败回退空串。
	 */
	function jinyu_wechat_get_appsecret() {
		$raw = jinyu_companion_get_option( 'wechat_appsecret', '' );
		return $raw ? (string) jinyu_companion_decrypt( $raw ) : '';
	}
}

if ( ! function_exists( 'jinyu_wechat_get_access_token' ) ) {
	/**
	 * 取 access_token（缓存 7000s，微信官方 7200s 过期）。失败返回空串并记日志，前端据此跳过注入（不致命）。
	 */
	function jinyu_wechat_get_access_token() {
		$cache = get_option( 'jinyu_wechat_access_token', [] );
		if ( is_array( $cache ) && ! empty( $cache['token'] ) && ! empty( $cache['exp'] ) && (int) $cache['exp'] > time() ) {
			return $cache['token'];
		}
		$appid  = jinyu_wechat_get_appid();
		$secret = jinyu_wechat_get_appsecret();
		if ( ! $appid || ! $secret ) {
			return '';
		}
		$url  = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . rawurlencode( $appid ) . '&secret=' . rawurlencode( $secret );
		$resp = wp_remote_get( $url, [ 'timeout' => 8 ] );
		if ( is_wp_error( $resp ) ) {
			error_log( '[jinyu-wechat] access_token request failed: ' . $resp->get_error_message() );
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['access_token'] ) ) {
			error_log( '[jinyu-wechat] access_token error: ' . wp_remote_retrieve_body( $resp ) );
			return '';
		}
		update_option(
			'jinyu_wechat_access_token',
			[
				'token' => $body['access_token'],
				'exp'   => time() + min( 7000, (int) ( $body['expires_in'] ?? 7200 ) - 200 ),
			],
			false
		);
		return $body['access_token'];
	}
}

if ( ! function_exists( 'jinyu_wechat_get_jsapi_ticket' ) ) {
	/**
	 * 取 jsapi_ticket（缓存 7000s）。依赖 access_token；errcode 非 0 视为失败。
	 */
	function jinyu_wechat_get_jsapi_ticket() {
		$cache = get_option( 'jinyu_wechat_ticket', [] );
		if ( is_array( $cache ) && ! empty( $cache['ticket'] ) && ! empty( $cache['exp'] ) && (int) $cache['exp'] > time() ) {
			return $cache['ticket'];
		}
		$at = jinyu_wechat_get_access_token();
		if ( ! $at ) {
			return '';
		}
		$url  = 'https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=' . rawurlencode( $at ) . '&type=jsapi';
		$resp = wp_remote_get( $url, [ 'timeout' => 8 ] );
		if ( is_wp_error( $resp ) ) {
			error_log( '[jinyu-wechat] ticket request failed: ' . $resp->get_error_message() );
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['ticket'] ) || (int) ( $body['errcode'] ?? 0 ) !== 0 ) {
			error_log( '[jinyu-wechat] ticket error: ' . wp_remote_retrieve_body( $resp ) );
			return '';
		}
		update_option(
			'jinyu_wechat_ticket',
			[
				'ticket' => $body['ticket'],
				'exp'    => time() + min( 7000, (int) ( $body['expires_in'] ?? 7200 ) - 200 ),
			],
			false
		);
		return $body['ticket'];
	}
}

if ( ! function_exists( 'jinyu_wechat_build_signature' ) ) {
	/**
	 * 计算签名。url 必须是当前页面地址（含 query，不含 #hash），与微信 JS 安全域名一致。
	 */
	function jinyu_wechat_build_signature( $ticket, $url ) {
		$nonce     = wp_generate_password( 16, false );
		$timestamp = (string) time();
		$raw       = 'jsapi_ticket=' . $ticket . '&noncestr=' . $nonce . '&timestamp=' . $timestamp . '&url=' . $url;
		return [
			'appId'     => jinyu_wechat_get_appid(),
			'timestamp' => $timestamp,
			'nonceStr'  => $nonce,
			'signature' => sha1( $raw ),
		];
	}
}

if ( ! function_exists( 'jinyu_wechat_current_url' ) ) {
	/**
	 * 当前前端页面 URL（用于签名）。与微信内浏览器地址栏一致（含 query，剔除 #hash）。
	 */
	function jinyu_wechat_current_url() {
		$proto = ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
			|| ( ! empty( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] ) ) ? 'https' : 'http';
		$host = ! empty( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uri  = ! empty( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return $proto . '://' . $host . $uri;
	}
}

if ( ! function_exists( 'jinyu_wechat_print_script' ) ) {
	/**
	 * 前端注入：jweixin + wx.config + 分享数据（读 og:* meta）。
	 * 挂 wp_head 优先级 3（seo meta 在 1，og 标签已就绪）。任何异常均静默跳过，不破坏页面。
	 */
	function jinyu_wechat_print_script() {
		if ( is_admin() ) {
			return;
		}
		if ( ! jinyu_wechat_share_enabled() ) {
			return;
		}
		$url   = jinyu_wechat_current_url();
		$ticket = jinyu_wechat_get_jsapi_ticket();
		if ( ! $ticket ) {
			return; // 签名拿不到（配置/网络/IP 白名单问题）→ 不注入，页面照常
		}
		$cfg   = jinyu_wechat_build_signature( $ticket, $url );
		$debug = jinyu_companion_is_checked( 'wechat_share_debug', false ) ? 'true' : 'false';
		// 分享数据从已输出的 og meta 读取，保证与 OG 卡片一致；无 og 时回退 title / 当前地址。
		$inline = 'window.jinyuWechat=' . wp_json_encode( $cfg ) . ';'
			. "(function(){if(typeof wx==='undefined')return;"
			. "var og=function(p){var m=document.querySelector('meta[property=\"'+p+'\"]');return m?m.getAttribute('content'):'';};"
			. "var data={title:og('og:title')||document.title,desc:og('og:description')||'',link:og('og:url')||location.href,imgUrl:og('og:image')||''};"
			. "wx.config({debug:" . $debug . ',appId:window.jinyuWechat.appId,timestamp:parseInt(window.jinyuWechat.timestamp,10),nonceStr:window.jinyuWechat.nonceStr,signature:window.jinyuWechat.signature,jsApiList:[\'updateAppMessageShareData\',\'updateTimelineShareData\']});'
			. 'wx.ready(function(){wx.updateAppMessageShareData({title:data.title,desc:data.desc,link:data.link,imgUrl:data.imgUrl});wx.updateTimelineShareData({title:data.title,link:data.link,imgUrl:data.imgUrl});});'
			. 'wx.error(function(res){if(' . $debug . '){console.warn(\'[jinyu-wechat] config fail:\',res);}});'
			. '})();';
		echo '<script src="https://res.wx.qq.com/open/js/jweixin-1.6.0.js"></script>' . PHP_EOL;
		echo '<script>' . $inline . '</script>' . PHP_EOL;
	}
}

add_action( 'wp_head', 'jinyu_wechat_print_script', 3 );
