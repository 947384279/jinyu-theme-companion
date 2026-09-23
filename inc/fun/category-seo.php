<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 分类法级 SEO：为所有公开分类法（分类 / 标签 / 自定义分类法）补充「自定义关键词 / 描述」字段。
 * 数据存入 term meta，键名与历史分类数据保持一致（jinyu_seo_cat_keywords / jinyu_seo_cat_desc），
 * 由 jinyu_seo_meta() 在各归档页 <head> 输出。
 *
 * 注意：分类法在 init 阶段才注册，故钩子注册延后到 init（高优先级），
 * 确保 get_taxonomies() 已拿到完整列表。
 */

function jinyu_tax_seo_add_fields(): void {
	$label_kw   = __( 'SEO 关键字', 'jinyu-theme-companion' );
	$label_desc = __( 'SEO 描述', 'jinyu-theme-companion' );
	$tip_kw     = __( '多个关键字使用英文逗号分隔，留空则默认显示分类名称', 'jinyu-theme-companion' );
	$tip_desc   = __( '留空则默认显示分类描述', 'jinyu-theme-companion' );
	?>
	<div class="form-field">
		<label for="jinyu_seo_cat_keywords"><?php echo esc_html( $label_kw ); ?></label>
		<input name="jinyu_seo_cat_keywords" id="jinyu_seo_cat_keywords" type="text" value="" size="40">
		<p><?php echo esc_html( $tip_kw ); ?></p>
	</div>
	<div class="form-field">
		<label for="jinyu_seo_cat_desc"><?php echo esc_html( $label_desc ); ?></label>
		<input name="jinyu_seo_cat_desc" id="jinyu_seo_cat_desc" type="text" value="" size="40">
		<p><?php echo esc_html( $tip_desc ); ?></p>
	</div>
	<?php
}

function jinyu_tax_seo_edit_fields( WP_Term $term ): void {
	$kw   = get_term_meta( $term->term_id, 'jinyu_seo_cat_keywords', true );
	$desc = get_term_meta( $term->term_id, 'jinyu_seo_cat_desc', true );
	$label_kw   = __( 'SEO 关键字', 'jinyu-theme-companion' );
	$label_desc = __( 'SEO 描述', 'jinyu-theme-companion' );
	$tip_kw     = __( '多个关键字使用英文逗号分隔，留空则默认显示分类名称', 'jinyu-theme-companion' );
	$tip_desc   = __( '留空则默认显示分类描述', 'jinyu-theme-companion' );
	?>
	<tr class="form-field">
		<th scope="row"><label for="jinyu_seo_cat_keywords"><?php echo esc_html( $label_kw ); ?></label></th>
		<td>
			<input name="jinyu_seo_cat_keywords" id="jinyu_seo_cat_keywords" type="text" value="<?php echo esc_attr( $kw ); ?>" size="40"><br>
			<span class="description"><?php echo esc_html( $tip_kw ); ?></span>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="jinyu_seo_cat_desc"><?php echo esc_html( $label_desc ); ?></label></th>
		<td>
			<input name="jinyu_seo_cat_desc" id="jinyu_seo_cat_desc" type="text" value="<?php echo esc_attr( $desc ); ?>" size="40"><br>
			<span class="description"><?php echo esc_html( $tip_desc ); ?></span>
		</td>
	</tr>
	<?php
}

function jinyu_tax_seo_save( int $term_id ): void {
	if ( ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	if ( isset( $_POST['jinyu_seo_cat_keywords'] ) ) {
		$val = sanitize_text_field( wp_unslash( $_POST['jinyu_seo_cat_keywords'] ) );
		if ( '' === $val ) {
			delete_term_meta( $term_id, 'jinyu_seo_cat_keywords' );
		} else {
			update_term_meta( $term_id, 'jinyu_seo_cat_keywords', $val );
		}
	}
	if ( isset( $_POST['jinyu_seo_cat_desc'] ) ) {
		$val = sanitize_text_field( wp_unslash( $_POST['jinyu_seo_cat_desc'] ) );
		if ( '' === $val ) {
			delete_term_meta( $term_id, 'jinyu_seo_cat_desc' );
		} else {
			update_term_meta( $term_id, 'jinyu_seo_cat_desc', $val );
		}
	}
}

add_action( 'init', static function (): void {
	foreach ( get_taxonomies( [ 'public' => true ] ) as $tax ) {
		add_action( $tax . '_add_form_fields', 'jinyu_tax_seo_add_fields' );
		add_action( $tax . '_edit_form_fields', 'jinyu_tax_seo_edit_fields' );
		add_action( 'created_' . $tax, 'jinyu_tax_seo_save', 10, 1 );
		add_action( 'edited_' . $tax, 'jinyu_tax_seo_save', 10, 1 );
	}
}, 99 );
