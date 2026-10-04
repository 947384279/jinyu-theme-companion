#!/usr/bin/env bash
#
# 全口径审计入口 —— 一次跑完所有门禁，输出一张汇总清单。
#
# 为什么要有这个脚本：
#   之前 phpcs 门禁、发布包闸门、wp.org PCP、架构硬约束是四套口径各扫各的，
#   每次扫描的「扫描面」不一样，于是同一份代码换个角度扫就冒出「新问题」。
#   常见成因有三类，这个脚本都堵住：
#     ① 口径在动：phpcs.xml.dist 里 exclude 加加减减；
#     ② 没有基线：修完不留痕，下次从零重看；
#     ③ 产物没落盘：scp 逐个传文件时半旧半新，扫到中间态（这个脚本不规避，
#        但要求改动全部同步完成后再跑）。
#   对应处理：
#     · 口径定死在 phpcs.xml.dist（第一层收口 / 第二层必改，逐条写 why）；
#     · 基线落在本文件同目录的 audit-baseline.txt，只报新增；
#     · 收工标准是「零新增」，不是「零告警」——存量告警允许在基线里，
#       新增一条都必须处理或显式进基线。
#
# 用法（脚本跑在服务器；本机没有 PHP）：
#     bash tools/audit.sh              # 只报新增问题
#     bash tools/audit.sh --scan       # 输出全量清单（不比基线）
#     bash tools/audit.sh --update     # 用当前结果重写基线（确认存量后执行）
#     bash tools/audit.sh --phpcs=/path/to/phpcs    # 指定 phpcs 入口
#
# 退出码：0 = 无新增；1 = 有新增问题；2 = 环境不满足。
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASELINE="$(dirname "${BASH_SOURCE[0]}")/audit-baseline.txt"
MODE="check"
PHPCS_BIN="/opt/wpcs/vendor/squizlabs/php_codesniffer/bin/phpcs"
PHP_BIN="/usr/local/php-8.5/bin/php"

while [ "$#" -gt 0 ]; do
    case "$1" in
        --scan)   MODE="scan" ;;
        --update) MODE="update" ;;
        --phpcs)  PHPCS_BIN="$2"; shift ;;
        *) echo "未知参数: $1" >&2; exit 2 ;;
    esac
    shift
done

cd "$ROOT" || exit 2

say() { printf '%s\n' "$*"; }
sayr() { printf '%s\n' "$*"; }

# ------------------------------------------------------------------ 口径 1/4 phpcs

run_phpcs() {
    local tmp
    tmp="$(mktemp)"
    if [ ! -x "$PHPCS_BIN" ] && [ ! -f "$PHPCS_BIN" ]; then
        say "[phpcs] FAIL: 找不到 phpcs 入口（--phpcs=<路径> 可指定）: $PHPCS_BIN"
        return 2
    fi
    # ⚠️ 必须带 -q：phpcs 默认把进度条（..E.E.. 63/63）打在 stdout，
    #    会混进 csv 里污染后续解析，表现为「csv 空、summary 有几十条」的假象。
    "$PHP_BIN" "$PHPCS_BIN" -q --report=csv 2>/dev/null > "$tmp"
    cat "$tmp"
    rm -f "$tmp"
}

# csv → 「文件:行:规则」指纹。
# ⚠️ 必须用 PHP 的 fgetcsv 解析，不能用 awk/grep 按逗号切：
#    phpcs 的消息字段里既有中文逗号、又有未成对引号（如 Dup 类名的 "…but found class-x.php."），
#    按逗号切会把 Source 列挤走，表现为「指纹里出现 try "$var"」这种假新增。
phpcs_fingerprints() {
    local csv
    csv="$(mktemp)"
    run_phpcs > "$csv"
    # ⚠️ 三处必须写死，否则基线不可信：
    #   ① fgetcsv 的 $escape 参数在 PHP 8.4+ 必须显式给（否则每条刷一条 Deprecated 到 stdout，
    #      被算成「新增问题」）；
    #   ② 路径统一转成相对 ROOT 的 POSIX 形式——基线落在仓库里，不能绑死服务器绝对路径；
    #   ③ 输出必须 sort -u：comm 要求两路输入同序，否则报 not in sorted order 且结果错。
    csv_path="$csv" root="$ROOT" "$PHP_BIN" -r '
        error_reporting( E_ALL & ~E_DEPRECATED );
        ini_set( "display_errors", "0" );
        $in = fopen( getenv("csv_path"), "r" );
        if ( ! $in ) { exit(0); }
        $root = rtrim( getenv("root"), "/" ) . "/";
        while ( ( $row = fgetcsv( $in, 0, ",", "\"", "\\" ) ) !== false ) {
            if ( count( $row ) < 6 ) { continue; }
            $src = trim( $row[5] );
            if ( $src === "" || strcasecmp( $src, "Source" ) === 0 ) { continue; }
            $file = str_replace( "\\", "/", trim( $row[0] ) );
            if ( strpos( $file, $root ) === 0 ) { $file = substr( $file, strlen( $root ) ); }
            $file = ltrim( $file, "/" );
            if ( $file === "" ) { continue; }
            echo $file . ":" . (int) $row[1] . ":" . $src . "\n";
        }
        fclose( $in );
    ' | LC_ALL=C sort -u
    rm -f "$csv"
}

phpcs_count() { run_phpcs | grep -c '^"'; }

