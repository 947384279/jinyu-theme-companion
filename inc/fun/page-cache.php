<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 整页缓存（仅未登录访客的 GET 请求）
 * 启用开关与有效期统一由配套插件设置面板（jinyu_companion_*）管控，不再读主题配置；
 * 内容更新时由本文件 jinyu_companion_cache_flush() 自动失效。
 *
 * 缓存架构约定（插件独占）：缓存引擎 = 后端 / 失效策略 / key 生成 / 清理，一律归插件；
 * 主题只可调用、不得定义 jinyu_cache_*。故失效入口用带插件前缀的 jinyu_companion_cache_flush()，
 * 主题即使定义了同义的 jinyu_cache_flush() 也无法劫持失效链。
 *
 * 存储后端：磁盘文件（wp-content/cache/jinyu/page/）。
 * 不用 transient 的原因：无外部缓存扩展时 transient 落 wp_options 表，缓存读要付一次 SQL、
 * 缓存写是一次大 option 的 INSERT/UPDATE，弱机上反而比不缓存更慢；且 autoload option 每次请求
 * 都被全量读进内存。落盘后命中路径零 SQL、零扩展依赖、零常驻内存。
 */

// ── 边缘模式前置判定（须早于下方钩子注册）──────────────────────────
// 两种模式：
// - simple（默认）：插件自己写盘 + init 命中直出，全平台通用、零服务器配置。
// - edge：由 Web 服务器（Nginx fastcgi_cache / Apache mod_cache_disk）缓存 PHP 输出，
// 插件只在 send_headers 下发 Cache-Control（含 stale-while-revalidate），
// 命中时完全不跑 PHP，且响应头随响应一并冻存（CSP/ETag 不丢）。
function jinyu_page_cache_mode(): string {
	$m = (string) jinyu_companion_get_option( 'page_cache_mode', 'simple' );
	return in_array( $m, [ 'simple', 'edge' ], true ) ? $m : 'simple';
}

function jinyu_page_cache_edge_server_effective(): string {
	$cfg = (string) jinyu_companion_get_option( 'page_cache_edge_server', 'auto' );
	if ( 'nginx' === $cfg || 'apache' === $cfg ) {
		return $cfg;
	}
	$sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	if ( false !== stripos( $sw, 'nginx' ) ) {
		return 'nginx';
	}
	if ( false !== stripos( $sw, 'apache' ) ) {
		return 'apache';
	}
	return 'nginx'; // 自管 VPS 最常见，auto 兜底按 nginx 出片段
}

/**
 * 边缘缓存“清缓存”所用的物理目录（供 jinyu_edge_purge_url / jinyu_edge_purge_all 使用）。
 *
 * 必须运行在 php-fpm 命名空间可见的路径上。绝大多数服务器 PHP 与 Web 服务器（nginx/apache）
 * 共享同一命名空间，WP_CONTENT_DIR 即真实路径。但本机 /web 是仅 php-fpm 可见的私有挂载，
 * 而用户在 page_cache_edge_path 填的往往是“宿主命名空间真实路径”（nginx 能写、php-fpm 读不到）。
 * 故这里优先用用户设置，并验证其在 php-fpm 是否可访问；不可访问（典型的 bind 挂载主机路径）
 * 则回退 WP_CONTENT_DIR——它与 nginx 写入的物理目录经 bind 挂载同源，purge 仍能精确定位文件。
 */
function jinyu_page_cache_edge_path(): string {
	$p = trim( (string) jinyu_companion_get_option( 'page_cache_edge_path', '' ) );
	if ( '' !== $p ) {
		$p = rtrim( $p, '/' );
		// 该路径在 php-fpm 命名空间是否可访问？不可访问则回退 WP_CONTENT_DIR（同源物理目录）。
		if ( @is_dir( $p ) || @is_dir( dirname( $p ) ) ) {
			return $p;
		}
	}
	return rtrim( WP_CONTENT_DIR . '/cache/jinyu/edge', '/' );
}

/**
 * 边缘模式服务器片段里的缓存目录：给 nginx/apache 用，必须在“宿主命名空间”可见。
 * 优先采用用户显式配置的 page_cache_edge_path（可填宿主真实路径，如本机
 * /home/wwwroot/.../web/wp-content/cache/jinyu/edge）；未配置时回退 WP_CONTENT_DIR——
 * 绝大多数服务器两者一致，无需额外处理。
 */
function jinyu_page_cache_edge_snippet_path(): string {
	$p = trim( (string) jinyu_companion_get_option( 'page_cache_edge_path', '' ) );
	if ( '' !== $p ) {
		return rtrim( $p, '/' );
	}
	return rtrim( WP_CONTENT_DIR . '/cache/jinyu/edge', '/' );
}

function jinyu_page_cache_php_layer_active(): bool {
	return 'edge' !== jinyu_page_cache_mode();
}

// 简单模式：插件自己写盘 + 命中直出；边缘模式：仅下发缓存头，由服务器缓存 PHP 输出。
if ( jinyu_page_cache_php_layer_active() ) {
	add_action( 'init', 'jinyu_page_cache_serve' );
	add_action( 'init', 'jinyu_page_cache_gc' );
	add_action( 'template_redirect', 'jinyu_page_cache_capture' );
} else {
	add_action( 'send_headers', 'jinyu_page_cache_edge_headers', 10 );
	// 迟到守卫：DONOTCACHEPAGE 与第三方的 no-cache 响应头常在渲染期才出现，send_headers 时看不到。
	add_action( 'template_redirect', 'jinyu_page_cache_edge_late_guard', 99 );
}

// REST 请求独立拦一道，两种模式都要。理由见函数注释：REST 走不到上面任何一个钩子。
add_action( 'parse_request', 'jinyu_page_cache_rest_guard', 9 );

// ── 内容变更自动失效 ──────────────────────────────────────────────
// 文章级变更只清「该文 + 首页 + 相关归档」，不波及全站（旧实现每次发文清空整站，浪费）。
// 评论增删/审核同理只清对应文章与首页。
add_action( 'save_post', 'jinyu_page_cache_flush_post', 999 );
add_action( 'deleted_post', 'jinyu_page_cache_flush_post' );
add_action(
    'comment_post',
    static function ( $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( $comment && 1 === (int) $comment->comment_approved ) {
			jinyu_page_cache_flush_post( (int) $comment->comment_post_ID );
		}
	},
    999
);
add_action(
    'wp_set_comment_status',
    static function ( $comment_id, $status ): void {
		if ( in_array( $status, [ 'approve', '1', 'hold', '0', 'spam', 'trash' ], true ) ) {
			$comment = get_comment( $comment_id );
			if ( $comment ) {
				jinyu_page_cache_flush_post( (int) $comment->comment_post_ID );
			}
		}
	},
    999,
    2
);

// ── 跨页变更：全站失效（影响范围大，必须整体清空）────────────────
// 术语增删改会影响其归档页与首页内容。
add_action( 'create_term', 'jinyu_page_cache_flush' );
add_action( 'edit_term', 'jinyu_page_cache_flush' );
add_action( 'delete_term', 'jinyu_page_cache_flush' );
// 主题 / 菜单 / 小工具 / 自定义器 / 固定链接 变更都会改变几乎每页输出。
add_action( 'switch_theme', 'jinyu_page_cache_flush' );
add_action( 'wp_update_nav_menu', 'jinyu_page_cache_flush' );
add_action( 'wp_delete_nav_menu', 'jinyu_page_cache_flush' );
add_action( 'activated_plugin', 'jinyu_page_cache_flush' );
add_action( 'deactivated_plugin', 'jinyu_page_cache_flush' );
add_action( 'upgrader_process_complete', 'jinyu_page_cache_flush' );
add_action( 'update_option', 'jinyu_page_cache_on_option_change', 10, 2 );

/**
 * 选项变更失效：仅当变更会影响前台输出时才全站清空，且忽略本插件自身选项（避免自触发/无意义清空）。
 *
 * @param string $option 变更的选项名。
 */
function jinyu_page_cache_on_option_change( string $option ): void {
	if ( 0 === strpos( $option, 'jinyu_companion_' ) || 0 === strpos( $option, 'jinyu_page_cache_' ) ) {
		return;
	}
	$affecting = [
		'blogname',
		'blogdescription',
		'permalink_structure',
		'rewrite_rules',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'nav_menu_locations',
	];
	if ( in_array( $option, $affecting, true ) ) {
		jinyu_page_cache_flush();
		return;
	}
	// 小工具实例 / 自定义器（主题 mods）变更影响每页
	if ( 0 === strpos( $option, 'widget_' ) || 0 === strpos( $option, 'theme_mods_' ) ) {
		jinyu_page_cache_flush();
	}
}

// 缓存结构版本：出现在文件名中。将来若需扩展 key 维度（语言、UA 分流等），
// 递增本常量即可让全部历史文件一次性失效，无需迁移脚本或手工清理。
if ( ! defined( 'JINYU_PAGE_CACHE_VERSION' ) ) {
	define( 'JINYU_PAGE_CACHE_VERSION', 'v1' );
}

// 磁盘回收的最小执行间隔（秒）。save_post 是高触发钩子，
// 不设闸门会在批量导入 / 评论风暴时反复扫目录，反而拖慢请求。
if ( ! defined( 'JINYU_PAGE_CACHE_GC_INTERVAL' ) ) {
	define( 'JINYU_PAGE_CACHE_GC_INTERVAL', 300 );
}

