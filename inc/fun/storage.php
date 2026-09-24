<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// 注意：本文件禁止文件级 return 守卫。PHP 8.5 对无条件顶层函数做「编译期早绑定」，
// 本文件被编译时 jinyu_is_storage_enabled 即已存在，文件顶部 if(function_exists(...)){return;}
// 会看到自己刚绑定的函数而直接 return —— 第 6 行之后所有 add_filter/add_action 全部失效（引擎变僵尸）。
// 与历史私有插件 wordpress-plugin-jinyu 的互斥由「调用方 require 处守卫」保证（见其主文件），不在本文件内做。

/**
 * 对象存储接入（又拍云 / 七牛 / 阿里云 OSS / 腾讯云 COS）
 * ------------------------------------------------------------------
 * - 统一抽象：Jinyu_Storage_Adapter 接口，业务层零改动换云。
 * - 阿里云 OSS / 腾讯云 COS / 七牛 Kodo 全部 S3 协议兼容 → Jinyu_Storage_S3（AWS SigV4）。
 * - 又拍云不兼容 S3 → Jinyu_Storage_Upyun（Basic Auth）。
 * - 加速域名重写：接管 wp_get_attachment_url（优先级 5，先于主题 cdn_url 过滤器），
 *   仅前台、仅当 storage_provider + storage_domain 同时设置时生效；未设置则完全不干预，零副作用。
 * - 推送/拉回走 AJAX 分批 + 自建任务表（Memcached 下 transient 不可靠，进度必须落 DB）。
 * - 所有动作默认关闭，开启/配置后才生效，不影响任何现有功能。
 */

/* ───────────────────────────────────────────────────────────
 * 适配器接口
 * ─────────────────────────────────────────────────────────── */
interface Jinyu_Storage_Adapter {
	public function put( $local, $key );
	public function get( $key );
	public function delete( $key );
	public function test( $prefix );
	public function list_keys( $prefix );
	/* 并行上传：items = [{local,key}], concurrency 为同时进行的连接数；返回与输入对齐的布尔数组 */
	public function put_multi( array $items, $concurrency );
}

/* ───────────────────────────────────────────────────────────
 * 并行上传辅助（curl_multi，带并发上限）
 * ─────────────────────────────────────────────────────────── */
function jinyu_curl_headers( $assoc ) {
	$out = array();
	foreach ( (array) $assoc as $k => $v ) {
		$out[] = $k . ': ' . $v;
	}
	return $out;
}

/**
 * 对象存储请求 TLS 证书校验开关。
 *
 * 默认开启（true）：服务端↔对象存储链路必须校验证书，防中间人监听/篡改。
 * 仅当运行环境 CA 证书链异常（极少见，多见于老旧/精简 PHP 环境）导致上传失败时，
 * 才在 wp-config.php 定义常量 JINYU_STORAGE_INSECURE_SSL 为 true 临时降级。
 *
 * @return bool
 */
function jinyu_storage_ssl_verify(): bool {
	return ! ( defined( 'JINYU_STORAGE_INSECURE_SSL' ) && JINYU_STORAGE_INSECURE_SSL );
}

/**
 * curl 的 CURLOPT_SSL_VERIFYHOST 取值：校验时为 2，关闭时为 0。
 *
 * @return int
 */
function jinyu_storage_ssl_host(): int {
	return jinyu_storage_ssl_verify() ? 2 : 0;
}

/**
 * 用 curl_multi 并发执行一批已配置好的 handle。
 * @param array $handles 每项 ['ch'=>curl_handle, 'index'=>int]
 * @param int   $concurrency 同时进行的请求上限
 * @return array index => bool（HTTP 2xx 视为成功）
 */
function jinyu_storage_run_multi( $handles, $concurrency = 8 ) {
	$results = array();
	foreach ( (array) $handles as $h ) {
		$results[ $h['index'] ] = false;
	}
	if ( empty( $handles ) ) {
		return $results;
	}
	$concurrency = max( 1, (int) $concurrency );
	$mh       = curl_multi_init();
	$inflight = array();
	$queue    = array_values( $handles );

	$add_more = function () use ( &$queue, &$inflight, $mh, $concurrency ) {
		while ( count( $inflight ) < $concurrency && ! empty( $queue ) ) {
			$item = array_shift( $queue );
			curl_multi_add_handle( $mh, $item['ch'] );
			$inflight[] = $item;
		}
	};

	$add_more();
	do {
		curl_multi_exec( $mh, $running );
		if ( $running ) {
			curl_multi_select( $mh, 1.0 );
		}
		while ( ( $info = curl_multi_info_read( $mh ) ) !== false ) {
			$ch = $info['handle'];
			foreach ( $inflight as $k => $item ) {
				if ( $item['ch'] === $ch ) {
					$code              = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
					$results[ $item['index'] ] = ( $code >= 200 && $code < 300 );
					curl_multi_remove_handle( $mh, $ch );
					curl_close( $ch );
					unset( $inflight[ $k ] );
					$inflight = array_values( $inflight );
					break;
				}
			}
			$add_more();
		}
	} while ( $running > 0 || ! empty( $inflight ) || ! empty( $queue ) );

	curl_multi_close( $mh );
	return $results;
}

/* ───────────────────────────────────────────────────────────
 * 工厂
 * ─────────────────────────────────────────────────────────── */
class Jinyu_Storage_Factory {
	public static function make( $cfg ) {
		if ( empty( $cfg['provider'] ) ) {
			return null;
		}
		if ( $cfg['provider'] === 'upyun' ) {
			return new Jinyu_Storage_Upyun( $cfg );
		}
		return new Jinyu_Storage_S3( $cfg );
	}
}

/* ───────────────────────────────────────────────────────────
 * S3 兼容适配器（AWS Signature V4）
 * 覆盖：阿里云 OSS / 腾讯云 COS / 七牛云 Kodo / 华为 OBS 等
 * ─────────────────────────────────────────────────────────── */
class Jinyu_Storage_S3 implements Jinyu_Storage_Adapter {
	private $cfg;

	public function __construct( $cfg ) {
		$this->cfg = $cfg;
	}

	private function host() {
		$bucket   = trim( $this->cfg['bucket'] ?? '' );
		$endpoint = trim( $this->cfg['endpoint'] ?? '' );
		return $bucket . '.' . preg_replace( '#^https?://#i', '', $endpoint );
	}

	private function base() {
		return 'https://' . $this->host();
	}

	private function uri( $key ) {
		$parts = explode( '/', ltrim( $key, '/' ) );
		$parts = array_map( 'rawurlencode', $parts );
		return '/' . implode( '/', $parts );
	}

