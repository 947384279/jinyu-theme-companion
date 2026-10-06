<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 查找可用的中文字体（跨平台路径探测）。
 *
 * 海报生成与水印文字都需要 CJK 字体：Linux 服务器若无字体，中文会渲染成方块。
 * 这里是唯一真源，poster.php 亦复用之，避免同一份候选列表出现第二份拷贝。
 *
 * @return string 字体文件路径，找不到返回空串。
 */
function jinyu_companion_find_font(): string {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}
	$candidates = array(
		'/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
		'/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
		'/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
		'/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
		'/usr/share/fonts/truetype/arphic/uming.ttc',
		'/usr/share/fonts/truetype/arphic/ukai.ttc',
		'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
		'/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
		'/System/Library/Fonts/PingFang.ttc',
		'/System/Library/Fonts/Hiragino Sans GB.ttc',
		'C:/Windows/Fonts/msyh.ttc',
		'C:/Windows/Fonts/msyhbd.ttc',
		'C:/Windows/Fonts/simsun.ttc',
		defined( 'ABSPATH' ) ? ABSPATH . 'wp-includes/fonts/opensans/OpenSans-Regular.ttf' : '',
	);
	foreach ( $candidates as $f ) {
		if ( $f && file_exists( $f ) ) {
			$cached = (string) $f;
			return $cached;
		}
	}
	// 兜底：扫描插件自带字体目录，让「把开源字体放进 assets/fonts/」真正生效。
	// GLOB_BRACE 部分平台（musl/Alpine 编译的 PHP）不存在，缺常量时按扩展名逐一 glob，避免 fatal。
	$font_dir = dirname( dirname( __DIR__ ) ) . '/assets/fonts';
	if ( is_dir( $font_dir ) ) {
		$font_files = defined( 'GLOB_BRACE' )
			? glob( $font_dir . '/*.{ttf,ttc,otf,woff,woff2}', GLOB_BRACE )
			: array_merge( ...array_map( static fn( $ext ) => glob( $font_dir . '/*.' . $ext ) ?: array(), array( 'ttf', 'ttc', 'otf', 'woff', 'woff2' ) ) );
		foreach ( (array) $font_files as $ff ) {
			if ( $ff && is_file( $ff ) ) {
				$cached = (string) $ff;
				return $cached;
			}
		}
	}
	$cached = '';
	return $cached;
}

/**
 * 是否正处于水印的内部处理流程中。
 *
 * 还原缩略图、重建 metadata 都会顺带把本插件的 `wp_generate_attachment_metadata`
 * 回调再触发一遍——`wp_generate_attachment_metadata()` 返回后，它的调用方通常紧接
 * 一行 `wp_update_attachment_metadata()`，而后者内部同样会 apply 同一个 filter。
 * 不拦住的话，「一键去除水印」会在还原完成的同一轮把原图重新打上水印，用户看到
 * 的是「点了没反应」。
 */
function jinyu_companion_wm_guarding(): bool {
	// 注意：这里与 jinyu_companion_wm_guard() 必须共用同一个计数器。
	// 此前两个函数各用自己的 static，读取侧永远是 0，守卫形同虚设，
	// regen_sizes 会把 wp_generate_attachment_metadata 递归调用到内存耗尽。
	return (int) ( $GLOBALS['jinyu_wm_deep'] ?? 0 ) > 0;
}

/** 进入（true）/ 退出（false）水印内部处理区。退出直接归零，嵌套时内层退出即可解锁。 */
function jinyu_companion_wm_guard( bool $on ): int {
	$GLOBALS['jinyu_wm_deep'] = $on ? ( (int) ( $GLOBALS['jinyu_wm_deep'] ?? 0 ) + 1 ) : 0;
	return (int) $GLOBALS['jinyu_wm_deep'];
}

/**
 * 本请求内是否已经处理过该附件（登记 + 判重）。
 *
 * 光靠上面的 guard 不够：guard 只罩得住 regen_sizes 自己发起的那次 metadata 流程，
 * 而 wp_generate_attachment_metadata() 返回之后，WP 调用方还会补一句
 * wp_update_attachment_metadata()，它同样会对本插件的 filter 再 apply 一次——
 * 那句不在任何守卫区间里。所以同一请求内必须认「这个附件已经处理过了」，
 * 否则一次上传连带重建会把原图连打两遍水印。
 */
function jinyu_companion_wm_handled( int $attach_id ): bool {
	static $handled = array();
	if ( isset( $handled[ $attach_id ] ) ) {
		return true;
	}
	$handled[ $attach_id ] = true;
	return false;
}

/**
 * 图片水印引擎（主题无关，可脱离金玉主题独立运行）。
 *
 * 设计要点：
 * 1) 介入点固定为 wp_generate_attachment_metadata（早于 add_attachment 触发的对象存储上云），
 *    保证「本地已打水印 → 云端同步到的也是水印图」，不会出现本地/云端双源漂移。
 * 2) 原图采用 rename 式备份：处理时把原文件改名为 xxx-jywmo.ext，水印图占用原路径。
 *    逻辑路径（_wp_attachment_metadata['file']、storage 推送映射）完全不变，
 *    既零额外磁盘占用，也让「去除水印」退化成一次改名。
 * 3) 幂等：附件 meta _jinyu_wm_sig 记录配置签名，签名一致直接跳过；
 *    配置变更后签名不同，重新处理时从备份原图重建，绝不会在水印图上再叠水印。
 * 4) 只用 WP 核心 API，不调任何主题函数，分发到第三方站点同样可用。
 */

final class Jinyu_Watermark {

	/** 引擎版本：改渲染逻辑后 bump，触发存量图按新规则重建。 */
	const VER = '2';

	/** 原图备份文件名后缀。 */
	const BK = '-jywmo';

	/** 字号上限：不允许超过底图短边的比例，防止小图上水印糊满。 */
	const MAX_SHORT_RATIO = 0.18;

	/** 记录配置签名与处理状态的附件 meta 键。 */
	const META = '_jinyu_wm_sig';

	/**
	 * 「本插件亲手写出的水印版」登记表：basename => [水印版 md5, 原图 md5]。
	 *
	 * 还原必须先确认现行文件是我们生成的水印版，才能放心把 -jywmo 备份盖回去。
	 * 只有文件名是不够的：用户把同一路径的图换掉之后文件名完全不变，单看名字
	 * 依旧会被当成「自己打的水印版」，还原的一瞬间就把用户的新图顶掉。
	 * 所以登记的是内容指纹——现图的 md5 必须落在这两个值里，才允许还原。
	 * 光靠 mtime 更不行：「刚打完水印」和「打完水印后用户又换了图」这两种情形，
	 * 现图 mtime 都比备份新，mtime 根本分不开它们。
	 */
	const META_FILES = '_jinyu_wm_files';

	/** 支持处理的图片扩展名。 */
	const EXTS = array( 'jpg', 'jpeg', 'png', 'webp' );

	/**
	 * 由 jpg/png 实时转出的 WebP 副本所用的质量。
	 *
	 * 必须与站点主题生成 webp 时的质量一致（当前主题固定 80）。同一张图若存在
	 * 两份质量不同的 webp，前台会随机挑到其中一份，水印有无就成了看运气的事。
	 */
	const WEBP_Q = 80;

	/** 保护性跳过（不算失败）的原因。 */
	const SKIPS = array( 'too_small', 'too_large', 'too_big_file', 'backup_file', 'unsupported_type', 'not_found', 'backup_exists', 'inherited' );

