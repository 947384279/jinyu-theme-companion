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

add_action('init', 'jinyu_page_cache_serve');
add_action('template_redirect', 'jinyu_page_cache_capture');

// 内容变更自动失效：发布/更新/删除文章、评论增删审核都会触碰整页缓存。
// 不依赖主题的 jinyu_cache_flush()，本插件自闭环。
add_action('save_post', 'jinyu_page_cache_flush', 999);
add_action('deleted_post', 'jinyu_page_cache_flush');
add_action('comment_post', static function ( $comment_id ): void {
	$comment = get_comment( $comment_id );
	if ( $comment && 1 === (int) $comment->comment_approved ) {
		jinyu_page_cache_flush();
	}
}, 999);
add_action('wp_set_comment_status', static function ( $comment_id, $status ): void {
	if ( in_array( $status, [ 'approve', '1', 'hold', '0', 'spam', 'trash' ], true ) ) {
		jinyu_page_cache_flush();
	}
}, 999, 2);

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
function jinyu_page_cache_epoch(): string
{
    $epoch = get_option('jinyu_page_cache_epoch');
    if (!is_string($epoch) || '' === $epoch) {
        $epoch = '1';
        update_option('jinyu_page_cache_epoch', $epoch, false);
    }
    return $epoch;
}

/**
 * 清理历史缓存文件（保留当前代际 + 当前 nonce tick 的全部命中）。
 *
 * 代际切换后旧文件本就读不到，GC 只是回收磁盘，延迟执行不影响正确性。
 */
function jinyu_page_cache_gc(): void
{
    $last = (int) get_option('jinyu_page_cache_gc_at', 0);
    if (time() - $last < JINYU_PAGE_CACHE_GC_INTERVAL) {
        return;
    }
    update_option('jinyu_page_cache_gc_at', time(), false);

    $file = jinyu_page_cache_file();
    if ('' === $file) {
        return;
    }

    $dir  = dirname($file);
    $keep = basename($file);

    foreach (glob($dir . '/page_*') ?: [] as $f) {
        $name = basename($f);
        // 元数据文件形如 <文件名>.meta，还原成原文件名后一并比对
        if ('.meta' === substr($name, -5)) {
            $name = substr($name, 0, -5);
        }
        if ($name !== $keep && 0 !== strpos($name, $keep)) {
            @unlink($f);
        }
    }
}

function jinyu_page_cache_flush(): void
{
    update_option('jinyu_page_cache_epoch', (string) time(), false);
    // GC 自身带频率闸门，此处调用不会每次 save_post 都扫目录
    jinyu_page_cache_gc();
}

/**
 * 全局缓存失效入口（插件独占，主题不可覆盖）。
 *
 * 存在意义：theme-shims.php 的 jinyu_cache_flush() 带 function_exists 守卫，
 * 主题一旦定义了同名函数，插件那两行 llms transient 的 delete 就永远不会执行
 * （发布文章后 llms.txt 最长 TTL 内仍是旧内容）。故内部调用一律走本函数 ——
 * 函数名带插件前缀，主题不可能定义，失效链不会被劫持。
 *
 * @return int 本次清理的缓存条目数。
 */
