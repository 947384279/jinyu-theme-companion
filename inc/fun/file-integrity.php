<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 文件完整性监控 + 恶意码扫描（后台自动，无设置页）。
 *
 * 周期性比对「插件自身目录」与「当前启用主题目录」的文件哈希基线，检出
 * 被修改 / 新增 / 删除；并对 PHP 源文件做常见 webshell / 混淆特征扫描。
 * 命中后通过邮件（复用全站 wp_mail；若已配置 SMTP 则由 SmtpConfig 接管）与 error_log 告警。
 *
 * 设计红线：
 *  - 默认开启、零配置，符合「不要页面」的诉求；
 *  - 配置与基线存独立 option（不污染 jinyu_companion_settings）；
 *  - 只读文件系统，绝不修改线上文件；隔离/修复是人工动作，本模块只告警；
 *  - apply_filters('jinyu_integrity_disable', false) 可整体关闭（含 cron）；
 *  - 与 theme-compat.php 的运行时防护（限流 / 锁定 / REST / xmlrpc 关闭）互补，不重叠。
 */

if ( ! defined( 'JINYU_INTEGRITY_CRON' ) ) {
	define( 'JINYU_INTEGRITY_CRON', 'jinyu_integrity_daily' );
}

if ( ! defined( 'JINYU_INTEGRITY_OPT' ) ) {
	define( 'JINYU_INTEGRITY_OPT', 'jinyu_integrity_state' );
}

// 纳入完整性哈希与恶意码扫描的扩展名。
if ( ! defined( 'JINYU_INTEGRITY_EXTS' ) ) {
	define( 'JINYU_INTEGRITY_EXTS', array( 'php', 'js', 'css', 'json', 'html', 'htm', 'tpl', 'txt' ) );
}

// 递归跳过的目录名（缓存 / 上传 / 依赖等非核心资产）。
if ( ! defined( 'JINYU_INTEGRITY_SKIP_DIRS' ) ) {
	define(
		'JINYU_INTEGRITY_SKIP_DIRS',
		array( 'node_modules', 'vendor', 'cache', 'tmp', 'uploads', '.git', '.svn', 'languages' )
	);
}

// 单次扫描文件数上限（弱机保护，避免 OOM / 超时）。
if ( ! defined( 'JINYU_INTEGRITY_MAX_FILES' ) ) {
	define( 'JINYU_INTEGRITY_MAX_FILES', 8000 );
}

/**
 * 读取合并默认值的配置。
 *
 * @return array
 */
function jinyu_integrity_settings(): array {
	$defaults = array(
		'enable'          => true,
		'scan_theme'      => true,
		'malware_enable'  => true,
		'email_alert'     => true,
		'baseline_built'  => 0,
		'last_run'        => 0,
		'last_status'     => '',
	);
	$stored   = get_option( JINYU_INTEGRITY_OPT, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return array_merge( $defaults, $stored );
}

/**
 * 是否整体禁用（含 cron 调度判断）。
 *
 * @return bool
 */
function jinyu_integrity_is_disabled(): bool {
	/**
	 * 允许外部（如 staging 环境）关闭自检。
	 *
	 * @param bool $disabled 默认不禁用。
	 */
	return (bool) apply_filters( 'jinyu_integrity_disable', false )
		|| ! jinyu_integrity_settings()['enable'];
}

/**
 * 递归收集目录内目标文件的「相对路径 => 绝对路径」。
 *
 * @param string $root        根目录。
 * @param string $base        用于计算相对路径的基准（默认同 root）。
 * @param int    $count_ref   文件计数（引用，受上限约束）。
 * @return array<string,string>
 */
function jinyu_integrity_collect( string $root, string $base = '', int &$count_ref = 0 ): array {
	$map = array();
	if ( '' === $base ) {
		$base = $root;
	}
	if ( ! is_dir( $root ) || ! is_readable( $root ) ) {
		return $map;
	}
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iter as $file ) {
		if ( $count_ref >= JINYU_INTEGRITY_MAX_FILES ) {
			break;
		}
		if ( $file->isDir() ) {
			if ( in_array( $file->getFilename(), JINYU_INTEGRITY_SKIP_DIRS, true ) ) {
				continue;
			}
			continue;
		}
		$ext = strtolower( $file->getExtension() );
		if ( ! in_array( $ext, JINYU_INTEGRITY_EXTS, true ) ) {
			continue;
		}
		$abs = $file->getPathname();
		$rel = ltrim( preg_replace( '#^' . preg_quote( $base, '#' ) . '#', '', $abs ), '/' );
		$map[ $rel ] = $abs;
		++$count_ref;
	}
	return $map;
}