	/**
	 * 同族原图：把 -WxH 尺寸后缀剥掉后指向的那份文件。
	 *
	 * cover_x-768x512.jpg 的族源是 cover_x.jpg。文件本身就是原图（没有尺寸
	 * 后缀）时返回空串 —— 它没有更高一级的源。找不到同族文件也返回空串。
	 */
	private static function family_source( string $file ): string {
		$dir  = (string) pathinfo( $file, PATHINFO_DIRNAME );
		$name = (string) pathinfo( $file, PATHINFO_FILENAME );
		$ext  = (string) pathinfo( $file, PATHINFO_EXTENSION );
		if ( ! preg_match( '/^(.+)-\d+x\d+$/', $name, $m ) ) {
			return '';
		}
		$p = $dir . '/' . $m[1] . '.' . $ext;
		return is_file( $p ) ? $p : '';
	}

	/**
	 * 这张图的「族」是否已带水印：族源图有 rename 式备份 = 原图已被本插件
	 * 处理过且此刻是水印版。凡是这种原图派生出来的新文件（regen 重建、编辑器
	 * 重采样），水印都会跟着原图缩小一份在里面 —— 它们绝不能再补打，否则
	 * 就是用户报告的「双水印重影」。文件自己是原图时看自己的备份。
	 */
	private static function family_watermarked( string $file ): bool {
		$src = self::family_source( $file );
		if ( '' === $src ) {
			$src = $file; // 自己就是原图：看自己有没有备份
		}
		return is_file( self::backup_path( $src ) );
	}

	/**
	 * 水印配置（带默认值）。全部落在 jinyu_companion_settings；
	 * 读写必须走插件的 jinyu_companion_get_option / jinyu_companion_save_settings，
	 * 绕过缓存层会导致「第一次保存不生效」。
	 */
	public static function config(): array {
		return array(
			'on_upload' => (bool) jinyu_companion_is_checked( 'img_wm_on_upload', true ),
			'text'      => (string) jinyu_companion_get_option( 'img_wm_text', '' ),
			'logo'      => (string) jinyu_companion_get_option( 'img_wm_logo', '' ),
			'size'      => (int) jinyu_companion_get_option( 'img_wm_size', 18 ),        // 字号，px；小图按 MAX_RATIO 自动缩
			'color'     => (string) jinyu_companion_get_option( 'img_wm_color', '#ffffff' ), // 文字颜色，十六进制
			'opacity'   => (int) jinyu_companion_get_option( 'img_wm_opacity', 60 ),     // 1-100
			'pos'       => (int) jinyu_companion_get_option( 'img_wm_pos', 9 ),          // 九宫格，9=右下
			'margin'    => (float) jinyu_companion_get_option( 'img_wm_margin', 0.02 ),  // 边距占短边比例
			'quality'   => (int) jinyu_companion_get_option( 'img_wm_quality', 82 ),     // JPEG 输出质量
			'sizes'     => (array) jinyu_companion_get_option( 'img_wm_sizes', array( 'full', 'large' ) ),
			'min_w'     => (int) jinyu_companion_get_option( 'img_wm_min_w', 400 ),      // 低于此宽度的尺寸不打
			'max_px'    => (int) jinyu_companion_get_option( 'img_wm_max_px', 8000000 ), // 像素上限，防弱机 OOM
			'max_bytes' => (int) jinyu_companion_get_option( 'img_wm_max_bytes', 5242880 ),
		);
	}

	/** 配置签名：任一渲染相关项变更都会改变签名，从而触发重建。 */
	public static function signature(): string {
		$c     = self::config();
		$raw   = array();
		$keys  = array(
			'text',
			'logo',
			'size',
			'color',
			'opacity',
			'pos',
			'margin',
			'quality',
			'sizes',
			'ver' => self::VER,
		);
		foreach ( $keys as $k => $v ) {
			$raw[ $k ] = is_int( $k ) ? $c[ $v ] : $v;
		}
		// 纯白 = 引入「文字颜色」之前的硬编码行为。签名里把它归为「未设置」，
		// 存量图签名保持不变——否则升级后跑一次批量会把全库图无意义地重编码一遍。
		$raw['color'] = 'ffffff' === self::normalize_hex( (string) $c['color'] ) ? '' : self::normalize_hex( (string) $c['color'] );
		ksort( $raw );
		return substr( md5( wp_json_encode( $raw ) ), 0, 12 );
	}

	/** 总开关：启用 + 本环境有可用图像编辑器。 */
	public static function is_enabled(): bool {
		if ( ! jinyu_companion_is_checked( 'img_wm_enable', false ) ) {
			return false;
		}
		return self::supported();
	}

