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

/** 本地专用、不进发布包的文档（非插件代码，且文件名含中文会被 wp.org 判 badly_named_files / unexpected_markdown_file）。 */
const LOCAL_ONLY_FILES = new Set(['_先读我-恢复说明.md']);

/** 发布包允许的文件/目录名字符集（ASCII 字母/数字/点/下划线/连字符）。 */
const SAFE_NAME_RE = /^[A-Za-z0-9._-]+$/;

/**
 * 校验单个条目能否进发布包：
 * - 本地专用文档 → 静默跳过（不进包）；
 * - 任一路径段含非 ASCII/空格/特殊字符 → 直接让构建失败，杜绝把 wp.org 会拒收的包传上去。
 * @returns {boolean} true=保留，false=跳过
 */
function gateEntry(rel) {
    const segs = rel.split('/');
    for (const seg of segs) {
        if (!SAFE_NAME_RE.test(seg)) {
            console.error('[zip] FAIL: 路径含非 ASCII/空格/特殊字符，wp.org 自动扫描会判 badly_named_files（阻塞上传）：' + rel);
            process.exit(1);
        }
    }
    if (LOCAL_ONLY_FILES.has(segs[segs.length - 1])) return false;
    return true;
}

function walk(dir, base, out) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const name = entry.name;
        const rel = base ? base + '/' + name : name;
        if (name.startsWith('.')) continue;   // 点文件/点目录（.gitignore、.git 等）一律不进包
        if (SKIP_DIRS.has(name)) continue;
        if (!gateEntry(rel)) continue;        // 本地专用文档跳过；非法文件名直接失败
        const abs = path.join(dir, name);
        if (name.includes('.')) {
            if (SKIP_FILE_RE.test(name)) continue;
            out.push(rel);
        } else {
            walk(abs, rel, out);
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
        return gateEntry(rel);   // 本地专用文档跳过；含中文/特殊字符的文件名直接构建失败
    });
    const entries = files.map((rel) => ({
        name: SLUG + '/' + rel.replace(/\\/g, '/'),
        data: fs.readFileSync(path.join(ROOT, rel)),
    }));

    const topLevel = [...new Set(entries.map((e) => e.name.split('/')[0]))];
    if (topLevel.length !== 1 || topLevel[0] !== SLUG) {
        console.error('[zip] FAIL: 顶层目录异常 —— ' + topLevel.join(', '));
        process.exit(1);
    }

    const buf = buildZip(entries);
    const outDir = path.join(os.homedir(), 'Desktop');
    const out = path.join(outDir, `${SLUG}-${version}.zip`);
    fs.writeFileSync(out, buf);

    const kb = (buf.length / 1024).toFixed(1);
    console.log(`[zip] ok: ${out}`);
    console.log(`[zip] ${entries.length} 个文件，顶层 "${topLevel[0]}"，${kb} KB`);
}

main();