/**
 * 计算一组文件的 sha256 指纹表。
 *
 * @param array<string,string> $files 相对路径 => 绝对路径。
 * @return array<string,string>         相对路径 => hash。
 */
function jinyu_integrity_hashes( array $files ): array {
	$hashes = array();
	foreach ( $files as $rel => $abs ) {
		if ( ! is_readable( $abs ) ) {
			continue;
		}
		$h = hash_file( 'sha256', $abs );
		if ( false !== $h ) {
			$hashes[ $rel ] = $h;
		}
	}
	ksort( $hashes );
	return $hashes;
}

/**
 * 建立基线（插件自身 + 当前启用主题）。
 *
 * @return bool 是否成功建立。
 */
function jinyu_integrity_build_baseline(): bool {
	$count = 0;
	$plugin_files = jinyu_integrity_collect( JINYU_COMPANION_DIR, JINYU_COMPANION_DIR, $count );
	$theme_files  = array();
	if ( jinyu_integrity_settings()['scan_theme'] ) {
		$theme = wp_get_theme();
		$theme_root = $theme->exists() ? $theme->get_stylesheet_directory() : '';
		if ( '' !== $theme_root ) {
			$theme_files = jinyu_integrity_collect( $theme_root, $theme_root, $count );
		}
	}
	$baseline = array(
		'built'   => time(),
		'plugin'  => jinyu_integrity_hashes( $plugin_files ),
		'theme'   => jinyu_integrity_hashes( $theme_files ),
	);
	$state = jinyu_integrity_settings();
	$state['baseline']       = $baseline;
	$state['baseline_built'] = $baseline['built'];
	update_option( JINYU_INTEGRITY_OPT, $state );
	return true;
}

/**
 * 读取基线（不存在则返回空结构）。
 *
 * @return array
 */
function jinyu_integrity_get_baseline(): array {
	$state = jinyu_integrity_settings();
	if ( empty( $state['baseline'] ) || ! is_array( $state['baseline'] ) ) {
		return array(
			'built' => 0,
			'plugin' => array(),
			'theme' => array(),
		);
	}
	return $state['baseline'];
}

/**
 * 比对单组（基线 vs 当前），返回 changed / added / removed 三类相对路径。
 *
 * @param array<string,string> $base 基线哈希表。
 * @param array<string,string> $curr 当前哈希表。
 * @return array{changed:string[],added:string[],removed:string[]}
 */
function jinyu_integrity_diff( array $base, array $curr ): array {
	$changed = array();
	$added   = array();
	$removed = array();
	foreach ( $curr as $rel => $h ) {
		if ( ! isset( $base[ $rel ] ) ) {
			$added[] = $rel;
		} elseif ( $base[ $rel ] !== $h ) {
			$changed[] = $rel;
		}
	}
	foreach ( $base as $rel => $h ) {
		if ( ! isset( $curr[ $rel ] ) ) {
			$removed[] = $rel;
		}
	}
	return array(
		'changed' => $changed,
		'added'   => $added,
		'removed' => $removed,
	);
}

/**
 * 恶意码特征（保守集合，刻意偏向「高置信度 webshell / 混淆」以降低误报）。
 *
 * @return string[] 正则（不区分大小写）。
 */
function jinyu_integrity_malware_patterns(): array {
	return array(
		'/eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',
		'/base64_decode\s*\(\s*["\']?[A-Za-z0-9+\/=]{200,}/i',
		'/(?:assert|create_function|preg_replace)\s*\(\s*[\'"]\s*\/\^?.*\/e/i',
		'/(?<![a-z0-9_])(?:shell_exec|exec|system|passthru|popen|proc_open|pcntl_exec)\s*\(/i',
		'/(?<![a-z0-9_])(?:curl_exec|fsockopen|pfsockopen)\s*\(/i',
		'/\$_(?:GET|POST|REQUEST|COOKIE|SERVER)\s*\[\s*[\'"].{0,20}["\']\s*\]\s*\(/i',
		'/<\?php\s*\$_(?:GET|POST|REQUEST)\s*\[.{0,30}\]\s*\(/i',
		'/(?:base64|str_rot13|gzinflate|gzdeflate|gzencode)\s*\(\s*[\'"]?[A-Za-z0-9+\/=]{120,}/i',
	);
}