	/** 本环境是否可用图像处理（Imagick / GD 任一）。 */
	public static function supported(): bool {
		// is_enabled() → supported()，而它在上传链路与批量任务里每个附件都要判断一次；
		// 探测本身要生成一张临时 PNG 并实例化编辑器，逐图探测等于给 900 张图多做 900 次无用功。
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			$cached = false;
			return false;
		}
		$has_gd = function_exists( 'imagecreatetruecolor' );
		$has_im = class_exists( 'Imagick', false );
		if ( ! $has_gd && ! $has_im ) {
			return false;
		}
		// 探测必须用「真实图像文件」：临时生成 1x1 PNG 验证 WP_Image_Editor 能实例化。
		// 早期用 WP_CONTENT_DIR/index.php（非图片）探测，GD 读非图必失败，导致永远误判不可用。
		$tmp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'jinyu_wm_probe' ) : '';
		if ( $tmp ) {
			$png = $tmp . '.png';
			@wp_delete_file( $tmp );
			$im = $has_gd ? imagecreatetruecolor( 2, 2 ) : null;
			if ( $im ) {
				imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
				if ( imagepng( $im, $png ) ) {
					$probe = wp_get_image_editor( $png );
					@wp_delete_file( $png );
					if ( $probe instanceof WP_Image_Editor ) {
						$cached = true;
						return true;
					}
				} else {
					@wp_delete_file( $png );
				}
			}
		}
		// 临时文件探测失败（极端环境）：回退到扩展能力检测。
		$cached = $has_gd || $has_im;
		return $cached;
	}

	/**
	 * sizes[].file 的绝对路径。
	 *
	 * 这个字段的基准并不统一：多数站点存的是「附件同目录相对名」（如 photo-800x600.jpg），
	 * 早期 WP 生成的尺寸会连日期目录一起存（2026/09/photo-800x600.jpg）。只按同目录拼，
	 * 后一种就永远拼出个不存在的文件，缩略图水印静默地一张都打不上。
	 * 先按同目录试，落空再按 uploads 根目录拼。
	 */
	private static function size_file( string $dir, string $rel ) {
		if ( '' === $rel ) {
			return null;
		}
		$same = $dir . '/' . $rel;
		if ( is_file( $same ) ) {
			return $same;
		}
		static $base = null;
		if ( null === $base && function_exists( 'wp_upload_dir' ) ) {
			$u    = wp_upload_dir();
			$base = isset( $u['basedir'] ) ? (string) $u['basedir'] : '';
		}
		if ( $base ) {
			$p = $base . '/' . ltrim( $rel, '/' );
			if ( is_file( $p ) ) {
				return $p;
			}
		}
		return $same; // 都不存在时按同目录返回，由上层 is_file 判掉
	}

	/** 处理范围文件路径列表：原图 + 已存在的目标缩略图。 */
	public static function target_files( int $attach_id ): array {
		$file = (string) get_attached_file( $attach_id );
		if ( ! $file || ! is_file( $file ) ) {
			return array();
		}
		$list = array( $file );
		$c    = self::config();
		// full 恒在列表首位：只勾「原图」也要正常处理（早期 count<2 拦截会让
		// 「只打原图」的合法配置整体静默失效，已移除）。
		if ( ! is_array( $c['sizes'] ) ) {
			return array( $file );
		}
		$meta = wp_get_attachment_metadata( $attach_id );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			// 首次上传走到这儿：本回调就跑在 wp_generate_attachment_metadata() 的最后一行，
			// _wp_attachment_metadata 尚未落库，wp_get_attachment_metadata() 是空的。
			// 早期这里直接 return array()，把 full 一起丢掉——结果新上传的图一张水印都打不上，
			// 要等用户事后重新生成缩略图才碰巧补上。此时唯一能动的就只剩原图。
			return $list;
		}
		$dir = dirname( $file );
		foreach ( $c['sizes'] as $s ) {
			if ( 'full' === $s || empty( $meta['sizes'][ $s ]['file'] ) ) {
				continue;
			}
			$p = self::size_file( $dir, (string) $meta['sizes'][ $s ]['file'] );
			if ( $p && is_file( $p ) ) {
				$list[] = $p;
			}
		}
		$list = array_values( array_unique( $list ) );

		/*
		补两类「磁盘上有、metadata 里没有」的 webp，它们的共同点是：
		 * 前台在用的就是它们，而引擎从头到尾不知道它们的存在。
		 *
		 * ① 有 jpg/png 源的（派生同步）：不在此处理，apply/remove 结束后会由
		 *    sync_webp() 统一从源图重算。这里要是把它们塞进来走 rename 备份，
		 *    等于给一份副本另外存一份「原图」——那份原图对用户根本不存在，纯属浪费。
		 * ② 没有 jpg/png 源的孤儿 webp（典型：源图当年转 webp 后 jpg 尺寸被删了）：
		 *    它已经是没有源可参考的独立文件，只能按普通文件处理，走 rename 备份。 */
		foreach ( $list as $f ) {
			$d = dirname( $f );
			// 该文件的「族名」：x.jpg 与 x-768x512.jpg 同族，都是 x
			$fam  = (string) pathinfo( basename( $f ), PATHINFO_FILENAME );
			$dups = array(); // 本轮已认领的副本，避免同族多个文件重复收
			foreach ( glob( $d . '/' . $fam . '*.webp' ) as $p ) {
				if ( ! is_file( $p ) || in_array( $p, $list, true ) || isset( $dups[ $p ] ) ) {
					continue;
				}
				$stem = (string) pathinfo( basename( $p ), PATHINFO_FILENAME );
				// 只认自己这一族的：x.webp / x-768x512.webp，不要 x-1.webp 那种邻居
				if ( $stem !== $fam && 0 !== strpos( $stem, $fam . '-' ) ) {
					continue;
				}
				$has_src = false;
				foreach ( array( '.jpg', '.jpeg', '.png' ) as $e ) {
					if ( is_file( $d . '/' . $stem . $e ) ) {
						$has_src = true;
						break;
					}
				}
				$dups[ $p ] = true;
				if ( $has_src ) {
					continue; // ① 派生同步，交给 sync_webp()，不在此重命名备份
				}
				// ② 孤儿副本：源图不在，只能当独立文件处理
				if ( false !== strpos( basename( $p ), self::BK ) ) {
					continue;
				}
				$list[] = $p;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * 同源的 WebP 派生副本路径（不存在返回空串）。
	 *
	 * 只认 jpg/jpeg/png 的「同名」副本，主图本身就是 webp 的情况不适用 ——
	 * 那种 webp 是用户传上来的原始数据，与派生副本完全是两回事。
	 */
	private static function webp_of( string $file ): string {
		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'jpg' !== $ext && 'jpeg' !== $ext && 'png' !== $ext ) {
			return '';
		}
		$p = (string) pathinfo( $file, PATHINFO_DIRNAME ) . '/' . (string) pathinfo( $file, PATHINFO_FILENAME ) . '.webp';
		return is_file( $p ) ? $p : '';
	}

	/**
	 * 让 WebP 派生副本跟随源图（jpg/png）的当前内容。
	 *
	 * WebP 从来不是用户原始数据，只是站点主题为前台交付实时转出来的同名副本
	 * （上传时转一次，前台首访时若缺失再补转一次）。它因此不进入 rename 式备份
	 * 体系：那份「原版」对用户不存在，备份它没有恢复价值。
	 *
	 * 源图落定后整体重算，而不是增量合成——
	 *   加水印：源图已带水印 → 副本必带水印；
	 *   去水印：源图已还原 → 副本重算成干净的无水印版。
	 * 这样前台拿到的永远和源图一致，且文件始终存在：既不会凭空多出一批 -jywmo.webp
	 * 垃圾，也不会因为删除副本导致整页缓存里的 URL 变成 404。
	 *
	 * @return bool 是否成功重算
	 */
	private static function sync_webp( string $file ): bool {
		if ( '' === self::webp_of( $file ) ) {
			return false; // 没有副本可同步
		}
		if ( ! is_file( $file ) ) {
			return false;
		}
		$editor = wp_get_image_editor( $file );
		if ( ! $editor || is_wp_error( $editor ) || ! $editor->load() ) {
			return false;
		}
		// 目标路径就是标准 .webp，不带任何自定义后缀：
		// WP 7.1 的 get_output_format() 会把自定义后缀当扩展名剥掉再换回标准扩展名，
		// 给 save() 传带后缀的临时名会写一个根本不存在的路径上去。
		$editor->set_quality( self::WEBP_Q );
		$dst = (string) pathinfo( $file, PATHINFO_DIRNAME ) . '/' . (string) pathinfo( $file, PATHINFO_FILENAME ) . '.webp';
		return (bool) $editor->save( $dst, 'image/webp' );
	}

	/** 登记表：basename => [水印版 md5, 原图 md5]，供还原时核对现行文件到底是谁。 */
	public static function registry( int $attach_id ): array {
		$raw = get_post_meta( $attach_id, self::META_FILES, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $k => $v ) {
			if ( is_array( $v ) && isset( $v[0], $v[1] ) ) {
				$out[ (string) $k ] = array( (string) $v[0], (string) $v[1] );
			}
		}
		return $out;
	}

	/** 两个文件是否内容一致（体积已被 max_bytes 挡住，md5 可接受）。 */
	private static function file_same( string $a, string $b ): bool {
		if ( ! is_file( $a ) || ! is_file( $b ) ) {
			return false;
		}
		if ( filesize( $a ) !== filesize( $b ) ) {
			return false;
		}
		if ( ! function_exists( 'md5_file' ) ) {
			return false;
		}
		return (string) md5_file( $a ) === (string) md5_file( $b );
	}

	/**
	 * 对单个文件应用水印（rename 式备份）。
	 *
	 * @param string     $file  目标文件绝对路径。
	 * @param array|null $proof 登记簿里该文件的 [水印版 md5, 原图 md5]：
	 *   命中 [0]（现图确是我们打的水印版）时，允许「还原到备份原图再按当前配置重打」，
	 *   这是配置变更后水印能跟着更新的唯一路径；无登记则维持保护性跳过。
	 * @return array {file, ok, skip, reason}
	 */
	public static function apply_file( string $file, $proof = null ): array {
		$out = array(
			'file' => $file,
			'ok' => false,
			'skip' => false,
			'reason' => '',
		);

		$ext = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::EXTS, true ) ) {
			return self::skip( $out, 'unsupported_type' );
		}
		// 备份文件本身不再处理
		if ( false !== strpos( (string) basename( $file ), self::BK ) ) {
			return self::skip( $out, 'backup_file' );
		}
		if ( ! is_file( $file ) ) {
			// 目标文件已被外部删除（换过介质、被别的插件清理）：属于「无事可做」，不是失败
			return self::skip( $out, 'not_found' );
		}

		$c = self::config();
		$info = @getimagesize( $file );
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			$out['reason'] = 'unreadable';
			return $out;
		}
		if ( (int) $info[0] < (int) $c['min_w'] ) {
			return self::skip( $out, 'too_small' );
		}
		if ( ( (int) $info[0] * (int) $info[1] ) > (int) $c['max_px'] ) {
			return self::skip( $out, 'too_large' );
		}
		if ( filesize( $file ) > (int) $c['max_bytes'] ) {
			return self::skip( $out, 'too_big_file' );
		}

		$editor = wp_get_image_editor( $file );
		// wp_get_image_editor 失败返回 WP_Error 对象（truthy），必须用 is_wp_error 拦，
		// 否则 WP_Error 上调 load() 直接 fatal undefined method。
		if ( ! $editor || is_wp_error( $editor ) || ! $editor->load() ) {
			$out['reason'] = 'no_editor';
			return $out;
		}
		// WP_Image_Editor::save() 只有 2 个参数（文件、mime），第三参会被静默丢弃；
		// 输出质量必须走 set_quality()，否则面板的「JPEG 质量」设置从未生效过。
		$editor->set_quality( (int) $c['quality'] );

		$backup = self::backup_path( $file );

		/*
		备份已存在 = 这张图以前处理过、而水印版已经不在了（被删、被别的插件挪走、
		 * 或上次处理到一半崩了）。rename 会直接覆盖它，那份旧原图就永久没了。
		 * 只有「内容与现图完全一致」（重复处理的残留）才允许覆盖。
		 *
		 * 配置变更后的正确重打：现图 md5 命中登记簿 [0]、且备份内容仍是登记簿 [1]
		 * 记录的那份原图，才把备份盖回原路径再重打——这正是头注释承诺的
		 * 「从备份原图重建」。[0] 命中只是「现图是我们打的」，并不保证备份还是当年
		 * 那张原图：备份一旦被污染图（上一次事故留下的水印版）顶替，还原就等于
		 * 把污染图当原图，再叠一层水印，双水印事故就会原样复发。所以两条指纹
		 * 都必须对上。没有登记背书就绝不覆盖：保住一份别人的原图比打水印重要。 */
		if ( is_file( $backup ) && ! self::file_same( $backup, $file ) ) {
			if ( is_array( $proof ) && '' !== (string) $proof[0] && '' !== (string) $proof[1]
				&& (string) @md5_file( $file ) === (string) $proof[0]
				&& (string) @md5_file( $backup ) === (string) $proof[1] ) {
				// 现图=登记在册的水印版、备份=登记在册的原图：还原（备份→原路径），继续重打
				if ( ! @rename( $backup, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 水印备份/还原的原子 rename，保留 @rename 的原子与返回值语义
					jinyu_companion_log( 'rebuild restore failed, skipped file=' . $file, 'watermark' );
					return self::skip( $out, 'backup_exists' );
				}
				clearstatcache( true, $file );
			} else {
				// 按保护性跳过处理：绝不为了打水印去覆盖一份别人的原图。
				// 留一条日志，否则用户会看到「这张图一直没水印」却查不出原因。
				jinyu_companion_log( 'backup exists, skipped attachment file=' . $file, 'watermark' );
				return self::skip( $out, 'backup_exists' );
			}
		}

		/*
		继承水印防护：本文件没有备份，但同族原图带水印（有备份为证），而且
		 * 本文件比那份备份晚生成 —— 说明它是原图打上水印之后才派生出来的
		 * （regen_sizes 重建、外部脚本重采样等），水印已随原图缩小一份在里面。
		 * 这个时点之后再补打，就是用户看到的「双水印重影」。mtime 留 2 秒余量，
		 * 拿不准就不拦（守住了再说，误拦比误打好排查）。 */
		if ( ! is_file( $backup ) && self::family_watermarked( $file ) ) {
			$src  = self::family_source( $file );
			$sbak = self::backup_path( '' !== $src ? $src : $file );
			$bt   = is_file( $sbak ) ? (int) @filemtime( $sbak ) : 0;
			$ct   = (int) @filemtime( $file );
			if ( $bt > 0 && $ct > 0 && $ct >= $bt + 2 ) {
				jinyu_companion_log( 'inherited watermark, skipped file=' . $file, 'watermark' );
				return self::skip( $out, 'inherited' );
			}
		}

		/*
		① 先在内存里合成。这一步磁盘不动，失败就是干净失败。
		 *    合成必须单独做成「先于任何文件操作」，否则下面 rename 之后才发现
		 *    底下没人动过原图，回滚逻辑就要多绕一圈。 */
		if ( ! self::composite( $editor, $c ) ) {
			$out['reason'] = 'composite_failed';
			return $out;
		}

		/*
		② 原图 → 备份名。rename 是原子的：并发请求里只有一个能抢到这个名字，
		 *    另一个失败退出，不会互相踩。这正是去掉「临时文件 + rename」方案的理由——
		 *    WP 7.1 的 get_output_format() 会把自定义后缀当成扩展名剥掉再换回标准扩展名，
		 *    给 save() 传 $file . '.jyc-wm-xxx' 会被改写到 $file . '.jpg'，
		 *    真正的临时文件根本不存在，随后 rename 回原路径必然失败。 */
		if ( ! rename( $file, $backup ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 水印备份：原图→备份名，原子操作
			$out['reason'] = 'backup_failed';
			return $out;
		}
		/* ③ 水印版直接写回原路径。失败则把备份改回去，原图完整回来。 */
		if ( ! $editor->save( $file, self::save_mime( $ext ) ) ) {
			rename( $backup, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 水印回滚：备份图改回原路径
			$out['reason'] = 'apply_failed';
			return $out;
		}
		$out['ok']     = true;
		$out['reason'] = 'ok';
		return $out;
	}

	/**
	 * 还原单个文件：备份图改回原路径。无备份 = 该文件本就没打水印，视为已还原而非失败。
	 *
	 * 出参 $reason：ok / renamed / no_backup / stale_backup / conflict。
	 *
	 * 安全闸门一（mtime）：打水印时是 rename(原图→备份) 再放入新合成的水印图，所以
	 * 备份（原图）的 mtime 必然早于当前文件（水印图）。若备份反而更晚，说明有人在
	 * 备份之后又写过这个文件，还原会把内容盖回去。
	 *
	 * 安全闸门二（登记簿，真正的防覆盖）：mtime 区分不了下面两种情形——
	 *   ① 刚打完水印：备份=原图，现图=水印版（正常，允许还原）；
	 *   ② 打完水印后用户又换了这张图：备份=旧原图，现图=用户的新原图。
	 * 两者的现图 mtime 都比备份新，只有 _jinyu_wm_files 这张表能分辨。
	 * 由调用方给出 $proof（登记在册的 [水印版 md5, 原图 md5]）：
	 *   array  = 有登记，现图 md5 必须命中其中之一才放行，否则回报 conflict；
	 *   false  = 有登记但这个文件名不在册（水印版早被换掉了）→ 拒绝；
	 *   null   = 旧数据从没登记过 → 退回 mtime 那一套，行为与历史一致。
	 */
	public static function remove_file( string $file, ?string &$reason = null, $proof = null ): bool {
		$reason = 'no_backup';
		$backup = self::backup_path( $file );
		if ( ! is_file( $backup ) ) {
			return is_file( $file );
		}
		if ( ! is_file( $file ) ) {
			$reason = 'renamed';
			return rename( $backup, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 去除水印：备份直接回归原路径
		}
		if ( is_array( $proof ) ) {
			$now = @md5_file( $file );
			if ( $now === (string) $proof[0] || $now === (string) $proof[1] ) {
				// 现图就是登记过的那一张（水印版，或两个值已经一致）：还原无损
				return self::do_restore( $file, $backup, $reason );
			}
			$reason = 'conflict';
			return false;                           // 既不是水印版也不是原图：绝不动它
		}
		/* 没有登记（旧数据）：至少「现图与备份字节一致」时还原无损失，放行。 */
		if ( self::file_same( $backup, $file ) ) {
			$reason = 'ok';
			return true;
		}
		if ( false === $proof ) {
			$reason = 'conflict';
			return false;
		}
		$bt = (int) @filemtime( $backup );
		$ct = (int) @filemtime( $file );
		/*
		filemtime 只有秒级精度，而整张图（读图 → 合成 → 编码）在快机器上常常落在一秒内：
		 * 此时备份与水印图的 mtime 相等，用 >= 判断会把它误认成「用户重新上传过原图」，
		 * 于是正常的还原被整批拦下，用户看到的是「点了去除却纹丝不动」。
		 * 留 1 秒余量：真·换过原图时备份 mtime 明确更新，照样拦得住。 */
		if ( $bt > 0 && $ct > 0 && $bt > $ct + 1 ) {
			$reason = 'stale_backup';
			return false;
		}
		return self::do_restore( $file, $backup, $reason );
	}

	/** 真正把备份改回原路径，供 remove_file 的各条放行分支共用。 */
	private static function do_restore( string $file, string $backup, ?string &$reason ): bool {
		$reason = 'ok';
		return (bool) @rename( $backup, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- do_restore 备份改回原路径
	}

	/** 备份路径：xxx.jpg → xxx-jywmo.jpg。 */
	public static function backup_path( string $file ): string {
		$dir  = (string) pathinfo( $file, PATHINFO_DIRNAME );
		$base = (string) pathinfo( $file, PATHINFO_FILENAME );
		$ext  = (string) pathinfo( $file, PATHINFO_EXTENSION );
		return $dir . '/' . $base . self::BK . '.' . $ext;
	}

	/**
	 * 应用一个附件的全部目标文件，并回写幂等签名。
	 *
	 * @return array {done, total, errors, webp, marked}
	 *               webp = 成功重算的 WebP 派生副本数（副本同步失败不计入 errors，
	 *               只记日志：它是前台可见性的补充，不该让整张图被判成失败）
	 */
	public static function apply_attachment( int $attach_id ): array {
		$sig   = self::signature();
		$stored = (string) get_post_meta( $attach_id, self::META, true );
		$files = $stored === $sig ? array() : self::target_files( $attach_id );

		// 与已有的登记表取并集：改过尺寸配置后只有部分文件被重写，
		// 若整表替换，之前打过水印的缩略图会掉出表外，将来还原时反被当成「不是我们打的」。
		$marked = self::registry( $attach_id );

		$done = 0;
		$errors = 0;
		$webp = 0;
		foreach ( $files as $f ) {
			/*
			传入登记指纹：现图确是登记在册的水印版时，apply_file 内部会先
			 * 还原到备份原图再重打（配置变更后水印跟着更新的唯一路径）。 */
			$b = basename( $f );
			$r = self::apply_file( $f, isset( $marked[ $b ] ) ? $marked[ $b ] : null );
			if ( $r['skip'] && in_array( $r['reason'], self::SKIPS, true ) ) {
				continue; // 保护性跳过不算失败
			}
			if ( $r['ok'] ) {
				++$done;
				/*
				记下这一对指纹：水印版内容 + 备份内容。还原时据此确认现图
				 * 就是我们打的那一张，而不是用户后来换上的另一张。 */
				$marked[ basename( $f ) ] = array(
					(string) @md5_file( $f ),
					(string) @md5_file( self::backup_path( $f ) ),
				);
				/*
				源图落定后同步派生副本。必须排在 apply_file 之后：
				 * 此刻 $f 已经是水印版，副本照着它重算才带得上水印。
				 * 没有副本可同步的（孤儿 webp 自己就是目标文件）不记失败。 */
				if ( '' !== self::webp_of( $f ) ) {
					if ( self::sync_webp( $f ) ) {
						++$webp;
					} else {
						jinyu_companion_log( 'webp sync failed for ' . $f, 'watermark' );
					}
				}
			} else {
				++$errors;
			}
		}
		if ( $done > 0 ) {
			update_post_meta( $attach_id, self::META, $sig );
			update_post_meta( $attach_id, self::META_FILES, $marked );
		}
		return array(
			'done' => $done,
			'total' => count( $files ),
			'errors' => $errors,
			'webp' => $webp,
		);
	}

	/**
	 * 还原一个附件，并让 WP 依据还原后的原图重算元数据（缩略图是从原图派生的）。
	 *
	 * @return array {done, errors, blocked, webp}
	 */
	public static function remove_attachment( int $attach_id ): array {
		/*
		登记簿：只有在这张表里的文件才被认定是「本插件生成的水印版」。
		 * 表整个是空的说明装的是旧版本、从没写过表——那是历史行为，按「放行」处理，
		 * 免得刚升级的用户点一次去除发现全被拦下。 */
		$reg     = self::registry( $attach_id );
		$has_reg = ! empty( $reg );

		$done = 0;
		$errors = 0;
		$blocked = 0;
		$webp = 0;
		foreach ( self::target_files( $attach_id ) as $f ) {
			if ( ! is_file( $f ) ) {
				continue; } // 文件已不在，不算失败
			$reason = '';
			$proof  = $has_reg ? ( $reg[ basename( $f ) ] ?? false ) : null;
			if ( self::remove_file( $f, $reason, $proof ) ) {
				// 只认真正动过文件的两种结果：no_backup 表示这张本来就干净，
				// 混进来会让汇总虚报「已还原 N 张」，用户以为清干净了其实没有。
				if ( 'ok' === $reason || 'renamed' === $reason ) {
					++$done;
					// 源图已还原成干净原图，派生副本跟着重算：前台拿到的是无水印版，
					// 而不是还留着水印的那份旧副本。孤儿副本自己就是目标，无需再同步。
					if ( '' !== self::webp_of( $f ) ) {
						if ( self::sync_webp( $f ) ) {
							++$webp;
						} else {
							jinyu_companion_log( 'webp sync failed on remove for ' . $f, 'watermark' );
						}
					}
				}
			} elseif ( 'conflict' === $reason || 'stale_backup' === $reason ) {
				// 现图不是本插件打的水印版，还原会把用户现行的图盖掉 → 一动不动
				++$blocked;
			} elseif ( 'no_backup' !== $reason ) {
				// 无备份 = 这张本来就没打过水印，还原无事可做，不该在汇总里报失败
				++$errors;
			}
		}
		// 有被拦下的文件说明这批没清干净，此时保留签名：下次还能重新处理这些图，
		// 而不是因为 meta 已删导致「一键加水印」把它们当成未处理、进而覆盖新原图。
		if ( 0 === $blocked ) {
			delete_post_meta( $attach_id, self::META );
			delete_post_meta( $attach_id, self::META_FILES );
		}
		// 原图回去了，派生出来的缩略图也得跟着还原（它们此刻还是带水印的旧文件）。
		// 重建产物此刻是干净的（原图已还原），不登记 —— 这张附件正在走出引擎的管理。
		self::regen_sizes( $attach_id, false );
		return array(
			'done' => $done,
			'errors' => $errors,
			'blocked' => $blocked,
			'webp' => $webp,
		);
	}

	/**
	 * 按当前原图重建缩略图，并让 metadata 落库。
	 *
	 * 三个必需动作：
	 * 1) 先删掉已存在的尺寸文件。_wp_make_subsizes() 会「跳过 sizes 里已有的尺寸名」，
	 *    同名文件不删的话重建会直接跳过，原样拿回旧的无水印缩略图。
	 * 2) 只删当前仍注册着的尺寸。wp_create_image_subsizes() 只会重建当前注册的那些，
	 *    滞留在 sizes 里的孤儿尺寸（典型：加过 add_image_size 的主题被换掉）删掉之后
	 *    不会有任何东西再生成它，等于把用户的图删了。
	 * 3) 整个重算过程套在 jinyu_companion_wm_guard() 里。重算结束后的那一句
	 *    wp_update_attachment_metadata() 同样会触发本插件的上传 filter，
	 *    不拦会把刚还原的原图重新打上水印。
	 *
	 * 另有一条硬闸门：拿不到重建能力时，一个字节都不要删。
	 * wp_generate_attachment_metadata() 在 WP 7.1 搬到了 wp-admin/includes/image.php，
	 * 命令行场景（wp-cli、wp-cron、第三方批量脚本）默认不加载它。早期版本只判断
	 * 「存在才重建」，于是命令行下变成了「照删不误、删完发现和不了」——
	 * 刚刚还原好的原图被 unlink 掉的那一刻就没了。
	 */
	public static function regen_sizes( int $attach_id, bool $register = true ): void {
		/* 先把重建能力补齐：函数不在就主动引入定义它的那个文件。 */
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/image.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$can_rebuild = function_exists( 'wp_generate_attachment_metadata' );

		jinyu_companion_wm_guard( true );
		$file    = (string) get_attached_file( $attach_id );
		$meta    = wp_get_attachment_metadata( $attach_id );
		$rebuilt = array(); // 被本方法删掉并将按原图重新生成的尺寸文件 => 相对名
		if ( $can_rebuild && $file && is_file( $file ) && is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$registered = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
			$dir        = dirname( $file );
			foreach ( $meta['sizes'] as $name => $s ) {
				if ( ! is_string( $name ) || empty( $s['file'] ) ) {
					continue;
				}
				if ( is_array( $registered ) && isset( $registered[ $name ] ) ) {
					$p = self::size_file( $dir, (string) $s['file'] );
					if ( $p && is_file( $p ) ) {
						@wp_delete_file( $p );
						$rebuilt[ (string) $s['file'] ] = $p;
					}
				}
				// 未注册的尺寸：一个字节都不动，留着它，等有朝一日还有人认领它
			}
		}
		if ( $can_rebuild && $file && is_file( $file ) ) {
			wp_generate_attachment_metadata( $attach_id, $file );
		}
		jinyu_companion_wm_guard( false );

		/*
		重建出的尺寸文件是从「当前原图」重采样的：原图带水印时它们天生就带着
		 * 一层随缩放的水印，绝不能让后续流程再补打一次（那正是「双水印重影」
		 * 的成因）。这里直接把新指纹写进登记簿：在册的刷新第一项；不在册的按
		 * [新指纹, ''] 收进（'' = 它没有独立备份，水印来自原图，还原时由本方法
		 * 按还原后的干净原图兜底重建）。 */
		if ( ! empty( $rebuilt ) ) {
			$marked = self::registry( $attach_id );
			foreach ( $rebuilt as $rel => $p ) {
				if ( ! is_file( $p ) ) {
					continue; // 重建失败：登记维持原状，别把指纹刷成不存在的文件
				}
				$name = basename( $rel );
				$cur  = (string) @md5_file( $p );
				$old  = isset( $marked[ $name ] ) && is_array( $marked[ $name ] ) ? $marked[ $name ] : null;
				if ( null === $old ) {
					$marked[ $name ] = array( $cur, '' );
					continue;
				}
				$bak = self::backup_path( $p );
				if ( is_file( $bak ) && self::file_same( $bak, $p ) ) {
					@wp_delete_file( $bak ); // 旧备份与重建产物相同（就是上一轮的 regen 产物），没有还原价值
					$marked[ $name ] = array( $cur, '' );
				} else {
					$marked[ $name ] = array( $cur, (string) ( $old[1] ?? '' ) );
				}
			}
			update_post_meta( $attach_id, self::META_FILES, $marked );
		}
	}

	/** 合成入口：图片水印优先，其次文字水印。 */
	private static function composite( WP_Image_Editor $editor, array $c ): bool {
		if ( ! empty( $c['logo'] ) && is_file( $c['logo'] ) ) {
			return self::composite_logo( $editor, $c );
		}
		$font = jinyu_companion_find_font();
		if ( ! $font || '' === trim( (string) $c['text'] ) ) {
			return false; // 没有文字也没有水印图 → 无从处理
		}
		return self::composite_text( $editor, $c, $font );
	}

	/**
	 * 取编辑器当前画布尺寸 [w, h]。
	 *
	 * WP 7.1 移除了 WP_Image_Editor::width()/height()（全 wp-includes 已无定义），
	 * 改用 get_size() 返回 array('width'=>..,'height'=>..)。历史版本两者都在，
	 * 所以留了回退；取不到返回 [0,0]，调用方必须自行拦，别让 0 流进坐标运算。
	 */
	private static function dims( WP_Image_Editor $editor ): array {
		if ( is_object( $editor ) && method_exists( $editor, 'get_size' ) ) {
			$s = $editor->get_size();
			if ( is_array( $s ) && ! empty( $s['width'] ) && ! empty( $s['height'] ) ) {
				return array( (int) $s['width'], (int) $s['height'] );
			}
		}
		if ( is_object( $editor ) && method_exists( $editor, 'width' ) && method_exists( $editor, 'height' ) ) {
			return array( (int) $editor->width(), (int) $editor->height() );
		}
		return array( 0, 0 );
	}

	/**
	 * 取编辑器内部的画布对象（GD resource/GdImage 或 Imagick）。
	 *
	 * $editor->image 是 protected，本类不是它的子类，直接读写会 fatal。
	 * 只能反射取一次并在本次合成内复用（反射本身不做缓存，避免 fpm 长驻进程里按
	 * spl_object_id 累积泄漏；一次处理的开销可忽略）。
	 */
	private static function canvas( WP_Image_Editor $editor ) {
		if ( ! is_object( $editor ) || ! method_exists( $editor, 'load' ) ) {
			return null;
		}
		foreach ( array( 'image', 'core' ) as $prop ) {
			if ( ! property_exists( $editor, $prop ) ) {
				continue;
			}
			if ( isset( $editor->$prop ) ) { // 万一将来被改成 public
				return $editor->$prop;
			}
			try {
				$rp = new ReflectionProperty( $editor, $prop );
				$rp->setAccessible( true );
				$val = $rp->getValue( $editor );
				if ( $val ) {
					return $val;
				}
			} catch ( Throwable $e ) {
				continue; // 反射被禁（disable_classes 等）时跳过，交给下一项
			}
		}
		return null;
	}

	/** 文字水印。宽度用真实字体度量：按字符数估算会把英文高估近一倍，右对齐/居中全偏。 */
	/**
	 * 实际绘制字号（px）。
	 *
	 * 面板暴露的是固定 px（用户看得懂「68」比「0.08」直观），但固定值套到小图上会糊满：
	 * 「短边 × 比例」的老算法在 1280×853 上算出来是 68px，在 400×267 缩略图上就成了 21px，
	 * 比例算法对大图友好、对小图却会缩到看不清。这里改成「按设定值画，但不超过短边 18%」——
	 * 大图严格等于用户填的数，缩略图自动收缩，两种情况都不会失控。
	 */
	private static function font_size( int $px, int $short ): int {
		return (int) max( 8, min( $px, (int) floor( $short * self::MAX_SHORT_RATIO ) ) );
	}

	/**
	 * 归一化十六进制颜色为 6 位小写 hex 字符串。
	 *
	 * 支持 #rgb / #rrggbb，大小写与有无 # 都不挑；非法值回退 $fallback。
	 * 在图片合成热路径上，一个手输错的颜色不该让整张图打不上水印——所以永远返回可用值。
	 */
	private static function normalize_hex( string $hex, string $fallback = 'ffffff' ): string {
		$s = strtolower( trim( (string) $hex ) );
		if ( '' === $s ) {
			$s = $fallback;
		}
		if ( '#' === $s[0] ) {
			$s = substr( $s, 1 );
		}
		if ( 3 === strlen( $s ) && ctype_xdigit( $s ) ) {
			$s = $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
		}
		return ( 6 === strlen( $s ) && ctype_xdigit( $s ) ) ? $s : $fallback;
	}

	/** 十六进制颜色拆成 RGB 三元组。 */
	private static function color_rgb( string $hex, string $fallback = 'ffffff' ): array {
		$h = self::normalize_hex( $hex, $fallback );
		return array(
			(int) hexdec( substr( $h, 0, 2 ) ),
			(int) hexdec( substr( $h, 2, 2 ) ),
			(int) hexdec( substr( $h, 4, 2 ) ),
		);
	}

	/** 十六进制颜色转成 rgba() 字符串（Imagick 用）。 */
	private static function color_rgba( string $hex, float $alpha, string $fallback = 'ffffff' ): string {
		list( $r, $g, $b ) = self::color_rgb( $hex, $fallback );
		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, round( max( 0.0, min( 1.0, $alpha ) ), 3 ) );
	}

	private static function composite_text( WP_Image_Editor $editor, array $c, string $font ): bool {
		list( $iw, $ih ) = self::dims( $editor );
		if ( $iw < 1 || $ih < 1 ) {
			return false; // 量不到尺寸就别合成了，算出来的坐标全是越界的
		}
		$short = min( $iw, $ih );
		$size  = self::font_size( (int) $c['size'], $short );
		$pad   = (int) round( $short * (float) $c['margin'] );
		$text  = (string) $c['text'];
		$mw    = self::text_metrics( $editor, $text, $font, $size );
		$lw    = $mw['w'];
		$lh    = max( (int) round( $size * 1.6 ), 16 );
		list( $bx, $by ) = self::position( (int) $c['pos'], $lw, $lh, $pad, $iw, $ih );

		$color = (string) ( $c['color'] ?? '#ffffff' );
		if ( $editor instanceof WP_Image_Editor_Imagick ) {
			return self::composite_text_imagick( $editor, $text, $font, $size, $bx, $by, $lw, $lh, (int) $c['opacity'], $color );
		}
		if ( $editor instanceof WP_Image_Editor_GD && function_exists( 'imagettftext' ) ) {
			return self::composite_text_gd( $editor, $text, $font, $size, $bx, $by, $lw, $lh, (int) $c['opacity'], $color );
		}
		return false;
	}

	/**
	 * 文字真实宽度（px）。Imagick 走 queryFontMetrics，GD 走 imagettfbbox；
	 * 度量失败回退「CJK 按 1 字号、ASCII 按 0.55 字号」的加权估算（比原 mb_strlen 全按 1 算准得多）。
	 */
	private static function text_metrics( WP_Image_Editor $editor, string $text, string $font, int $size ): array {
		if ( $editor instanceof WP_Image_Editor_Imagick && class_exists( 'Imagick' ) ) {
			try {
				$draw = new ImagickDraw();
				$draw->setFont( $font );
				$draw->setFontSize( $size );
				$m = ( new Imagick() )->queryFontMetrics( $draw, $text );
				if ( ! empty( $m['textWidth'] ) ) {
					return array(
						'w' => (int) ceil( (float) $m['textWidth'] ),
						'h' => (int) ceil( (float) $m['textHeight'] ),
					);
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch,Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- 捕获 Imagick 查询字体尺寸失败后走 GD 回退，空 catch 是设计意图不是漏写
				/* 走回退 */ }
		}
		if ( $editor instanceof WP_Image_Editor_GD && function_exists( 'imagettfbbox' ) ) {
			$box = @imagettfbbox( $size, 0, $font, $text );
			if ( is_array( $box ) ) {
				return array(
					'w' => abs( (int) $box[2] - (int) $box[0] ) + 2,
					'h' => abs( (int) $box[5] - (int) $box[1] ),
				);
			}
		}
		$w = 0;
		foreach ( mb_str_split( $text ) as $ch ) {
			$w += ( ord( $ch ) < 128 ) ? (int) round( $size * 0.55 ) : $size;
		}
		return array(
			'w' => max( 1, $w ),
			'h' => (int) round( $size * 1.6 ),
		);
	}

	/** 九宫格定位：返回 [x, y]。 */
	private static function position( int $pos, int $lw, int $lh, int $pad, int $iw, int $ih ): array {
		$pos = ( $pos < 1 || $pos > 9 ) ? 9 : $pos;
		$col = $pos % 3;                        // 1/2/0 = 左/中/右
		$row = (int) floor( ( $pos - 1 ) / 3 ); // 0/1/2 = 上/中/下
		$x   = ( 1 === $col ) ? $pad : ( ( 0 === $col ) ? max( 0, $iw - $lw - $pad ) : (int) round( ( $iw - $lw ) / 2 ) );
		$y   = ( 0 === $row ) ? $pad : ( ( 2 === $row ) ? max( 0, $ih - $lh - $pad ) : (int) round( ( $ih - $lh ) / 2 ) );
		return array( $x, $y );
	}

	private static function composite_text_imagick( WP_Image_Editor $editor, string $text, string $font, int $size, int $x, int $y, int $lw, int $lh, int $opacity, string $color ): bool {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$a = max( 0.05, min( 1, (float) $opacity / 100 ) );
			$wm   = new Imagick();
			$draw = new ImagickDraw();
			// 画布用真实度量宽，不再写死 size*24：长文本被截断、短文本浪费合成
			$wm->newImage( max( $lw, 64 ), max( $lh, 16 ), new ImagickPixel( 'rgba(0,0,0,0)' ) );
			$wm->setImageFormat( 'png' );
			$draw->setFont( $font );
			$draw->setFontSize( $size );
			// 不透明度 + 自定义文字颜色都烘进填充色；描边固定深色，保证浅色图上仍然可读
			$draw->setFillColor( new ImagickPixel( self::color_rgba( $color, $a * 0.92 ) ) );
			$draw->setStrokeColor( new ImagickPixel( 'rgba(0,0,0,' . round( $a * 0.5, 3 ) . ')' ) );
			$draw->setStrokeWidth( max( 1, (int) round( $size / 30 ) ) );
			$wm->annotateImage( $draw, 0, (int) round( $size * 1.2 ), 0, $text );
			self::canvas( $editor )->compositeImage( $wm, Imagick::COMPOSITE_OVER, $x, $y );
			$wm->destroy();
			$draw->destroy();
			return true;
		} catch ( Exception $e ) {
			return false;
		}
	}

	private static function composite_text_gd( WP_Image_Editor $editor, string $text, string $font, int $size, int $x, int $y, int $lw, int $lh, int $opacity, string $color ): bool {
		// 层尺寸用调用方传入的真实度量；不再有 64px 下限（下限会让右对齐/居中偏移）
		$layer = imagecreatetruecolor( max( 1, $lw ), max( 1, $lh ) );
		if ( ! $layer ) {
			return false;
		}
		// 全透明底 + alpha 颜色画字：imagecopymerge 在 truecolor 下会忽略不透明度，
		// 因此不透明度直接烘进文字颜色的 alpha 通道，再走支持 alpha 的 imagecopy。
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $lw - 1, $lh - 1, imagecolorallocatealpha( $layer, 0, 0, 0, 127 ) );
		imagealphablending( $layer, true );
		// alpha = 不透明度：Imagick 分支写的是 opacity/100，这里写反成 (100-opacity) 会让
		// 「不透明度 60」渲染出 40% 的强度，两条路径对同一个设置给出相反结果。
		$a     = (int) round( max( 1, min( 100, $opacity ) ) / 100 * 127 );
		list( $r, $g, $b ) = self::color_rgb( $color );
		// 描边固定深色（在浅色图上垫底），填充色跟随「文字颜色」设置
		$edge = imagecolorallocatealpha( $layer, 0, 0, 0, max( 1, min( 127, $a + 20 ) ) );
		$fill = imagecolorallocatealpha( $layer, $r, $g, $b, $a );
		imagettftext( $layer, $size, 0, 2, (int) round( $size * 1.15 ), $edge, $font, $text );
		imagettftext( $layer, $size, 0, 0, (int) round( $size * 1.1 ), $fill, $font, $text );
		$cv = self::canvas( $editor );
		if ( ! is_gd_image( $cv ) ) {
			return false;
		}
		imagealphablending( $cv, true );
		$ok = imagecopy( $cv, $layer, $x, $y, 0, 0, $lw, $lh );
		return (bool) $ok;
	}

	/**
	 * 图片水印（PNG 带 alpha）。
	 * 宽度 = 底图短边 × ratio，等比缩放；九宫格定位与文字水印同一套 position()。
	 * 早期版本坐标硬编码 (0,0)，位置设置对图片水印无效，且 logo 不缩放、
	 * GD 路径用 imagecopymerge（truecolor 下忽略不透明度）——全部修正。
	 */
	private static function composite_logo( WP_Image_Editor $editor, array $c ): bool {
		$info = @getimagesize( (string) $c['logo'] );
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			return false;
		}
		list( $iw, $ih ) = self::dims( $editor );
		if ( $iw < 1 || $ih < 1 ) {
			return false;
		}
		$short = min( $iw, $ih );
		$pad   = (int) round( $short * (float) $c['margin'] );
		$lw    = self::font_size( (int) $c['size'], $short );
		$lh    = max( 1, (int) round( (int) $info[1] * $lw / (int) $info[0] ) );
		list( $x, $y ) = self::position( (int) $c['pos'], $lw, $lh, $pad, $iw, $ih );
		$op    = max( 1, min( 100, (int) $c['opacity'] ) );

		if ( $editor instanceof WP_Image_Editor_Imagick ) {
			if ( ! class_exists( 'Imagick' ) ) {
				return false;
			}
			try {
				$wm = new Imagick( (string) $c['logo'] );
				$wm->setImageFormat( 'png' );
				$wm->evaluateImage( Imagick::EVALUATE_MULTIPLY, $op / 100, Imagick::CHANNEL_ALPHA );
				$wm->thumbnailImage( $lw, $lh, true );
				self::canvas( $editor )->compositeImage( $wm, Imagick::COMPOSITE_OVER, $x, $y );
				$wm->destroy();
				return true;
			} catch ( Exception $e ) {
				return false;
			}
		}
		if ( $editor instanceof WP_Image_Editor_GD ) {
			$src = wp_get_image_editor( (string) $c['logo'] );
			// 同 apply_file：失败是 WP_Error 对象，必须 is_wp_error 拦
			if ( ! $src || is_wp_error( $src ) || ! $src->load() ) {
				return false;
			}
			// imagecopyresampled 缩放贴合 + alpha 混合；不用 imagecopymerge（truecolor 忽略不透明度）
			$cv   = self::canvas( $editor );
			$logo = self::canvas( $src );
			list( $sw, $sh ) = self::dims( $src );
			if ( ! is_gd_image( $cv ) || ! is_gd_image( $logo ) || $sw < 1 || $sh < 1 ) {
				return false;
			}
			imagealphablending( $cv, true );
			return (bool) imagecopyresampled(
                $cv,
                $logo,
                $x,
                $y,
                0,
                0,
                $lw,
                $lh,
                $sw,
                $sh
			);
		}
		return false;
	}

	/** 扩展名 → editor->save 的 mime。 */
	private static function save_mime( string $ext ): string {
		static $map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'image/jpeg';
	}

	/** 打上 skip 语义的返回。 */
	private static function skip( array $out, string $reason ): array {
		$out['skip'] = true;
		$out['reason'] = $reason;
		return $out;
	}
}