	private function guess_type( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
			'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
			'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
			'pdf' => 'application/pdf', 'zip' => 'application/zip', 'txt' => 'text/plain',
			'json' => 'application/json', 'css' => 'text/css', 'js' => 'application/javascript',
			'html' => 'text/html', 'xml' => 'application/xml', 'woff2' => 'font/woff2',
		);
		return $map[ $ext ] ?? 'application/octet-stream';
	}

	/**
	 * 计算 SigV4 鉴权头。
	 * @param string $method PUT/GET/DELETE
	 * @param string $uri    已编码路径（不含 query）
	 * @param string $body   请求体（PUT 用 UNSIGNED-PAYLOAD）
	 * @param array  $extra  额外需签名的头部（如 Content-Type）
	 * @param string $query  规范化 query 串（LIST 时使用）
	 */
	private function sign( $method, $uri, $body, $extra = array(), $query = '' ) {
		$host      = $this->host();
		$amzdate   = gmdate( 'Ymd\THis\Z' );
		$datestamp = gmdate( 'Ymd' );
		$region    = trim( $this->cfg['region'] ?? '' );
		$access    = trim( $this->cfg['access_key'] ?? '' );
		$secret    = trim( $this->cfg['secret'] ?? '' );

		$payload_hash = ( $method === 'PUT' ) ? 'UNSIGNED-PAYLOAD' : hash( 'sha256', $body );

		$headers = array(
			'host'               => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'        => $amzdate,
		);
		foreach ( $extra as $k => $v ) {
			$headers[ strtolower( $k ) ] = trim( (string) $v );
		}
		ksort( $headers );

		$canon_headers = '';
		$signed        = array();
		foreach ( $headers as $k => $v ) {
			$canon_headers .= $k . ':' . $v . "\n";
			$signed[]       = $k;
		}
		$signed_headers = implode( ';', $signed );

		$canon_req = $method . "\n" . $uri . "\n" . $query . "\n" . $canon_headers . "\n" . $signed_headers . "\n" . $payload_hash;

		$sts = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $datestamp . '/' . $region . '/s3/aws4_request' . "\n" . hash( 'sha256', $canon_req );

		$k1 = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret, true );
		$k2 = hash_hmac( 'sha256', $region, $k1, true );
		$k3 = hash_hmac( 'sha256', 's3', $k2, true );
		$k4 = hash_hmac( 'sha256', 'aws4_request', $k3, true );
		$sig = hash_hmac( 'sha256', $sts, $k4 );

		$auth = 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $datestamp . '/' . $region . '/s3/aws4_request, SignedHeaders=' . $signed_headers . ', Signature=' . $sig;

		$out = array(
			'Authorization'         => $auth,
			'x-amz-date'           => $amzdate,
			'x-amz-content-sha256' => $payload_hash,
		);
		foreach ( $extra as $k => $v ) {
			$out[ $k ] = $v;
		}
		return $out;
	}

	public function put( $local, $key ) {
		$body = @file_get_contents( $local );
		if ( $body === false ) {
			return false;
		}
		$ct      = $this->guess_type( $local );
		$uri     = $this->uri( $key );
		$headers = $this->sign( 'PUT', $uri, $body, array( 'Content-Type' => $ct ) );
		$res     = wp_remote_request(
			$this->base() . $uri,
			array(
			'method'      => 'PUT',
			'headers'     => $headers,
			'body'        => $body,
			'timeout'     => 120,
			'sslverify' => jinyu_storage_ssl_verify(),
		)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code >= 200 && $code < 300;
	}

	public function put_multi( $items, $concurrency = 8 ) {
		$n       = count( $items );
		$results = array_fill( 0, $n, false );
		$handles = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$local = $items[ $i ]['local'];
			$key   = $items[ $i ]['key'];
			$body  = @file_get_contents( $local );
			if ( $body === false ) {
				continue;
			}
			$ct      = $this->guess_type( $local );
			$uri     = $this->uri( $key );
			$headers = $this->sign( 'PUT', $uri, $body, array( 'Content-Type' => $ct ) );
			$ch      = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $this->base() . $uri,
					CURLOPT_CUSTOMREQUEST  => 'PUT',
					CURLOPT_HTTPHEADER     => jinyu_curl_headers( $headers ),
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_SSL_VERIFYPEER => jinyu_storage_ssl_verify(),
					CURLOPT_SSL_VERIFYHOST => jinyu_storage_ssl_host(),
					CURLOPT_TIMEOUT        => 120,
					CURLOPT_CONNECTTIMEOUT => 15,
				)
			);
			$handles[] = array( 'ch' => $ch, 'index' => $i );
		}
		$ok = jinyu_storage_run_multi( $handles, $concurrency );
		foreach ( $ok as $idx => $v ) {
			$results[ $idx ] = $v;
		}
		return $results;
	}

	public function get( $key ) {
		$uri     = $this->uri( $key );
		$headers = $this->sign( 'GET', $uri, '' );
		$res     = wp_remote_get(
			$this->base() . $uri,
			array(
				'headers'   => $headers,
				'timeout'   => 120,
				'sslverify' => jinyu_storage_ssl_verify(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return false;
		}
		return wp_remote_retrieve_body( $res );
	}

	public function delete( $key ) {
		$uri     = $this->uri( $key );
		$headers = $this->sign( 'DELETE', $uri, '' );
		$res     = wp_remote_request(
			$this->base() . $uri,
			array(
				'method'    => 'DELETE',
				'headers'   => $headers,
				'timeout'   => 60,
				'sslverify' => jinyu_storage_ssl_verify(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code === 204 || $code === 200 || $code === 404;
	}

	public function test( $prefix = '' ) {
		$tmp = function_exists( 'wp_tempnam' )
			? wp_tempnam( 'jinyu-probe' )
			: tempnam( sys_get_temp_dir(), 'jinyu-probe' );
		if ( ! $tmp ) {
			return '无法创建临时文件';
		}
		file_put_contents( $tmp, 'jinyu-probe-' . time() );
		$key = rtrim( $prefix, '/' ) . '/.jinyu-probe-' . uniqid() . '.txt';
		$ok  = $this->put( $tmp, $key );
		@unlink( $tmp );
		if ( ! $ok ) {
			return '上传测试文件失败（检查 桶名 / 区域 / Endpoint / 密钥权限）';
		}
		$back = $this->get( $key );
		$this->delete( $key );
		return ( $back !== false ) ? true : '上传成功但回读验证失败';
	}

	public function list_keys( $prefix = '' ) {
		$keys  = array();
		$token = '';
		$pages = 0;
		do {
			$params = array( 'list-type' => '2' );
			if ( $prefix !== '' ) {
				$params['prefix'] = $prefix;
			}
			if ( $token !== '' ) {
				$params['continuation-token'] = $token;
			}
			ksort( $params );
			$qsa  = array();
			foreach ( $params as $k => $v ) {
				$qsa[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
			}
			$query = implode( '&', $qsa );
			$uri   = '/';
			$host  = $this->host();

			$amzdate   = gmdate( 'Ymd\THis\Z' );
			$datestamp = gmdate( 'Ymd' );
			$region    = trim( $this->cfg['region'] ?? '' );
			$access    = trim( $this->cfg['access_key'] ?? '' );
			$secret    = trim( $this->cfg['secret'] ?? '' );

			$payload_hash    = hash( 'sha256', '' );
			$headers         = array(
				'host'               => $host,
				'x-amz-content-sha256' => $payload_hash,
				'x-amz-date'        => $amzdate,
			);
			ksort( $headers );
			$canon_headers = '';
			$signed        = array();
			foreach ( $headers as $k => $v ) {
				$canon_headers .= $k . ':' . $v . "\n";
				$signed[]       = $k;
			}
			$signed_headers = implode( ';', $signed );
			$canon_req      = 'GET' . "\n" . $uri . "\n" . $query . "\n" . $canon_headers . "\n" . $signed_headers . "\n" . $payload_hash;
			$sts            = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $datestamp . '/' . $region . '/s3/aws4_request' . "\n" . hash( 'sha256', $canon_req );
			$k1             = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret, true );
			$k2             = hash_hmac( 'sha256', $region, $k1, true );
			$k3             = hash_hmac( 'sha256', 's3', $k2, true );
			$k4             = hash_hmac( 'sha256', 'aws4_request', $k3, true );
			$sig            = hash_hmac( 'sha256', $sts, $k4 );
			$auth           = 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $datestamp . '/' . $region . '/s3/aws4_request, SignedHeaders=' . $signed_headers . ', Signature=' . $sig;

			$res = wp_remote_get(
				$this->base() . $uri . '?' . $query,
				array(
					'headers'   => array(
						'Authorization'         => $auth,
						'x-amz-date'           => $amzdate,
						'x-amz-content-sha256' => $payload_hash,
					),
					'timeout'   => 60,
					'sslverify' => jinyu_storage_ssl_verify(),
				)
			);
			if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
				break;
			}
			$xml = simplexml_load_string( wp_remote_retrieve_body( $res ) );
			if ( $xml === false ) {
				break;
			}
			foreach ( $xml->Contents as $c ) {
				if ( isset( $c->Key ) ) {
					$key = (string) $c->Key;
					// 跳过目录占位对象（以 / 结尾），否则「一键拉回」会尝试下载目录而失败
					if ( $key !== '' && substr( $key, -1 ) !== '/' ) {
						$keys[] = $key;
					}
				}
			}
			$token = isset( $xml->NextContinuationToken ) ? (string) $xml->NextContinuationToken : '';
			$pages++;
		} while ( $token !== '' && $pages < 50 );
		return $keys;
	}
}

/* ───────────────────────────────────────────────────────────
 * 又拍云适配器（REST Basic Auth）
 * ─────────────────────────────────────────────────────────── */
class Jinyu_Storage_Upyun implements Jinyu_Storage_Adapter {
	private $cfg;

	public function __construct( $cfg ) {
		$this->cfg = $cfg;
	}

	private function host() {
		$endpoint = trim( $this->cfg['endpoint'] ?? '' );
		// 又拍云 API 域名不带桶前缀（与 S3 机制不同），默认 v0.api.upyun.com。
		// 兼容误填 CDN 域（如 xxx.b0.upaiyun.com）：自动回退到 API 域，避免 "invisible domain"。
		if ( $endpoint === '' || ( strpos( $endpoint, 'upaiyun.com' ) !== false && strpos( $endpoint, 'api' ) === false ) ) {
			$endpoint = 'v0.api.upyun.com';
		}
		return preg_replace( '#^https?://#i', '', $endpoint );
	}

	private function base() {
		return 'https://' . $this->host() . '/' . trim( $this->cfg['bucket'] ?? '', '/' );
	}

	private function auth() {
		$operator = trim( $this->cfg['access_key'] ?? '' );
		$password = trim( $this->cfg['secret'] ?? '' );
		// 又拍云 REST：Basic base64(操作员:密码明文)，切勿再对密码取 md5，否则 401 user password error。
		return 'Basic ' . base64_encode( $operator . ':' . $password );
	}

	private function date_header() {
		return gmdate( 'D, d M Y H:i:s' ) . ' GMT';
	}

	private function guess_type( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
			'gif' => 'image/gif', 'webp' => 'image/webp',
			'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg', 'pdf' => 'application/pdf',
			'zip' => 'application/zip', 'txt' => 'text/plain', 'json' => 'application/json',
		);
		return $map[ $ext ] ?? 'application/octet-stream';
	}

	public function put( $local, $key ) {
		$body = @file_get_contents( $local );
		if ( $body === false ) {
			return false;
		}
		$url     = $this->base() . '/' . ltrim( $key, '/' );
		$headers = array(
			'Authorization'   => $this->auth(),
			'Date'           => $this->date_header(),
			'Content-Type'   => $this->guess_type( $local ),
			'Content-Length' => strlen( $body ),
		);
		$res     = wp_remote_request(
			$url,
			array(
				'method'    => 'PUT',
				'headers'   => $headers,
				'body'      => $body,
				'timeout'   => 120,
				'sslverify' => jinyu_storage_ssl_verify(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code >= 200 && $code < 300;
	}

	public function put_multi( $items, $concurrency = 8 ) {
		$n       = count( $items );
		$results = array_fill( 0, $n, false );
		$handles = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$local = $items[ $i ]['local'];
			$key   = $items[ $i ]['key'];
			$body  = @file_get_contents( $local );
			if ( $body === false ) {
				continue;
			}
			$url  = $this->base() . '/' . ltrim( $key, '/' );
			$ct   = $this->guess_type( $local );
			$headers = array(
				'Authorization: ' . $this->auth(),
				'Date: ' . $this->date_header(),
				'Content-Type: ' . $ct,
				'Content-Length: ' . strlen( $body ),
			);
			$ch   = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_CUSTOMREQUEST  => 'PUT',
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_SSL_VERIFYPEER => jinyu_storage_ssl_verify(),
					CURLOPT_SSL_VERIFYHOST => jinyu_storage_ssl_host(),
					CURLOPT_TIMEOUT        => 120,
					CURLOPT_CONNECTTIMEOUT => 15,
				)
			);
			$handles[] = array( 'ch' => $ch, 'index' => $i );
		}
		$ok = jinyu_storage_run_multi( $handles, $concurrency );
		foreach ( $ok as $idx => $v ) {
			$results[ $idx ] = $v;
		}
		return $results;
	}

	public function get( $key ) {
		$url = $this->base() . '/' . ltrim( $key, '/' );
		$res = wp_remote_get(
			$url,
			array(
				'headers'   => array(
					'Authorization' => $this->auth(),
					'Date'          => $this->date_header(),
				),
				'timeout'   => 120,
				'sslverify' => jinyu_storage_ssl_verify(),
			)
		);
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return false;
		}
		return wp_remote_retrieve_body( $res );
	}

	public function delete( $key ) {
		$url = $this->base() . '/' . ltrim( $key, '/' );
		$res = wp_remote_request(
			$url,
			array(
				'method'    => 'DELETE',
				'headers'   => array(
					'Authorization' => $this->auth(),
					'Date'          => $this->date_header(),
				),
				'timeout'   => 60,
				'sslverify' => jinyu_storage_ssl_verify(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code === 200 || $code === 204 || $code === 404;
	}

	public function test( $prefix = '' ) {
		$tmp = function_exists( 'wp_tempnam' )
			? wp_tempnam( 'jinyu-probe' )
			: tempnam( sys_get_temp_dir(), 'jinyu-probe' );
		if ( ! $tmp ) {
			return '无法创建临时文件';
		}
		file_put_contents( $tmp, 'jinyu-probe-' . time() );
		$key = rtrim( $prefix, '/' ) . '/.jinyu-probe-' . uniqid() . '.txt';
		$ok  = $this->put( $tmp, $key );
		@unlink( $tmp );
		if ( ! $ok ) {
			return '上传测试文件失败（检查 服务名 / 操作员 / 密码 / 权限）';
		}
		$back = $this->get( $key );
		$this->delete( $key );
		return ( $back !== false ) ? true : '上传成功但回读验证失败';
	}

	public function list_keys( $prefix = '' ) {
		return $this->list_dir( rtrim( (string) $prefix, '/' ), 0 );
	}

	/**
	 * 递归列举目录。
	 * 又拍云 LIST 的条目 type 为 MIME（文件）或 'folder'（目录），
	 * 必须跳过目录项并深入子目录，否则「一键拉回」会拿目录当文件下载而全部失败。
	 */
	private function list_dir( $dir, $depth ) {
		$keys = array();
		if ( $depth > 8 ) {
			return $keys;
		}
		$dir   = trim( (string) $dir, '/' );
		$iter  = '';
		$pages = 0;
		do {
			$url     = $this->base() . '/' . ( $dir !== '' ? $dir . '/' : '' );
			$headers = array(
				'Authorization' => $this->auth(),
				'Date'          => $this->date_header(),
				'Accept'        => 'application/json',
				'x-list-limit'  => '100',
			);
			if ( $iter !== '' ) {
				$headers['x-list-iter'] = $iter;
			}
			$res = wp_remote_get(
				$url,
				array(
					'headers'   => $headers,
					'timeout'   => 60,
					'sslverify' => jinyu_storage_ssl_verify(),
				)
			);
			if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
				break;
			}
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $data ) ) {
				break;
			}
			foreach ( (array) ( $data['files'] ?? array() ) as $f ) {
				$name = (string) ( $f['name'] ?? '' );
				if ( $name === '' ) {
					continue;
				}
				$full = ( $dir !== '' ? $dir . '/' : '' ) . $name;
				if ( ( $f['type'] ?? '' ) === 'folder' ) {
					$keys = array_merge( $keys, $this->list_dir( $full, $depth + 1 ) );
				} else {
					$keys[] = $full;
				}
			}
			$iter = (string) ( $data['iter'] ?? '' );
			$pages++;
		} while ( $iter !== '' && $pages < 200 );
		return $keys;
	}
}

/* ───────────────────────────────────────────────────────────
 * 一次性迁移：主题 JINYU_OPT → 本插件独立选项 jinyu_companion_settings
 * 插件加载期主题函数尚不存在（WP 先加载插件后加载主题），故挂 after_setup_theme 执行。
 * 迁移后存储配置完全归本插件所有，主题设置页的导入 / 重置不再影响对象存储。
 * ─────────────────────────────────────────────────────────── */
if ( ! function_exists( 'jinyu_storage_maybe_migrate' ) ) {
	function jinyu_storage_maybe_migrate(): void {
		if ( get_option( 'jinyu_companion_storage_migrated' ) ) {
			return;
		}
		$s       = jinyu_companion_get_settings();
		$changed = false;
		if ( function_exists( 'jinyu_get_option' ) ) {
			$keys = array( 'storage_provider', 'storage_domain', 'storage_prefix', 'storage_bucket', 'storage_region', 'storage_endpoint', 'storage_access_key', 'storage_auto_upload', 'storage_delete_local' );
			foreach ( $keys as $k ) {
				if ( empty( $s[ $k ] ) && '' !== (string) jinyu_get_option( $k, '' ) ) {
					$s[ $k ] = (string) jinyu_get_option( $k, '' );
					$changed = true;
				}
			}
			// Secret：主题侧为 jinyu_encrypt 密文；本插件解密函数与主题同源（同 wp_salt('auth') 派生、
			// 同 jinyu_enc2:: 前缀），可透明解出明文，再按本插件密文重新入库。
			if ( empty( $s['storage_secret'] ) ) {
				$enc = (string) jinyu_get_option( 'storage_secret', '' );
				if ( '' !== $enc ) {
					$plain = jinyu_companion_decrypt( $enc );
					if ( '' !== $plain ) {
						$s['storage_secret'] = jinyu_companion_encrypt( $plain );
						$changed             = true;
					}
				}
			}
		}
		if ( $changed ) {
			jinyu_companion_save_settings( $s );
		}
		update_option( 'jinyu_companion_storage_migrated', 1 );
	}
	// 不挂 after_setup_theme：本文件由主文件在 after_setup_theme 回调内 require（主题函数已就绪），
	// 此刻再挂同名钩子不会在本请求内执行（迭代器已过同优先级）。直接调用，幂等（内部有标记短路）。
	jinyu_storage_maybe_migrate();
}

/* ───────────────────────────────────────────────────────────
 * 配置读取
 * ─────────────────────────────────────────────────────────── */
function jinyu_storage_config( $input = null ) {
	$map = array(
		'provider'   => 'storage_provider',
		'bucket'     => 'storage_bucket',
		'region'     => 'storage_region',
		'endpoint'   => 'storage_endpoint',
		'access_key' => 'storage_access_key',
		'secret'     => 'storage_secret',
		'domain'     => 'storage_domain',
		'prefix'     => 'storage_prefix',
	);
	if ( is_array( $input ) ) {
		$cfg = array();
		foreach ( $map as $k => $opt ) {
			$val = '';
			if ( isset( $input[ $k ] ) ) {
				$val = $input[ $k ];
			} elseif ( isset( $input[ $opt ] ) ) {
				$val = $input[ $opt ];
			}
			$cfg[ $k ] = trim( (string) $val );
		}
		// 密码类字段若表单留空，表示沿用已保存值
		foreach ( array( 'access_key', 'secret' ) as $k ) {
			if ( $cfg[ $k ] === '' ) {
				$cfg[ $k ] = jinyu_companion_get_option( 'storage_' . $k, '' );
			}
		}
	} else {
		$cfg = array();
		foreach ( $map as $k => $opt ) {
			$cfg[ $k ] = jinyu_companion_get_option( $opt, '' );
		}
	}
	// 密钥为加密入库：读取时解密还原明文以供签名（非密文原样返回，兼容表单明文输入）
	$cfg['secret'] = jinyu_companion_decrypt( $cfg['secret'] );
	return $cfg;
}

function jinyu_is_storage_enabled() {
	$cfg = jinyu_storage_config();
	return $cfg['provider'] !== '' && $cfg['bucket'] !== '' && $cfg['access_key'] !== '' && $cfg['secret'] !== '';
}

/**
 * 配置完整性校验：S3 兼容模式下 endpoint / region 缺失会导致 host 拼成
 * 「bucket.」、签名区域为空，报错极难排查，故提前拦截并给出明确提示。
 *
 * @return string|true true 表示通过，否则返回错误文案。
 */
function jinyu_storage_validate_cfg( $cfg ) {
	if ( ( $cfg['provider'] ?? '' ) === 'upyun' ) {
		return true;
	}
	$missing = array();
	if ( trim( (string) ( $cfg['endpoint'] ?? '' ) ) === '' ) {
		$missing[] = 'Endpoint';
	}
	if ( trim( (string) ( $cfg['region'] ?? '' ) ) === '' ) {
		$missing[] = 'Region';
	}
	if ( $missing ) {
		return sprintf(
			/* translators: %s: 字段清单 */
			__( 'S3 兼容模式必须填写：%s（例如阿里云 OSS 填 oss-cn-hangzhou.aliyuncs.com，Region 填 oss-cn-hangzhou）', 'jinyu-theme-companion' ),
			implode( '、', $missing )
		);
	}
	return true;
}

/* ───────────────────────────────────────────────────────────
 * 加速域名 URL 重写（仅前台；优先级 5 先于主题 cdn_url）
 * ─────────────────────────────────────────────────────────── */
add_filter(
	'wp_get_attachment_url',
	function ( $url ) {
		if ( is_admin() ) {
			return $url;
		}
		$cfg = jinyu_storage_config();
		// 加速域名填写即生效：未填则不重写（附件仍走本地 uploads）；已填即视为启用，无需额外开关。
		if ( empty( $cfg['provider'] ) || empty( $cfg['domain'] ) ) {
			return $url;
		}
		$base = wp_upload_dir()['baseurl'];
		if ( $base && strpos( $url, $base ) === 0 ) {
			// 与推送映射保持一致：远端 key = 前缀 + uploads 相对路径。
			// URL 也带上前缀，确保「加速域名(绑桶根) + 前缀 + 相对路径」与桶内文件一一对应，避免 404。
			$rel    = ltrim( substr( $url, strlen( $base ) ), '/' );
			$prefix = trim( (string) $cfg['prefix'], '/' );
			$path   = ( $prefix !== '' ? $prefix . '/' : '' ) . $rel;
			return rtrim( $cfg['domain'], '/' ) . '/' . $path;
		}
		return $url;
	},
	5
);

/* ───────────────────────────────────────────────────────────
 * 新附件自动同步到存储
 * ─────────────────────────────────────────────────────────── */
add_action(
	'add_attachment',
	function ( $post_id ) {
		if ( ! jinyu_companion_is_checked( 'storage_auto_upload' ) ) {
			return;
		}
		if ( ! jinyu_is_storage_enabled() ) {
			return;
		}
		$cfg = jinyu_storage_config();
		$ad  = Jinyu_Storage_Factory::make( $cfg );
		if ( ! $ad ) {
			return;
		}
		$prefix = rtrim( $cfg['prefix'], '/' ) . '/';
		$file   = get_attached_file( $post_id );
		$files  = array();
		if ( $file ) {
			$files[] = $file;
		}
		$meta = wp_get_attachment_metadata( $post_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && $file ) {
			$dir = dirname( $file );
			foreach ( $meta['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$files[] = $dir . '/' . $s['file'];
				}
			}
		}
		$basedir = wp_upload_dir()['basedir'];
		$items   = array();
		foreach ( array_unique( $files ) as $f ) {
			if ( is_file( $f ) ) {
				$rel = wp_normalize_path( ltrim( str_replace( $basedir, '', $f ), '/' ) );
				$items[] = array( 'local' => $f, 'key' => $prefix . $rel );
			}
		}
		// curl_multi 并发上传（8 路），多尺寸图片不再逐个串行等待。
		if ( $items ) {
			$ad->put_multi( $items, 8 );
		}
	}
);

/* 删除媒体时同步删除云端对象：delete_attachment 在本地文件删除前触发，
 * 此时可完整取到主文件与全部尺寸的相对路径，远端 key 与推送映射一致。 */
add_action(
	'delete_attachment',
	function ( $post_id ) {
		if ( ! jinyu_is_storage_enabled() ) {
			return;
		}
		$cfg = jinyu_storage_config();
		$ad  = Jinyu_Storage_Factory::make( $cfg );
		if ( ! $ad ) {
			return;
		}
		$prefix  = rtrim( $cfg['prefix'], '/' ) . '/';
		$basedir = wp_upload_dir()['basedir'];
		$file    = get_attached_file( $post_id );
		$files   = array();
		if ( $file ) {
			$files[] = $file;
		}
		$meta = wp_get_attachment_metadata( $post_id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && $file ) {
			$dir = dirname( $file );
			foreach ( $meta['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$files[] = $dir . '/' . $s['file'];
				}
			}
		}
		foreach ( array_unique( $files ) as $f ) {
			$rel = wp_normalize_path( ltrim( str_replace( $basedir, '', $f ), '/' ) );
			if ( $rel !== '' ) {
				$ad->delete( $prefix . $rel );
			}
		}
	}
);

/* ───────────────────────────────────────────────────────────
 * 任务表（推送/拉回进度落 DB，避免 Memcached 下 transient 不可靠）
 * ─────────────────────────────────────────────────────────── */
function jinyu_storage_table() {
	global $wpdb;
	return $wpdb->prefix . 'jinyu_storage_tasks';
}

function jinyu_storage_install_table() {
	static $exists = null;
	if ( $exists ) {
		return; // 本请求内已确认表存在，跳过昂贵的 dbDelta（批处理每 2.5s 调一次）
	}
	global $wpdb;
	$table   = jinyu_storage_table();
	// 先廉价探测：表已存在则本请求内不再触发 dbDelta（全表结构比对 + 潜在 ALTER）
	if ( null === $exists ) {
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists ) {
			return;
		}
	}
	$charset = $wpdb->get_charset_collate();
	$sql     = "CREATE TABLE IF NOT EXISTS $table (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		type VARCHAR(16) NOT NULL DEFAULT 'push',
		status VARCHAR(16) NOT NULL DEFAULT 'pending',
		data LONGTEXT NULL,
		done INT UNSIGNED NOT NULL DEFAULT 0,
		total INT UNSIGNED NOT NULL DEFAULT 0,
		message TEXT NULL,
		created_at DATETIME NULL,
		updated_at DATETIME NULL,
		PRIMARY KEY (id),
		KEY type_status (type, status)
	) $charset;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	$exists = true;
}