/**
 * 扫描一组文件内容，返回命中清单。
 *
 * @param array<string,string> $files 相对路径 => 绝对路径。
 * @return array<int,array{file:string,line:int,pattern:string}>
 */
function jinyu_integrity_malware_scan( array $files ): array {
	$patterns = jinyu_integrity_malware_patterns();
	$hits     = array();
	foreach ( $files as $rel => $abs ) {
		if ( ! is_readable( $abs ) ) {
			continue;
		}
		$ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
		if ( 'php' !== $ext ) {
			continue; // 仅 PHP 需扫语法级特征
		}
		$content = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 本地可信文件路径读取
		if ( false === $content ) {
			continue;
		}
		$lines = preg_split( '/\r\n|\r|\n/', $content );
		if ( false === $lines ) {
			$lines = array( $content );
		}
		foreach ( $lines as $idx => $line ) {
			foreach ( $patterns as $p ) {
				if ( preg_match( $p, $line ) ) {
					$hits[] = array(
						'file'    => $rel,
						'line'    => $idx + 1,
						'pattern' => $p,
					);
					break;
				}
			}
			if ( count( $hits ) >= 200 ) {
				break 2;
			}
		}
	}
	return $hits;
}

/**
 * 执行一次完整自检：完整性比对 + 恶意码扫描。返回结构化结果。
 *
 * @return array{ok:bool,modified:string[],added:string[],removed:string[],malware:array,ran:int}
 */
function jinyu_integrity_run(): array {
	$empty = array(
		'ok'       => true,
		'modified' => array(),
		'added'    => array(),
		'removed'  => array(),
		'malware'  => array(),
		'ran'      => time(),
	);

	$state    = jinyu_integrity_settings();
	$baseline = jinyu_integrity_get_baseline();
	if ( empty( $baseline['plugin'] ) && empty( $baseline['theme'] ) ) {
		// 首次运行无基线：静默建立，不告警（避免误报）。
		jinyu_integrity_build_baseline();
		return $empty;
	}

	$count         = 0;
	$plugin_files  = jinyu_integrity_collect( JINYU_COMPANION_DIR, JINYU_COMPANION_DIR, $count );
	$plugin_curr   = jinyu_integrity_hashes( $plugin_files );
	$diff_plugin   = jinyu_integrity_diff( (array) ( $baseline['plugin'] ?? array() ), $plugin_curr );

	$diff_theme = array(
		'changed' => array(),
		'added' => array(),
		'removed' => array(),
	);
	if ( $state['scan_theme'] ) {
		$theme = wp_get_theme();
		$theme_root = $theme->exists() ? $theme->get_stylesheet_directory() : '';
		if ( '' !== $theme_root ) {
			$theme_files = jinyu_integrity_collect( $theme_root, $theme_root, $count );
			$theme_curr  = jinyu_integrity_hashes( $theme_files );
			$diff_theme  = jinyu_integrity_diff( (array) ( $baseline['theme'] ?? array() ), $theme_curr );
		}
	}

	$modified = array_merge(
		array_map( static fn( $p ) => 'plugin: ' . $p, $diff_plugin['changed'] ),
		array_map( static fn( $p ) => 'theme: ' . $p, $diff_theme['changed'] )
	);
	$added    = array_merge(
		array_map( static fn( $p ) => 'plugin: ' . $p, $diff_plugin['added'] ),
		array_map( static fn( $p ) => 'theme: ' . $p, $diff_theme['added'] )
	);
	$removed  = array_merge(
		array_map( static fn( $p ) => 'plugin: ' . $p, $diff_plugin['removed'] ),
		array_map( static fn( $p ) => 'theme: ' . $p, $diff_theme['removed'] )
	);

	$malware = array();
	if ( $state['malware_enable'] ) {
		$malware = jinyu_integrity_malware_scan( $plugin_files );
		if ( $state['scan_theme'] && '' !== ( $theme_root ?? '' ) ) {
			$theme_files = $theme_files ?? array();
			$malware     = array_merge( $malware, jinyu_integrity_malware_scan( $theme_files ) );
		}
	}

	$ok = empty( $modified ) && empty( $added ) && empty( $removed ) && empty( $malware );

	$state['last_run']    = time();
	$state['last_status'] = $ok ? 'clean' : 'alert';
	update_option( JINYU_INTEGRITY_OPT, $state );

	$result = array(
		'ok'       => $ok,
		'modified' => $modified,
		'added'    => $added,
		'removed'  => $removed,
		'malware'  => $malware,
		'ran'      => $state['last_run'],
	);

	if ( ! $ok && $state['email_alert'] ) {
		jinyu_integrity_alert( $result );
	}
	return $result;
}

