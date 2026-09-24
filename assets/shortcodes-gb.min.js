/*! Jinyu Companion · Gutenberg 短代码面板（更多菜单 → 弹窗一键插入 core/shortcode 块）。
 *  数据源：window.JINYU_SC_GB.list（wp_localize_script 注入） */
(function (wp) {
	'use strict';
	var list = (window.JINYU_SC_GB && window.JINYU_SC_GB.list) || [];
	if (!wp || !wp.plugins || !wp.editPost || !wp.blocks || !wp.data || !list.length) { return; }
	var el = wp.element.createElement;
	var frag = wp.element.Fragment;
	var useState = wp.element.useState;
	var MenuItem = wp.editPost.PluginMoreMenuItem;
	var Modal = wp.components.Modal;
	var Btn = wp.components.Button;

	function insert(tpl, close) {
		wp.data.dispatch('core/block-editor').insertBlocks(
			wp.blocks.createBlock('core/shortcode', { content: tpl })
		);
		close();
	}

	wp.plugins.registerPlugin('jinyu-shortcodes', {
		render: function () {
			var open = useState(false);
			var setOpen = open[1];
			var close = function () { setOpen(false); };
			return el(frag, {},
				el(MenuItem, { onClick: function () { setOpen(true); } }, '金玉短代码'),
				open[0] ? el(Modal, {
					title: '插入金玉短代码',
					onRequestClose: close
				}, el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '8px' } },
					list.map(function (item, i) {
						return el(Btn, {
							key: item.key || i,
							variant: 'secondary',
							onClick: function () { insert(item.tpl, close); }
						}, item.label);
					})
				)) : null
			);
		}
	});
})(window.wp);