add_action( 'after_switch_theme', 'jinyu_storage_install_table' );

function jinyu_storage_scan_uploads() {
	$basedir = wp_upload_dir()['basedir'];
	$list    = array();
	if ( ! is_dir( $basedir ) ) {
		return $list;
	}
	$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $rii as $f ) {
		if ( $f->isDir() ) {
			continue;
		}
		$rel    = wp_normalize_path( ltrim( str_replace( $basedir, '', $f->getPathname() ), '/' ) );
		$list[] = $rel;
	}
		return $list;
	}

/* ───────────────────────────────────────────────────────────
 * 服务端批处理核心（AJAX 与 CLI 自愈共用）
 * 处理一个批次并推进 done；无活跃任务返回 null，出错返回 false。
 * 读已保存的主题配置，故可在无浏览器（cron/CLI）上下文独立运行。
 * ─────────────────────────────────────────────────────────── */
function jinyu_storage_process_one( $type ) {
	global $wpdb;
	$table = jinyu_storage_table();
	jinyu_storage_install_table();

	$cfg = jinyu_storage_config();
	if ( empty( $cfg['provider'] ) || empty( $cfg['bucket'] ) || empty( $cfg['access_key'] ) || empty( $cfg['secret'] ) ) {
		return null;
	}
	$ad = Jinyu_Storage_Factory::make( $cfg );
	if ( ! $ad ) {
		return null;
	}

	$task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE type=%s AND status IN ('running','pending') ORDER BY id DESC LIMIT 1", $type ) );
	if ( ! $task ) {
		return null;
	}

	$data  = json_decode( $task->data, true );
	if ( ! is_array( $data ) ) {
		$data = array();
	}
	$total = (int) $task->total;
	$done  = (int) $task->done;
	$batch = 50;
	$end   = min( $done + $batch, $total );
	$basedir = wp_upload_dir()['basedir'];
	$prefix  = rtrim( $cfg['prefix'], '/' ) . '/';
	$errors  = 0;

	if ( $type === 'push' ) {
		$items = array();
		for ( $i = $done; $i < $end; $i++ ) {
			$rel   = $data[ $i ];
			$local = $basedir . '/' . $rel;
			if ( is_file( $local ) ) {
				$items[] = array( 'local' => $local, 'key' => $prefix . $rel );
			}
		}
		// 批内并行上传（并发上限 16），与 AJAX 处理器一致
		$results = $ad->put_multi( $items, 16 );
		foreach ( $items as $k => $it ) {
			if ( empty( $results[ $k ] ) ) {
				$errors++;
			} elseif ( jinyu_companion_is_checked( 'storage_delete_local' ) ) {
				@unlink( $it['local'] );
			}
		}
		// 整批推进：不存在的本地文件视为已跳过，仍计入 done（与 AJAX 处理器 $done=$end 一致）
		$done = $end;
	} else { // pull
		for ( $i = $done; $i < $end; $i++ ) {
			$key = $data[ $i ];
			$rel = ltrim( (string) substr( $key, strlen( $prefix ) ), '/' );
			if ( $rel === '' ) {
				$done++;
				continue;
			}
			$local = $basedir . '/' . $rel;
			wp_mkdir_p( dirname( $local ) );
			$body = $ad->get( $key );
			if ( $body !== false && $body !== '' ) {
				if ( @file_put_contents( $local, $body ) === false ) {
					$errors++;
				}
			} else {
				$errors++;
			}
			$done++;
		}
	}

	$status = ( $done >= $total ) ? 'done' : 'running';
	$msg    = ( $errors > 0 )
		? sprintf( __( '已处理 %1$d/%2$d，%3$d 个失败', 'jinyu-theme-companion' ), $done, $total, $errors )
		: sprintf( __( '已处理 %1$d/%2$d', 'jinyu-theme-companion' ), $done, $total );
	$wpdb->update(
		$table,
		array( 'done' => $done, 'status' => $status, 'message' => $msg, 'updated_at' => current_time( 'mysql' ) ),
		array( 'id' => $task->id )
	);
	return array( 'done' => $done, 'total' => $total, 'errors' => $errors, 'status' => $status, 'message' => $msg );
}