/**
 * 发送告警邮件（复用全站 wp_mail；SMTP 接管时经 SmtpConfig）。
 *
 * @param array $result jinyu_integrity_run() 返回结构。
 */
function jinyu_integrity_alert( array $result ): void {
	$admin_email = get_option( 'admin_email' );
	if ( ! is_email( $admin_email ) ) {
		return;
	}
	$lines   = array();
	$lines[] = sprintf( '[%s] 站点完整性自检发现异常', wp_parse_url( home_url(), PHP_URL_HOST ) );
	$lines[] = '时间：' . wp_date( 'Y-m-d H:i:s' );
	$lines[] = '';
	if ( ! empty( $result['modified'] ) ) {
		$lines[] = '◆ 被修改的文件（' . count( $result['modified'] ) . '）：';
		$lines[] = implode( "\n", array_slice( $result['modified'], 0, 50 ) );
		$lines[] = '';
	}
	if ( ! empty( $result['added'] ) ) {
		$lines[] = '◆ 新增的文件（' . count( $result['added'] ) . '）：';
		$lines[] = implode( "\n", array_slice( $result['added'], 0, 50 ) );
		$lines[] = '';
	}
	if ( ! empty( $result['removed'] ) ) {
		$lines[] = '◆ 被删除的文件（' . count( $result['removed'] ) . '）：';
		$lines[] = implode( "\n", array_slice( $result['removed'], 0, 50 ) );
		$lines[] = '';
	}
	if ( ! empty( $result['malware'] ) ) {
		$lines[] = '◆ 恶意码命中（' . count( $result['malware'] ) . '）：';
		foreach ( array_slice( $result['malware'], 0, 50 ) as $h ) {
			$lines[] = sprintf( '  %s : 第 %d 行', $h['file'], $h['line'] );
		}
		$lines[] = '';
	}
	$lines[] = '本邮件由「金玉主题配套插件 · 文件完整性监控」自动发出。请登录服务器人工核实，勿直接信任本告警。';

	$subject = sprintf( '[金玉] 站点完整性告警：%s', wp_parse_url( home_url(), PHP_URL_HOST ) );
	wp_mail( $admin_email, $subject, implode( "\n", $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 纯文本邮件内容
	error_log( 'Jinyu Integrity: ' . count( $result['modified'] ) . ' modified, ' . count( $result['added'] ) . ' added, ' . count( $result['removed'] ) . ' removed, ' . count( $result['malware'] ) . ' malware hits.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 完整性告警的运维日志出口（与邮件同一步双通道），便于 SSH 侧 grep 追溯，非调试残留。
}

/**
 * 调度每日自检（激活时注册；禁用时清除）。
 */
function jinyu_integrity_schedule(): void {
	if ( jinyu_integrity_is_disabled() ) {
		if ( wp_next_scheduled( JINYU_INTEGRITY_CRON ) ) {
			wp_unschedule_event( (int) wp_next_scheduled( JINYU_INTEGRITY_CRON ), JINYU_INTEGRITY_CRON );
		}
		return;
	}
	if ( ! wp_next_scheduled( JINYU_INTEGRITY_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', JINYU_INTEGRITY_CRON );
	}
	// 首次激活若无基线，立即建一份，避免首日空跑告警。
	$state = jinyu_integrity_settings();
	if ( empty( $state['baseline'] ) ) {
		jinyu_integrity_build_baseline();
	}
}

add_action( JINYU_INTEGRITY_CRON, 'jinyu_integrity_run' );
add_action( 'init', 'jinyu_integrity_schedule', 20 );