function jinyu_companion_cache_flush(): int
{
    $n = 0;

    // 整页缓存：推进代际号，旧代际的全部 key 一次性整体失效。
    if (function_exists('jinyu_page_cache_flush')) {
        jinyu_page_cache_flush();
        ++$n;
    }

    // llms.txt / llms-full.txt 输出缓存。
    delete_transient('jinyu_llms_index_cache');
    delete_transient('jinyu_llms_full_cache');
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
function jinyu_page_cache_identity(): string
{
    $host = '';
    if (function_exists('wp_parse_url')) {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }
    if ('' === $host) {
        $host = trim((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    }

    $blog = function_exists('is_multisite') && is_multisite() ? ':' . (int) get_current_blog_id() : '';

    return md5($host . $blog . '|' . jinyu_page_cache_canonical_uri());
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
function jinyu_page_cache_file(): string
{
    static $path = '';

    if ('' !== $path) {
        return $path;
    }

    $dir = jinyu_page_cache_dir();
    // 目录不存在则尝试创建；只读文件系统 / 权限不足时静默放弃缓存，
    // 但仍要留一条可观测记录（jinyu_page_cache_note_blocked），否则用户开着开关却始终不生效也不自知
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        jinyu_page_cache_note_blocked('no_mkdir');
        return '';
    }

    $tick = function_exists('wp_nonce_tick') ? wp_nonce_tick() : (int) ceil(time() / (DAY_IN_SECONDS / 2));

    $path = $dir . '/page_' . JINYU_PAGE_CACHE_VERSION . '_' . jinyu_page_cache_epoch() . '_' . $tick . '_' . jinyu_page_cache_identity() . '.html';

    return $path;
}

/**
 * 缓存目录的绝对路径（只算路径，不负责创建）。
 *
 * 与文件名的拼接分离出来，供状态诊断、GC、管理端提示复用同一处定义 ——
 * 目录位置散落在多个函数里，改一处漏一处就会诊断到不存在的路径。
 */
function jinyu_page_cache_dir(): string
{
    return WP_CONTENT_DIR . '/cache/jinyu/page';
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
function jinyu_page_cache_note_blocked(string $reason): void
{
    $prev    = (string) get_option('jinyu_page_cache_blocked_reason', '');
    $at      = (int) get_option('jinyu_page_cache_blocked_at', 0);
    $expired = time() - $at > JINYU_PAGE_CACHE_NOTE_INTERVAL;

    // 原因变了就没法比时间 —— 新原因可能一直没被记过，必须立刻更新
    if ($prev === $reason && !$expired) {
        return;
    }

    update_option('jinyu_page_cache_blocked_reason', $reason, false);
    update_option('jinyu_page_cache_blocked_at', time(), false);

    // 服务器错误日志留痕，方便排查时 grep；
    // 已经写过同样的原因就别重复刷，否则日志本身成为问题。
    $msg = sprintf(
        '[jinyu] 整页缓存不可用（%s）：目录 %s%s',
        $reason,
        jinyu_page_cache_dir(),
        'no_mkdir' === $reason ? ' 无法创建' : ' 不可写入'
    );
    if ('write_fail' === $reason) {
        $msg = '[jinyu] 整页缓存写入失败：可能是磁盘已满或 inode 耗尽';
    }
    if ('write_fail' === $prev || !in_array($reason, [ $prev ], true)) {
        @error_log($msg);
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
function jinyu_page_cache_status(): array
{
    $dir = jinyu_page_cache_dir();

    $status = [
        'dir'        => $dir,
        'enabled'    => function_exists('jinyu_companion_is_checked') && jinyu_companion_is_checked('page_cache_enable'),
        'ready'      => false,
        'writable'   => false,
        'files'      => 0,
        'last_write' => 0,
        'reason'     => 'disabled',
    ];

    // 没开开关就不该有任何噪音：目录不存在是常态，不是问题
    if (!$status['enabled']) {
        return $status;
    }

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        $status['reason'] = 'no_mkdir';
        return $status;
    }

    if (!is_writable($dir)) {
        $status['reason'] = 'not_writable';
        return $status;
    }

    $status['ready']    = true;
    $status['writable'] = true;
    $status['reason']   = 'ok';

    // 文件数 / 最近写入需要扫目录，带闸门：只在超过间隔时重算，结果复用
    $probe = (int) get_option('jinyu_page_cache_probe_at', 0);
    if (time() - $probe > JINYU_PAGE_CACHE_PROBE_INTERVAL) {
        $files  = glob($dir . '/page_*.html') ?: [];
        $newest = 0;
        foreach ($files as $f) {
            $m = (int) @filemtime($f);
            if ($m > $newest) {
                $newest = $m;
            }
        }
        update_option('jinyu_page_cache_probe_at', time(), false);
        update_option('jinyu_page_cache_stat', [
            'files'      => count($files),
            'last_write' => $newest,
        ], false);
    }

    $stat = get_option('jinyu_page_cache_stat');
    if (is_array($stat)) {
        $status['files']      = (int) ($stat['files'] ?? 0);
        $status['last_write'] = (int) ($stat['last_write'] ?? 0);
    }

    return $status;
}

/**
 * 「缓存不可用」的人类可读修复指引。
 *
 * 只给通用做法，不猜用户的服务器面板类型：权限问题的根因是 php-fpm 运行用户
 * 与目录属主不一致，SSH 与面板两条路最终都落到同一条命令上。
 */
function jinyu_page_cache_hint(): string
{
    $dir = jinyu_page_cache_dir();
    $up  = WP_CONTENT_DIR . '/cache';

    return sprintf(
        /*
         * translators: 1: 缓存目录绝对路径；2: wp-content 目录路径
         */
        __(
            '整页缓存已开启，但无法把 HTML 写入 <code>%1$s</code>，缓存实际未生效。'
            . '请在 SSH 中执行 <code>mkdir -p %1$s &amp;&amp; chown -R www:www %2$s</code>'
            . '（把 www 换成你的 php-fpm 运行用户）；面板用户可在文件管理器中把 <code>%2$s</code> 改为 775、'
            . '并让属主与 PHP 进程一致。设置完刷新本页即可。',
            'jinyu-theme-companion'
        ),
        esc_html($dir),
        esc_html($up)
    );
}

/**
 * 后台提醒：缓存开关已开但后端不可用。
 *
 * 挂在 admin_notices 上，任何后台页面都能看到；带 1 次 / 24 小时 的去重，
 * 避免用户在每个页面都看到同一条警告。开关关着时不出现。
 */
add_action('admin_notices', 'jinyu_page_cache_admin_notice');

function jinyu_page_cache_admin_notice(): void
{
    if (function_exists('wp_installing') && wp_installing()) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    $s = jinyu_page_cache_status();
    if ($s['ready'] || 'disabled' === $s['reason']) {
        return;
    }

    // 与 note_blocked 共用去重窗口，但不完全依赖它 —— 那边的闸门写的是另一个 option
    if ((int) get_option('jinyu_page_cache_noticed_at', 0) > time() - DAY_IN_SECONDS) {
        return;
    }
    update_option('jinyu_page_cache_noticed_at', time(), false);

    printf(
        '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
        esc_html__('整页缓存未生效', 'jinyu-theme-companion'),
        jinyu_page_cache_hint()
    );
}

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
function jinyu_page_cache_etag_hint(): string
{
    return sprintf(
        /*
         * translators: 1: Nginx 需要追加的配置行
         */
        __(
            '命中时已下发 ETag。若希望浏览器 / CDN 回「304 未修改」以节省流量，需确认 Web 服务器把条件请求头转给了 PHP'
            . '（Nginx 在 server 段追加：<code>%1$s</code>）。未配置不影响缓存生效，仅少一次带宽优化；Apache 一般无需设置。',
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
function jinyu_page_cache_uri_parts(): array
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (!is_string($uri) || '' === $uri) {
        return ['/', []];
    }
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || '' === $path) {
        $path = '/';
    }
    $params = [];
    $query  = parse_url($uri, PHP_URL_QUERY);
    if (is_string($query) && '' !== $query) {
        parse_str($query, $params);
    }
    return [$path, is_array($params) ? $params : []];
}

/**
 * 多行规则文本 → 规则数组（去空行、去首尾空白、跳过 # 注释行）。
 */
function jinyu_page_cache_rules(string $key): array
{
    $raw = (string) jinyu_companion_get_option($key, '');
    if ('' === trim($raw)) {
        return [];
    }
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
        $line = trim((string) $line);
        if ('' === $line || 0 === strpos($line, '#')) {
            continue;
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * 逗号 / 空格分隔的参数名 → 小写数组。
 */
function jinyu_page_cache_param_names(string $key): array
{
    $raw = (string) jinyu_companion_get_option($key, '');
    if ('' === trim($raw)) {
        return [];
    }
    $out = [];
    foreach (preg_split('/[,\s]+/', trim($raw)) ?: [] as $p) {
        $p = strtolower(trim((string) $p));
        if ('' !== $p) {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * 归一化 URI：剔除「忽略参数」，剩余参数按键排序，使参数顺序不同也命中同一份缓存。
 */
function jinyu_page_cache_canonical_uri(): string
{
    [$path, $params] = jinyu_page_cache_uri_parts();
    $ignore = jinyu_page_cache_param_names('page_cache_ignore_params');
    if ($ignore) {
        foreach (array_keys($params) as $k) {
            if (in_array(strtolower((string) $k), $ignore, true)) {
                unset($params[$k]);
            }
        }
    }
    ksort($params);
    $qs = $params ? http_build_query($params) : '';
    return $path . ('' !== $qs ? '?' . $qs : '');
}

/**
 * 单条路径规则匹配：精确相等，或以规则为目录前缀（/go 命中 /go/123，不命中 /google）。
 * 规则以 * 结尾时按前缀通配。
 */
function jinyu_page_cache_path_match(string $path, string $rule): bool
{
    $rule = trim($rule);
    if ('' === $rule) {
        return false;
    }
    if ('*' === substr($rule, -1)) {
        $rule = substr($rule, 0, -1);
    }
    $r = rtrim($rule, '/');
    if ('' === $r) {
        return false;
    }
    return $path === $r || 0 === strpos($path, $r . '/');
}

/**
 * 例外判定：命中排除路径或排除参数时，本次请求既不读缓存也不写缓存。
 *
 * serve（init）与 capture（template_redirect）共用同一判定，保证读写一致 ——
 * 否则会出现「读了不该读的缓存」或「写了永远读不到的缓存」。
 */
function jinyu_page_cache_is_excluded(): bool
{
    [$path, $params] = jinyu_page_cache_uri_parts();

    foreach (jinyu_page_cache_rules('page_cache_exclude_paths') as $rule) {
        if (jinyu_page_cache_path_match(rtrim($path, '/'), $rule)) {
            return true;
        }
    }

    $skip = jinyu_page_cache_param_names('page_cache_exclude_params');
    if ($skip) {
        foreach (array_keys($params) as $k) {
            if (in_array(strtolower((string) $k), $skip, true)) {
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
function jinyu_page_cache_is_search_uri(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ('' === $uri) return false;
    return (bool) (preg_match('#[?&]s=[^&]*#', $uri) || stripos($uri, '/search/') !== false);
}

/**
 * 非 HTML 响应的 URI 特征（REST API / oEmbed / XML-RPC / Cron / AJAX）。
 *
 * 这些端点在匿名访客下同样走前台渲染链，只拦 capture 端是不够的：漏了会让
 * JSON 响应被当 HTML 写进缓存，serve 端再按 .meta 取 Content-Type 原样回吐，
 * 等于给 API 响应套了个错误的 Content-Type。故与搜索页一样做成 URI 判据，
 * serve（init，WP 查询未跑、is_rest() 不可用）与 capture 共用同一套判定。
 */
function jinyu_page_cache_is_non_html_uri(): bool
{
    [$path, $params] = jinyu_page_cache_uri_parts();
    $p = strtolower($path);

    if (in_array($p, [ '/wp-cron.php', '/xmlrpc.php', '/wp-login.php' ], true)) {
        return true;
    }
    // admin-ajax.php 挂在 wp-admin 目录下，实际请求路径带 /wp-admin 前缀，
    // 前缀比对漏掉它（capture 端虽被 is_admin() 拦，serve 端却读得到，判据要统一）
    if (false !== strpos($p, '/admin-ajax.php')) {
        return true;
    }

    foreach ([ '/wp-json/', '/oembed' ] as $seg) {
        if (false !== strpos($p, $seg)) {
            return true;
        }
    }

    foreach ([ 'rest_route', '_jsonp', 'doing_wp_cron' ] as $k) {
        if (isset($params[$k]) && '' !== (string) $params[$k]) {
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
function jinyu_page_cache_if_none_match_match( string $header, string $etag ): bool
{
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
    $given = trim( $given, "\" \\" );

    return '' !== $given && hash_equals( $want, $given );
}

/**
 * 缓存页的 Content-Type。
 *
 * 缓存页有可能是 XML / JSON / 纯文本（插件的 GET 端点），一律按 text/html 声明
 * 会让浏览器解析错误或直接下载，故落盘时如实记录、命中时还原。
 */
function jinyu_page_cache_content_type(): string
{
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
 * 命中时下发的缓存响应头。
 *
 * 必须放开浏览器本地缓存（不设 no-cache），客户端才会带 If-None-Match 回来，
 * 配合 ETag 即可零字节 304。
 */
function jinyu_page_cache_send_headers( string $etag ): void
{
    header( 'ETag: ' . $etag );
    header( 'Cache-Control: public, max-age=600, must-revalidate' );
}

function jinyu_page_cache_serve(): void
{
    if ( ! jinyu_companion_is_checked( 'page_cache_enable' ) ) {
        return;
    }
    // 安装 / 自动升级进行中（wp-admin/install.php、upgrade.php）不参与缓存：
    // 此时写缓存会把半成品页固化，升级完成后旧页可能残留到 TTL 结束。
    if ( function_exists( 'wp_installing' ) && wp_installing() ) {
        return;
    }
    if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
    if (is_user_logged_in() || is_admin()) return;
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'wp-admin') !== false) return;
    if (strpos($_SERVER['REQUEST_URI'] ?? '', 'wp-login') !== false) return;
    // 搜索页不缓存：serve 端早于 WP 查询（is_search() 不可用），按 URI 特征判定；
    // 每个搜索词一个 URI，缓存只会膨胀且命中意义不大。
    if (jinyu_page_cache_is_search_uri()) return;
    if (jinyu_page_cache_is_non_html_uri()) return;
    if (jinyu_page_cache_is_excluded()) return;

    $file = jinyu_page_cache_file();
    if ('' === $file || !is_readable($file)) {
        return;   // 未命中（或缓存目录不可用）：交给 WP 正常渲染
    }

    // TTL 兜底：epoch 覆盖内容变更，TTL 覆盖「绕过 save_post 直接改库」这类漏失效场景。
    $ttl     = max(60, (int) jinyu_companion_get_option('page_cache_ttl', '3600'));
    $written = (int) @filemtime($file);
    if ($written > 0 && time() - $written > $ttl) {
        return;   // 已过期，本次重新生成，旧文件由 GC 回收
    }

    $body = (string) file_get_contents($file);

    // 响应头已在极早阶段发出（主题或插件提前 echo 过）：ETag / Cache-Control 都设不了，
    // 但缓存内容本身有效，降级为「不带缓存指令的直接输出」而不是整条命中路径作废。
    if (headers_sent()) {
        echo $body;
        exit;
    }

    $meta_file    = $file . '.meta';
    $content_type = is_readable($meta_file) ? (string) file_get_contents($meta_file) : '';
    if ('' === $content_type || strlen($content_type) > 191) {
        $content_type = 'text/html; charset=utf-8';
    }

    // WP 默认给前台发的 no-cache 指令会阻止客户端带条件请求，必须先摘掉，否则 304 永不触发
    if (function_exists('header_remove')) {
        header_remove('Cache-Control');
        header_remove('Expires');
        header_remove('Pragma');
        header_remove('Last-Modified');
    }

    // 复用已读进内存的正文算摘要，避免 md5_file 再把同一份文件读一遍
    $etag = '"' . md5($body) . '"';

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && jinyu_page_cache_if_none_match_match((string) $_SERVER['HTTP_IF_NONE_MATCH'], $etag)) {
        header('HTTP/1.1 304 Not Modified', true, 304);
        jinyu_page_cache_send_headers($etag);
        exit;
    }

    header('X-Jinyu-Cache: HIT');
    jinyu_page_cache_send_headers($etag);
    header('Content-Type: ' . $content_type);

    // 标记本次为缓存命中：性能采样（inc/fun/live.php）据此跳过，
    // 否则几毫秒的缓存响应会把「实时心跳」曲线压成一条直线。
    define('JINYU_CACHE_HIT', true);
    echo $body;
    // 缓存命中时已在 init 阶段 echo+exit，template_redirect 不会触发，
    // 而来源统计(jinyu_track_visit_source)挂在该钩子上，故在此显式调用，
    // 确保匿名访客的来源 PV/UV 在缓存命中时仍被记录（写库已在函数内延迟到 shutdown，exit 后仍会执行）。
    if (function_exists('jinyu_track_visit_source')) {
        jinyu_track_visit_source();
    }
    // UV Cookie 补写：命中路径绕过了 wp 钩子，不补写则每个缓存 PV 都会被 shutdown 统计记为新 UV。
    if (function_exists('jinyu_stats_set_uv_cookie_now')) {
        jinyu_stats_set_uv_cookie_now();
    }
    exit;
}

function jinyu_page_cache_capture(): void
{
    if ( ! jinyu_companion_is_checked( 'page_cache_enable' ) ) {
        return;
    }
    // 安装 / 自动升级进行中不写缓存，与 serve 端判定保持一致（读写同判据）。
    if ( function_exists( 'wp_installing' ) && wp_installing() ) {
        return;
    }
    // 检测到第三方整页缓存插件时自动让位，避免两层 HTML 缓存冲突 / 内容不同步。
    if (function_exists('jinyu_has_external_page_cache') && jinyu_has_external_page_cache()) return;
    if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
    if (is_user_logged_in() || is_admin()) return;
    // 这些响应体不该进整页缓存：404 / feed / 预览 / robots / trackback / 搜索
    if (is_404() || is_feed() || is_preview() || is_robots() || is_trackback() || is_search()) return;
    if (jinyu_page_cache_is_search_uri()) return;
    // URI 判据之上的最后一道：至此 WP 查询已完成，REST 请求标志位才可用
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (jinyu_page_cache_is_non_html_uri()) return;
    if (jinyu_page_cache_is_excluded()) return;

    ob_start(static function ($html) {
        if (strlen($html) < 500) {
            return $html;
        }

        $file = jinyu_page_cache_file();
        if ('' === $file) {
            return $html;   // 缓存目录建不出来：静默降级，但已由 note_blocked 记录原因
        }

        if (false === @file_put_contents($file, $html)) {
            // 目录可写但写不进去 = 磁盘满 / inode 耗尽，比目录缺失更隐蔽，必须上报
            jinyu_page_cache_note_blocked('write_fail');
            return $html;
        }
        @chmod($file, 0644);

        $ct = jinyu_page_cache_content_type();
        if ('' !== $ct && strlen($ct) < 191) {
            @file_put_contents($file . '.meta', $ct);
            @chmod($file . '.meta', 0644);
        }

        return $html;
    });
}
