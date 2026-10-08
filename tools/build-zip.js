/**
 * 插件发布包打包器（零依赖，纯 Node）。
 *
 * 用法：
 *   node tools/build-zip.js            # 打安装包
 *   node tools/build-zip.js --check    # 只校验版本一致性，不产出文件
 *
 * 规则：
 * - 文件清单取自 `git ls-files`，天然遵守 .gitignore（.workbuddy/、预览与脚本/ 不会被打进去）。
 *   另外统一剔除所有点文件/点目录（.gitignore、.git/ 等）与根级 tools/（打包脚本），
 *   确保发布包不含仓库元数据与开发件。
 * - 顶层目录必须是插件目录名 jinyu-theme-companion/，否则 WordPress「上传插件」判为无效包。
 *
 * ## 发布包铁律（每次打包必须过，硬门槛，不只是建议）
 *   1. **包里不许有私密信息**：不只要按文件名拦（.git / .workbuddy / node_modules / ftp-config /
 *      密钥 / 备份），还要对**所有即将入包的文本文件做内容扫描**（私钥、JWT、平台令牌、
 *      API key / secret / token / 口令字面量）。文件名黑名单只能挡「名字不对」的，
 *      挡不住「名字正常、内容里写了口令」的漏网文件。
 *   2. **包里不许有测试调试文件**：扩展到名称形状 `_test/test-/debug-/probe-/verify-/demo-/preview-/shot-`
 *      以及 `.log .mjs .bak .orig .tmp .swp` 一并 FAIL（生产功能文件如 transport-check.php
 *      不匹配这些形状，不会误伤）。
 *   3. **每次打包必须回显内容清单**（路径 + 体积 + 文件数 + 总体积），打包前可 `--list` 只列不产包。
 *      人眼看一遍清单，是最后一道也是唯一一道「我到底传了什么」的闸。
 *   ⚠️ 2026-10-02 手工压缩出过 49.5MB 的包：顶层混编别处源码 + node_modules + .git 全历史 +
 *      某个明文 FTP 口令目录。排除规则当时是对的，失效的是「人没走这个脚本」。
 *      所以上面三条全部做成 **process.exit(1) 级硬失败**，不做 warning。
 *
 * - 版本取自插件主文件头的 `Version:`，与 readme.txt 的 `Stable tag:`、以及同文件里的
 *   `JINYU_COMPANION_VER` 常量三处交叉校验，不一致直接 fail（和主题 tools/sync-version.js --check 一个口径）。
 * - 输出：~/Desktop/jinyu-theme-companion-<ver>.zip
 *
 * 为什么不用 shell 的 zip：这台机器（Git Bash）没有 zip 命令，用 zlib + 手写 zip 结构最稳。
 */

'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const { spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const SLUG = 'jinyu-theme-companion';
const MAIN_FILE = path.join(ROOT, SLUG + '.php');
const README = path.join(ROOT, 'readme.txt');

/* ---------------------------------------------------------------- 版本 */

function readVersionFromFile(file) {
    const src = fs.readFileSync(file, 'utf8');
    const m = /^[ \t*]*Version:[ \t]*([0-9][0-9A-Za-z.\-+]*)/m.exec(src);
    return m ? m[1].trim() : null;
}

function readStableTag() {
    const src = fs.readFileSync(README, 'utf8');
    const m = /^[ \t*]*Stable tag:[ \t]*([0-9][0-9A-Za-z.\-+]*)/m.exec(src);
    return m ? m[1].trim() : null;
}