/* ───────────────────────────────────────────────────────────
 * AJAX：测试连接
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_test',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		$cfg = jinyu_storage_config( $_POST );
		if ( empty( $cfg['provider'] ) || empty( $cfg['bucket'] ) || empty( $cfg['access_key'] ) || empty( $cfg['secret'] ) ) {
			wp_send_json_error( __( '请填写完整的存储配置（服务商 / 桶 / AccessKey / Secret）', 'jinyu-theme-companion' ) );
		}
		$validate = jinyu_storage_validate_cfg( $cfg );
		if ( $validate !== true ) {
			wp_send_json_error( $validate );
		}
		$ad = Jinyu_Storage_Factory::make( $cfg );
		if ( ! $ad ) {
			wp_send_json_error( __( '不支持的存储服务商', 'jinyu-theme-companion' ) );
		}
		$result = $ad->test( rtrim( $cfg['prefix'], '/' ) . '/' );
		if ( $result === true ) {
			wp_send_json_success( __( '连接成功：已上传并删除测试文件', 'jinyu-theme-companion' ) );
		}
		wp_send_json_error( __( '连接失败：', 'jinyu-theme-companion' ) . $result );
	}
);

/* ───────────────────────────────────────────────────────────
 * AJAX：一键推送（分批处理，进度落 DB）
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_push',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		jinyu_storage_install_table();
		global $wpdb;
		$table = jinyu_storage_table();
		$cfg   = jinyu_storage_config( $_POST );
		if ( empty( $cfg['provider'] ) || empty( $cfg['bucket'] ) || empty( $cfg['access_key'] ) || empty( $cfg['secret'] ) ) {
			wp_send_json_error( __( '请先填写并保存存储配置', 'jinyu-theme-companion' ) );
		}
		$validate = jinyu_storage_validate_cfg( $cfg );
		if ( $validate !== true ) {
			wp_send_json_error( $validate );
		}
		$ad = Jinyu_Storage_Factory::make( $cfg );
		if ( ! $ad ) {
			wp_send_json_error( __( '不支持的存储服务商', 'jinyu-theme-companion' ) );
		}

		// 含 stopped：停止后再点同一按钮=从上次进度续传（不重新扫描）；重新全量扫描仅在上一任务跑完后才会发生。
		$task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE type=%s AND status IN ('running','pending','stopped') ORDER BY id DESC LIMIT 1", 'push' ) );
		if ( $task && $task->status === 'stopped' ) {
			// 复活为 running，后续批次回写（WHERE status='running'）才能生效
			$wpdb->update( $table, array( 'status' => 'running', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $task->id ) );
			$task->status = 'running';
		}
		if ( ! $task ) {
			$list  = jinyu_storage_scan_uploads();
			$wpdb->insert(
				$table,
				array(
					'type'       => 'push',
					'status'     => 'running',
					'data'       => wp_json_encode( $list ),
					'total'      => count( $list ),
					'done'       => 0,
					'message'    => '',
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				)
			);
			$task = (object) array(
				'id'   => $wpdb->insert_id,
				'data' => wp_json_encode( $list ),
				'total' => count( $list ),
				'done' => 0,
				'status' => 'running',
			);
		}

		$data  = json_decode( $task->data, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$total = (int) $task->total;
		$done  = (int) $task->done;
		$batch = 50;
		$end   = min( $done + $batch, $total );
		$basedir = wp_upload_dir()['basedir'];
		$prefix  = rtrim( $cfg['prefix'], '/' ) . '/';
		$items   = array();
		for ( $i = $done; $i < $end; $i++ ) {
			$rel   = $data[ $i ];
			$local = $basedir . '/' . $rel;
			if ( is_file( $local ) ) {
				$items[] = array( 'local' => $local, 'key' => $prefix . $rel );
			}
		}
		// 批内并行上传（并发上限 16，curl_multi），显著缩短大批量推送耗时
		$concurrency = 16;
		$results     = $ad->put_multi( $items, $concurrency );
		$errors      = 0;
		foreach ( $items as $k => $it ) {
			if ( empty( $results[ $k ] ) ) {
				$errors++;
			} elseif ( jinyu_companion_is_checked( 'storage_delete_local' ) ) {
				@unlink( $it['local'] );
			}
		}
		$done   = $end;
		$status = ( $done >= $total ) ? 'done' : 'running';
		$msg    = ( $errors > 0 )
			? sprintf( __( '已处理 %1$d/%2$d，%3$d 个失败', 'jinyu-theme-companion' ), $done, $total, $errors )
			: sprintf( __( '已处理 %1$d/%2$d', 'jinyu-theme-companion' ), $done, $total );
		// 仅当任务仍是 running 才回写：用户点「停止」后，在途批次不得把状态改回 running（否则下次进入会误续跑）。
		$wpdb->update(
			$table,
			array( 'done' => $done, 'status' => $status, 'message' => $msg, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $task->id, 'status' => 'running' )
		);
		wp_send_json_success( array( 'done' => $done, 'total' => $total, 'errors' => $errors, 'status' => $status, 'message' => $msg ) );
	}
);

/* ───────────────────────────────────────────────────────────
 * AJAX：一键拉回（先列出远端，再分批下载）
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_pull',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		jinyu_storage_install_table();
		global $wpdb;
		$table = jinyu_storage_table();
		$cfg   = jinyu_storage_config( $_POST );
		if ( empty( $cfg['provider'] ) || empty( $cfg['bucket'] ) || empty( $cfg['access_key'] ) || empty( $cfg['secret'] ) ) {
			wp_send_json_error( __( '请先填写并保存存储配置', 'jinyu-theme-companion' ) );
		}
		$ad = Jinyu_Storage_Factory::make( $cfg );
		if ( ! $ad ) {
			wp_send_json_error( __( '不支持的存储服务商', 'jinyu-theme-companion' ) );
		}

		// 含 stopped：停止后再点同一按钮=从上次进度续传。
		$task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE type=%s AND status IN ('running','pending','stopped') ORDER BY id DESC LIMIT 1", 'pull' ) );
		if ( $task && $task->status === 'stopped' ) {
			$wpdb->update( $table, array( 'status' => 'running', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $task->id ) );
			$task->status = 'running';
		}
		if ( ! $task ) {
			$prefix = rtrim( $cfg['prefix'], '/' ) . '/';
			$keys   = $ad->list_keys( $prefix );
			$wpdb->insert(
				$table,
				array(
					'type'       => 'pull',
					'status'     => 'running',
					'data'       => wp_json_encode( $keys ),
					'total'      => count( $keys ),
					'done'       => 0,
					'message'    => '',
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				)
			);
			$task = (object) array(
				'id'   => $wpdb->insert_id,
				'data' => wp_json_encode( $keys ),
				'total' => count( $keys ),
				'done' => 0,
				'status' => 'running',
			);
		}

		$data  = json_decode( $task->data, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$total   = (int) $task->total;
		$done    = (int) $task->done;
		$batch   = 30;
		$end     = min( $done + $batch, $total );
		$basedir = wp_upload_dir()['basedir'];
		$prefix  = rtrim( $cfg['prefix'], '/' ) . '/';
		$errors  = 0;
		for ( $i = $done; $i < $end; $i++ ) {
			$key = $data[ $i ];
			$rel = ltrim( (string) substr( $key, strlen( $prefix ) ), '/' );
			if ( $rel === '' ) {
				$done++;
				continue;
			}
			$local = $basedir . '/' . $rel;
			wp_mkdir_p( dirname( $local ) );
			$body = $ad->get( $key );
			if ( $body !== false && $body !== '' ) {
				if ( @file_put_contents( $local, $body ) === false ) {
					$errors++;
				}
			} else {
				$errors++;
			}
			$done++;
		}
		$status = ( $done >= $total ) ? 'done' : 'running';
		$msg    = ( $errors > 0 )
			? sprintf( __( '已拉回 %1$d/%2$d，%3$d 个失败', 'jinyu-theme-companion' ), $done, $total, $errors )
			: sprintf( __( '已拉回 %1$d/%2$d', 'jinyu-theme-companion' ), $done, $total );
		// 仅当任务仍是 running 才回写：停止后在途批次不得复活任务。
		$wpdb->update(
			$table,
			array( 'done' => $done, 'status' => $status, 'message' => $msg, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $task->id, 'status' => 'running' )
		);
		wp_send_json_success( array( 'done' => $done, 'total' => $total, 'errors' => $errors, 'status' => $status, 'message' => $msg ) );
	}
);

/* ───────────────────────────────────────────────────────────
 * AJAX：任务进度查询
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_status',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		global $wpdb;
		$table = jinyu_storage_table();
		$task  = $wpdb->get_row( "SELECT * FROM $table WHERE type IN ('push','pull','sync') AND status IN ('running','pending') ORDER BY id DESC LIMIT 1" );
		if ( ! $task ) {
			wp_send_json_success( array( 'active' => false ) );
		}
		wp_send_json_success(
			array(
				'active'  => true,
				'type'    => $task->type,
				'done'    => (int) $task->done,
				'total'   => (int) $task->total,
				'status'  => $task->status,
				'message' => $task->message,
			)
		);
	}
);

/* ───────────────────────────────────────────────────────────
 * AJAX：停止批量任务（push/pull/sync 标记 stopped）
 * 在途批次回写带 status='running' 条件，不会复活已停止的任务。
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_stop',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		jinyu_storage_install_table();
		global $wpdb;
		$table = jinyu_storage_table();
		$n     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = 'stopped', message = %s, updated_at = %s WHERE type IN ('push','pull','sync') AND status IN ('running','pending')",
				__( '已手动停止（进度保留，可重新开始）', 'jinyu-theme-companion' ),
				current_time( 'mysql' )
			)
		);
		wp_send_json_success( array( 'stopped' => (int) $n ) );
	}
);

/* ───────────────────────────────────────────────────────────
 * AJAX：同步指定资源（一个或多个，textarea 每行一个路径或本站 URL）
 * 大列表落库为 sync 任务，每批 50 个、并发 16，前端自动续批；
 * 中途关闭浏览器进度不丢，再次进入由 status 检测并带 sync_continue=1 续跑。
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_sync_selected',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		jinyu_storage_install_table();
		global $wpdb;
		$table = jinyu_storage_table();
		$cfg   = jinyu_storage_config( $_POST );
		if ( empty( $cfg['provider'] ) || empty( $cfg['bucket'] ) || empty( $cfg['access_key'] ) || empty( $cfg['secret'] ) ) {
			wp_send_json_error( __( '请先填写并保存存储配置', 'jinyu-theme-companion' ) );
		}
		$ad = Jinyu_Storage_Factory::make( $cfg );
		if ( ! $ad ) {
			wp_send_json_error( __( '不支持的存储服务商', 'jinyu-theme-companion' ) );
		}

		$continue = ! empty( $_POST['sync_continue'] );
		if ( $continue ) {
			// 续跑模式：接续最近一个未完成的 sync 任务
			$task = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE type=%s AND status IN ('running','pending') ORDER BY id DESC LIMIT 1", 'sync' ) );
			if ( ! $task ) {
				wp_send_json_success( array( 'done' => 0, 'total' => 0, 'errors' => 0, 'status' => 'done', 'message' => __( '没有进行中的同步任务', 'jinyu-theme-companion' ) ) );
			}
			$items = json_decode( (string) $task->data, true );
			if ( ! is_array( $items ) ) {
				$items = array();
			}
		} else {
			// 已有未完成的 sync 任务时不重复建任务，避免并发双跑
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE type=%s AND status IN ('running','pending') LIMIT 1", 'sync' ) );
			if ( $exists ) {
				wp_send_json_error( __( '已有同步任务进行中，请等待其完成或先点「停止任务」', 'jinyu-theme-companion' ) );
			}
			$raw   = isset( $_POST['sync_paths'] ) ? (string) wp_unslash( $_POST['sync_paths'] ) : '';
			$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) ) );
			if ( empty( $lines ) ) {
				wp_send_json_error( __( '请先在文本框里填写要同步的文件路径（每行一个）', 'jinyu-theme-companion' ) );
			}
			$upload  = wp_upload_dir();
			$basedir = $upload['basedir'];
			$baseurl = $upload['baseurl'];
			$prefix  = rtrim( $cfg['prefix'], '/' ) . '/';
			$basedir_real = realpath( $basedir );
			$items = array();
			$bad   = array();
			foreach ( $lines as $rel ) {
				$rel = str_replace( '\\', '/', $rel );
				// 支持粘贴本站完整附件 URL：剥出 uploads 相对路径；外站 URL 直接拒收。
				if ( stripos( $rel, 'http://' ) === 0 || stripos( $rel, 'https://' ) === 0 ) {
					if ( $baseurl && strpos( $rel, $baseurl ) === 0 ) {
						$rel = ltrim( substr( $rel, strlen( $baseurl ) ), '/' );
					} else {
						$bad[] = $rel;
						continue;
					}
				}
				$rel = ltrim( $rel, '/' );
				if ( $rel === '' ) {
					continue;
				}
				$local = $basedir . '/' . $rel;
				// 防目录穿越：真实路径必须仍在 uploads 目录内。
				$real = realpath( $local );
				if ( ! $basedir_real || ! $real || strpos( $real, $basedir_real ) !== 0 || ! is_file( $real ) ) {
					$bad[] = $rel;
					continue;
				}
				$items[] = array( 'local' => $real, 'key' => $prefix . $rel );
			}
			if ( empty( $items ) ) {
				wp_send_json_error( __( '没有找到有效文件：', 'jinyu-theme-companion' ) . implode( '、', array_slice( $bad, 0, 5 ) ) );
			}
			$wpdb->insert(
				$table,
				array(
					'type'       => 'sync',
					'status'     => 'running',
					'data'       => wp_json_encode( $items ),
					'total'      => count( $items ),
					'done'       => 0,
					'message'    => '',
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				)
			);
			$task = (object) array( 'id' => $wpdb->insert_id, 'total' => count( $items ), 'done' => 0, 'status' => 'running' );
		}

		$total = (int) $task->total;
		$done  = (int) $task->done;
		$end   = min( $done + 50, $total );
		$batch = array_slice( $items, $done, $end - $done );
		// 并发 16（curl_multi），批量同步明显提速
		$results = $ad->put_multi( $batch, 16 );
		$errors  = 0;
		$failed  = array();
		foreach ( $batch as $k => $it ) {
			if ( ! empty( $results[ $k ] ) ) {
				if ( jinyu_companion_is_checked( 'storage_delete_local' ) ) {
					@unlink( $it['local'] );
				}
			} else {
				$errors++;
				$failed[] = basename( $it['key'] );
			}
		}
		$done   = $end;
		$status = ( $done >= $total ) ? 'done' : 'running';
		$msg    = sprintf( __( '已同步 %1$d/%2$d', 'jinyu-theme-companion' ), $done, $total );
		if ( $errors > 0 ) {
			$msg .= '，' . sprintf( __( '本批失败 %1$d 个：%2$s', 'jinyu-theme-companion' ), $errors, implode( '、', array_slice( $failed, 0, 5 ) ) );
		} elseif ( $status === 'done' ) {
			$msg = sprintf( __( '已同步 %d 个文件到云端', 'jinyu-theme-companion' ), $done );
		}
		// 仅当任务仍是 running 才回写：停止后在途批次不得复活任务。
		$wpdb->update(
			$table,
			array( 'done' => $done, 'status' => $status, 'message' => $msg, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $task->id, 'status' => 'running' )
		);
		wp_send_json_success( array( 'done' => $done, 'total' => $total, 'errors' => $errors, 'status' => $status, 'message' => $msg ) );
	}
);

/* ───────────────────────────────────────────────────────────
 * AJAX：一键改加速域名（保存 storage_domain + 清缓存）
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_apply_domain',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		$domain = isset( $_POST['storage_domain'] ) ? trim( (string) $_POST['storage_domain'] ) : '';
		if ( $domain !== '' && ! preg_match( '#^https?://#i', $domain ) ) {
			$domain = 'https://' . $domain;
		}
		$s                     = jinyu_companion_get_settings();
		$s['storage_domain']   = $domain;
		jinyu_companion_save_settings( $s );
		if ( function_exists( 'jinyu_cache_flush' ) ) {
			jinyu_cache_flush();
		}
		wp_send_json_success( __( '加速域名已更新并刷新缓存，附件链接已切换', 'jinyu-theme-companion' ) );
	}
);

/* ───────────────────────────────────────────────────────────
 * AJAX：停用加速域名（回退本地 uploads + 清缓存）——图片异常时一键止血
 * ─────────────────────────────────────────────────────────── */
add_action(
	'wp_ajax_jinyu_storage_unapply_domain',
	function () {
		check_ajax_referer( 'jinyu_companion_settings', 'jinyu_companion_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( '权限不足', 'jinyu-theme-companion' ) );
		}
		$s                     = jinyu_companion_get_settings();
		$s['storage_domain']   = '';
		jinyu_companion_save_settings( $s );
		if ( function_exists( 'jinyu_cache_flush' ) ) {
			jinyu_cache_flush();
		}
		wp_send_json_success( __( '已停用加速域名，附件链接回退本地并刷新缓存', 'jinyu-theme-companion' ) );
	}
);