// 缓存状态诊断的重算间隔（秒）。文件数 / 最近写入需要 glob 整个缓存目录，
// 每次请求都做在文件多的站点上是白白开销，故只限后台页面触发、且带闸门。
if ( ! defined( 'JINYU_PAGE_CACHE_PROBE_INTERVAL' ) ) {
	define( 'JINYU_PAGE_CACHE_PROBE_INTERVAL', 300 );
}

// 「缓存不可用」告警的最小重复间隔（秒）。目录不可写、磁盘写满这类状况在
// 每次前台请求都会命中，不去重会把 error_log 和数据库写爆。
if ( ! defined( 'JINYU_PAGE_CACHE_NOTE_INTERVAL' ) ) {
	define( 'JINYU_PAGE_CACHE_NOTE_INTERVAL', 3600 );
}

/**
 * 整页缓存代际（epoch）：flush 时推进代际号，旧代际的所有 key 一次性整体失效，
 * 无需逐 URI 枚举删除。option 不自动加载（autoload=no），成本为一次主键级 UPDATE。
 */
function jinyu_page_cache_epoch(): string {
    $epoch = get_option( 'jinyu_page_cache_epoch' );
    if ( ! is_string( $epoch ) || '' === $epoch ) {
        $epoch = '1';
        update_option( 'jinyu_page_cache_epoch', $epoch, false );
    }
    return $epoch;
}

/**
 * 清理历史缓存文件（保留当前代际 + 当前 nonce tick 的全部命中）。
 *
 * 代际切换后旧文件本就读不到，GC 只是回收磁盘，延迟执行不影响正确性。
 * 只保留「当前代际 + 当前 nonce tick」前缀下的全部文件，旧代际/tick 才是真正过期的；
 * 旧实现用单页文件名做前缀，会把其他页面的有效缓存一并删掉——在「按文章精细失效」后尤不可取。
 */
function jinyu_page_cache_gc(): void {
    if ( ! function_exists( 'jinyu_companion_is_checked' ) || ! jinyu_companion_is_checked( 'page_cache_enable' ) ) {
        return;
    }
    // 边缘模式由服务器管理自己的缓存回收（inactive=/max_size=），插件不写静态文件，无需 GC。
    if ( ! jinyu_page_cache_php_layer_active() ) {
        return;
    }

    $last = (int) get_option( 'jinyu_page_cache_gc_at', 0 );
    if ( time() - $last < JINYU_PAGE_CACHE_GC_INTERVAL ) {
        return;
    }
    update_option( 'jinyu_page_cache_gc_at', time(), false );

    $dir = jinyu_page_cache_dir();
    if ( '' === $dir || ! is_dir( $dir ) ) {
        return;
    }

    $tick        = function_exists( 'wp_nonce_tick' ) ? wp_nonce_tick() : (int) ceil( time() / ( DAY_IN_SECONDS / 2 ) );
    $keep_prefix = 'page_' . JINYU_PAGE_CACHE_VERSION . '_' . jinyu_page_cache_epoch() . '_' . $tick . '_';

    foreach ( glob( $dir . '/page_*' ) ?: [] as $f ) {
        $name = basename( $f );
        if ( '.meta' === substr( $name, -5 ) ) {
            continue; // 元数据随其 .html 一并判定
        }
        // 原子写入的临时文件：正常路径下 rename 后即不存在，残留只可能是进程被中途杀掉。
        // 它不属于任何代际，必须单独按时间回收，否则会混进下面的代际比对里被永久保留。
        if ( '.tmp' === substr( $name, -4 ) ) {
            if ( time() - (int) @filemtime( $f ) > HOUR_IN_SECONDS ) {
                @wp_delete_file( $f );
            }
            continue;
        }
        if ( 0 !== strpos( $name, $keep_prefix ) ) {
            @wp_delete_file( $f );
            @wp_delete_file( $f . '.meta' );
        }
    }

    // 清理过期的重建锁文件（>1h 必已无进程持有；删目录项不影响活跃 flock 的 inode）。
    foreach ( glob( $dir . '/.lock_*' ) ?: [] as $lf ) {
        if ( time() - (int) @filemtime( $lf ) > HOUR_IN_SECONDS ) {
            @wp_delete_file( $lf );
        }
    }
}

/**
 * 全站缓存失效：推进代际号，旧代际文件整体读不到，GC 回收磁盘。
 * 用于「影响范围跨多页」的变更：主题切换、小工具/菜单/选项变更、插件启停、升级、术语变更。
 */
function jinyu_page_cache_flush(): void {
    // 代际号用「自增」而不是 time()：同一秒内可能连续失效多次（批量导入逐篇 save_post、
    // 多个钩子连锁触发），time() 在这一秒里返回同一个值，update_option 写入相同字符串时
    // 不会真正推进代际 —— 而这一秒内已有页面按旧代际落盘，它们将不被失效，旧内容会一直服务到 TTL。
    update_option( 'jinyu_page_cache_epoch', (string) ( (int) jinyu_page_cache_epoch() + 1 ), false );
    // GC 自身带频率闸门，此处调用不会每次都扫目录
    jinyu_page_cache_gc();
    // 边缘模式：服务器层缓存走全量刷新（配合 stale-while-revalidate 无感）
    jinyu_edge_purge_all();
}

/**
 * 按 URL 精确删除缓存文件（跨任意版本/代际/nonce tick）。
 *
 * 文件名 = page_{版本}_{代际}_{tick}_{身份}.html，身份是 URI 的 md5；
 * 用 page_*_*_*_{身份}.html 通配即可命中所属 URI 的全部历史文件，无需知道当前代际/tick。
 *
 * @param string $url 任意页面 URL（文章永久链接 / 归档 / 首页等）。
 */
function jinyu_page_cache_delete_uri( string $url ): void {
    $dir = jinyu_page_cache_dir();
    if ( '' === $dir || ! is_dir( $dir ) ) {
        return;
    }
    $identity = jinyu_page_cache_identity_for_uri( $url );
    if ( '' === $identity ) {
        return;
    }
    foreach ( glob( $dir . '/page_*_*_*_' . $identity . '.html' ) ?: [] as $f ) {
        @wp_delete_file( $f );
        @wp_delete_file( $f . '.meta' );
    }
}

/**
 * 单篇内容级失效：只清「该文永久链接 + 首页 + 该文类型归档 + 该文所属术语归档」，
 * 不动其他文章缓存。避免每发一篇文章就把整站缓存清空（旧实现的浪费点）。
 *
 * @param int $post_id
 */
function jinyu_page_cache_flush_post( int $post_id ): void {
    $post_id = (int) $post_id;
    if ( $post_id <= 0 ) {
        return;
    }
    if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
        return;
    }
    if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
        return;
    }

    $urls = [];
    $permalink = get_permalink( $post_id );
    if ( is_string( $permalink ) && '' !== $permalink ) {
        $urls[] = $permalink;
    }
    // 首页 / 前台页受最新内容影响，一并失效
    $urls[] = home_url( '/' );

    $post = get_post( $post_id );
    if ( $post ) {
        $archive = get_post_type_archive_link( $post->post_type );
        if ( $archive && ! is_wp_error( $archive ) ) {
            $urls[] = $archive;
        }
        foreach ( get_post_taxonomies( $post ) as $tax ) {
            $terms = get_the_terms( $post_id, $tax );
            if ( is_array( $terms ) ) {
                foreach ( $terms as $t ) {
                    $link = get_term_link( $t, $tax );
                    if ( is_string( $link ) && ! is_wp_error( $link ) ) {
                        $urls[] = $link;
                    }
                }
            }
        }
    }

    // 简单模式删插件静态文件；边缘模式清服务器层缓存（Nginx 精确 / Apache 全量）。
    // 两层调用都做了「模式不匹配则直接返回」的自我保护，可无顾虑地一起调用。
    foreach ( array_unique( $urls ) as $u ) {
        jinyu_page_cache_delete_uri( $u );
        jinyu_edge_purge_url( $u );
    }
}

/**
 * 全局缓存失效入口（插件独占，主题不可覆盖）。
 *
 * 存在意义：早年的 jinyu_cache_flush() 带 function_exists 守卫，主题一旦定义了同名函数，
 * 插件那两行 llms transient 的 delete 就永远不会执行
 * （发布文章后 llms.txt 最长 TTL 内仍是旧内容）。故内部调用一律走本函数 ——
 * 函数名带插件前缀，主题不可能定义，失效链不会被劫持。
 *
 * @return int 本次清理的缓存条目数。
 */
function jinyu_companion_cache_flush(): int {
    $n = 0;

    // 整页缓存：全站推进代际号，旧代际的全部 key 一次性整体失效。
    if ( function_exists( 'jinyu_page_cache_flush' ) ) {
        jinyu_page_cache_flush();
        ++$n;
    }

    // llms.txt / llms-full.txt 输出缓存。
    delete_transient( 'jinyu_llms_index_cache' );
    delete_transient( 'jinyu_llms_full_cache' );
    $n += 2;

    return $n;
}

/**
 * 缓存身份：决定同一份页面缓存归属于哪个站点。
 *
 * 并入域名与（多站点下的）blog id：一台服务器跑多个 WP 时插件目录可以共享，
 * 缺了这一层，A 站会命中按 URI 计算出的同文件名、实为 B 站内容的缓存页。
 *
 * @return string md5 摘要
 */
function jinyu_page_cache_identity(): string {
    return jinyu_page_cache_identity_for( jinyu_page_cache_canonical_uri() );
}

/**
 * 由规范化 URI 计算缓存身份摘要（域名 + blog id + 规范化路径/参数）。
 * 抽成「输入 URI 字符串」的纯函数，供当前请求与「按 URL 精确失效」复用同一算法。
 *
 * @param string $canonical 规范化后的 path?query
 *
 * @return string md5 摘要
 */