/** 运行时常量 JINYU_COMPANION_VER：模块判断与资源路径读的是它，漏改会让线上版本号漂移。 */
function readRuntimeConst() {
    const src = fs.readFileSync(MAIN_FILE, 'utf8');
    const m = /define\(\s*['"]JINYU_COMPANION_VER['"]\s*,\s*['"]([0-9][0-9A-Za-z.\-+]*)['"]/.exec(src);
    return m ? m[1].trim() : null;
}

function checkVersion() {
    const fromMain = readVersionFromFile(MAIN_FILE);
    const fromReadme = readStableTag();
    const fromConst = readRuntimeConst();
    const problems = [];
    if (!fromMain) problems.push('插件主文件头缺少 `Version:`');
    if (!fromReadme) problems.push('readme.txt 缺少 `Stable tag:`');
    if (!fromConst) problems.push('插件主文件缺少 JINYU_COMPANION_VER 常量');
    const all = [fromMain, fromReadme, fromConst].filter(Boolean);
    if (new Set(all).size > 1) {
        problems.push(`版本不一致：主文件 ${fromMain} / readme ${fromReadme} / 常量 ${fromConst}`);
    }
    if (problems.length) {
        problems.forEach((p) => console.error('[zip] FAIL: ' + p));
        process.exit(1);
    }
    console.log('[zip] version ok: ' + fromMain + '（主文件 / readme.txt / JINYU_COMPANION_VER 三处一致）');
    return fromMain;
}

/* ---------------------------------------------------------------- 文件清单 */

/**
 * 与 .gitignore 等价的排除项。git ls-files 不可用时用它兜底。
 * ⚠️ 发布包路径只允许 [A-Za-z0-9._-]：任何中文/空格/特殊字符文件名都会触发 wp.org 自动扫描的
 *   `badly_named_files` ERROR（阻塞上传）。详见下方 gateEntry()。
 */
const SKIP_DIRS = new Set(['.git', '.workbuddy', 'node_modules', '_deploy', '_theme_ref', '预览与脚本']);
const SKIP_FILE_RE = /^(_build\.js|_shot_.*\.png|settings-preview\.html|.*\.log)$/;

/** 发布包允许的文件/目录名字符集（ASCII 字母/数字/点/下划线/连字符）。 */
const SAFE_NAME_RE = /^[A-Za-z0-9._-]+$/;

/**
 * 凭据 / 私域文件黑名单：命中即构建失败。
 *
 * 为什么在「排除」之外还要「断言」：SKIP_DIRS 属于策略，任何一条被误删或改名都是
 * **静默放行**——2026-10-02 就手工压缩出过一个 49.5MB 的包，顶层混编了别处的源码、
 * node_modules、.git 全历史，以及某个含明文 FTP 口令的本地配置目录。排除规则当时是对的，
 * 失效的是「人没走这个脚本」。断言把静默失败变成响亮崩溃。
 */
const FORBIDDEN_RE = /(^|\/)(\.git|\.workbuddy|node_modules|预览与脚本)($|\/)|ftp-config|\.pem$|\.key$|\.env$|(^|\/)(_?backup|dump|export)[-_.]/i;

/**
 * 发布包准入闸门：任一路径段含非 ASCII/空格/特殊字符 → 直接让构建失败。
 * wp.org 自动扫描会判 badly_named_files / unexpected_markdown_file（ERROR，阻塞上传），
 * 与其传上去被拒，不如构建期就崩。
 * @param {string} rel 相对仓库根的路径
 */
function gateEntry(rel) {
    for (const seg of rel.split('/')) {
        if (!SAFE_NAME_RE.test(seg)) {
            console.error('[zip] FAIL: 路径含非 ASCII/空格/特殊字符，wp.org 自动扫描会判 badly_named_files（阻塞上传）：' + rel);
            process.exit(1);
        }
    }
    if (FORBIDDEN_RE.test(rel)) {
        console.error('[zip] FAIL: 命中凭据/私域文件黑名单（.git 历史 / .workbuddy 配置 / node_modules / 备份 / 密钥文件）：' + rel);
        process.exit(1);
    }
}

/* ---------------------------------------------------------------- 内容扫描 */

/** 只扫文本；截图/字体/媒体/压缩包按扩展名跳过（二进制正则不可靠）。 */
const SCANNABLE_RE = /\.(php|js|css|txt|json|html|htm|md|xml|csv|ini|tpl)$/i;

/**
 * 凭据特征。每条都配一句 why，FAIL 时直接说清命中了什么，别让人对着哈希猜。
 * 只认「高置信度形状」（私钥头段 / 令牌前缀 / 长字面量），宁可漏放也不误伤正常代码。
 */
const SECRET_PATTERNS = [
    // ⚠️ 必须「BEGIN + ≥300 字符 base64 主体 + END」三者连成一段才算私钥。
    //    class-apple.php 的 normalize_pem() 会拿 chunk_split() 拼 BEGIN/END 头尾（结构性字符串），
    //    主体是函数名不是 base64 —— 只认单个 BEGIN 头会把生产代码误判成泄露。
    //    300 是下限：真 PKCS8 RSA-2048 私钥主体约 1600 字符。
    { why: 'PEM 私钥', re: /-----BEGIN(?: [A-Z]+)? PRIVATE KEY-----[\r\n]+[A-Za-z0-9+/=]{300,}[\r\n]+-----END(?: [A-Z]+)? PRIVATE KEY-----/ },
    { why: 'JWT token', re: /\beyJ[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}\.[A-Za-z0-9_-]{6,}/ },
    { why: 'Git / 聊天平台令牌', re: /\b(?:ghp|gho|ghu|ghs|ghr|glpat|xox[baprs]-)[A-Za-z0-9_-]{16,}/ },
    { why: 'AWS access key id', re: /\bAKIA[0-9A-Z]{12,}/ },
    { why: 'AI / 第三方 API key', re: /\bsk-[A-Za-z0-9_-]{20,}/ },
    // ⚠️ 键名与值之间必须是 `=>` 或 `=`，且值 ≥20 字符纯凭据字符集。
    //    不能写成「键名 + 任意字符 + 引号 + 字符串」，否则 `password = trim( $cfg['secret'] )`
    //    这种「读配置」的正常代码会被判成写口令（storage.php 第 601 行踩过）。
    { why: 'API key / secret / token 字面量', re: /\b(?:api[_-]?key|apikey|secret[_-]?key|client[_-]?secret|access[_-]?token|auth[_-]?token|private[_-]?key|app[_-]?secret)\b\s*=>?\s*["'][A-Za-z0-9_+\/=]{20,}["']/i },
    { why: '口令字面量', re: /\b(?:db[_-]?password|wp[_-]?password|password|passwd|pwd)\b\s*=>?\s*["'][^"'\s]{6,}["']/i },
];

/**
 * 铁律 1 执行器：对单个待入包文件做内容扫描，命中即硬失败。
 * @param {string} rel 相对仓库根的路径
 * @param {Buffer} data 文件内容
 */
function scanSecrets(rel, data) {
    if (!SCANNABLE_RE.test(rel)) return;
    const src = data.toString('utf8');
    for (const { re, why } of SECRET_PATTERNS) {
        const hit = re.exec(src);
        if (!hit) continue;
        const line = src.slice(0, src.indexOf(hit[0]) + hit[0].length).split('\n').length;
        console.error(`[zip] FAIL 私密信息（${why}）：${rel} 第 ${line} 行`);
        console.error(`[zip]   ↳ ${hit[0].slice(0, 70)}`);
        process.exit(1);
    }
}

/**
 * 铁律 2：调试/测试文件形状。
 * 注意只按「文件名形状」拦 —— 生产功能文件（transport-check.php、uninstall.php、stats.php 等）
 * 都不匹配这些形状，不会被误伤。
 */
const DEBUG_FILE_RE = /(?:^|[-_/])(?:_?test|tests|debug|probe|verify|demo|preview|shot)(?:[-_.]|$)|(?:^|[-_])(?:test|debug|probe|verify|demo)(?:[-_.]|$)|(?:^|[-_])(?:test|debug|probe|verify|demo)[-_.][\w.-]+\.(?:php|js|mjs|html?|css)$|\.(?:log|mjs|bak|orig|tmp|swp)(?:$|\.)/i;

/** 铁律 2 执行器。 */
function gateDebugFile(rel) {
    if (!DEBUG_FILE_RE.test(rel)) return;
    console.error(`[zip] FAIL 测试/调试文件不得进发布包：${rel}`);
    process.exit(1);
}

function walk(dir, base, out) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const name = entry.name;
        const rel = base ? base + '/' + name : name;
        if (name.startsWith('.')) continue;   // 点文件/点目录（.gitignore、.git 等）一律不进包
        if (SKIP_DIRS.has(name)) continue;
        gateEntry(rel);                       // 非法文件名直接 process.exit，不返回
        const abs = path.join(dir, name);
        // 必须按 dirent 类型判定，不能用「文件名里有没有点」代替 ——
        // 否则 LICENCE / CHANGELOG / run.sh 这类无扩展名文件会被当成目录递归，
        // 结果既不报错也不入包（静默漏包）。
        if (entry.isDirectory()) {
            walk(abs, rel, out);
        } else {
            if (SKIP_FILE_RE.test(name)) continue;
            out.push(rel);
        }
    }
    return out;
}

function listFiles() {
    const r = spawnSync('git', ['ls-files', '-z'], { cwd: ROOT, encoding: 'buffer' });
    if (!r.error && r.status === 0) {
        const list = r.stdout.toString('utf8').split('\u0000').filter(Boolean);
        if (list.length) return list;
    }

    // 兜底：这台机器 Git Bash 默认 PATH 里没有 git，直接遍历。
    const list = walk(ROOT, '', []).sort();
    if (!list.length) {
        console.error('[zip] FAIL: 既拿不到 git ls-files，遍历结果也为空');
        process.exit(1);
    }
    console.log('[zip] 注：git 不可用，已改用目录遍历取文件清单');
    return list;
}

/* ---------------------------------------------------------------- zip 结构 */

const CRC_TABLE = (() => {
    const t = new Int32Array(256);
    for (let i = 0; i < 256; i++) {
        let c = i;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        t[i] = c;
    }
    return t;
})();

function crc32(buf) {
    let c = -1;
    for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
    return (c ^ -1) >>> 0;
}

function dosDateTime(date) {
    const time = (date.getHours() << 11) | (date.getMinutes() << 5) | ((date.getSeconds() / 2) | 0);
    const day = ((date.getFullYear() - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate();
    return { time, day };
}

/**
 * 生成 zip 字节流。文件用 deflate 压缩，文件名按 UTF-8 存储（general purpose flag 0x0800）。
 * @param {Array<{name: string, data: Buffer}>} entries
 * @returns {Buffer}
 */
function buildZip(entries) {
    const { time, day } = dosDateTime(new Date());
    const chunks = [];
    const central = [];
    let offset = 0;

    for (const e of entries) {
        const raw = e.data;
        const comp = zlib.deflateRawSync(raw, { level: 9 });
        const crc = crc32(raw);
        const nameBuf = Buffer.from(e.name, 'utf8');

        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);   // signature
        local.writeUInt16LE(20, 4);           // version needed to extract
        local.writeUInt16LE(0x0800, 6);       // flags: UTF-8 name
        local.writeUInt16LE(8, 8);            // method: deflate
        local.writeUInt16LE(time, 10);
        local.writeUInt16LE(day, 12);
        local.writeUInt32LE(crc, 14);
        local.writeUInt32LE(comp.length, 18);
        local.writeUInt32LE(raw.length, 22);
        local.writeUInt16LE(nameBuf.length, 26);
        local.writeUInt16LE(0, 28);           // extra length

        chunks.push(local, nameBuf, comp);

        const cd = Buffer.alloc(46);
        cd.writeUInt32LE(0x02014b50, 0);      // central directory signature
        cd.writeUInt16LE(20, 4);              // version made by
        cd.writeUInt16LE(20, 6);              // version needed
        cd.writeUInt16LE(0x0800, 8);
        cd.writeUInt16LE(8, 10);
        cd.writeUInt16LE(time, 12);
        cd.writeUInt16LE(day, 14);
        cd.writeUInt32LE(crc, 16);
        cd.writeUInt32LE(comp.length, 20);
        cd.writeUInt32LE(raw.length, 24);
        cd.writeUInt16LE(nameBuf.length, 28);
        cd.writeUInt16LE(0, 30);              // extra
        cd.writeUInt16LE(0, 32);              // comment
        cd.writeUInt16LE(0, 34);              // disk number start
        cd.writeUInt16LE(0, 36);              // internal attrs
        cd.writeUInt32LE((0o100644 << 16) >>> 0, 38); // external attrs: 0644
        cd.writeUInt32LE(offset, 42);
        central.push(cd, nameBuf);

        offset += local.length + nameBuf.length + comp.length;
    }

    const cdBuf = Buffer.concat(central);
    const eocd = Buffer.alloc(22);
    eocd.writeUInt32LE(0x06054b50, 0);
    eocd.writeUInt16LE(0, 4);                // disk number
    eocd.writeUInt16LE(0, 6);                // disk with start of central dir
    eocd.writeUInt16LE(entries.length, 8);
    eocd.writeUInt16LE(entries.length, 10);
    eocd.writeUInt32LE(cdBuf.length, 12);
    eocd.writeUInt32LE(offset, 16);
    eocd.writeUInt16LE(0, 20);               // comment length

    return Buffer.concat([...chunks, cdBuf, eocd]);
}

/* ---------------------------------------------------------------- 产出校验 */

/**
 * 铁律 3 上半：回显待传清单（路径 + 单文件体积 + 合计）。
 * 打包前让人肉眼过一遍「我到底传了什么」——这是唯一一道人工闸，不能省。
 */
function printManifest(files) {
    const rows = files
        .map((rel) => {
            let size = 0;
            try { size = fs.statSync(path.join(ROOT, rel)).size; } catch (_) { size = 0; }
            return { rel, size };
        })
        .sort((a, b) => a.rel.localeCompare(b.rel));
    console.log(`[zip] 待传清单 ${rows.length} 个文件：`);
    for (const r of rows) {
        console.log(`[zip]   ${(r.size / 1024).toFixed(1).padStart(8)} KB  ${r.rel}`);
    }
    const total = rows.reduce((s, r) => s + r.size, 0);
    console.log(`[zip] 合计 ${(total / 1024 / 1024).toFixed(2)} MB`);
}

/**
 * 铁律 3 下半：回读成品 zip 的 central directory，逐条比对「zip 里真有的」与「预期入包的」。
 * 这一步防的是「清单算对了、生成环节写漏/写错」——比如顶层目录名写错、条目被截断。
 */
function verifyZip(buf, expected) {
    const eocd = buf.length - 22;
    if (eocd < 0 || buf.readUInt32LE(eocd) !== 0x06054b50) {
        console.error('[zip] FAIL 成品 zip 结构损坏（EOCD 缺失）');
        process.exit(1);
    }
    const cdSize = buf.readUInt32LE(eocd + 12);
    const cdOffset = buf.readUInt32LE(eocd + 16);
    const names = new Set();
    let p = cdOffset;
    while (p + 46 <= cdOffset + cdSize && buf.readUInt32LE(p) === 0x02014b50) {
        const nameLen = buf.readUInt16LE(p + 28);
        const extraLen = buf.readUInt16LE(p + 30);
        const commentLen = buf.readUInt16LE(p + 32);
        names.add(buf.slice(p + 46, p + 46 + nameLen).toString('utf8'));
        p += 46 + nameLen + extraLen + commentLen;
    }
    if (names.size !== expected.length || !expected.every((e) => names.has(e.name))) {
        console.error(`[zip] FAIL 成品 zip 与清单不符：期望 ${expected.length} 个条目，实到 ${names.size} 个`);
        process.exit(1);
    }
    console.log(`[zip] 回读校验通过：zip 内 ${names.size} 个条目与清单逐条一致`);
}

/* ---------------------------------------------------------------- 主流程 */

function main() {
    const version = checkVersion();

    if (process.argv.includes('--check')) {
        console.log('[zip] --check 通过，未产出文件');
        return;
    }

    // 统一过滤：任何层级上的点文件/点目录（.gitignore、.git/ 等）都不进包，
    // 无论清单来自 git ls-files 还是兜底遍历。
    const files = listFiles().filter((rel) => {
        if (rel.split('/').some((seg) => seg.startsWith('.'))) return false;
        if (rel === 'tools' || rel.startsWith('tools/')) return false; // 打包脚本等开发件不发用户
        if (rel === 'phpcs.xml.dist') return false;                     // 开发期规范配置，不进包
        if (rel === '预览与脚本' || rel.startsWith('预览与脚本/')) return false; // 服务器自检脚本，运维用
        return true;
    });
    if (process.argv.includes('--list')) {
        // 只过目：走一遍路径闸门 + 调试件闸门 + 清单回显，但不产出 zip、不做内容级扫描。
        files.forEach(gateEntry);
        files.forEach(gateDebugFile);
        printManifest(files);
        console.log('[zip] --list 结束，未产出文件（正式打包还会做内容级敏感扫描）');
        return;
    }

    files.forEach(gateEntry);       // 含中文/特殊字符的文件名直接构建失败（不进过滤器，避免误当返回值）
    files.forEach(gateDebugFile);   // 铁律 2：调试/测试件形状
    const entries = files.map((rel) => ({
        rel,
        name: SLUG + '/' + rel.replace(/\\/g, '/'),
        data: fs.readFileSync(path.join(ROOT, rel)),
    }));
    entries.forEach((e) => scanSecrets(e.rel, e.data));   // 铁律 1：内容扫凭据

    const topLevel = [...new Set(entries.map((e) => e.name.split('/')[0]))];
    if (topLevel.length !== 1 || topLevel[0] !== SLUG) {
        console.error('[zip] FAIL: 顶层目录异常 —— ' + topLevel.join(', '));
        process.exit(1);
    }

    const buf = buildZip(entries);
    verifyZip(buf, entries);   // 铁律 3：回读成品 zip，确认写出去的与打算传的一致
    const outDir = path.join(os.homedir(), 'Desktop');
    const out = path.join(outDir, `${SLUG}-${version}.zip`);
    fs.writeFileSync(out, buf);

    const kb = (buf.length / 1024).toFixed(1);
    console.log(`[zip] ok: ${out}`);
    console.log(`[zip] ${entries.length} 个文件，顶层 "${topLevel[0]}"，${kb} KB`);
    printManifest(files);
}

main();
