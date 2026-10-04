<?php
/**
 * Jinyu Memcached Object Cache drop-in (top-tier build).
 *
 * 由「金玉主题配套插件」性能中心在检测到 PECL Memcached 扩展 + 运行中守护进程时
 * 一键部署到 wp-content/object-cache.php。删除本文件即可恢复 WordPress 默认
 * 数据库对象缓存，无任何残留。
 *
 * JINYU_DROPIN_MARKER:jinyu-memcached-object-cache
 *
 * 能力（覆盖并超越常见第三方 drop-in）：
 *   - 请求内本地缓存（避免同一请求重复打 Memcached）
 *   - 批量读写（get_multiple / add_multiple / set_multiple / delete_multiple）
 *   - CAS 乐观锁（get_with_cas / cas，高并发计数防竞争）
 *   - 一致性哈希（OPT_LIBKETAMA_COMPATIBLE，多客户端分布一致）
 *   - 缓存加法挂起（兼容 wp_suspend_cache_addition）
 *   - Memcached 扩展缺失时优雅降级（回退核心缓存，绝不白屏）
 *   - 统计接口（getStats）+ 命中/未命中计数
 *   - 持久连接池 + 多服务器/键盐可配 + 多站点前缀隔离
 *   - 代际式清空：wp_cache_flush() 只让本站缓存整体失效，不做服务器级 flush，
 *     不会殃及同一台 Memcached 上的其它站点 / 应用
 *
 * 可选常量（在 wp-config.php 中定义以覆盖默认配置）：
 *   JINYU_MEMCACHED_SERVERS  => [ ['127.0.0.1', 11211] ]   服务器列表
 *   JINYU_MEMCACHED_POOL     => 'jinyu-object-cache'        持久连接池名
 *   JINYU_MEMCACHED_KEY_SALT => '自定义盐'                  多站/共享实例防键冲突
 *
 * @package Jinyu_Theme_Companion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 优雅降级：若 Memcached 扩展被禁用/卸载，什么都不做直接返回，
// 由 WordPress 自己加载 wp-includes/cache.php 走默认缓存（多数第三方 drop-in 在此场景下会 fatal）。
//
// 注意：这里绝不能 require cache.php —— 那会让 load.php 紧接着看到的 function_exists('wp_cache_init')
// 变成 true，从而把 wp_using_ext_object_cache() 置为 true。后果是整站「声称在用外部对象缓存」，
// 实际用的是核心 WP_Object_Cache，缓存类插件会据此显示错误状态、wp_cache_flush_runtime() 等
// 语义也会跟着错。什么都不做，才是真正的回退。
if ( ! class_exists( 'Memcached', false ) ) {
	return;
}

class Jinyu_Memcached_Object_Cache {

	/** @var Memcached */
	private $mc;

	/** 请求内本地缓存：避免同一请求对相同 key 反复打 Memcached */
	private $cache = [];

	private $global_prefix;
	private $blog_prefix;
	private $key_salt = '';

	/** 本安装的缓存代际：wp_cache_flush() 时推进，使全部旧键一次性失效 */
	private $flush_gen = '';

	protected $global_groups         = [];
	protected $non_persistent_groups = [];

	public $cache_hits   = 0;
	public $cache_misses = 0;

	public function __construct() {
		$servers = defined( 'JINYU_MEMCACHED_SERVERS' )
			? JINYU_MEMCACHED_SERVERS
			: [ [ '127.0.0.1', 11211 ] ];

		$pool = defined( 'JINYU_MEMCACHED_POOL' ) ? JINYU_MEMCACHED_POOL : 'jinyu-object-cache';

		// 命名持久连接池：跨请求复用连接，降低握手开销。
		$this->mc = new Memcached( (string) $pool );
		if ( ! $this->mc->getServerList() ) {
			$this->mc->setOption( Memcached::OPT_LIBKETAMA_COMPATIBLE, true );   // 分布一致，扩容不雪崩
			$this->mc->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );        // 连接超时 1s
			$this->mc->setOption( Memcached::OPT_RETRY_TIMEOUT, 300 );           // 重试间隔 300ms
			$this->mc->setOption( Memcached::OPT_SEND_TIMEOUT, 1000 );           // 发送超时 1s
			$this->mc->setOption( Memcached::OPT_REMOVE_FAILED_SERVERS, true );  // 自动剔除故障节点
			$this->mc->setOption( Memcached::OPT_SERVER_FAILURE_LIMIT, 2 );      // 失败 2 次即剔除
			$this->mc->addServers( $servers );
		}

		$salt = defined( 'JINYU_MEMCACHED_KEY_SALT' )
			? JINYU_MEMCACHED_KEY_SALT
			: ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'jtc' );
		$site = md5( ABSPATH . $salt );

		$this->key_salt      = defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '';
		$this->global_prefix = $site . ':global:';

		// 与 WP 默认全局组保持一致：跨站/跨请求共享，不随 blog 前缀隔离。
		$this->global_groups = [
			'users',
			'userlogins',
			'usermeta',
			'user_meta',
			'useremail',
			'userslugs',
			'site-transient',
			'site-options',
			'blog-details',
			'blog-id-cache',
			'networks',
			'sites',
			'rss',
			'global-posts',
		];
		// 非持久组：仅本次请求内有效（计数/插件加载态），不写 Memcached。
		$this->non_persistent_groups = [ 'counts', 'plugins' ];

		// 代际：本安装独占（$site 由 ABSPATH + 键盐派生），同机其它 WordPress 安装互不影响。
		$this->flush_gen = $this->read_flush_generation();

		$this->switch_to_blog( is_multisite() ? get_current_blog_id() : (int) ( $GLOBALS['table_prefix'] ?? 0 ) );
	}

	// ───────────────────────── 缓存代际（wp_cache_flush 的实现） ─────────────────────────

	/**
	 * 生成一个新的代际值。
	 *
	 * 用「时间戳 + 唯一后缀」而不是自增数字：一旦代际键被淘汰或 Memcached 重启，读不到旧值时
	 * 无论回退到哪个固定值都可能让更早写入的旧键重新可见，从而读到过期数据；时间戳只增不减，
	 * 回退时生成的必然大于历史值，最坏结果只是缓存整体冷一次。
	 */
	private function new_generation() {
		return (string) time() . '-' . str_replace( '.', '', uniqid( '', true ) );
	}

	/**
	 * 读取本安装的缓存代际；键不存在时原子地新建一个。
	 */
	private function read_flush_generation() {
		$key = $this->global_prefix . 'flush-gen';
		$gen = $this->mc->get( $key );
		if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() && is_string( $gen ) && '' !== $gen ) {
			return $gen;
		}

		$gen = $this->new_generation();
		// add 是原子写入，避免并发请求各写各的代际把缓存劈成两份。
		if ( ! $this->mc->add( $key, $gen ) ) {
			$existing = $this->mc->get( $key );
			if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() && is_string( $existing ) && '' !== $existing ) {
				return $existing;
			}
		}
		return $gen;
	}

	// ───────────────────────── 本地缓存（请求内） ─────────────────────────

	private function internal_exists( $key ) {
		return isset( $this->cache[ $key ] ) && $this->cache[ $key ] !== false;
	}

	private function get_from_internal( $key ) {
		if ( ! $this->internal_exists( $key ) ) {
			return false;
		}
		return is_object( $this->cache[ $key ] ) ? clone $this->cache[ $key ] : $this->cache[ $key ];
	}

	private function add_to_internal( $key, $value ) {
		$this->cache[ $key ] = is_object( $value ) ? clone $value : $value;
	}

	private function delete_from_internal( $key ) {
		unset( $this->cache[ $key ] );
	}

	private function is_non_persistent_group( $group ) {
		$group = $group ?: 'default';
		return isset( $this->non_persistent_groups[ $group ] );
	}

	private function build_key( $id, $group = 'default' ) {
		$group  = $group ?: 'default';
		$prefix = isset( $this->global_groups[ $group ] ) ? $this->global_prefix : $this->blog_prefix;
		$key    = $this->key_salt . $prefix . $this->flush_gen . ':' . $group . ':' . $id;

		// Memcached 的 key 有两个硬限制：长度上限 250 **字节**，且不允许空格 / 控制字符
		// （实测超限时服务端回 CLIENT_ERROR bad command line format）。踩中后 get/set 全部失败，
		// 而调用方只看到「永远不命中」—— 该条目每次请求都重算、不报任何错，是最难排查的一类问题。
		// 常见触发场景：WP_CACHE_KEY_SALT 配了长盐、插件用超长 transient 名、多站点长前缀。
		// 统一降级为定长摘要：对同一个 id 结果稳定，get 与 set 仍指向同一个键。
		// 注意必须用 strlen（字节）而不是字符数，中文/百分号编码的键按字节算。
		if ( strlen( $key ) > 250 || preg_match( '/[\x00-\x20\x7f]/', $key ) ) {
			$key = 'jy:' . md5( $key );
		}
		return $key;
	}

	// ───────────────────────── 基础读写 ─────────────────────────

	public function add( $id, $data, $group = 'default', $expire = 0 ) {
		if ( wp_suspend_cache_addition() ) {
			return false;
		}
		$key = $this->build_key( $id, $group );
		if ( $this->is_non_persistent_group( $group ) ) {
			if ( $this->internal_exists( $key ) ) {
				return false;
			}
			$this->add_to_internal( $key, $data );
			return true;
		}
		$result = $this->mc->add( $key, $data, (int) $expire );
		if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() ) {
			$this->add_to_internal( $key, $data );
		} else {
			$this->delete_from_internal( $key );
		}
		return $result;
	}

	public function replace( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->build_key( $id, $group );
		if ( $this->is_non_persistent_group( $group ) ) {
			if ( ! $this->internal_exists( $key ) ) {
				return false;
			}
			$this->add_to_internal( $key, $data );
			return true;
		}
		$result = $this->mc->replace( $key, $data, (int) $expire );
		if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() ) {
			$this->add_to_internal( $key, $data );
		} else {
			$this->delete_from_internal( $key );
		}
		return $result;
	}

	public function set( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->build_key( $id, $group );
		if ( $this->is_non_persistent_group( $group ) ) {
			$this->add_to_internal( $key, $data );
			return true;
		}
		$result = $this->mc->set( $key, $data, (int) $expire );
		if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() ) {
			$this->add_to_internal( $key, $data );
		} else {
			$this->delete_from_internal( $key );
		}
		return $result;
	}

	public function get( $id, $group = 'default', $force = false, &$found = null ) {
		$key = $this->build_key( $id, $group );

		if ( $this->internal_exists( $key ) && ! $force ) {
			$found = true;
			++$this->cache_hits;
			return $this->get_from_internal( $key );
		} elseif ( $this->is_non_persistent_group( $group ) ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}

		$value = $this->mc->get( $key );
		// 只有 RES_SUCCESS 才算命中。把超时 / 连接失败 / 部分读到等故障也当成命中，
		// 会以 $found = true 返回 false，调用方据此认为「缓存里存的就是空值」，造成数据丢失。
		if ( Memcached::RES_SUCCESS !== $this->mc->getResultCode() ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}

		$found = true;
		++$this->cache_hits;
		$this->add_to_internal( $key, $value );
		return $value;
	}

	public function get_multiple( $ids, $group = 'default', $force = false ) {
		$caches = [];
		$keys   = [];

		foreach ( (array) $ids as $id ) {
			$keys[ $id ] = $this->build_key( $id, $group );
		}

		if ( $this->is_non_persistent_group( $group ) ) {
			foreach ( $keys as $id => $key ) {
				$caches[ $id ] = $this->internal_exists( $key ) ? $this->get_from_internal( $key ) : false;
			}
			return $caches;
		}

		if ( ! $force ) {
			foreach ( $keys as $id => $key ) {
				if ( $this->internal_exists( $key ) ) {
					$caches[ $id ] = $this->get_from_internal( $key );
				} else {
					$force = true;
					break;
				}
			}
			if ( ! $force ) {
				return $caches;
			}
		}

		$results = $this->mc->getMulti( array_values( $keys ) );
		foreach ( $keys as $id => $key ) {
			if ( $results && isset( $results[ $key ] ) ) {
				$caches[ $id ] = $results[ $key ];
				$this->add_to_internal( $key, $caches[ $id ] );
				++$this->cache_hits;
			} else {
				$caches[ $id ] = false;
				$this->delete_from_internal( $key );
				++$this->cache_misses;
			}
		}
		return $caches;
	}

	// ───────────────────────── 批量写 / 删 ─────────────────────────
	// Memcached 没有原子 addMulti；批量写在语义上等价于逐个调用，故逐个转发，
	// 返回值形状（成功项为 true）与核心 `wp_cache_*_multiple()` 的约定一致。

	public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
		$values = [];
		foreach ( $data as $id => $value ) {
			$values[ $id ] = $this->add( $id, $value, $group, $expire );
		}
		return $values;
	}

	public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
		$values = [];
		foreach ( $data as $id => $value ) {
			$values[ $id ] = $this->set( $id, $value, $group, $expire );
		}
		return $values;
	}

	public function delete_multiple( array $keys, $group = 'default' ) {
		$values = [];
		foreach ( $keys as $id ) {
			$values[ $id ] = $this->delete( $id, $group );
		}
		return $values;
	}

	public function get_with_cas( $id, $group = 'default', &$cas_token = null ) {
		$key = $this->build_key( $id, $group );
		if ( defined( 'Memcached::GET_EXTENDED' ) ) {
			$result = $this->mc->get( $key, null, Memcached::GET_EXTENDED );
			if ( Memcached::RES_SUCCESS !== $this->mc->getResultCode() ) {
				return false;
			}
			$cas_token = $result['cas'];
			return $result['value'];
		}
		$result = $this->mc->get( $key, null, $cas_token );
		if ( Memcached::RES_SUCCESS !== $this->mc->getResultCode() ) {
			return false;
		}
		return $result;
	}

	public function cas( $cas_token, $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->build_key( $id, $group );
		if ( is_object( $data ) ) {
			$data = clone $data;
		}
		$this->delete_from_internal( $key );
		return $this->mc->cas( (float) $cas_token, $key, $data, (int) $expire );
	}

	public function incr( $id, $offset = 1, $group = 'default' ) {
		$key    = $this->build_key( $id, $group );
		$result = $this->mc->increment( $key, $offset );
		if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() ) {
			$this->add_to_internal( $key, $result );
		} else {
			$this->delete_from_internal( $key );
		}
		return $result;
	}

	public function decr( $id, $offset = 1, $group = 'default' ) {
		$key    = $this->build_key( $id, $group );
		$result = $this->mc->decrement( $key, $offset );
		if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() ) {
			$this->add_to_internal( $key, $result );
		} else {
			$this->delete_from_internal( $key );
		}
		return $result;
	}

	public function delete( $id, $group = 'default' ) {
		$key = $this->build_key( $id, $group );
		$this->delete_from_internal( $key );
		if ( $this->is_non_persistent_group( $group ) ) {
			return true;
		}
		return $this->mc->delete( $key );
	}

	/**
	 * 清空对象缓存。
	 *
	 * 不用 Memcached::flush()：那是**服务器级**清空，同一台 Memcached 上其它站点与应用的
	 * 数据会被一起抹掉（很多插件会在保存设置、重建索引时调用 wp_cache_flush，殃及面很大）。
	 * 这里改为推进本安装的缓存代际 —— 键前缀一变，全部旧键即刻读不到，再由各自 TTL 自然回收；
	 * 对同机其它安装零影响。
	 */
	public function flush() {
		$this->cache = [];

		$key  = $this->global_prefix . 'flush-gen';
		$next = $this->new_generation();

		$ok = $this->mc->set( $key, $next );
		if ( ! $ok ) {
			// 写入失败（连接异常等）时再试一次 add，尽量不留「代际没推进但调用方以为清了」的状态。
			$ok = $this->mc->add( $key, $next );
		}
		$this->flush_gen = $next;

		return (bool) $ok;
	}

	/**
	 * 只清空请求内的本地缓存，不动 Memcached。
	 * 对应 WP 6.1+ 的 wp_cache_flush_runtime()（核心以「是否存在本方法」判断实现能力）。
	 */
	public function flush_runtime() {
		$this->cache = [];
		return true;
	}

	public function add_global_groups( $groups ) {
		$groups              = (array) $groups;
		$this->global_groups = array_merge( $this->global_groups, $groups );
	}

	public function add_non_persistent_groups( $groups ) {
		$groups                      = (array) $groups;
		$this->non_persistent_groups = array_merge( $this->non_persistent_groups, $groups );
	}

	public function switch_to_blog( $blog_id ) {
		if ( is_multisite() ) {
			$blog_id = (int) $blog_id;
		} else {
			global $table_prefix;
			$blog_id = is_numeric( $blog_id ) ? (int) $blog_id : (int) ( $table_prefix ?? 0 );
		}
		$salt              = defined( 'JINYU_MEMCACHED_KEY_SALT' )
			? JINYU_MEMCACHED_KEY_SALT
			: ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'jtc' );
		$site              = md5( ABSPATH . $salt );
		$this->blog_prefix = $site . ':' . (int) $blog_id . ':';
		// 切换站点后清空本地缓存，避免跨站键污染。
		$this->cache = [];
	}

	public function get_stats() {
		return $this->mc->getStats();
	}

	public function get_mc() {
		return $this->mc;
	}

	public function failure_callback( $host, $port ) {
		// 静默：故障剔除已由 OPT_REMOVE_FAILED_SERVERS 接管。
	}

	// 持久连接池下不主动 quit()，保留连接复用。
	public function close() {
		return true;
	}
}