function jinyu_page_cache_identity_for( string $canonical ): string {
    $host = '';
    if ( function_exists( 'wp_parse_url' ) ) {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
    }
    if ( '' === $host ) {
        $host = trim( (string) preg_replace( '/:\d+$/', '', isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) );
    }

    $blog = function_exists( 'is_multisite' ) && is_multisite() ? ':' . (int) get_current_blog_id() : '';

    return md5( $host . $blog . '|' . $canonical );
}

/**
 * 由任意 URL（文章永久链接 / 归档 / 首页等）计算缓存身份，供精确失效删除对应文件。
 *
 * @param string $url
 *
 * @return string md5 摘要；解析失败返回空串
 */
function jinyu_page_cache_identity_for_uri( string $url ): string {
    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    if ( '' === $path ) {
        $path = '/';
    }
    $params = [];
    $query  = wp_parse_url( $url, PHP_URL_QUERY );
    if ( is_string( $query ) && '' !== $query ) {
        parse_str( $query, $params );
    }
    return jinyu_page_cache_identity_for( jinyu_page_cache_canonicalize( $path, $params ) );
}

/**
 * 当前请求的缓存文件路径（同一次请求内只计算一次）。
 *
 * 文件名 = page_{版本}_{代际}_{nonce tick}_{身份摘要}.html
 *  - 版本：结构升级时整体作废历史文件
 *  - 代际：save_post / 评论变更时推进，旧代际文件一次性全部读不到
 *  - tick：缓存页内嵌了 wp_create_nonce，按 12h 轮换并入 key，防止把过期 nonce 发给访客
 *          （缓存页里的 nonce 只有在 key 变化、页面重建时才会更新）
 *  - 身份：域名 + blog id，隔离同机多站 / Multisite 网络
 *
 * @return string 绝对路径；目录不可用时返回空串，调用方静默降级为不使用缓存
 */
function jinyu_page_cache_file(): string {
    static $path = '';

    if ( '' !== $path ) {
        return $path;
    }

    $dir = jinyu_page_cache_dir();
    // 目录不存在则尝试创建；只读文件系统 / 权限不足时静默放弃缓存，
    // 但仍要留一条可观测记录（jinyu_page_cache_note_blocked），否则用户开着开关却始终不生效也不自知
    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
        jinyu_page_cache_note_blocked( 'no_mkdir' );
        return '';
    }

    $tick = function_exists( 'wp_nonce_tick' ) ? wp_nonce_tick() : (int) ceil( time() / ( DAY_IN_SECONDS / 2 ) );

    $path = $dir . '/page_' . JINYU_PAGE_CACHE_VERSION . '_' . jinyu_page_cache_epoch() . '_' . $tick . '_' . jinyu_page_cache_identity() . '.html';

    return $path;
}

/**
 * 缓存目录的绝对路径（只算路径，不负责创建）。
 *
 * 与文件名的拼接分离出来，供状态诊断、GC、管理端提示复用同一处定义 ——
 * 目录位置散落在多个函数里，改一处漏一处就会诊断到不存在的路径。
 */
function jinyu_page_cache_dir(): string {
    return WP_CONTENT_DIR . '/cache/jinyu/page';
}

/**
 * 重建锁：按 URI 身份的逐页互斥，挡住缓存失效/过期瞬间的惊群（多进程同时重渲染）。
 *
 * 获取成功返回文件句柄（调用方须在请求结束释放）；已被他人持有时返回 null。
 * 基于 flock，要求服务器文件系统支持（Linux 满足）；Windows 本地开发不支持时自动降级为无锁。
 *
 * @param resource|null $set 传入则设置当前持有的句柄，省略则取回。
 *
 * @return resource|null
 */
function jinyu_page_cache_build_lock( $set = null ) {
    static $fh = null;
    if ( null !== $set ) {
        $fh = $set;
    }
    return $fh;
}

/**
 * 尝试获取某 URI 的重建锁（非阻塞）。成功返回句柄，已被他人持有返回 null。
 *
 * @param string $identity
 *
 * @return resource|null
 */
