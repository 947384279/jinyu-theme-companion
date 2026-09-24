/*! Jinyu Companion · TinyMCE 短代码菜单（经典编辑器）。数据源：window.JINYU_SC.list */
(function () {
	'use strict';
	if (typeof tinymce === 'undefined') { return; }
	tinymce.PluginManager.add('jinyu_shortcodes', function (editor) {
		var list = (window.JINYU_SC && window.JINYU_SC.list) || [];
		if (!list.length) { return; }
		editor.addButton('jinyu_shortcodes', {
			type: 'menubutton',
			text: '短代码',
			icon: false,
			tooltip: '插入金玉短代码',
			menu: list.map(function (item) {
				return {
					text: item.label,
					onclick: function () { editor.insertContent(item.tpl); }
				};
			})
		});
	});
})();