if ( ! function_exists( 'wp_cache_init' ) ) {
function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new Jinyu_Memcached_Object_Cache();
}

function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set( $key, $data, $group, $expire );
}

function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}

function wp_cache_delete( $key, $group = 'default', $deprecated = false ) {
	return $GLOBALS['wp_object_cache']->delete( $key, $group );
}

function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, $expire );
}

function wp_cache_flush() {
	return $GLOBALS['wp_object_cache']->flush();
}

function wp_cache_get_multiple( $keys, $group = 'default', $force = false ) {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}

function wp_cache_add_multiple( array $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add_multiple( $data, $group, $expire );
}

function wp_cache_set_multiple( array $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set_multiple( $data, $group, $expire );
}

function wp_cache_delete_multiple( array $keys, $group = 'default' ) {
	return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group );
}

function wp_cache_flush_runtime() {
	return $GLOBALS['wp_object_cache']->flush_runtime();
}

/**
 * 本实现支持的能力集。核心（含 wp-includes/cache-compat.php）据此决定是否走本实现，
 * 缺了它核心会判定「不支持」并对 wp_cache_flush_runtime() 抛 _doing_it_wrong。
 *
 * @param string $feature 能力名。
 * @return bool
 */
function wp_cache_supports( $feature ) {
	switch ( $feature ) {
		case 'add_multiple':
		case 'set_multiple':
		case 'get_multiple':
		case 'delete_multiple':
		case 'flush_runtime':
			return true;
		default:
			return false;
	}
}

function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}

function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
}

function wp_cache_switch_to_blog( $blog_id ) {
	if ( method_exists( $GLOBALS['wp_object_cache'], 'switch_to_blog' ) ) {
		$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
	}
}

function wp_cache_get_with_cas( $key, $group = 'default', &$cas_token = null ) {
	return $GLOBALS['wp_object_cache']->get_with_cas( $key, $group, $cas_token );
}

function wp_cache_cas( $cas_token, $key, $data, $group = 'default', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->cas( $cas_token, $key, $data, $group, $expire );
}

function wp_cache_get_stats() {
	return $GLOBALS['wp_object_cache']->get_stats();
}

function wp_cache_close() {
	return $GLOBALS['wp_object_cache']->close();
}

function wp_cache_reset() {
	wp_cache_init();
}

}