/**
 * 上传链路：生成完各尺寸后自动打水印。
 *
 * 挂在 wp_generate_attachment_metadata（metadata 入库前、add_attachment 上云前）；
 * 顺序反了会导致「本地有水印、CDN/云端没有」的双源漂移。
 *
 * @param array $metadata    附件元数据。
 * @param int   $attach_id   附件 ID。
 * @return array
 */
function jinyu_companion_wm_metadata( $metadata, $attach_id ) {
	$attach_id = (int) $attach_id;
	// 两条防重路径：① 正处于内部处理流程（重算缩略图会再触发本回调）；
	// ② 本请求内已处理过该附件（WP 调用方在 wp_generate_attachment_metadata()
	// 返回后还会补一次 wp_update_attachment_metadata，同样会踢到本回调）。
	// 少了任何一条，一次上传就会把原图连打两次水印。
	if ( jinyu_companion_wm_guarding() || jinyu_companion_wm_handled( $attach_id ) ) {
		return $metadata;
	}
	// 上传时生效需同时满足：总开关开 + on_upload 开 + 本环境支持图像处理
	$c = Jinyu_Watermark::config();
	if ( ! jinyu_companion_is_checked( 'img_wm_enable', false ) || empty( $c['on_upload'] ) || ! Jinyu_Watermark::supported() ) {
		return $metadata;
	}
	// 既没有文字也没有水印图时无从处理：早退，否则每次上传都会空转并写一条 error_log
	if ( '' === trim( (string) $c['text'] ) && empty( $c['logo'] ) ) {
		return $metadata;
	}
	$stored = (string) get_post_meta( $attach_id, Jinyu_Watermark::META, true );
	if ( $stored === Jinyu_Watermark::signature() ) {
		return $metadata; // 同一配置不重复处理
	}
	$res = Jinyu_Watermark::apply_attachment( $attach_id );
	if ( $res['errors'] > 0 ) {
		// 水印不是内容正确性的前提：上传链路里失败只记日志，绝不阻断上传
		jinyu_companion_log( 'skipped: attachment=' . $attach_id . ' errors=' . $res['errors'], 'watermark' );
	}
	/*
	缩略图必须在这里重建：本回调跑在 wp_generate_attachment_metadata() 的最后一行，
	 * 此刻各尺寸文件已经按「还没打水印的原图」生成完毕。apply 只是换掉了原路径上的文件，
	 * 尺寸文件不会跟着变——不重建，前台看到的 medium / large 永远是没有水印的旧图。
	 * 仅在水印确实打上时重建：否则等于白删一遍尺寸文件再生成。 */
	if ( $res['done'] > 0 ) {
		Jinyu_Watermark::regen_sizes( $attach_id );
	}
	return $metadata;
}
add_filter( 'wp_generate_attachment_metadata', 'jinyu_companion_wm_metadata', 10, 2 );
