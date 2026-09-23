/* =========================================================================
 * Jinyu Theme Companion · 设置面板交互 (admin.js)
 * 分区导航 / 深色切换 / 验证码分段 / TTL 滑杆人类可读 / 颜色 chip / 概览联动 / toast
 * 仅作用于 .jyc-app 作用域，避免与 WordPress 后台原生脚本冲突。
 * ========================================================================= */
(function () {
	'use strict';

	var app = document.querySelector('.jyc-app');
	if (!app) { return; }

	/* ---------------- 顶部分区导航 ---------------- */
	var navItems = document.querySelectorAll('#jyc-nav .jyc-nav-item');
	var panes = {
		overview: document.getElementById('pane-overview'),
		seo: document.getElementById('pane-seo'),
		content: document.getElementById('pane-content'),
		perf: document.getElementById('pane-perf'),
		comment: document.getElementById('pane-comment'),
		smtp: document.getElementById('pane-smtp'),
		storage: document.getElementById('pane-storage')
	};

	function showPane(name) {
		for (var k in panes) {
			if (panes[k]) { panes[k].classList.toggle('jyc-shown', k === name); }
		}
	}

	navItems.forEach(function (it) {
		it.addEventListener('click', function () {
			navItems.forEach(function (n) { n.classList.remove('jyc-active'); });
			it.classList.add('jyc-active');
			showPane(it.getAttribute('data-mod'));
			// 记住当前分区，供保存后停留原页（服务端读此隐藏字段）
			var ap = document.getElementById('jyc-activePane');
			if (ap) { ap.value = it.getAttribute('data-mod'); }
		});
	});

	/* ---------------- 深色 / 浅色切换 ---------------- */
	var tog = document.getElementById('jyc-themeTog');
	var ico = document.getElementById('jyc-themeIco');
	var sun = '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>';
	var moon = '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>';

	function applyTheme(dark) {
		app.setAttribute('data-theme', dark ? 'dark' : 'light');
		if (ico) { ico.innerHTML = dark ? sun : moon; }
		try { localStorage.setItem('jyc_theme', dark ? 'dark' : 'light'); } catch (e) {}
	}

	if (tog) {
		tog.addEventListener('click', function () {
			var dark = app.getAttribute('data-theme') === 'dark';
			applyTheme(!dark);
		});
	}
	try {
		var saved = localStorage.getItem('jyc_theme');
		if (saved) { applyTheme(saved === 'dark'); }
	} catch (e) {}

	/* ---------------- 验证码分段控制器 ---------------- */
	var seg = document.getElementById('jyc-captchaSeg');
	if (seg) {
		var capInput = seg.parentElement.querySelector('input[name="captcha_policy"]');
		var segBtns = seg.querySelectorAll('button');
		segBtns.forEach(function (b) {
			b.addEventListener('click', function () {
				segBtns.forEach(function (x) { x.classList.remove('jyc-active'); });
				b.classList.add('jyc-active');
				if (capInput) { capInput.value = b.getAttribute('data-v'); }
				computeOverview();
			});
		});
	}

	/* ---------------- TTL 滑杆：人类可读 ---------------- */
	var range = document.getElementById('jyc-ttlRange');
	var valEl = document.getElementById('jyc-ttlVal');
	var secEl = document.getElementById('jyc-ttlSec');
	function humanTtl(s) {
		s = +s;
		if (s < 3600) { return Math.round(s / 60) + ' 分钟'; }
		if (s < 86400) { return (s / 3600).toFixed(s % 3600 ? 1 : 0) + ' 小时'; }
		return (s / 86400).toFixed(1) + ' 天';
	}
	function updateTtl() {
		if (!range) { return; }
		var s = +range.value;
		var p = (s - 60) / (86400 - 60) * 100;
		range.style.setProperty('--p', p + '%');
		if (valEl) { valEl.textContent = humanTtl(s); }
		if (secEl) { secEl.textContent = s + ' 秒'; }
	}
	if (range) { range.addEventListener('input', updateTtl); updateTtl(); }

	/* ---------------- 颜色 chip ---------------- */
	var color = document.querySelector('input[name="style_color_primary"]');
	var chip = document.getElementById('jyc-colorChip');
	if (color && chip) {
		color.addEventListener('input', function () { chip.textContent = color.value.toUpperCase(); });
	}

	/* ---------------- 概览联动 ---------------- */
	var toggleKeys = [
		['seo_open', 'SEO / OG'], ['twitter_card_enable', 'Twitter 卡片'], ['llms_enable', 'llms.txt'],
		['auto_link_enable', '自动内链'], ['indexnow_enable', 'IndexNow'], ['page_cache_enable', '整页缓存'],
		['speculation_enable', 'Speculation 预取'], ['img_alt_enable', '图片 alt 补全'], ['close_comments_old', '旧文关评'],
		['ld_json_enable', 'JSON-LD']
	];
	function computeOverview() {
		var on = 0;
		var checks = document.getElementById('jyc-checks');
		if (checks) { checks.innerHTML = ''; }
		toggleKeys.forEach(function (pair) {
			var el = document.querySelector('input[name="' + pair[0] + '"]');
			var isOn = el && el.checked;
			if (isOn) { on++; }
			if (checks) {
				// 生成「状态点 + 文字」整行（此前误只 append firstChild，文字节点被丢弃 → 只剩圆点）
				var d = document.createElement('div');
				d.className = 'jyc-check';
				var dot = document.createElement('span');
				dot.className = 'jyc-dot' + (isOn ? ' jyc-on' : '');
				var lbl = document.createElement('span');
				lbl.className = 'jyc-check-label';
				lbl.textContent = pair[1];
				d.appendChild(dot);
				d.appendChild(lbl);
				checks.appendChild(d);
			}
		});
		var pct = Math.round(on / toggleKeys.length * 100);
		var set = function (id, v) { var e = document.getElementById(id); if (e) { e.textContent = v; } };
		set('jyc-pct', pct + '%');
		set('jyc-bentoPct', pct + '%');
		var fill = document.getElementById('jyc-pbarFill');
		if (fill) { fill.style.width = pct + '%'; }
		set('jyc-hsOn', on);
		var seoEl = document.querySelector('input[name="seo_open"]');
		var pushEl = document.querySelector('input[name="indexnow_enable"]');
		var cacheEl = document.querySelector('input[name="page_cache_enable"]');
		set('jyc-sSeo', seoEl && seoEl.checked ? '开' : '关');
		set('jyc-sPush', pushEl && pushEl.checked ? '开' : '关');
		set('jyc-sCache', cacheEl && cacheEl.checked ? '开' : '关');
		var capVal = capInput ? capInput.value : 'smart';
		set('jyc-sCap', { smart: '智能', always: '始终', off: '关闭' }[capVal]);
	}

	document.querySelectorAll('.jyc-switch input').forEach(function (el) {
		el.addEventListener('change', computeOverview);
	});
	computeOverview();

	/* ---------------- Toast ---------------- */
	var toastEl = document.getElementById('jyc-toast');
	var toastMsg = document.getElementById('jyc-toastMsg');
	var tT;
	function scheduleHide(ms) {
		clearTimeout(tT);
		tT = setTimeout(function () { toastEl.classList.remove('jyc-show'); }, ms);
	}
	window.jycToast = function (msg, ms) {
		if (!toastEl) { return; }
		if (msg && toastMsg) { toastMsg.textContent = msg; }
		toastEl.classList.add('jyc-show');
		scheduleHide(ms || 2400);
	};
	// 保存成功后由服务端渲染 .jyc-show 进场，这里只接管自动隐藏
	if (toastEl && toastEl.classList.contains('jyc-show')) { scheduleHide(3000); }

	/* ---------------- SMTP 真实测试邮件 ---------------- */
	window.jycTestSmtp = function (btn) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		// 收集整个表单（含 smtp_* 当前值 + jinyu_companion_nonce），未保存也能测刚填的配置。
		var fd = new FormData(form);
		fd.append('action', 'jinyu_test_smtp');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '发送中…'; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) { jycToast(res.data || '测试邮件已发送'); }
				else { jycToast((res && res.data) ? String(res.data) : '发送失败，请检查配置'); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () {
				if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; }
			});
	};

	/* ---------------- 对象存储：测试连接 ---------------- */
	// 与 SMTP 测试同理：整表单提交（含 storage_* 当前值 + jinyu_companion_nonce），未保存也能测。
	window.jycTestStorage = function (btn) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_storage_test');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '测试中…'; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) { jycToast(res.data || '连接成功'); }
				else { jycToast((res && res.data) ? String(res.data) : '连接失败，请检查配置'); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () {
				if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; }
			});
	};

	/* ---------------- 对象存储：一键替换 / 复原链接 ---------------- */
	// apply/unapply 后端直接写 storage_domain 并清缓存（含刚填未保存的值，无需先保存）。
	window.jycStorageDomain = function (btn, action) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd = new FormData(form);
		fd.append('action', action);
		var dEl = form.querySelector('input[name="storage_domain"]');
		if (dEl) { fd.set('storage_domain', dEl.value); }
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = '处理中…'; loading = true; }
		fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					jycToast(res.data || '已完成');
					// 同步回输入框，让界面与后端状态一致（复原后域名清空）
					if (dEl && action === 'jinyu_storage_unapply_domain') { dEl.value = ''; }
				} else { jycToast((res && res.data) ? String(res.data) : '操作失败'); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () { if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; } });
	};

	/* ---------------- 对象存储：批量任务引擎（push/pull/sync 统一） ----------------
	 * 后端每次调用处理一批（上传/同步每批 50、并发 16）并把进度落 DB；
	 * status=running 时前端自动续批；运行中每 2.5s 自动轮询进度（无需手动刷新）；
	 * 中途关闭页面任务停在 DB，再次进入本页自动检测并续跑。 */
	var jycBatch = { type: null, running: false, timer: null, poll: null };
	function jycBatchEls() {
		return {
			prog: document.getElementById('jyc-batchProg'),
			fill: document.getElementById('jyc-batchFill'),
			msg: document.getElementById('jyc-batchMsg')
		};
	}
	function jycBatchLock(on, type) {
		var sel = '.jyc-batch-btn, .jyc-sync-btn';
		document.querySelectorAll(sel).forEach(function (b) { b.disabled = on; });
		// 运行文案只替换当前任务对应的按钮（data-batch 匹配），其余按钮保持原文字、仅禁用
		var busy = type === 'push' ? '上传中…' : (type === 'pull' ? '拉回中…' : '同步中…');
		document.querySelectorAll(sel).forEach(function (b) {
			var t = b.getAttribute('data-label');
			if (!t) { return; }
			var mine = b.getAttribute('data-batch') === type;
			b.textContent = (on && mine) ? busy : t;
		});
		var stopBtn = document.getElementById('jyc-batchStop');
		if (stopBtn) { stopBtn.hidden = !on; }
	}
	function jycBatchRender(d, prefix) {
		var e = jycBatchEls();
		if (e.prog) { e.prog.hidden = false; }
		if (e.fill && d.total) { e.fill.style.width = Math.min(100, Math.round(d.done / d.total * 100)) + '%'; }
		if (e.msg) { e.msg.textContent = (prefix ? prefix + '：' : '') + (d.message || (d.done + '/' + d.total)); }
	}
	function jycBatchAbort() {
		jycBatch.running = false;
		if (jycBatch.timer) { clearTimeout(jycBatch.timer); jycBatch.timer = null; }
		if (jycBatch.poll) { clearTimeout(jycBatch.poll); jycBatch.poll = null; }
		jycBatchLock(false, jycBatch.type || 'push');
		jycBatch.type = null;
	}
	window.jycStorageStop = function () {
		if (!jycBatch.running) { return; }
		if (jycBatch.timer) { clearTimeout(jycBatch.timer); jycBatch.timer = null; }
		jycBatch.running = false;
		var fd = new FormData(document.getElementById('jyc-form'));
		fd.append('action', 'jinyu_storage_stop');
		var e = jycBatchEls();
		fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (e.msg) { e.msg.textContent = (res && res.success) ? '已停止（进度保留，可重新开始）' : '已停止'; }
			})
			.catch(function () { if (e.msg) { e.msg.textContent = '已停止'; } });
		if (jycBatch.poll) { clearTimeout(jycBatch.poll); jycBatch.poll = null; }
		jycBatchLock(false, jycBatch.type || 'push');
		jycBatch.type = null;
		jycToast('任务已停止，已处理的进度保留');
	};
	function jycBatchStart(type, fd) {
		if (jycBatch.running) { return; }
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		jycBatch.running = true;
		jycBatch.type = type;
		jycBatchLock(true, type);
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		// 自动刷新：运行中每 2.5s 拉一次 DB 进度，多标签页/恢复场景下显示保持新鲜
		(function poll() {
			if (!jycBatch.running) { return; }
			var sfd = new FormData(form);
			sfd.append('action', 'jinyu_storage_status');
			fetch(aurl, { method: 'POST', body: sfd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (jycBatch.running && res && res.success && res.data && res.data.active) { jycBatchRender(res.data); }
				})
				.catch(function () {})
				.then(function () { if (jycBatch.running) { jycBatch.poll = setTimeout(poll, 2500); } });
		})();
		var retries = 0;
		(function step() {
			if (!jycBatch.running) { return; }
			fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!jycBatch.running) { return; }
					if (!(res && res.success)) {
						jycToast((res && res.data) ? String(res.data) : '任务失败');
						if (jycBatchEls().msg) { jycBatchEls().msg.textContent = String((res && res.data) || '任务失败'); }
						jycBatchAbort(); return;
					}
					retries = 0;
					var d = res.data;
					jycBatchRender(d);
					if (d.status === 'done') {
						jycToast(d.errors > 0 ? (d.message || '完成，但有失败项') : (d.message || '任务完成'));
						if (type === 'sync') {
							var ta = document.getElementById('jyc-syncPaths');
							if (ta) { ta.value = ''; }
						}
						jycBatchAbort(); return;
					}
					jycBatch.timer = setTimeout(step, 500);
				})
				.catch(function () {
					// 单次请求失败不断链：进度落 DB，稍后重试同一批（幂等续跑）；连续失败 3 次才中止
					retries++;
					if (!jycBatch.running) { return; }
					if (retries >= 3) { jycToast('请求连续失败，任务已暂停；进度已保存在数据库，稍后重进页面会自动继续'); jycBatchAbort(); return; }
					jycBatch.timer = setTimeout(step, 2000);
				});
		})();
	}
	window.jycStorageBatch = function (btn, type) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd = new FormData(form);
		fd.append('action', type === 'pull' ? 'jinyu_storage_pull' : 'jinyu_storage_push');
		jycBatchStart(type, fd);
	};
	window.jycStorageSyncSelected = function (btn) {
		var form = document.getElementById('jyc-form');
		var ta = document.getElementById('jyc-syncPaths');
		if (!form || !ta) { return; }
		if (!ta.value.trim()) { jycToast('请先填写要同步的文件路径'); ta.focus(); return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_storage_sync_selected');
		fd.set('sync_paths', ta.value);
		jycBatchStart('sync', fd);
	};
	// 页面加载即检测未完成任务：有则显示进度并自动续跑（关闭浏览器/切走再回来不丢进度）
	(function () {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_storage_status');
		fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!(res && res.success && res.data && res.data.active)) { return; }
				var d = res.data;
				var type = d.type === 'pull' ? 'pull' : (d.type === 'sync' ? 'sync' : 'push');
				var name = type === 'pull' ? '拉回' : (type === 'sync' ? '同步' : '上传');
				jycBatchRender(d, '检测到未完成的' + name + '任务，已自动继续');
				var ffd = new FormData(form);
				if (type === 'sync') {
					ffd.append('action', 'jinyu_storage_sync_selected');
					ffd.append('sync_continue', '1');
				} else {
					ffd.append('action', type === 'pull' ? 'jinyu_storage_pull' : 'jinyu_storage_push');
				}
				jycBatchStart(type, ffd);
			})
			.catch(function () {});
	})();

	/* ---------------- 自绘下拉：替换原生 <option> 弹层 ----------------
	 * 原生 option 弹层浏览器禁止样式化；此增强把 select.jyc-inp 换成自绘列表。
	 * 原生 select 隐藏保留在表单内（display:none 仍随表单提交），值双向同步。 */
	var CHEV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
	var CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
	document.querySelectorAll('.jyc-app select.jyc-inp').forEach(function (sel) {
		if (sel.closest('.jyc-select')) { return; }
		var wrap = document.createElement('div');
		wrap.className = 'jyc-select';
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'jyc-select-btn';
		btn.setAttribute('aria-haspopup', 'listbox');
		var lbl = document.createElement('span');
		lbl.className = 'jyc-select-label';
		btn.appendChild(lbl);
		btn.insertAdjacentHTML('beforeend', CHEV);
		var list = document.createElement('div');
		list.className = 'jyc-select-list';
		list.setAttribute('role', 'listbox');

		function renderLabel() {
			var o = sel.options[sel.selectedIndex];
			lbl.textContent = o ? o.textContent : '';
		}
		function syncItems() {
			list.querySelectorAll('.jyc-select-item').forEach(function (it, i) {
				it.classList.toggle('jyc-sel', i === sel.selectedIndex);
			});
		}
		function closeList() { wrap.classList.remove('jyc-open'); list.classList.remove('jyc-open'); }
		function openList() {
			document.querySelectorAll('.jyc-select-list.jyc-open').forEach(function (l) {
				l.classList.remove('jyc-open');
				if (l.parentElement) { l.parentElement.classList.remove('jyc-open'); }
			});
			wrap.classList.add('jyc-open');
			list.classList.add('jyc-open');
		}

		Array.prototype.forEach.call(sel.options, function (o, i) {
			var it = document.createElement('div');
			it.className = 'jyc-select-item' + (o.selected ? ' jyc-sel' : '');
			it.setAttribute('role', 'option');
			var t = document.createElement('span');
			t.textContent = o.textContent;
			it.appendChild(t);
			it.insertAdjacentHTML('beforeend', CHECK);
			it.addEventListener('click', function () {
				sel.selectedIndex = i;
				sel.dispatchEvent(new Event('change', { bubbles: true }));
				renderLabel();
				syncItems();
				closeList();
			});
			list.appendChild(it);
		});

		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (list.classList.contains('jyc-open')) { closeList(); } else { openList(); }
		});
		// 键盘：上下改值（同步显示），Enter 开合，Esc 关闭
		btn.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				var d = e.key === 'ArrowDown' ? 1 : -1;
				var n = sel.selectedIndex + d;
				if (n >= 0 && n < sel.options.length) {
					sel.selectedIndex = n;
					sel.dispatchEvent(new Event('change', { bubbles: true }));
					renderLabel(); syncItems();
				}
			} else if (e.key === 'Escape') { closeList(); }
		});
		document.addEventListener('click', closeList);

		sel.style.display = 'none';
		sel.parentNode.insertBefore(wrap, sel);
		wrap.appendChild(btn);
		wrap.appendChild(list);
		wrap.appendChild(sel);
		renderLabel();
	});

})();
