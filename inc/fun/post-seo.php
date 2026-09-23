<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 文章级 SEO：为单篇内容补充「自定义关键词 / 描述」。
 * meta key 与输出端保持一致：
 *   - jinyu_seo.php  读取 jinyu_seo_desc / jinyu_seo_keys 输出 <meta>
 *   - seo-jsonld.php 读取 jinyu_seo_desc 生成结构化数据
 * 同时支持经典编辑器与区块编辑器：register_meta 让字段进入 REST（区块编辑器可保存），
 * 经典 add_meta_box 在两种编辑器侧栏都会渲染。
 * 注意：编辑入口始终可用；实际是否输出 <meta> 由 seo.php 的 seo_open 开关与 SEO 插件让位逻辑决定。
 */

function jinyu_post_seo_register_meta(): void {
	foreach ( [ 'jinyu_seo_desc', 'jinyu_seo_keys' ] as $key ) {
		register_meta(
			'post',
			$key,
			[
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'show_in_rest'      => true,
			]
		);
	}
}
add_action( 'init', 'jinyu_post_seo_register_meta' );

function jinyu_post_seo_add_meta_box(): void {
	$types = get_post_types( [ 'public' => true ] );
	// 附件无需 SEO 描述 / 关键词。
	unset( $types['attachment'] );
	foreach ( $types as $type ) {
		add_meta_box(
			'jinyu-post-seo',
			__( '金玉 SEO（关键词 / 描述）', 'jinyu-theme-companion' ),
			'jinyu_post_seo_meta_box_html',
			$type,
			'side',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'jinyu_post_seo_add_meta_box' );

function jinyu_post_seo_meta_box_html( WP_Post $post ): void {
	wp_nonce_field( 'jinyu_post_seo_save', 'jinyu_post_seo_nonce' );
	$desc = get_post_meta( $post->ID, 'jinyu_seo_desc', true );
	$keys = get_post_meta( $post->ID, 'jinyu_seo_keys', true );
	?>
	<p>
		<label for="jinyu_seo_desc" style="display:block;font-weight:600;margin-bottom:4px"><?php echo esc_html__( 'SEO 描述', 'jinyu-theme-companion' ); ?></label>
		<textarea id="jinyu_seo_desc" name="jinyu_seo_desc" rows="3" style="width:100%" placeholder="<?php echo esc_attr__( '留空则用摘要 / 正文首段', 'jinyu-theme-companion' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
		<span class="description" style="font-size:12px"><?php echo esc_html__( '输出为 <meta name="description">，也被结构化数据引用。', 'jinyu-theme-companion' ); ?></span>
	</p>
	<p>
		<label for="jinyu_seo_keys" style="display:block;font-weight:600;margin-bottom:4px"><?php echo esc_html__( 'SEO 关键词', 'jinyu-theme-companion' ); ?></label>
		<input id="jinyu_seo_keys" name="jinyu_seo_keys" type="text" style="width:100%" value="<?php echo esc_attr( $keys ); ?>" placeholder="<?php echo esc_attr__( '英文逗号分隔', 'jinyu-theme-companion' ); ?>">
		<span class="description" style="font-size:12px"><?php echo esc_html__( '留空则用文章标签；输出为 <meta name="keywords">。', 'jinyu-theme-companion' ); ?></span>
	</p>
	<?php
}

function jinyu_post_seo_save( int $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['jinyu_post_seo_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['jinyu_post_seo_nonce'] ), 'jinyu_post_seo_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['jinyu_seo_desc'] ) ) {
		$val = sanitize_textarea_field( wp_unslash( $_POST['jinyu_seo_desc'] ) );
		if ( '' === $val ) {
			delete_post_meta( $post_id, 'jinyu_seo_desc' );
		} else {
			update_post_meta( $post_id, 'jinyu_seo_desc', $val );
		}
	}
	if ( isset( $_POST['jinyu_seo_keys'] ) ) {
		$val = sanitize_text_field( wp_unslash( $_POST['jinyu_seo_keys'] ) );
		if ( '' === $val ) {
			delete_post_meta( $post_id, 'jinyu_seo_keys' );
		} else {
			update_post_meta( $post_id, 'jinyu_seo_keys', $val );
		}
	}
}
add_action( 'save_post', 'jinyu_post_seo_save' );