function jinyu_page_cache_acquire_build_lock( string $identity ) {
    $dir = jinyu_page_cache_dir();
    if ( '' === $dir || ! is_dir( $dir ) ) {
        return null;
    }
    $lock = $dir . '/.lock_' . $identity;
    $fh   = @fopen( $lock, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors -- 重建锁需 flock，WP_Filesystem 不支持
    if ( false === $fh ) {
        return null;
    }
    if ( ! @flock( $fh, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock,WordPress.PHP.NoSilencedErrors -- 缓存重建互斥锁，无可替代 WP API
        @fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.PHP.NoSilencedErrors -- 锁文件句柄配套关闭，WP_Filesystem 无 flock
        return null;
    }
    return $fh;
}

/**
 * 释放重建锁（同时清空当前持有记录）。
 *
 * @param resource|null $fh
 */
function jinyu_page_cache_release_build_lock( $fh ): void {
    if ( is_resource( $fh ) ) {
        @flock( $fh, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock,WordPress.PHP.NoSilencedErrors -- 缓存重建互斥锁，无可替代 WP API
        @fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.PHP.NoSilencedErrors -- 锁文件句柄配套关闭，WP_Filesystem 无 flock
    }
    jinyu_page_cache_build_lock( null );
}

/**
 * 记录「缓存后端不可用」（目录建不出来 / 不可写 / 写入失败）。
 *
 * 去重的意义：这类状况每个前台请求都会命中，不去重会把 error_log 与
 * 数据库写爆（一次写 option 就是一次 UPDATE）。同一原因在
 * JINYU_PAGE_CACHE_NOTE_INTERVAL 内只记一次；原因变化时立即更新。
 *
 * @param string $reason no_mkdir | not_writable | write_fail
 */
function jinyu_page_cache_note_blocked( string $reason ): void {
    $prev    = (string) get_option( 'jinyu_page_cache_blocked_reason', '' );
    $at      = (int) get_option( 'jinyu_page_cache_blocked_at', 0 );
    $expired = time() - $at > JINYU_PAGE_CACHE_NOTE_INTERVAL;

    // 原因变了就没法比时间 —— 新原因可能一直没被记过，必须立刻更新
    if ( $prev === $reason && ! $expired ) {
        return;
    }

    update_option( 'jinyu_page_cache_blocked_reason', $reason, false );
    update_option( 'jinyu_page_cache_blocked_at', time(), false );

    // 服务器错误日志留痕，方便排查时 grep；
    // 已经写过同样的原因就别重复刷，否则日志本身成为问题。
    $msg = sprintf(
        '[jinyu] 整页缓存不可用（%s）：目录 %s%s',
        $reason,
        jinyu_page_cache_dir(),
        'no_mkdir' === $reason ? ' 无法创建' : ' 不可写入'
    );
    if ( 'write_fail' === $reason ) {
        $msg = '[jinyu] 整页缓存写入失败：可能是磁盘已满或 inode 耗尽';
    }
    if ( 'write_fail' === $prev || ! in_array( $reason, [ $prev ], true ) ) {
        @error_log( $msg );
    }
}

/**
 * 整页缓存后端状态诊断。
 *
 * 存在的理由：目录建不出来时缓存是「静默降级」的 —— 开关是开着的，用户以为生效了，
 * 实际上每个请求都在重跑 WordPress。把可用性显式暴露出来，管理端提醒、设置面板提示、
 * 性能中心看板才有东西可展示。
 *
 * reason 取值：
 *  - disabled    开关未开（不是故障，不提示）
 *  - ok          就绪
 *  - no_mkdir    目录建不出来（权限不足 / 只读文件系统）
 *  - not_writable 目录存在但不可写
 *  - write_fail  目录可写但刚才那次写入失败（磁盘满 / inode 耗尽）
 *
 * @return array{dir:string,enabled:bool,ready:bool,writable:bool,files:int,last_write:int,reason:string}
 */
function jinyu_page_cache_status(): array {
    $dir = jinyu_page_cache_dir();

    $status = [
        'dir'        => $dir,
        'enabled'    => function_exists( 'jinyu_companion_is_checked' ) && jinyu_companion_is_checked( 'page_cache_enable' ),
        'ready'      => false,
        'writable'   => false,
        'files'      => 0,
        'last_write' => 0,
        'reason'     => 'disabled',
    ];

    // 没开开关就不该有任何噪音：目录不存在是常态，不是问题
    if ( ! $status['enabled'] ) {
        return $status;
    }

    // 边缘模式：缓存由 Web 服务器写入，插件不碰目录，跳过可写性诊断，改报模式信息。
    if ( 'edge' === jinyu_page_cache_mode() ) {
        $status['reason']       = 'edge';
        $status['ready']        = true;
        $status['writable']     = true;
        $status['edge_server']  = jinyu_page_cache_edge_server_effective();
        $status['edge_path']    = jinyu_page_cache_edge_snippet_path();
        return $status;
    }

    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) && ! is_dir( $dir ) ) {
        $status['reason'] = 'no_mkdir';
        return $status;
    }

    if ( ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- 前端缓存目录可写性检查，无可替代 WP API
        $status['reason'] = 'not_writable';
        return $status;
    }

    $status['ready']    = true;
    $status['writable'] = true;
    $status['reason']   = 'ok';

    // 文件数 / 最近写入需要扫目录，带闸门：只在超过间隔时重算，结果复用
    $probe = (int) get_option( 'jinyu_page_cache_probe_at', 0 );
    if ( time() - $probe > JINYU_PAGE_CACHE_PROBE_INTERVAL ) {
        $files  = glob( $dir . '/page_*.html' ) ?: [];
        $newest = 0;
        foreach ( $files as $f ) {
            $m = (int) @filemtime( $f );
            if ( $m > $newest ) {
                $newest = $m;
            }
        }
        update_option( 'jinyu_page_cache_probe_at', time(), false );
        update_option(
            'jinyu_page_cache_stat',
            [
				'files'      => count( $files ),
				'last_write' => $newest,
			],
            false
        );
    }

    $stat = get_option( 'jinyu_page_cache_stat' );
    if ( is_array( $stat ) ) {
        $status['files']      = (int) ( $stat['files'] ?? 0 );
        $status['last_write'] = (int) ( $stat['last_write'] ?? 0 );
    }

    return $status;
}

/**
 * 「缓存不可用」的人类可读修复指引。
 *
 * 只给通用做法，不猜用户的服务器面板类型：权限问题的根因是 php-fpm 运行用户
 * 与目录属主不一致，SSH 与面板两条路最终都落到同一条命令上。
 */
function jinyu_page_cache_hint(): string {
    $dir = jinyu_page_cache_dir();
    $up  = WP_CONTENT_DIR . '/cache';

    return sprintf(
        /*
         * translators: 1: 缓存目录绝对路径；2: wp-content 目录路径
         */
        __(
            '整页缓存已开启，但无法把 HTML 写入 <code>%1$s</code>，缓存实际未生效。请在 SSH 中执行 <code>mkdir -p %1$s &amp;&amp; chown -R www:www %2$s</code>（把 www 换成你的 php-fpm 运行用户）；面板用户可在文件管理器中把 <code>%2$s</code> 改为 775、并让属主与 PHP 进程一致。设置完刷新本页即可。',
            'jinyu-theme-companion'
        ),
        esc_html( $dir ),
        esc_html( $up )
    );
}

/**
 * 后台提醒：缓存开关已开但后端不可用。
 *
 * 挂在 admin_notices 上，任何后台页面都能看到；带 1 次 / 24 小时 的去重，
 * 避免用户在每个页面都看到同一条警告。开关关着时不出现。
 */
add_action( 'admin_notices', 'jinyu_page_cache_admin_notice' );

function jinyu_page_cache_admin_notice(): void {
    if ( function_exists( 'wp_installing' ) && wp_installing() ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $s = jinyu_page_cache_status();
    if ( $s['ready'] || 'disabled' === $s['reason'] ) {
        return;
    }

    // 与 note_blocked 共用去重窗口，但不完全依赖它 —— 那边的闸门写的是另一个 option
    if ( (int) get_option( 'jinyu_page_cache_noticed_at', 0 ) > time() - DAY_IN_SECONDS ) {
        return;
    }
    update_option( 'jinyu_page_cache_noticed_at', time(), false );

    printf(
        '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
        esc_html__( '整页缓存未生效', 'jinyu-theme-companion' ),
        jinyu_page_cache_hint() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控/对外原始输出（JSON-LD/SVG/缓存页/内部构造 HTML），无需转义
    );
}
 // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控/对外原始输出（JSON-LD/SVG/缓存页/内部构造 HTML），无需转义
/**
 * 「条件请求」配置提示（仅缓存就绪时展示）。
 *
 * 与 jinyu_page_cache_hint() 的区别：那条是「坏了」必须红色告警，这条是「没配、但不影响可用」。
 * 缓存命中照常返回 HTML 并带 ETag / Cache-Control，缺的只是浏览器与 CDN 回 304 那一次省流量，
 * 所以这里不进后台 notice、不算 status() 里的 reason —— 否则会把面板用户的注意力从
 * 「目录写不进去」这种真故障上引开。只做成设置面板里的一行中性说明。
 *
 * 明确写出来而不静默处理的原因：Nginx 默认不转发非标配请求头，用户若自己配过 CDN，
 * 会发现「ETag 发了但永远不回 304」而查不到原因。
 */
function jinyu_page_cache_etag_hint(): string {
    return sprintf(
        /*
         * translators: 1: Nginx 需要追加的配置行
         */
        __(
            '命中时已下发 ETag。若希望浏览器 / CDN 回「304 未修改」以节省流量，需确认 Web 服务器把条件请求头转给了 PHP（Nginx 在 server 段追加：<code>%1$s</code>）。未配置不影响缓存生效，仅少一次带宽优化；Apache 一般无需设置。',
            'jinyu-theme-companion'
        ),
        'fastcgi_param HTTP_IF_NONE_MATCH $http_if_none_match;'
    );
}

/**
 * 拆出当前请求的 path 与查询参数数组。
 *
 * @return array{0:string,1:array} [path, params]
 */
function jinyu_page_cache_uri_parts(): array {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
    if ( '' === $uri ) {
        return [ '/', [] ];
    }
    $path = wp_parse_url( $uri, PHP_URL_PATH );
    if ( ! is_string( $path ) || '' === $path ) {
        $path = '/';
    }
    $params = [];
    $query  = wp_parse_url( $uri, PHP_URL_QUERY );
    if ( is_string( $query ) && '' !== $query ) {
        parse_str( $query, $params );
    }
    return [ $path, is_array( $params ) ? $params : [] ];
}

/**
 * 多行规则文本 → 规则数组（去空行、去首尾空白、跳过 # 注释行）。
 */
function jinyu_page_cache_rules( string $key ): array {
    $raw = (string) jinyu_companion_get_option( $key, '' );
    if ( '' === trim( $raw ) ) {
        return [];
    }
    $out = [];
    foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
        $line = trim( (string) $line );
        if ( '' === $line || 0 === strpos( $line, '#' ) ) {
            continue;
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * 逗号 / 空格分隔的参数名 → 小写数组。
 */
function jinyu_page_cache_param_names( string $key ): array {
    $raw = (string) jinyu_companion_get_option( $key, '' );
    if ( '' === trim( $raw ) ) {
        return [];
    }
    $out = [];
    foreach ( preg_split( '/[,\s]+/', trim( $raw ) ) ?: [] as $p ) {
        $p = strtolower( trim( (string) $p ) );
        if ( '' !== $p ) {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * 归一化 URI：剔除「忽略参数」，剩余参数按键排序，使参数顺序不同也命中同一份缓存。
 * 抽成接收 path + 参数数组的纯函数，供当前请求与「按 URL 失效」共用。
 */
function jinyu_page_cache_canonicalize( string $path, array $params ): string {
    $ignore = jinyu_page_cache_param_names( 'page_cache_ignore_params' );
    if ( $ignore ) {
        foreach ( array_keys( $params ) as $k ) {
            if ( in_array( strtolower( (string) $k ), $ignore, true ) ) {
                unset( $params[ $k ] );
            }
        }
    }
    ksort( $params );
    $qs = $params ? http_build_query( $params ) : '';
    return $path . ( '' !== $qs ? '?' . $qs : '' );
}

/**
 * 当前请求 URI 的规范化形式（剔除忽略参数、参数排序）。
 */
function jinyu_page_cache_canonical_uri(): string {
    [$path, $params] = jinyu_page_cache_uri_parts();
    return jinyu_page_cache_canonicalize( $path, $params );
}

/**
 * 单条路径规则匹配：精确相等，或以规则为目录前缀（/go 命中 /go/123，不命中 /google）。
 * 规则以 * 结尾时按前缀通配。
 */
function jinyu_page_cache_path_match( string $path, string $rule ): bool {
    $rule = trim( $rule );
    if ( '' === $rule ) {
        return false;
    }
    if ( '*' === substr( $rule, -1 ) ) {
        $rule = substr( $rule, 0, -1 );
    }
    $r = rtrim( $rule, '/' );
    if ( '' === $r ) {
        return false;
    }
    return $path === $r || 0 === strpos( $path, $r . '/' );
}

/**
 * 例外判定：命中排除路径或排除参数时，本次请求既不读缓存也不写缓存。
 *
 * serve（init）与 capture（template_redirect）共用同一判定，保证读写一致 ——
 * 否则会出现「读了不该读的缓存」或「写了永远读不到的缓存」。
 */
function jinyu_page_cache_is_excluded(): bool {
    [$path, $params] = jinyu_page_cache_uri_parts();

    foreach ( jinyu_page_cache_rules( 'page_cache_exclude_paths' ) as $rule ) {
        if ( jinyu_page_cache_path_match( rtrim( $path, '/' ), $rule ) ) {
            return true;
        }
    }

    $skip = jinyu_page_cache_param_names( 'page_cache_exclude_params' );
    if ( $skip ) {
        foreach ( array_keys( $params ) as $k ) {
            if ( in_array( strtolower( (string) $k ), $skip, true ) ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * 按 URI 特征判定搜索请求（?s=… 或 /search/… 重写）。
 * serve 端先于 WP 查询运行，is_search() 尚不可用，故以 URI 判定；
 * capture 端仅作 is_search() 之外的兜底。
 */
function jinyu_page_cache_is_search_uri(): bool {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    if ( '' === $uri ) {
		return false;
    }
    return (bool) ( preg_match( '#[?&]s=[^&]*#', $uri ) || stripos( $uri, '/search/' ) !== false );
}

/**
 * 非 HTML 响应的 URI 特征（REST API / oEmbed / XML-RPC / Cron / AJAX）。
 *
 * 这些端点在匿名访客下同样走前台渲染链，只拦 capture 端是不够的：漏了会让
 * JSON 响应被当 HTML 写进缓存，serve 端再按 .meta 取 Content-Type 原样回吐，
 * 等于给 API 响应套了个错误的 Content-Type。故与搜索页一样做成 URI 判据，
 * serve（init，WP 查询未跑、is_rest() 不可用）与 capture 共用同一套判定。
 */
function jinyu_page_cache_is_non_html_uri(): bool {
    [$path, $params] = jinyu_page_cache_uri_parts();
    $p = strtolower( $path );

    if ( in_array( $p, [ '/wp-cron.php', '/xmlrpc.php', '/wp-login.php' ], true ) ) {
        return true;
    }
    // admin-ajax.php 挂在 wp-admin 目录下，实际请求路径带 /wp-admin 前缀，
    // 前缀比对漏掉它（capture 端虽被 is_admin() 拦，serve 端却读得到，判据要统一）
    if ( false !== strpos( $p, '/admin-ajax.php' ) ) {
        return true;
    }

    foreach ( [ '/wp-json/', '/oembed' ] as $seg ) {
        if ( false !== strpos( $p, $seg ) ) {
            return true;
        }
    }

    foreach ( [ 'rest_route', '_jsonp', 'doing_wp_cron' ] as $k ) {
        if ( isset( $params[ $k ] ) && '' !== (string) $params[ $k ] ) {
            return true;
        }
    }

    return false;
}

/**
 * 条件请求比对：请求头 If-None-Match 与当前 ETag 等价时返回 true。
 *
 * 浏览器 / 代理可能带 W/ 弱化前缀或 -gzip 后缀（nginx 的 gunzip 协商），
 * 比对前统一剥离 —— 缓存页内容对语义等价，弱化校验足够。
 */
function jinyu_page_cache_if_none_match_match( string $header, string $etag ): bool {
    $want = trim( $etag, '"' );
    if ( '' === $want ) {
        return false;
    }

    // 按 RFC 7232 解析：, *  （星号表示任意当前状态，一律视为未变化）
    // 容忍 W/ 前缀与 -gzip 后缀（nginx gunzip 协商），以及某些代理对引号做的 \" 转义
    $given = trim( $header );
    if ( '*' === $given ) {
        return true;
    }
    if ( 0 === stripos( $given, 'W/' ) ) {
        $given = substr( $given, 2 );
    }
    if ( '-gzip' === substr( $given, -5 ) ) {
        $given = substr( $given, 0, -5 );
    }
    $given = trim( $given, '" \\' );

    return '' !== $given && hash_equals( $want, $given );
}

/**
 * 缓存页的 Content-Type。
 *
 * 缓存页有可能是 XML / JSON / 纯文本（插件的 GET 端点），一律按 text/html 声明
 * 会让浏览器解析错误或直接下载，故落盘时如实记录、命中时还原。
 */
function jinyu_page_cache_content_type(): string {
    foreach ( headers_list() ?: [] as $h ) {
        if ( 0 === stripos( $h, 'Content-Type:' ) ) {
            $ct = trim( substr( $h, 13 ) );
            if ( '' !== $ct && strlen( $ct ) < 191 ) {
                return $ct;
            }
        }
    }
    return 'text/html; charset=utf-8';
}

/**
 * 缓存命中时需原样回放的安全响应头白名单（与 WP Super Cache 3.1.2 覆盖口径对齐）。
 * 仅回放这些、且只取首个值，避免重复头或意外重放 Set-Cookie 等动态头。
 */
function jinyu_page_cache_replayable_headers(): array {
    return [
        'Content-Security-Policy',
        'Content-Security-Policy-Report-Only',
        'X-Content-Type-Options',
        'X-Frame-Options',
        'X-XSS-Protection',
        'Referrer-Policy',
        'Permissions-Policy',
        'Cross-Origin-Opener-Policy',
        'Cross-Origin-Embedder-Policy',
        'Cross-Origin-Resource-Policy',
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Headers',
        'Access-Control-Allow-Methods',
        'Strict-Transport-Security',
        'X-Download-Options',
        'X-Permitted-Cross-Domain-Policies',
    ];
}

/**
 * 从当前已下发响应头中，挑出白名单内的安全头（首个值），供落盘后命中时回放。
 *
 * @return array<string,string> 头名 => 头值
 */
function jinyu_page_cache_capture_headers(): array {
    $allow = array_map( 'strtolower', jinyu_page_cache_replayable_headers() );
    $out   = [];
    foreach ( headers_list() ?: [] as $h ) {
        $pos = strpos( $h, ':' );
        if ( false === $pos ) {
            continue;
        }
        $name = trim( substr( $h, 0, $pos ) );
        $val  = trim( substr( $h, $pos + 1 ) );
        if ( '' === $val ) {
            continue;
        }
        if ( ! in_array( strtolower( $name ), $allow, true ) ) {
            continue;
        }
        if ( ! isset( $out[ $name ] ) ) { // 只保留首个值
            $out[ $name ] = $val;
        }
    }
    return $out;
}

/**
 * 命中时下发的缓存响应头。
 *
 * 必须放开浏览器本地缓存（不设 no-cache），客户端才会带 If-None-Match 回来，
 * 配合 ETag 即可零字节 304。
 */
function jinyu_page_cache_send_headers( string $etag ): void {
    $ttl = max( 60, (int) jinyu_companion_get_option( 'page_cache_ttl', '3600' ) );
    // 浏览器 max-age 固定 600 是为了「比服务端 TTL 短」——更短才好在服务端清理后尽快回源。
    // 但反过来不成立：TTL 配得比 600 小时（如 300），固定 600 会让访客在服务端已过期、
    // 已清理之后，仍从自己浏览器的本地缓存读旧页面，must-revalidate 也拦不住。
    // 故取两者较小值：既保留「尽快回源」的意图，又保证永不超过服务端有效期。
    header( 'ETag: ' . $etag );
    header( 'Cache-Control: public, max-age=' . min( 600, $ttl ) . ', must-revalidate' );
}

/**
 * 是否携带 WP 匿名评论者身份 cookie（comment_author_*）。
 * 这类访客的姓名/邮箱/网址会被 WP 预填进评论表单，若按匿名处理进缓存，
 * 会把个人信息串号下发给其他访客——必须与登录用户同等对待（不读不写缓存）。
 */
function jinyu_page_cache_has_commenter_cookie(): bool {
    foreach ( array_keys( $_COOKIE ) as $k ) {
        if ( is_string( $k ) && strpos( (string) $k, 'comment_author_' ) === 0 ) {
            return true;
        }
    }
    return false;
}

/**
 * 是否携带「文章密码」cookie（wp-postpass_*）。
 *
 * 访客输入正确密码后，WordPress 会为该文章渲染**解锁后的完整正文**并种下这个 cookie。
 * 整页缓存按 URI（不含 cookie）共享，这种响应一旦落盘，任何匿名访客都能读到加锁内容。
 * 故带该 cookie 的请求一律不读不写缓存。
 *
 * 为什么按 cookie 判定而不是 post_password_required()：serve 挂在 init，早于 WP 查询，
 * 条件标签尚不可用；且密码校验通过后核心本身也不再下发 no-cache，没有别的信号可依赖。
 */
function jinyu_page_cache_has_password_cookie(): bool {
    foreach ( array_keys( $_COOKIE ) as $k ) {
        if ( is_string( $k ) && 0 === strpos( (string) $k, 'wp-postpass_' ) ) {
            return true;
        }
    }
    return false;
}

/**
 * 是否有组件声明「本页不可缓存」。
 *
 * DONOTCACHEPAGE 是整页缓存生态的事实标准（WooCommerce 的购物车/结算、各类会员与表单插件
 * 都会定义它），不认这个常量就会把明确拒绝缓存的个性化页面缓存后下发给所有访客。
 * 该常量名不属本插件命名空间，只能按约定直读。
 *
 * @return bool true = 跳过缓存（既不读也不写）
 */
function jinyu_page_cache_is_bypass(): bool {
    if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
        return true;
    }
    return (bool) apply_filters( 'jinyu_page_cache_bypass', false );
}

/**
 * 当前响应是否已声明「不可缓存」（Cache-Control 含 no-cache / no-store / private）。
 *
 * WordPress 的 nocache_headers() 下发的是 `no-cache, must-revalidate, max-age=0, no-store, private`，
 * 核心自身在「已登录」「404」「密码保护文章」三种情况下都会调用它；第三方插件同样靠它表达
 * 「别缓存我」。把这类响应当可缓存写进磁盘，等于覆盖了对方明确的意图。
 *
 * 只能在响应头已下发之后判定：simple 模式的 capture 位于 template_redirect（核心 send_headers
 * 已执行）满足该前提；edge 模式的回调本身挂在 send_headers 动作上、执行时核心 header() 已发出。
 */
function jinyu_page_cache_response_forbids_cache(): bool {
    foreach ( headers_list() ?: [] as $h ) {
        if ( 0 !== stripos( $h, 'Cache-Control:' ) ) {
            continue;
        }
        $val = strtolower( trim( substr( $h, 14 ) ) );
        if ( false !== strpos( $val, 'no-cache' ) || false !== strpos( $val, 'no-store' ) || false !== strpos( $val, 'private' ) ) {
            return true;
        }
    }
    return false;
}

/**
 * 明确下发「本响应不得被任何一层缓存」。
 *
 * 边缘模式下若只是「不下发缓存指令」，Web 服务器仍会按 fastcgi_cache_valid 缓存 200 响应，
 * 必须显式声明 private + no-store（Nginx 与 Apache 都会据此拒绝缓存）。
 */
function jinyu_page_cache_send_private_headers(): void {
    if ( function_exists( 'header_remove' ) ) {
        header_remove( 'Cache-Control' );
        header_remove( 'Pragma' );
        header_remove( 'Expires' );
    }
    header( 'Cache-Control: private, no-store, max-age=0' );
}

/**
 * REST 请求一律不可缓存（任意模式均生效）。
 *
 * 为什么必须单独挂一个钩子：REST 由 rest_api_loaded 挂在 parse_request（优先级 10）上处理，
 * 它会 rest_get_server()->serve_request() 之后直接 die()，于是 send_headers 与
 * template_redirect 上的全部守卫根本来不及执行 —— 实测 `?rest_route=/wp/v2/posts` 会带着
 * `Content-Type: application/json` 被 Nginx 按 200 缓存落盘并跨访客下发；若该端点需要鉴权
 * （应用密码 / Basic Auth，不带 wordpress_logged_in cookie），响应就会被缓存后回放给所有人。
 *
 * 本钩子优先级 9，抢在 rest_api_loaded 之前执行；此时 WP::parse_request() 已把
 * query_vars['rest_route'] 填好（`/wp-json/` 与 `?rest_route=` 两种形式都会落到这里）。
 */
function jinyu_page_cache_rest_guard(): void {
    $wp = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;
    if ( ! $wp instanceof WP || empty( $wp->query_vars['rest_route'] ) ) {
        return;
    }
    jinyu_page_cache_send_private_headers();
}

function jinyu_page_cache_serve(): void {
    if ( ! jinyu_companion_is_checked( 'page_cache_enable' ) ) {
        return;
    }
    // 安装 / 自动升级进行中（wp-admin/install.php、upgrade.php）不参与缓存：
    // 此时写缓存会把半成品页固化，升级完成后旧页可能残留到 TTL 结束。
    if ( function_exists( 'wp_installing' ) && wp_installing() ) {
        return;
    }
    $req_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
    $req_uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    if ( 'GET' !== $req_method ) {
		return;
    }
    if ( is_user_logged_in() || is_admin() || jinyu_page_cache_has_commenter_cookie() ) {
		return;
    }
    // 已输入文章密码的访客：正文是解锁后的私有内容，绝不能读写按 URI 共享的缓存页。
    if ( jinyu_page_cache_has_password_cookie() ) {
		return;
    }
    // 其它组件声明的「本页不可缓存」（DONOTCACHEPAGE / 过滤器）。
    if ( jinyu_page_cache_is_bypass() ) {
		return;
    }
    if ( strpos( $req_uri, 'wp-admin' ) !== false ) {
		return;
    }
    if ( strpos( $req_uri, 'wp-login' ) !== false ) {
		return;
    }
    // 搜索页不缓存：serve 端早于 WP 查询（is_search() 不可用），按 URI 特征判定；
    // 每个搜索词一个 URI，缓存只会膨胀且命中意义不大。
    if ( jinyu_page_cache_is_search_uri() ) {
		return;
    }
    if ( jinyu_page_cache_is_non_html_uri() ) {
		return;
    }
    if ( jinyu_page_cache_is_excluded() ) {
		return;
    }

    $file     = jinyu_page_cache_file();
    $servable = ( '' !== $file && is_readable( $file ) ) ? $file : '';
    $ttl      = max( 60, (int) jinyu_companion_get_option( 'page_cache_ttl', '3600' ) );
    $expired  = false;
    if ( $servable ) {
        $written = (int) @filemtime( $servable );
        if ( $written > 0 && time() - $written > $ttl ) {
            $expired = true;
        }
    }

    if ( $servable && ! $expired ) {
        // 命中：直接回吐缓存页（含 304 协商）。
        jinyu_page_cache_emit( $servable, false );
        exit;
    }

    // 未命中或已过期：尝试成为「重建者」。拿到锁的本进程负责重渲染，
    // 其余并发请求要么回吐略旧的缓存（过期但有文件）、要么短暂轮询等新页，避免惊群。
    $identity = jinyu_page_cache_identity();
    $lock     = jinyu_page_cache_acquire_build_lock( $identity );
    if ( null !== $lock ) {
        // 标记本进程为「重建者」；锁在 capture 的 shutdown（写盘之后）释放，
        // 避免释放后他人抢锁时文件尚未落盘，导致并发写同一文件。
        jinyu_page_cache_build_lock( $lock );
        return; // 交给 WP 正常渲染，capture 在 shutdown 写盘并释放锁
    }

    // 别人正在重建：过期但有文件 → 回吐旧页（宁可略旧，也不让 N 个进程重渲染）。
    if ( $servable ) {
        jinyu_page_cache_emit( $servable, true );
        exit;
    }
    // 冷缓存（文件根本不存在）被他人占用：短暂轮询，等到就发新页，否则退回 WP 渲染。
    for ( $i = 0; $i < 15 && ! is_readable( $file ); $i++ ) {
        usleep( 100000 );
    }
    if ( is_readable( $file ) ) {
        jinyu_page_cache_emit( $file, false );
        exit;
    }
    // 极端冷启动竞态：放弃互斥，直接渲染（写盘仍用 LOCK_EX 防半截文件）。
}

/**
 * 读取缓存文件并回吐响应（含 ETag / 304 协商 / Content-Type 还原）。
 *
 * @param string $file  缓存 HTML 绝对路径
 * @param bool   $stale true=内容可能略旧，标 STALE（仍走 304：客户端若已持有相同字节则无需重传）
 */
function jinyu_page_cache_emit( string $file, bool $stale ): void {
    $body = (string) file_get_contents( $file );

    // 响应头已在极早阶段发出（主题或插件提前 echo 过）：ETag / Cache-Control 都设不了，
    // 但缓存内容本身有效，降级为「不带缓存指令的直接输出」而不是整条命中路径作废。
    if ( headers_sent() ) {
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控/对外原始输出（缓存页），无需转义
        exit;
    }

    $meta_file    = $file . '.meta';
    $content_type = 'text/html; charset=utf-8';
    $hdrs         = [];
    if ( is_readable( $meta_file ) ) {
        $raw = (string) file_get_contents( $meta_file );
        $dec = json_decode( $raw, true );
        if ( is_array( $dec ) ) {
            $ct = isset( $dec['ct'] ) ? (string) $dec['ct'] : '';
            if ( '' !== $ct && strlen( $ct ) < 191 ) {
                $content_type = $ct;
            }
            if ( ! empty( $dec['hdrs'] ) && is_array( $dec['hdrs'] ) ) {
                $hdrs = $dec['hdrs'];
            }
        } elseif ( '' !== $raw && strlen( $raw ) < 191 ) {
            // 旧格式兼容：.meta 仅存纯 Content-Type 字符串
            $content_type = $raw;
        }
    }

    // WP 默认给前台发的 no-cache 指令会阻止客户端带条件请求，必须先摘掉，否则 304 永不触发
    if ( function_exists( 'header_remove' ) ) {
        header_remove( 'Cache-Control' );
        header_remove( 'Expires' );
        header_remove( 'Pragma' );
        header_remove( 'Last-Modified' );
    }

    // 复用已读进内存的正文算摘要，避免 md5_file 再把同一份文件读一遍
    $etag = '"' . md5( $body ) . '"';

    $if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
    if ( '' !== $if_none_match && jinyu_page_cache_if_none_match_match( $if_none_match, $etag ) ) {
        header( 'HTTP/1.1 304 Not Modified', true, 304 );
        jinyu_page_cache_send_headers( $etag );
        exit;
    }

    header( 'X-Jinyu-Cache: ' . ( $stale ? 'STALE' : 'HIT' ) );
    jinyu_page_cache_send_headers( $etag );
    header( 'Content-Type: ' . $content_type );

    // 回放捕获时记录的站点安全响应头（CSP / X-Frame-Options / Permissions-Policy 等）。
    // 这些头由主题/PHP 在渲染期下发，缓存命中路径绕过了那段代码；不回放会让缓存页裸奔（CSP 失效）。
    // 头名/值均来自站点自身 header() 调用（非用户输入），原样重放下发即可，且本机 nginx 未设这些头、无重复头风险。
    foreach ( $hdrs as $jinyu_h_name => $jinyu_h_val ) {
        if ( is_string( $jinyu_h_name ) && is_string( $jinyu_h_val ) && '' !== $jinyu_h_val ) {
            header( $jinyu_h_name . ': ' . $jinyu_h_val ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 源自站点自身 header()，非外部输入
        }
    }

    // 标记本次为缓存命中：性能采样（inc/fun/live.php）据此跳过，
    // 否则几毫秒的缓存响应会把「实时心跳」曲线压成一条直线。
    define( 'JINYU_CACHE_HIT', true );
    echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控/对外原始输出（缓存页），无需转义
    // 缓存命中时已在 init 阶段 echo+exit，template_redirect 不会触发，
    // 而来源统计(jinyu_track_visit_source)挂在该钩子上，故在此显式调用，
    // 确保匿名访客的来源 PV/UV 在缓存命中时仍被记录（写库已在函数内延迟到 shutdown，exit 后仍会执行）。
    if ( function_exists( 'jinyu_track_visit_source' ) ) { // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 受控/对外原始输出（缓存页），无需转义
        jinyu_track_visit_source();
    }
    // UV Cookie 补写：命中路径绕过了 wp 钩子，不补写则每个缓存 PV 都会被 shutdown 统计记为新 UV。
    if ( function_exists( 'jinyu_stats_set_uv_cookie_now' ) ) {
        jinyu_stats_set_uv_cookie_now();
    }
    exit;
}

function jinyu_page_cache_capture(): void {
    if ( ! jinyu_companion_is_checked( 'page_cache_enable' ) ) {
        return;
    }
    // 安装 / 自动升级进行中不写缓存，与 serve 端判定保持一致（读写同判据）。
    if ( function_exists( 'wp_installing' ) && wp_installing() ) {
        return;
    }
    // 检测到第三方整页缓存插件时自动让位，避免两层 HTML 缓存冲突 / 内容不同步。
    if ( function_exists( 'jinyu_has_external_page_cache' ) && jinyu_has_external_page_cache() ) {
		return;
    }
    $req_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
    if ( 'GET' !== $req_method ) {
		return;
    }
    if ( is_user_logged_in() || is_admin() || jinyu_page_cache_has_commenter_cookie() ) {
		return;
    }
    // 密码保护：带密码 cookie（已解锁）或当前文章仍需密码（加锁页）都不写缓存。
    // 核心对加锁页会下发 no-cache（下面的响应头判定也会拦到），这里按语义再显式拦一道，
    // 使行为不依赖核心版本的具体实现。
    if ( jinyu_page_cache_has_password_cookie() ) {
		return;
    }
    if ( is_singular() && post_password_required() ) {
		return;
    }
    // DONOTCACHEPAGE（整页缓存生态的通用约定）与过滤器声明的跳过。
    if ( jinyu_page_cache_is_bypass() ) {
		return;
    }
    // 已由 WordPress 或其它组件下发「不可缓存」响应头（nocache_headers()）：尊重声明，不写盘。
    if ( jinyu_page_cache_response_forbids_cache() ) {
		return;
    }
    // 这些响应体不该进整页缓存：404 / feed / 预览 / robots / trackback / 搜索
    if ( is_404() || is_feed() || is_preview() || is_robots() || is_trackback() || is_search() ) {
		return;
    }
    if ( jinyu_page_cache_is_search_uri() ) {
		return;
    }
    // URI 判据之上的最后一道：至此 WP 查询已完成，REST 请求标志位才可用
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
    }
    if ( jinyu_page_cache_is_non_html_uri() ) {
		return;
    }
    if ( jinyu_page_cache_is_excluded() ) {
		return;
    }

    $buffer_level = ob_get_level();
    ob_start(
        static function ( $html ) {
			if ( strlen( $html ) < 500 ) {
				return $html;
			}

			$file = jinyu_page_cache_file();
			if ( '' === $file ) {
				return $html;   // 缓存目录建不出来：静默降级，但已由 note_blocked 记录原因
			}

			// 原子落盘：先写临时文件再 rename（同目录内 rename 为原子操作）。
			// 若直接就地覆盖，读者（命中路径的 file_get_contents 不加锁）会在 TTL 过期瞬间
			// 读到「已截断但尚未写完」的半截 HTML，页面结构直接崩坏。
			$tmp = $file . '.tmp';
			if ( false === @file_put_contents( $tmp, $html, LOCK_EX ) ) {
				// 目录可写但写不进去 = 磁盘满 / inode 耗尽，比目录缺失更隐蔽，必须上报
				jinyu_page_cache_note_blocked( 'write_fail' );
				return $html;
			}
			@chmod( $tmp, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- 前端写缓存后修正权限，WP_Filesystem 在前台有凭据弹窗风险
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 原子替换缓存文件，WP_Filesystem 的 move() 非原子且前台会弹凭据
				@wp_delete_file( $tmp );
				jinyu_page_cache_note_blocked( 'write_fail' );
				return $html;
			}

			$ct   = jinyu_page_cache_content_type();
			$hdrs = jinyu_page_cache_capture_headers();
			if ( '' !== $ct && strlen( $ct ) < 191 ) {
				$store = [ 'ct' => $ct ];
				if ( ! empty( $hdrs ) ) {
					$store['hdrs'] = $hdrs;
				}
				// 与正文同样原子替换，避免命中路径读到半截 JSON（json_decode 失败会退回默认 Content-Type）
				$meta_tmp = $file . '.meta.tmp';
				if ( false !== @file_put_contents( $meta_tmp, (string) wp_json_encode( $store ), LOCK_EX )
                && @rename( $meta_tmp, $file . '.meta' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 原子替换缓存元数据，WP_Filesystem 的 move() 非原子且前台会弹凭据
					@chmod( $file . '.meta', 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- 前端写缓存后修正权限，WP_Filesystem 在前台有凭据弹窗风险
				} else {
					@wp_delete_file( $meta_tmp );
				}
			}

			return $html;
		}
    );

    // 显式闭合本函数打开的缓冲区（wp.org：不允许 ob_start 留到请求结束由 PHP 关闭）。
    // shutdown 里兜底统一收尾：已由其他代码关闭时，下面的循环自然不执行；回调仍在该步写入缓存文件，
    // 写入时机与「靠 PHP 请求结束隐式关闭」完全一致，行为不变。
    add_action(
        'shutdown',
        static function () use ( $buffer_level ): void {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_flush();
			}
			// 写盘完成后才释放重建锁，确保并发请求读到的是完整文件。
			$lh = jinyu_page_cache_build_lock();
			if ( null !== $lh ) {
				jinyu_page_cache_release_build_lock( $lh );
			}
		},
        999
    );
}

/**
 * 边缘模式：为可缓存的匿名 GET 请求下发 Cache-Control（含 stale-while-revalidate / stale-if-error）。
 *
 * 与简单模式不同，边缘模式下 PHP 照常渲染，由 Nginx fastcgi_cache / Apache mod_cache 缓存整份响应
 * （含 CSP/ETag 等响应头一并冻存）。这里只负责把「该缓存多久、过期后能否先用旧页」告诉服务器。
 * 仅在可缓存判定通过时下发；其余请求（登录/后台/搜索/排除项）一律不下发，服务器便不会缓存它们。
 */
function jinyu_page_cache_edge_headers(): void {
    if ( 'edge' !== jinyu_page_cache_mode() ) {
        return;
    }

    // 关键前提：边缘模式下「不下发缓存指令」不等于「不要缓存」。nginx 的 fastcgi_cache_valid 200
    // 会把任何未被明确禁止的 200 响应缓存起来，所以下面每一条「不该缓存」的分支都必须**显式**
    // 下发 private + no-store，否则判断形同虚设，缓存决定权会落回服务器配置手里。
    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
    if ( 'GET' !== $method ) {
        // 非 GET（尤其是 HEAD）：nginx 的 fastcgi_cache_key 不含请求方法，冷缓存时一次 HEAD 就会
        // 让服务器按 GET 的同一个 key 缓存一份「只有响应头、没有 body」的响应，之后所有 GET 都命中
        // 这份空响应 —— 表现为整站白屏，并一直持续到 TTL 结束。
        jinyu_page_cache_send_private_headers();
        return;
    }

    if ( is_admin() || is_user_logged_in() || jinyu_page_cache_has_commenter_cookie() ) {
        jinyu_page_cache_send_private_headers();
        return;
    }

    $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
    if ( false !== stripos( $uri, 'wp-admin' ) || false !== stripos( $uri, 'wp-login' ) ) {
        jinyu_page_cache_send_private_headers();
        return;
    }
    if ( jinyu_page_cache_is_search_uri() || jinyu_page_cache_is_non_html_uri() || jinyu_page_cache_is_excluded() ) {
        jinyu_page_cache_send_private_headers();
        return;
    }

    // 核心或其它组件已声明不可缓存（404 / 密码保护文章的加锁页 / 第三方 nocache_headers）：
    // 它们自己已下发 no-cache，原样保留即可。本回调挂在 send_headers 动作上，执行时核心的
    // header() 已发出，这里读得到真实值；若继续下发 public，等于把对方明确拒绝缓存的响应交给服务器缓存。
    if ( jinyu_page_cache_response_forbids_cache() ) {
        return;
    }
    // 已输入正确密码的访客：核心此时不再下发 no-cache（解锁条件已满足），但正文是解锁后的
    // 私有内容，必须显式禁止任何一层缓存，否则会被按 URI 冻存后下发给所有匿名访客。
    if ( jinyu_page_cache_has_password_cookie() || jinyu_page_cache_is_bypass() ) {
        jinyu_page_cache_send_private_headers();
        return;
    }

    $ttl = max( 60, (int) jinyu_companion_get_option( 'page_cache_ttl', '3600' ) );

    if ( function_exists( 'header_remove' ) ) {
        header_remove( 'Cache-Control' );
        header_remove( 'Pragma' );
        header_remove( 'Expires' );
    }
    header( 'Cache-Control: public, max-age=' . $ttl . ', stale-while-revalidate=' . $ttl . ', stale-if-error=' . ( $ttl * 2 ) );
}

/**
 * 边缘模式的「迟到守卫」。
 *
 * 边缘模式的 public 指令在 send_headers 就下发了，而 DONOTCACHEPAGE 与第三方的 nocache_headers()
 * 往往要等到主题/插件渲染期才出现。这里在 template_redirect 末尾复查一次：一旦发现不可缓存信号，
 * 就把已下发的 public 改写成 private + no-store，让服务器最终不会缓存这份响应。
 * 响应头已发出（早于本钩子的直接输出）时无法再改，直接放弃。
 */
function jinyu_page_cache_edge_late_guard(): void {
	if ( headers_sent() ) {
		return;
	}
	if ( jinyu_page_cache_response_forbids_cache() || jinyu_page_cache_is_bypass() ) {
		jinyu_page_cache_send_private_headers();
	}
}

/**
 * 边缘模式按 URL 精确清除服务器缓存。
 *
 * - Nginx（fastcgi_cache，flat path 无 levels）：缓存文件名 = md5(cache_key)，直接 unlink 即可精确删。
 * - Apache（mod_cache_disk）：按 URL 精确删不可靠（文件哈希布局不透明），退化为全量刷新，
 *   配合 stale-while-revalidate 体感无感（见 jinyu_edge_purge_all）。
 *
 * @param string $url 待清除缓存的页面 URL.
 */
function jinyu_edge_purge_url( string $url ): void {
    if ( 'edge' !== jinyu_page_cache_mode() ) {
        return;
    }
    if ( 'apache' === jinyu_page_cache_edge_server_effective() ) {
        jinyu_edge_purge_all();
        return;
    }

    $parts  = (array) wp_parse_url( $url );
    $scheme = isset( $parts['scheme'] ) && 'https' === $parts['scheme'] ? 'https' : (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
    $host   = isset( $parts['host'] ) ? (string) $parts['host'] : (string) wp_parse_url( home_url(), PHP_URL_HOST );
    $path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
    $query  = isset( $parts['query'] ) ? (string) $parts['query'] : '';
    $req_uri = $path . ( '' !== $query ? '?' . $query : '' );

    // 须与片段里的 fastcgi_cache_key "$scheme$host$request_uri" 完全一致.
    $key  = $scheme . $host . $req_uri;
    $file = jinyu_page_cache_edge_path() . '/' . md5( $key );
    if ( is_file( $file ) ) {
        @wp_delete_file( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 边缘缓存精确清理，无可替代 WP API 且无外部输入
    }
}

/**
 * 边缘模式全量清除服务器缓存（Nginx / Apache 通用）。
 * 直接删边缘缓存目录下的全部缓存文件。简单模式下本函数直接返回，不做任何事。
 *
 * 必须递归：Nginx 是 flat 文件（一层），Apache mod_cache_disk 会按
 * CacheDirLevels / CacheDirLength 铺成多层子目录（默认 5 层 × 每层 3 字符）。
 * 只删顶层的 is_file 会让 Apache 上的「全量刷新」实际变成空操作 —— 缓存一直留到 TTL 自然过期。
 */
function jinyu_edge_purge_all(): void {
    if ( 'edge' !== jinyu_page_cache_mode() ) {
        return;
    }
    $dir = realpath( jinyu_page_cache_edge_path() );
    if ( false === $dir || ! is_dir( $dir ) ) {
        return;
    }
    // 护栏：只允许清 wp-content/cache 之下的目录，避免「边缘缓存目录」被误填成
    // wp-content 或站点根目录时把整站文件递归删空。
    $cache_root = realpath( WP_CONTENT_DIR . '/cache' );
    if ( false === $cache_root || 0 !== strpos( $dir . DIRECTORY_SEPARATOR, $cache_root . DIRECTORY_SEPARATOR ) ) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $entry ) {
        /** @var SplFileInfo $entry */
        $path = $entry->getPathname();
        if ( $entry->isDir() ) {
            @rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors -- 递归删缓存目录树（路径已限定在 wp-content/cache 之下），WP_Filesystem::delete 需要凭据且卸载/CLI 上下文不可用
            continue;
        }
        @wp_delete_file( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 边缘缓存全量清理，无可替代 WP API 且无外部输入
    }
}

/**
 * 生成边缘模式的服务器配置片段（opt-in 文本，用户自行粘贴，插件绝不自动写服务器配置）。
 *
 * @param string $server nginx | apache 待生成片段的服务器类型.
 */
function jinyu_page_cache_edge_snippet( string $server ): string {
    $path = jinyu_page_cache_edge_snippet_path();
    $ttl  = max( 60, (int) jinyu_companion_get_option( 'page_cache_ttl', '3600' ) );

    if ( 'apache' === $server ) {
        // 注：Plugin Check（wp.org）不允许 heredoc，配置片段以逐行数组拼接。
        $lines = array(
            '# ===== 金玉配套插件 · 边缘缓存（Apache / mod_cache_disk）=====',
            '# 需启用 mod_cache / mod_cache_disk。',
            '# 本段只能放在 vhost（server）配置里。放进 .htaccess 会 500 ——',
            '# CacheQuickHandler / CacheLock / CacheIgnoreHeaders 在 Apache 里仅允许出现于 server / vhost 上下文。',
            '<IfModule mod_cache_disk.c>',
            "\t" . 'CacheQuickHandler off',
            "\t" . 'CacheLock on',
            "\t" . 'CacheDefaultExpire ' . $ttl,
            // 共享缓存必须剥掉 Set-Cookie：mod_cache 默认会把 Set-Cookie 一起存进缓存并回放给其他访客。
            "\t" . 'CacheIgnoreHeaders Set-Cookie',
            // 必须与插件设置里的「边缘缓存目录」完全一致，否则插件清理不到它写下的缓存文件。
            "\t" . 'CacheRoot "' . $path . '"',
            "\t" . '<Location />',
            "\t\t" . 'CacheEnable disk',
            "\t\t" . 'CacheHeader on',
            "\t" . '</Location>',
            '',
            "\t" . '# ── 以下情况一律不缓存 ──',
            // 环境变量名必须是 mod_cache 约定的字面量 no-cache（Apache 2.2.12+ 起支持）。
            // 写成别的名字再配 CacheDisable env=xxx 是无效写法：CacheDisable 只接受 URL 前缀或 on，
            // 没有 env= 参数，整条规则会静默失效（不报错，也不生效）。
            "\t" . 'SetEnvIf Cookie "wordpress_logged_in" no-cache',
            "\t" . 'SetEnvIf Cookie "comment_author_"     no-cache',
            "\t" . 'SetEnvIf Cookie "wp-postpass_"        no-cache',
            // 只有 GET 进缓存。缓存 key 不含请求方法，若让 HEAD 走缓存，冷缓存时一次 HEAD 会按 GET 的
            // key 存入一份「只有响应头、没有 body」的响应，随后所有 GET 都命中空页 —— 整站白屏。
            "\t" . 'SetEnvIf Request_Method "!^(GET)$"    no-cache',
            // 后台 / REST / XML-RPC 不缓存。REST 的 query string 形式（/?rest_route=/wp/v2/posts）要单独列，
            // 只写 /wp-json 会漏，鉴权端点的 JSON 一旦被缓存就会跨访客下发。
            "\t" . 'SetEnvIf Request_URI "^/(wp-admin|wp-login|wp-json|xmlrpc.php)" no-cache',
            "\t" . 'SetEnvIf Request_URI "rest_route="    no-cache',
            '</IfModule>',
        );

        return implode( "\n", $lines );
    }

    // 默认 Nginx：fastcgi_cache 缓存 PHP 响应，flat path 便于按 md5 精确清除.
    // 注：Plugin Check（wp.org）不允许 heredoc，配置片段以逐行数组拼接。
    $lines = array(
        '# ===== 金玉配套插件 · 边缘缓存（Nginx / fastcgi_cache）=====',
        '# 1) 放入 http 段（或 conf.d 独立文件）：',
        'fastcgi_cache_path ' . $path . ' keys_zone=jinyu_edge:10m max_size=512m inactive=60m use_temp_path=off;',
        '',
        'map $http_cookie $jinyu_skip_cookie {',
        "\t" . 'default               0;',
        "\t" . '~*wordpress_logged_in 1;',
        "\t" . '~*comment_author_     1;',
        "\t" . '~*wp-postpass_        1;',
        '}',
        // 只有 GET 进缓存。这张 map 是必需的，不能省：
        // fastcgi_cache_methods 的默认值是「GET HEAD」，且 HEAD 会被 nginx 强制加回列表，
        // 即便显式写 fastcgi_cache_methods GET 也去不掉它。
        // 而 fastcgi_cache_key 里没有请求方法，一旦让 HEAD 落盘，冷缓存时一次 HEAD 就会按 GET 的
        // 同一个 key 存入一份「只有响应头、没有 body」的响应，随后所有 GET 都命中空页 —— 整站白屏。
        'map $request_method $jinyu_skip_method {',
        "\t" . 'GET     0;',
        "\t" . 'default 1;',
        '}',
        '',
        '# 2) 放入「处理 PHP 的 location 内」（如 location ~ \.php$ 或 location /）：',
        'set $jinyu_skip 0;',
        'if ($jinyu_skip_cookie) { set $jinyu_skip 1; }',
        'if ($jinyu_skip_method) { set $jinyu_skip 1; }',
        // rest_route= 是 REST 的 query string 形式（/?rest_route=/wp/v2/posts），只匹配 /wp-json 会漏，
        // 鉴权端点的 JSON 一旦被缓存就会跨访客下发。
        'if ($request_uri ~* "/wp-admin|/wp-login|/wp-json|/xmlrpc.php|rest_route=") { set $jinyu_skip 1; }',
        '',
        'fastcgi_cache jinyu_edge;',
        'fastcgi_cache_key "$scheme$host$request_uri";',
        'fastcgi_cache_lock on;',
        'fastcgi_cache_use_stale updating error timeout http_500 http_503;',
        'fastcgi_cache_valid 200 301 302 ' . $ttl . ';',
        'fastcgi_cache_bypass $jinyu_skip;',
        'fastcgi_no_cache $jinyu_skip;',
        'fastcgi_cache_revalidate on;',
        '',
        '# 3) 放入「server 段（location 之外）」：标记命中状态。',
        '#    放在 server 层才能保留你已有的 HSTS 等 server 级 add_header（放 location 内会覆盖它们）。',
        '#    always 让 404 / 5xx 也带上该头，便于排查；不加的话只有 2xx/3xx 能看到。',
        'add_header X-Jinyu-Cache $upstream_cache_status always;',
        '',
        '# 改完先 nginx -t 校验，再 nginx -s reload。',
    );

    return implode( "\n", $lines );
}