# ------------------------------------------------------------------ 口径 2/4 发布包闸门
# build-zip.js 只在本机可跑（需要 node 与 git），这里做等价的静态复检：
# 调试件、凭据文件、非 ASCII 路径。真正的打包闸门仍然必须走 node tools/build-zip.js。
gate_release() {
    local bad=0
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        say "[闸门] FAIL 调试/测试件不得进发布包: $f"
        bad=1
    done < <(find . -path ./.git -prune -o -path ./.workbuddy -prune -o -path './预览与脚本' -prune -o -path ./node_modules -prune -o -type f \( -name '*.log' -o -name '*.mjs' -o -name '*.bak' -o -name '*.orig' -o -name '*.swp' -o -name '.tmp' \) -print 2>/dev/null)
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        say "[闸门] FAIL 凭据/密钥文件: $f"
        bad=1
    done < <(find . -path ./.git -prune -o -path ./.workbuddy -prune -o -type f \( -name '*.pem' -o -name '*.key' -o -name '.env' -o -name '*ftp-config*' \) -print 2>/dev/null)
    return $bad
}

# ------------------------------------------------------------------ 口径 3/4 架构硬约束
# 项目硬约束：全局符号一律 jinyu_ 前缀；插件只做 sitemap 减法、不自造渲染/不注册 provider。
gate_architecture() {
    local bad=0
    # 调试函数残留在生产文件里
    # ⚠️ 必须排除注释行：项目有 jinyu_companion_log() 唯一日志出口，
    #    注释里大量提到 error_log（说明为什么不用它），按字符串 grep 会全误报。
    while IFS= read -r hit; do
        [ -z "$hit" ] && continue
        say "[架构] FAIL 生产代码残留调试输出: $hit"
        bad=1
    done < <(grep -rn --include=*.php -E '(^|[^A-Za-z_])(var_dump|print_r|error_log|var_export)\s*\(' inc/ *.php 2>/dev/null \
        | grep -v 'phpcs:ignore' \
        | grep -vE ':[0-9]+:[ \t]*(//|\*|/\*|#)' | head -20)
    # 非 ASCII 路径（wp.org 会判 badly_named_files，阻塞上传）
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        say "[架构] FAIL 路径含非 ASCII/空格（wp.org 判 badly_named_files）: $f"
        bad=1
    done < <(find . -path ./.git -prune -o -path ./.workbuddy -prune -o -path './预览与脚本' -prune -o -type d -print 2>/dev/null | grep -P '[^\x00-\x7F]' | head -10)
    return $bad
}

# ------------------------------------------------------------------ 口径 4/4 行尾
gate_lineendings() {
	local bad=0 tmpf
	tmpf="$(mktemp)"
	grep -rlIU $'\r' --include='*.php' --include='*.js' --include='*.css' --include='*.txt' --include='*.tpl' . 2>/dev/null \
		| grep -v '^\./\.git/' | grep -v '^\./\.workbuddy/' | grep -v '^\./预览与脚本/' | head -20 > "$tmpf"
	while IFS= read -r f; do
		[ -z "$f" ] && continue
		say "[行尾] FAIL 文件含 CRLF（应统一 LF）：$f"
		bad=1
	done < "$tmpf"
	rm -f "$tmpf"
	return $bad
}


# ------------------------------------------------------------------ 主流程

main() {
    local total new=0 gatebad=0

    total="$(phpcs_count)"
    say "===== jinyu-theme-companion 全口径审计 ====="
    say "[phpcs] 全量问题数: ${total}"

    local fingerprints
    fingerprints="$(phpcs_fingerprints)"

    # 指纹为空时 `printf '%s\n' ""` 会吐出一个空行，必须滤掉：
    # 否则基线里永远躺着一条空项，且下次比对时空行会被算成「新增 1」。
    fingerprints="$(printf '%s\n' "$fingerprints" | grep -v '^$')"

    if [ "$MODE" = "scan" ]; then
        echo "$fingerprints"
        say "[phpcs] --scan 模式：未比基线"
        exit 0
    fi

    if [ ! -f "$BASELINE" ]; then
        : > "$BASELINE"
    fi

    local newlist
    newlist="$(comm -23 <(printf '%s\n' "$fingerprints") <(LC_ALL=C sort -u "$BASELINE" | grep -v '^$'))"

    if [ -n "$newlist" ]; then
        say "[phpcs] 新增问题 $(echo "$newlist" | wc -l) 条:"
        echo "$newlist" | sed 's/^/    /'
        new=$(echo "$newlist" | wc -l)
    else
        say "[phpcs] 无新增（基线内共 $(wc -l < "$BASELINE") 条）"
    fi

    # ⚠️ 必须取显式 $? 而不能写 `if $gate`：闸门函数「无问题」时是按约定 return 0 的，
    #    退出码 0 在 shell 里就是真，于是 `if $gate` 把「全过」当「失败」，
    #    表现为「一行 FAIL 都没输出、结尾却报新增 3 项」这种查不着的假失败。
    #    三个闸门共用同一套约定：0 = 通过，非 0 = 有命中（入口处已 print 明细）。
    local rc=0
    for gate in gate_release gate_architecture gate_lineendings; do
        $gate
        rc=$?
        if [ "$rc" -ne 0 ]; then
            say "[闸门] $gate 未通过（rc=$rc）"
            gatebad=1
            new=$(( new + 1 ))
        fi
    done

    if [ "$MODE" = "update" ]; then
        printf '%s\n' "$fingerprints" | grep -v '^$' | LC_ALL=C sort -u > "$BASELINE"
        say "[基线] 已重写 baseline（$(wc -l < "$BASELINE") 条指纹）"
        exit 0
    fi

    say "-----"
    if [ "$new" -eq 0 ] && [ "$gatebad" -eq 0 ]; then
        say "审计通过：无新增问题。收工标准 = 零新增。"
        exit 0
    fi
    say "审计未通过：新增 ${new} 项。"
    exit 1
}

main
