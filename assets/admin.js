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
		perfcenter: document.getElementById('pane-perfcenter'),
		comment: document.getElementById('pane-comment'),
		smtp: document.getElementById('pane-smtp'),
		storage: document.getElementById('pane-storage'),
		social: document.getElementById('pane-social'),
		wechat: document.getElementById('pane-wechat')
	};

	function showPane(name) {
		for (var k in panes) {
			if (panes[k]) { panes[k].classList.toggle('jyc-shown', k === name); }
		}
	}

	/* 滑动指示器：导航底部高亮条，随激活 tab 平滑滑动到其位置并匹配宽度。 */
	var nav = document.getElementById('jyc-nav');
	var navIndicator = null;
	if (nav) {
		navIndicator = document.createElement('span');
		navIndicator.className = 'jyc-nav-indicator jyc-hidden';
		nav.appendChild(navIndicator);
	}

	/* 导航栏溢出提示：屏幕不够宽时 tab 被挤进横向滚动区。
	   左右箭头作为 nav-wrap 内 flex 兄弟项独占固定宽度，不遮挡菜单。
	   点左/右箭头按可视宽度 70% 平滑滚动；支持滚轮横向滚动。 */
	var navEdgeL = nav ? nav.parentNode.querySelector('.jyc-nav-edge-left') : null;
	var navEdgeR = nav ? nav.parentNode.querySelector('.jyc-nav-edge-right') : null;
	function syncNavOverflow() {
		if (!nav) { return; }
		var overflow = nav.scrollWidth > nav.clientWidth + 1;
		var maxScroll = nav.scrollWidth - nav.clientWidth;
		if (navEdgeL) {
			navEdgeL.classList.toggle('show', overflow && nav.scrollLeft > 1);
		}
		if (navEdgeR) {
			navEdgeR.classList.toggle('show', overflow && nav.scrollLeft + nav.clientWidth < nav.scrollWidth - 2);
		}
	}
	function navScrollBy(dir) {
		if (!nav) { return; }
		var distance = nav.clientWidth * 0.7 * dir;
		var target = Math.max(0, Math.min(nav.scrollWidth - nav.clientWidth, nav.scrollLeft + distance));
		if (reduceMotion) { nav.scrollLeft = target; }
		else { try { nav.scrollTo({ left: target, behavior: 'smooth' }); } catch (e) { nav.scrollLeft = target; } }
	}
	if (nav) {
		nav.addEventListener('scroll', syncNavOverflow, { passive: true });
		window.addEventListener('resize', syncNavOverflow);
		if (navEdgeL) { navEdgeL.addEventListener('click', function () { navScrollBy(-1); }); }
		if (navEdgeR) { navEdgeR.addEventListener('click', function () { navScrollBy(1); }); }
		nav.addEventListener('wheel', function (e) {
			if (nav.scrollWidth > nav.clientWidth) {
				nav.scrollLeft += e.deltaY;
				if (e.deltaY) { e.preventDefault(); }
			}
		}, { passive: false });
		syncNavOverflow();
	}
	function moveIndicator(el) {
		if (!navIndicator || !nav || !el) { return; }
		// 指示器是 nav 内的 position:absolute 元素，与 tab 同处 nav 的「内容坐标系」，并随 nav
		// 横向滚动一起移动。因此直接对齐到 tab 的内容坐标 offsetLeft / offsetWidth 即可；
		// 切勿再减 nav.scrollLeft —— 否则非全屏/窄屏下 nav 一旦横向滚动，横条会整体向左偏移
		// scrollLeft 像素（原 bug：全屏 scrollLeft=0 看不出，窄屏切换几次就错位）。
		navIndicator.style.width = el.offsetWidth + 'px';
		navIndicator.style.transform = 'translateX(' + el.offsetLeft + 'px)';
		navIndicator.classList.remove('jyc-hidden');
	}
	function activeNavEl() {
		return document.querySelector('#jyc-nav .jyc-nav-item.jyc-active');
	}
// 指示器与 tab 同处 nav 内容坐标系，会随 nav 横向滚动天然对齐，无需在 scroll 时重算。
// 仅在 window 缩放（布局 reflow 改变 tab 位置）时重算一次，且先去过渡避免动画干扰；同时刷新溢出箭头。
var scrollRAF;
if (nav) {
	window.addEventListener('resize', function () {
		cancelAnimationFrame(scrollRAF);
		scrollRAF = requestAnimationFrame(function () {
			syncNavOverflow();
			if (navIndicator) { navIndicator.style.transition = 'none'; }
			moveIndicator(activeNavEl());
			requestAnimationFrame(function () { if (navIndicator) { navIndicator.style.transition = ''; } });
		});
	});
}

	/* 让激活的 tab 在「横向溢出」的导航条里可见：窄屏 / 多 tab 时高亮项被挤出可视区，
	 * 用户看不到它高亮、误以为没跳到 tab。仅当该项确实在可视区外才滚动，避免无谓抖动。
	 * behavior：点击用 smooth，进入用 auto（不与窗口滚动抢动画）。 */
	function scrollNavIntoView(el, behavior) {
		var nav = document.getElementById('jyc-nav');
		if (!nav || !el) { return; }
		var navRect = nav.getBoundingClientRect();
		var elRect = el.getBoundingClientRect();
		if (elRect.left < navRect.left || elRect.right > navRect.right) {
			var target = nav.scrollLeft + (elRect.left - navRect.left) - 16;
			if (nav.scrollTo) {
				try { nav.scrollTo({ left: target, behavior: behavior || 'auto' }); } catch (e) { nav.scrollLeft = target; }
			} else {
				nav.scrollLeft = target;
			}
		}
	}

	/* 用户偏好：减少动态效果时，所有滚动降级为即时（无障碍 + 不晕）。 */
	var reduceMotion = false;
	try {
		reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	} catch (e) {}

	/* 窗口滚动到分区顶部：JS 实时测算 sticky 顶栏（WP adminbar + 本插件顶栏，含窄屏换行）
	 * 的累计高度作为偏移，避免被遮挡或留白过大；统一平滑/即时，比 scrollIntoView 更可控优雅。 */
	function scrollPaneIntoView(pane, behavior) {
		if (!pane) { return; }
		if (behavior === 'smooth' && reduceMotion) { behavior = 'auto'; }
		behavior = behavior || 'auto';
		var offset = 14;
		var bar = document.getElementById('wpadminbar');
		if (bar) { offset += bar.getBoundingClientRect().height || 0; }
		var top = document.querySelector('.jyc-topnav');
		if (top) { offset += top.getBoundingClientRect().height || 0; }
		var y = pane.getBoundingClientRect().top + window.pageYOffset - offset;
		try { window.scrollTo({ top: Math.max(0, y), behavior: behavior }); }
		catch (e) { try { window.scrollTo(0, Math.max(0, y)); } catch (e2) {} }
	}

	/* 选中并切换分区：统一处理 nav 高亮 / 显隐 / 隐藏域 / 记忆 / 地址栏 / tab 入视。
	 * 记忆优先级：URL ?pane（可分享/书签）> localStorage（最稳，刷新必在）> 服务端已渲染 .jyc-shown。
	 * smooth：点击切换时平滑；刷新/直链进入时即时（避免加载动画）。 */
	function selectPane(mod, smooth) {
		if (!mod || !panes[mod]) { mod = 'overview'; }
		navItems.forEach(function (n) {
			n.classList.toggle('jyc-active', n.getAttribute('data-mod') === mod);
		});
		showPane(mod);
		var ap = document.getElementById('jyc-activePane');
		if (ap) { ap.value = mod; }
		try { localStorage.setItem('jyc_pane', mod); } catch (e) {}
		try {
			var u = new URL(window.location.href);
			if (mod === 'overview') { u.searchParams.delete('pane'); }
			else { u.searchParams.set('pane', mod); }
			window.history.replaceState(null, '', u.toString());
		} catch (e) {}
		scrollNavIntoView(document.querySelector('#jyc-nav .jyc-nav-item.jyc-active'), smooth ? 'smooth' : 'auto');
		moveIndicator(activeNavEl());
	}

	navItems.forEach(function (it) {
		it.addEventListener('click', function () {
			var mod = it.getAttribute('data-mod');
			selectPane(mod, true);
			scrollPaneIntoView(panes[mod], 'smooth');
		});
	});

	/* 刷新 / 直链进入：主动恢复选中 tab，避免仅靠 URL 参数丢失后回落概览。 */
	function resolvePane() {
		try {
			var p = new URL(window.location.href).searchParams.get('pane');
			if (p && panes[p]) { return p; }
		} catch (e) {}
		try {
			var s = localStorage.getItem('jyc_pane');
			if (s && panes[s]) { return s; }
		} catch (e) {}
		for (var k in panes) {
			if (panes[k] && panes[k].classList.contains('jyc-shown')) { return k; }
		}
		return 'overview';
	}
	/* 防止浏览器刷新后自动恢复旧滚动位置，否则会与下方「跳到 tab」叠加成先恢复再跳走的双段抖动。 */
	try { if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; } } catch (e) {}

	// 进入即定位到上次所在分区：先切好高亮/显隐，等布局稳定一帧再平滑滚到该 tab 顶部；
	// 已禁用浏览器恢复，全过程只有一次顺滑运动，干净不抖。概览页本就在顶部，无需滚动。
	var entryPane = resolvePane();
	selectPane(entryPane, false);
	// 首屏指示器瞬时落位（不计动画），避免从左侧滑入的怪异感；点击切换时才平滑滑动。
	if (navIndicator) { navIndicator.style.transition = 'none'; }
	moveIndicator(activeNavEl());
	requestAnimationFrame(function () {
		requestAnimationFrame(function () {
			if (navIndicator) { navIndicator.style.transition = ''; }
			if (entryPane !== 'overview') { scrollPaneIntoView(panes[entryPane], 'smooth'); }
		});
	});

	/* ---------------- 深色 / 浅色切换 ---------------- */
	var tog = document.getElementById('jyc-themeTog');
	var ico = document.getElementById('jyc-themeIco');
	var sun = '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>';
	var moon = '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>';

	function applyTheme(dark) {
		app.setAttribute('data-theme', dark ? 'dark' : 'light');
		/* 同步到 body：深色下把 WP 后台容器底色一并涂黑，杜绝画布外露出的浅色条 */
		document.body.classList.toggle('jyc-dark', dark);
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

	/* ---------------- 「使用说明」悬浮气泡 ----------------
	 * 约定：按钮 .jyc-info-btn[data-pop="气泡ID"] + 气泡 <div class="jyc-pop" id="..." hidden>，多卡片可复用。
	 * 气泡挂到 .jyc-app 下 position:fixed（脱离 overflow:hidden 裁剪容器）；selOrigin() 补偿包含块偏移。
	 * hover 按钮显示，移出按钮/气泡 180ms 后收起（留出把鼠标移进气泡的时间，可选中复制内容）；
	 * 触屏 / 键盘走 click / focus 切换；下方放不下自动上翻；scroll/resize 按 rAF 节流重定位。 */
	Array.prototype.forEach.call(document.querySelectorAll('.jyc-info-btn[data-pop]'), function (btn) {
		var pop = document.getElementById(btn.getAttribute('data-pop'));
		if (!pop) { return; }
		var hideT = 0;
		function place() {
			var r = btn.getBoundingClientRect();
			var vw = document.documentElement.clientWidth;
			var vh = document.documentElement.clientHeight;
			var org = selOrigin();
			pop.style.maxHeight = 'none';
			var w = pop.offsetWidth, h = pop.offsetHeight;
			var below = vh - r.bottom - 8, above = r.top - 8;
			var up = h > below && above > below;
			pop.style.maxHeight = Math.round(Math.max(120, up ? above : below)) + 'px';
			/* 右缘对齐按钮，左右 8px 视口内缩 */
			pop.style.left = (Math.min(Math.max(8, r.right - w), Math.max(8, vw - w - 8)) - org.x) + 'px';
			if (up) {
				pop.style.top = (r.top - Math.min(h, above) - 8 - org.y) + 'px';
			} else {
				pop.style.top = (r.bottom + 8 - org.y) + 'px';
			}
		}
		function show() {
			clearTimeout(hideT);
			if (!pop.hidden) { return; }
			pop.hidden = false;
			place();                 // 先定位再显形，避免在旧位置闪一帧
			pop.classList.add('jyc-open');
			btn.setAttribute('aria-expanded', 'true');
			requestAnimationFrame(place);  // 卡片 hover transform 过渡结束后校准一次
		}
		function hideNow() {
			clearTimeout(hideT);
			pop.hidden = true;
			pop.classList.remove('jyc-open');
			btn.setAttribute('aria-expanded', 'false');
		}
		function hideSoon() {
			clearTimeout(hideT);
			hideT = setTimeout(hideNow, 180);
		}
		btn.addEventListener('mouseenter', show);
		btn.addEventListener('mouseleave', hideSoon);
		btn.addEventListener('focus', show);
		btn.addEventListener('blur', hideNow);
		pop.addEventListener('mouseenter', function () { clearTimeout(hideT); });
		pop.addEventListener('mouseleave', hideSoon);
		btn.addEventListener('click', function (e) {
			e.stopPropagation();
			if (pop.hidden) { show(); } else { hideNow(); }
		});
		pop.addEventListener('click', function (e) { e.stopPropagation(); });
		btn.addEventListener('jyc-pop-place', function () { if (!pop.hidden) { place(); } });
		app.appendChild(pop);        // 气泡交给 .jyc-app 托管（脱离所有裁剪容器）
	});
	document.addEventListener('click', function () {
		document.querySelectorAll('.jyc-pop.jyc-open').forEach(function (p) {
			p.hidden = true;
			p.classList.remove('jyc-open');
			document.querySelectorAll('.jyc-info-btn[aria-expanded="true"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
		});
	});
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key) {
			document.querySelectorAll('.jyc-pop.jyc-open').forEach(function (p) { p.hidden = true; p.classList.remove('jyc-open'); });
		}
	});
	(function () {                   // scroll / resize 重定位（rAF 节流）
		var raf = 0;
		function move() {
			if (raf) { return; }
			raf = requestAnimationFrame(function () {
				raf = 0;
				document.querySelectorAll('.jyc-pop.jyc-open').forEach(function (p) {
					var btn = document.querySelector('.jyc-info-btn[aria-expanded="true"][data-pop="' + p.id + '"]');
					if (btn) { btn.dispatchEvent(new Event('jyc-pop-place')); }
				});
			});
		}
		window.addEventListener('scroll', move, true);
		window.addEventListener('resize', move);
	})();

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

	/* ---------------- 概览磁贴：跳转 + 待办实时重算 ---------------- */
	/* 待办规格由服务端下发（data-todos），前端只负责判定 —— 「哪些算待办」不在 PHP / JS 各写一份，避免漂移。 */
	var todoTile = document.getElementById('jyc-tileTodo');
	var todoSpec = [];
	if (todoTile) {
		try { todoSpec = JSON.parse(todoTile.getAttribute('data-todos') || '[]') || []; } catch (e) { todoSpec = []; }
	}
	function todoOpen(item) {
		var el = document.querySelector('.jyc-pane [name="' + item.n + '"]');
		if (!el) { return true; }	// 控件缺失（字段被裁剪）→ 保守记为待办，宁可多提示不漏提示
		if (item.t === 'check') { return !el.checked; }
		if (item.t === 'filled') { return !String(el.value || '').trim(); }
		return el.value === 'off';
	}
	function computeTodos() {
		if (!todoTile || !todoSpec.length) { return; }
		var open = todoSpec.filter(todoOpen);
		var head = document.getElementById('jyc-sTodo');
		var note = document.getElementById('jyc-sTodoNote');
		var dot = todoTile.querySelector('.jyc-k i');
		if (head) { head.textContent = open.length + ' 项'; }
		if (note) { note.textContent = open.length ? open[0].l : '暂无优化建议'; }
		todoTile.setAttribute('data-jyc-goto', open.length ? open[0].p : 'perfcenter');
		if (dot) { dot.style.background = open.length ? 'var(--warn)' : 'var(--ok)'; }
	}
	function gotoPane(mod) {
		if (!mod || !panes[mod]) { return; }
		selectPane(mod, true);
		scrollPaneIntoView(panes[mod], 'smooth');
	}
	document.querySelectorAll('.jyc-tile-link').forEach(function (t) {
		t.addEventListener('click', function () { gotoPane(t.getAttribute('data-jyc-goto')); });
		t.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
				e.preventDefault();
				gotoPane(t.getAttribute('data-jyc-goto'));
			}
		});
	});
	/* 待办涉及文本框 / 下拉，不在 .jyc-switch 监听范围内，单独在表单上委托（只认规格里的字段名）。 */
	(function () {
		var formEl = document.getElementById('jyc-form');
		if (!formEl || !todoSpec.length) { return; }
		var names = todoSpec.map(function (i) { return i.n; });
		['change', 'input'].forEach(function (ev) {
			formEl.addEventListener(ev, function (e) {
				if (names.indexOf((e.target && e.target.name) || '') !== -1) { computeTodos(); }
			});
		});
	})();

	/* ---------------- 概览联动 ---------------- */
	var toggleKeys = [
		['seo_open', 'SEO / OG'], ['twitter_card_enable', 'Twitter 卡片'], ['llms_enable', 'llms.txt'],
		['no_category_enable', '去除 /category/'], ['ld_json_enable', 'JSON-LD'],
		['auto_link_enable', '自动内链'], ['indexnow_enable', 'IndexNow'], ['page_cache_enable', '整页缓存'],
		['speculation_enable', 'Speculation 预取'], ['img_alt_enable', '图片 alt 补全'], ['close_comments_old', '旧文关评'],
		['storage_auto_upload', '附件自动上云'], ['storage_delete_local', '推送后删本地']
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
		/* 可配置字段总数：按面板真实控件数动态统计，避免硬编码失真 */
		set('jyc-hsFields', document.querySelectorAll('.jyc-pane input, .jyc-pane select, .jyc-pane textarea').length);
		var seoEl = document.querySelector('input[name="seo_open"]');
		var pushEl = document.querySelector('input[name="indexnow_enable"]');
		var cacheEl = document.querySelector('input[name="page_cache_enable"]');
		set('jyc-sSeo', seoEl && seoEl.checked ? '开' : '关');
		set('jyc-sPush', pushEl && pushEl.checked ? '开' : '关');
		set('jyc-sCache', cacheEl && cacheEl.checked ? '开' : '关');
		var capVal = capInput ? capInput.value : 'smart';
		set('jyc-sCap', { smart: '智能', always: '始终', off: '关闭' }[capVal]);
		computeTodos();
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

	/* ---------------- 数据库优化 ---------------- */
	window.jycDbOptimize = function (btn) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		if (!window.confirm('确定立即优化数据库？将清理修订版本、自动草稿、垃圾评论、孤立元数据与过期瞬态。')) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_db_optimize');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '优化中…'; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) { jycToast(res.data && res.data.msg ? res.data.msg : '数据库优化完成'); }
				else { jycToast((res && res.data) ? String(res.data) : '优化失败，请重试'); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () {
				if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; }
			});
	};

	/* ---------------- Autoload 瘦身：扫描 + 改按需加载 ---------------- */
	window.jycAutoloadScan = function (btn) {
		var form = document.getElementById('jyc-form');
		var box = document.getElementById('jyc-autoloadResult');
		if (!form || !box) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_autoload_scan');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '扫描中…'; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!(res && res.success)) {
					jycToast((res && res.data) ? String(res.data) : '扫描失败，请重试');
					return;
				}
				var items = (res.data && res.data.items) ? res.data.items : [];
				var html = '<div class="jyc-muted" style="font-size:12px;margin-bottom:6px">' + String(res.data.msg || '') + '</div>';
				if (!items.length) {
					html += '<div style="font-size:13px;color:#2e7d32">没有超过 128KB 的大选项，autoload 很干净。</div>';
				} else {
					html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
					items.forEach(function (it) {
						html += '<tr>'
							+ '<td style="padding:5px 8px 5px 0;border-bottom:1px solid rgba(128,128,128,.18);word-break:break-all">' + esc(it.name) + (it.protected ? ' <span class="jyc-muted" style="font-size:11px">（受保护）</span>' : '') + '</td>'
							+ '<td style="padding:5px 8px;border-bottom:1px solid rgba(128,128,128,.18);white-space:nowrap">' + esc(it.size_h) + '</td>'
							+ '<td style="padding:5px 0;border-bottom:1px solid rgba(128,128,128,.18);text-align:right;white-space:nowrap">'
							+ (it.protected ? '' : '<button type="button" class="jyc-btn jyc-btn-soft" style="padding:2px 10px;font-size:12px" data-opt="' + esc(it.name) + '" onclick="window.jycAutoloadFix(this)">按需加载</button>')
							+ '</td></tr>';
					});
					html += '</table>';
					html += '<div class="jyc-muted" style="font-size:12px;margin-top:6px">「受保护」= 核心必需或高频读取选项，插件拒绝修改。改动后前台如异常，刷新本页再扫描可点「恢复」。</div>';
				}
				box.innerHTML = html;
				box.hidden = false;
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () {
				if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; }
			});
	};

	window.jycAutoloadFix = function (btn) {
		var form = document.getElementById('jyc-form');
		var name = btn ? (btn.getAttribute('data-opt') || '') : '';
		if (!form || !name) { return; }
		var undo = btn.getAttribute('data-undo') === '1';
		if (!undo && !window.confirm('将选项「' + name + '」改为按需加载（autoload=no）？\n该选项体积较大且非核心必需；若前台出现异常可点「恢复」改回。')) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_autoload_fix');
		fd.append('option_name', name);
		if (undo) { fd.append('undo', '1'); }
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = btn.textContent;
		btn.disabled = true; btn.textContent = '…';
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					jycToast(res.data && res.data.msg ? res.data.msg : (undo ? '已恢复' : '已改为按需加载'));
					// 双态切换：no→恢复按钮 / yes→按需加载按钮
					if (undo) { btn.textContent = '按需加载'; btn.removeAttribute('data-undo'); }
					else { btn.textContent = '恢复'; btn.setAttribute('data-undo', '1'); }
					btn.disabled = false;
				} else {
					jycToast((res && res.data) ? String(res.data) : '操作失败，请重试');
					btn.disabled = false; btn.textContent = oldTxt;
				}
			})
			.catch(function () { jycToast('请求失败，请重试'); btn.disabled = false; btn.textContent = oldTxt; });
	};

	/* ---------------- HTTP 传输体检：只在点击时发请求 ---------------- */
	window.jycTransportProbe = function (btn) {
		var form = document.getElementById('jyc-form');
		var box = document.getElementById('jyc-tpResult');
		var ago = document.getElementById('jyc-tpAgo');
		if (!form || !box) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_transport_probe');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = btn ? btn.textContent : '';
		if (btn) { btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '体检中…'; }
		box.innerHTML = '<div class="jyc-tp-empty">正在探测…</div>';
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return null; })
			.then(function (res) {
				if (res && res.success) {
					// 结果 HTML 由服务端渲染（与首屏同一函数），前端只做替换，避免两套模板漂移。
					box.innerHTML = res.data.html;
					if (ago) { ago.textContent = res.data.ago ? ('上次 ' + res.data.ago) : ''; }
				} else {
					box.innerHTML = '<div class="jyc-tp-empty">' + esc((res && res.data) ? String(res.data) : '请求失败，请重试') + '</div>';
					jycToast((res && res.data) ? String(res.data) : '请求失败，请重试');
				}
				if (btn) { btn.disabled = false; btn.textContent = oldTxt; }
			});
	};

	/* ---------------- 历史垃圾评论清理：扫描 + 人工确认后删除 ---------------- */
	window.jycCcToggle = function (btn) {
		var bar = btn.closest('.jyc-cc-bar');
		if (!bar) { return; }
		var result = bar.parentNode;
		var collapsed = result.classList.toggle('jyc-cc-collapsed');
		btn.classList.toggle('is-open', collapsed);
		btn.setAttribute('aria-expanded', String(!collapsed));
	};
	window.jycCcScan = function (btn) {
		var form = document.getElementById('jyc-form');
		var box = document.getElementById('jyc-ccResult');
		if (!form || !box) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_comment_cleanup_scan');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '扫描中…'; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!(res && res.success)) {
					jycToast((res && res.data) ? String(res.data) : '扫描失败，请重试');
					return;
				}
				var items = (res.data && res.data.items) ? res.data.items : [];
				var html = '<div class="jyc-cc-bar">'
					+ '<button type="button" class="jyc-icon-btn" aria-label="折叠/展开扫描结果" aria-expanded="true" onclick="window.jycCcToggle(this)"><span class="jyc-ico-ch" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></span></button>'
					+ '<span class="jyc-cc-title">扫描结果</span>'
					+ '<div class="jyc-cc-actions">'
					+ '<label class="jyc-switch" style="margin:0"><input type="checkbox" id="jyc-ccAll"><span class="jyc-track"></span></label>'
					+ '<span class="jyc-muted" style="font-size:12px" id="jyc-ccSelCount">已选 0 项</span>'
					+ '<button type="button" class="jyc-icon-btn jyc-icon-danger" data-loading="删除中…" aria-label="删除选中" title="删除选中" onclick="window.jycCcDelete(this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg></button>'
					+ '</div></div>';
				html += '<div class="jyc-cc-body">';
				html += '<div class="jyc-muted" style="font-size:12px;margin-bottom:8px">' + esc(res.data && res.data.msg ? res.data.msg : '') + '</div>';
				html += '<div id="jyc-ccList" style="max-height:420px;overflow:auto;border:1px solid rgba(128,128,128,.18);border-radius:8px">';
				if (!items.length) {
					html += '<div style="padding:14px;font-size:13px;color:#2e7d32">没有发现疑似垃圾评论，评论很干净。</div>';
				} else {
					items.forEach(function (it) {
						var color = it.level === 'high' ? '#d23f3f' : (it.level === 'mid' ? '#e08a1e' : '#5a7fb5');
						html += '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-bottom:1px solid rgba(128,128,128,.14);cursor:pointer">'
							+ '<input type="checkbox" class="jyc-ccItem" data-id="' + esc(it.id) + '" style="margin-top:3px">'
							+ '<div style="flex:1;min-width:0">'
							+ '<div style="font-size:13px"><b>' + esc(it.author) + '</b> <span class="jyc-muted" style="font-size:12px">' + esc(it.date) + '</span>'
							+ '<span style="font-size:11px;color:' + color + ';border:1px solid ' + color + ';border-radius:10px;padding:0 6px;margin-left:4px">' + esc(it.score) + ' 分</span></div>';
						if (it.reasons && it.reasons.length) {
							html += '<div style="font-size:12px;color:#b06a00;margin:3px 0">' + esc(it.reasons.join('；')) + '</div>';
						}
						if (it.excerpt) {
							html += '<div class="jyc-muted" style="font-size:12px;word-break:break-all">' + esc(it.excerpt) + '</div>';
						}
						html += '</div></label>';
					});
				}
				html += '</div></div>';
				box.innerHTML = html;
				box.hidden = false;
				var all = document.getElementById('jyc-ccAll');
				if (all) {
					all.addEventListener('change', function () {
						box.querySelectorAll('.jyc-ccItem').forEach(function (c) { c.checked = all.checked; });
						jycCcUpdateCount();
					});
				}
				box.querySelectorAll('.jyc-ccItem').forEach(function (c) {
					c.addEventListener('change', jycCcUpdateCount);
				});
				jycCcUpdateCount();
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () {
				if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; }
			});
	};
	function jycCcUpdateCount() {
		var box = document.getElementById('jyc-ccResult');
		if (!box) { return; }
		var n = box.querySelectorAll('.jyc-ccItem:checked').length;
		var el = document.getElementById('jyc-ccSelCount');
		if (el) { el.textContent = '已选 ' + n + ' 项'; }
	}
	window.jycCcDelete = function (btn) {
		var form = document.getElementById('jyc-form');
		var box = document.getElementById('jyc-ccResult');
		if (!form || !box) { return; }
		var ids = [];
		box.querySelectorAll('.jyc-ccItem:checked').forEach(function (c) {
			var v = parseInt(c.getAttribute('data-id'), 10);
			if (v) { ids.push(v); }
		});
		if (!ids.length) { jycToast('请先勾选要删除的评论'); return; }
		var bakEl = document.getElementById('jyc-ccBackup');
		var willBackup = bakEl && bakEl.checked;
		if (!window.confirm('确认删除选中的 ' + ids.length + ' 条评论？此操作不可撤销' + (willBackup ? '（已开启备份到备份表）' : '（未开启备份）') + '。')) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_comment_cleanup_delete');
		fd.append('ids', ids.join(','));
		fd.append('backup', willBackup ? '1' : '0');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldHtml = '', loading = false;
		if (btn) { oldHtml = btn.innerHTML; btn.disabled = true; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					jycToast(res.data && res.data.msg ? res.data.msg : '删除完成');
					box.querySelectorAll('.jyc-ccItem:checked').forEach(function (c) {
						var lab = c.closest('label');
						if (lab && lab.parentNode) { lab.parentNode.removeChild(lab); }
					});
					var all = document.getElementById('jyc-ccAll');
					if (all) { all.checked = false; }
					jycCcUpdateCount();
				} else {
					jycToast((res && res.data) ? String(res.data) : '删除失败，请重试');
				}
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () {
				if (loading && btn) { btn.disabled = false; if (oldHtml) { btn.innerHTML = oldHtml; } }
			});
	};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* ---------------- 内容 SEO 诊断 ---------------- */
	// 与图片体检同款折叠开关：首点跑诊断，之后点击=开合；状态点绿=达标，橙=有待补写。
	window.jycSeoDiag = function (btn) {
		var form = document.getElementById('jyc-form');
		var fold = document.getElementById('jyc-seoDiagResult');
		var inner = document.getElementById('jyc-seoDiagInner');
		if (!form || !fold || !inner) { return; }
		var txt = btn.querySelector('.jyc-fold-txt');
		if (inner.childNodes.length) {
			var willOpen = !fold.classList.contains('jyc-fold-open');
			fold.classList.toggle('jyc-fold-open', willOpen);
			btn.classList.toggle('is-open', willOpen);
			btn.setAttribute('aria-expanded', String(willOpen));
			return;
		}
		var fd = new FormData(form);
		fd.append('action', 'jinyu_seo_diag');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = txt ? txt.textContent : '';
		btn.disabled = true;
		btn.classList.add('is-busy');
		if (txt) { txt.textContent = btn.getAttribute('data-loading') || '诊断中…'; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!(res && res.success)) {
					jycToast((res && res.data) ? String(res.data) : '诊断失败，请重试');
					btn.classList.remove('is-busy');
					return;
				}
				var d = res.data || {};
				function list(items, total, unit) {
					if (!total) { return '<div style="font-size:13px;color:#2e7d32">无（' + unit + '）</div>'; }
					var html = '<div style="font-size:13px;margin-bottom:4px">共 <b>' + total + '</b> 篇' + (items.length < total ? '（显示最近 ' + items.length + ' 篇）' : '') + '</div>';
					html += '<table style="width:100%;border-collapse:collapse;font-size:13px">';
					items.forEach(function (it) {
						html += '<tr>'
							+ '<td style="padding:5px 8px 5px 0;border-bottom:1px solid rgba(128,128,128,.18);word-break:break-all">' + esc(it.title) + '</td>'
							+ '<td style="padding:5px 8px;border-bottom:1px solid rgba(128,128,128,.18);white-space:nowrap" class="jyc-muted">' + it.len + ' 字</td>'
							+ '<td style="padding:5px 0;border-bottom:1px solid rgba(128,128,128,.18);text-align:right;white-space:nowrap">'
							+ (it.edit ? '<a href="' + esc(it.edit) + '" target="_blank" style="font-size:12px">编辑</a>' : '')
							+ '</td></tr>';
					});
					html += '</table>';
					return html;
				}
				inner.innerHTML = '<div class="jyc-muted" style="font-size:12px;margin-bottom:6px">标题过短（&lt;15 字）</div>'
					+ list(d.short_title, d.short_title_total, '标题全部达标')
					+ '<div class="jyc-muted" style="font-size:12px;margin:10px 0 6px">摘要过短（&lt;60 字，且未写自定义描述）</div>'
					+ list(d.short_desc, d.short_desc_total, '摘要全部达标');
				fold.classList.add('jyc-fold-open');
				btn.classList.remove('is-busy');
				btn.classList.add('is-open', (d.short_title_total || d.short_desc_total) ? 'is-warn' : 'is-ok');
				btn.setAttribute('aria-expanded', 'true');
			})
			.catch(function () {
				jycToast('请求失败，请重试');
				btn.classList.remove('is-busy');
			})
			.then(function () {
				btn.disabled = false;
				if (txt) { txt.textContent = oldTxt; }
			});
	};

	/* ---------------- 推送记录：刷新 / 清空 + 推送后自动刷新 ----------------
	 * 表格 HTML 由服务端统一渲染（首屏与 AJAX 共用同一渲染函数），前端只做替换，避免两套标记漂移。 */
	function jycPushLogSet(html) {
		var box = document.getElementById('jyc-pushLogBody');
		if (box) { box.innerHTML = html || ''; }
	}
	window.jycPushLogRefresh = function (btn) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_push_log_refresh');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '刷新中…'; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) { jycPushLogSet(res.data && res.data.html); }
				else { jycToast((res && res.data) ? String(res.data) : '刷新失败，请重试'); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () { if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; } });
	};
	window.jycPushLogClear = function (btn) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		if (!window.confirm('确定清空全部推送记录？仅清除后台留痕，不影响已提交给搜索引擎的 URL。')) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_push_log_clear');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = '', loading = false;
		if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = btn.getAttribute('data-loading') || '清空中…'; loading = true; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					jycPushLogSet(res.data && res.data.html);
					jycToast((res.data && res.data.msg) ? res.data.msg : '推送记录已清空');
				} else { jycToast((res && res.data) ? String(res.data) : '清空失败，请重试'); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () { if (loading && btn) { btn.disabled = false; btn.textContent = oldTxt; } });
	};

	/* ---------------- 全量补推 ---------------- */
	// 与图片体检同款：按钮即折叠开关，首次点击跑推送，之后点击=开合结果。
	// 状态点语义：灰=未推送，橙闪=推送中，绿=完成。
	window.jycBulkPush = function (btn) {
		var form = document.getElementById('jyc-form');
		var fold = document.getElementById('jyc-bulkPushResult');
		var inner = document.getElementById('jyc-bulkPushInner');
		if (!form || !fold || !inner) { return; }
		var txt = btn.querySelector('.jyc-fold-txt');
		if (inner.childNodes.length) {
			var willOpen = !fold.classList.contains('jyc-fold-open');
			fold.classList.toggle('jyc-fold-open', willOpen);
			btn.classList.toggle('is-open', willOpen);
			btn.setAttribute('aria-expanded', String(willOpen));
			return;
		}
		var fd = new FormData(form);
		fd.append('action', 'jinyu_bulk_push');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = txt ? txt.textContent : '';
		btn.disabled = true;
		btn.classList.add('is-busy');
		if (txt) { txt.textContent = btn.getAttribute('data-loading') || '推送中…'; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!(res && res.success)) {
					jycToast((res && res.data) ? String(res.data) : '推送失败，请重试');
					btn.classList.remove('is-busy');
					return;
				}
				var d = res.data || {};
				function row(label, st) {
					if (!st || !st.enabled) { return ''; }
					var ok = st.ok ? '✓ 已提交' : '✗ 接口未返回成功';
					return '<div style="font-size:13px;padding:4px 0;border-bottom:1px solid rgba(128,128,128,.16)">'
						+ esc(label) + '：共 ' + st.total + ' 条，本次提交 ' + st.sent + ' 条 &nbsp;<b>' + ok + '</b></div>';
				}
				var html = row('IndexNow', d.indexnow) + row('百度主动推送', d.baidu);
				if (!html) { html = '<div style="font-size:13px">未启用任何推送渠道。</div>'; }
				inner.innerHTML = html;
				fold.classList.add('jyc-fold-open');
				btn.classList.remove('is-busy');
				btn.classList.add('is-open', 'is-ok');
				btn.setAttribute('aria-expanded', 'true');
				jycToast('全量补推完成');
				window.jycPushLogRefresh(null);   // 补推留痕后立即刷新「推送记录」
			})
			.catch(function () {
				jycToast('请求失败，请重试');
				btn.classList.remove('is-busy');
			})
			.then(function () {
				btn.disabled = false;
				if (txt) { txt.textContent = oldTxt; }
			});
	};

	/* ---------------- 图片 SEO：体检 ---------------- */
	// 按钮即折叠开关：首次点击跑体检，之后点击=开合结果（grid-rows 高度过渡动画）。
	// 状态点语义：灰=未体检，橙闪=体检中，绿=无真问题，橙=有需处理项。
	// 已自动兜底明细的展开/收起。
	window.jycImgAuditAuto = function () {
		var el = document.getElementById('jyc-imgAuditAuto');
		if (!el) { return; }
		el.hidden = !el.hidden;
		var t = document.getElementById('jyc-imgAuditAutoToggle');
		if (t) { t.textContent = el.hidden ? '展开明细 ▸' : '收起明细 ▴'; }
	};
	window.jycImgAudit = function (btn) {
		var form = document.getElementById('jyc-form');
		var fold = document.getElementById('jyc-imgAuditResult');
		var inner = document.getElementById('jyc-imgAuditInner');
		if (!form || !fold || !inner) { return; }
		var txt = btn.querySelector('.jyc-fold-txt');
		// 已有结果：仅开合，不重扫全站。
		if (inner.childNodes.length) {
			var willOpen = !fold.classList.contains('jyc-fold-open');
			fold.classList.toggle('jyc-fold-open', willOpen);
			btn.classList.toggle('is-open', willOpen);
			btn.setAttribute('aria-expanded', String(willOpen));
			return;
		}
		// 整表单提交（含 jinyu_companion_nonce）。
		var fd = new FormData(form);
		fd.append('action', 'jinyu_img_audit');
		fd.append('force', '1');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var oldTxt = txt ? txt.textContent : '';
		btn.disabled = true;
		btn.classList.remove('is-ok', 'is-warn');
		btn.classList.add('is-busy');
		if (txt) { txt.textContent = btn.getAttribute('data-loading') || '体检中…'; }
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!(res && res.success)) {
					jycToast((res && res.data) ? String(res.data) : '体检失败，请重试');
					btn.classList.remove('is-busy');
					return;
				}
				function esc(s) {
					return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
						return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
					});
				}
				var d = res.data || {};
				var stuck = parseInt(d.stuck_dim, 10) || 0;
				var weak = parseInt(d.weak_alt, 10) || 0;
				var real = stuck + weak; // 前台补不了、需要作者动手的真问题数。
				var html = '<div class="jyc-muted" style="font-size:12px;margin-bottom:8px">全站 <b>' + esc(d.posts) + '</b> 篇文章含 <b>' + esc(d.imgs) + '</b> 张正文图片</div>';
				// 状态横幅：只按「前台补不了」的真问题定性，避免吓到用户。
				if (!real) {
					html += '<div style="font-size:13px;padding:8px 10px;border-radius:6px;margin-bottom:8px;background:rgba(46,125,50,.1);color:#2e7d32"><b>✓ 图片 SEO 状态良好</b>：前台输出的图片均已自动补齐 alt 与尺寸，无失效图片，搜索引擎抓到的 HTML 是规范的。</div>';
				} else {
					var need = [];
					if (stuck) { need.push('<b>' + esc(stuck) + '</b> 张外链图 / 已失效图片（补不了尺寸，建议转存到媒体库）'); }
					if (weak) { need.push('<b>' + esc(weak) + '</b> 张图片的 alt 只能用文件名兜底（建议补真实描述）'); }
					html += '<div style="font-size:13px;padding:8px 10px;border-radius:6px;margin-bottom:8px;background:rgba(230,126,34,.12);color:#b06000"><b>有 ' + esc(real) + ' 处图片问题需要处理</b>：' + need.join('；') + '。</div>';
				}
				// 真问题文章列表（只列有「补不了」项的，通常很短）。
				if (real && d.list && d.list.length) {
					html += '<div style="font-size:13px;margin:4px 0">需要处理的文章' + (d.list.length < d.list_total ? '（前 ' + d.list.length + ' 篇 / 共 ' + esc(d.list_total) + ' 篇）' : '') + '</div>';
					html += '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px">';
					d.list.forEach(function (it) {
						var tags = [];
						if (it.s) { tags.push('外链×' + it.s); }
						if (it.w) { tags.push('alt质量差×' + it.w); }
						html += '<tr>'
							+ '<td style="padding:5px 8px 5px 0;border-bottom:1px solid rgba(128,128,128,.18);word-break:break-all">' + esc(it.title) + '</td>'
							+ '<td style="padding:5px 8px;border-bottom:1px solid rgba(128,128,128,.18);white-space:nowrap" class="jyc-muted">' + esc(tags.join('，')) + '</td>'
							+ '<td style="padding:5px 0;border-bottom:1px solid rgba(128,128,128,.18);text-align:right;white-space:nowrap">'
							+ '<a href="' + esc(it.url) + '" target="_blank" rel="noopener" style="font-size:12px">查看</a>'
							+ (it.edit ? ' <a href="' + esc(it.edit) + '" target="_blank" style="font-size:12px">编辑</a>' : '')
							+ '</td></tr>';
					});
					html += '</table>';
				}
				// 已自动兜底的历史原文：默认折叠 + 正面表述，不作为问题吓人。
				var autoAlt = parseInt(d.auto_alt, 10) || 0;
				var autoDim = parseInt(d.auto_dim, 10) || 0;
				if (autoAlt > 0 || autoDim > 0) {
					var autoBits = [];
					if (autoAlt > 0) { autoBits.push('<b>' + esc(autoAlt) + '</b> 张 alt'); }
					if (autoDim > 0) { autoBits.push('<b>' + esc(autoDim) + '</b> 张尺寸'); }
					html += '<div class="jyc-muted" style="font-size:12px;margin-bottom:4px">另有 ' + autoBits.join('、') + ' 未写在文章原文里，前台输出时插件已自动补齐' + (parseInt(d.auto_articles, 10) > 0 ? '（涉及 ' + esc(d.auto_articles) + ' 篇文章）' : '') + '，<b>无需处理</b>。</div>';
					html += '<div style="font-size:12px"><a href="javascript:;" id="jyc-imgAuditAutoToggle" onclick="window.jycImgAuditAuto()">展开明细 ▸</a></div>';
					html += '<div id="jyc-imgAuditAuto" hidden style="margin-top:6px">';
					html += '<div class="jyc-muted" style="font-size:12px;margin-bottom:4px">历史原文统计（数据库口径，仅供参考，前台渲染不受影响）：</div>';
					if (d.auto_list && d.auto_list.length) {
						html += '<table style="width:100%;border-collapse:collapse;font-size:12px">';
						d.auto_list.forEach(function (it) {
							var tags = [];
							if (it.a) { tags.push('原文未写 alt×' + it.a); }
							if (it.d) { tags.push('原文未写尺寸×' + it.d); }
							html += '<tr>'
								+ '<td style="padding:4px 8px 4px 0;border-bottom:1px solid rgba(128,128,128,.12);word-break:break-all">' + esc(it.title) + '</td>'
								+ '<td style="padding:4px 8px;border-bottom:1px solid rgba(128,128,128,.12);white-space:nowrap" class="jyc-muted">' + esc(tags.join('，')) + '</td>'
								+ '<td style="padding:4px 0;border-bottom:1px solid rgba(128,128,128,.12);text-align:right;white-space:nowrap"><a href="' + esc(it.url) + '" target="_blank" rel="noopener" style="font-size:12px">查看</a></td>'
								+ '</tr>';
						});
						html += '</table>';
					}
					html += '</div>';
				}
				inner.innerHTML = html;
				fold.classList.add('jyc-fold-open');
				btn.classList.remove('is-busy');
				btn.classList.add('is-open', real > 0 ? 'is-warn' : 'is-ok');
				btn.setAttribute('aria-expanded', 'true');
			})
			.catch(function () {
				jycToast('请求失败，请重试');
				btn.classList.remove('is-busy');
			})
			.then(function () {
				btn.disabled = false;
				if (txt) { txt.textContent = oldTxt; }
			});
	};

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

	/* ---------------- 对象存储：链接切换开关（apply/unapply 同按钮来回拨动） ----------------
	 * 按钮随当前状态变换文案与动作：CDN 加速中 → 复原为本地链接；本地直连 → 一键替换为 CDN。
	 * 后端直接写选项并清缓存（apply 未填域名时回退已保存配置），成功后翻转按钮与状态胶囊。 */
	window.jycStorageDomain = function (btn) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var action = btn.getAttribute('data-action');
		var fd = new FormData(form);
		fd.append('action', action);
		var dEl = form.querySelector('input[name="storage_domain"]');
		if (dEl) { fd.set('storage_domain', dEl.value); }
		var oldTxt = btn.textContent;
		btn.disabled = true; btn.textContent = '处理中…';
		var flipped = false;
		fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					jycToast(res.data || '已完成');
					flipped = true;
					jycDomainFlip(action === 'jinyu_storage_apply_domain');
				} else { jycToast((res && res.data) ? String(res.data) : '操作失败'); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () { btn.disabled = false; if (!flipped) { btn.textContent = oldTxt; } });
	};
	function jycDomainFlip(on) {
		var btn = document.getElementById('jycDomainToggle');
		if (btn) {
			btn.setAttribute('data-action', on ? 'jinyu_storage_unapply_domain' : 'jinyu_storage_apply_domain');
			btn.classList.toggle('jyc-btn-primary', !on);
			btn.classList.toggle('jyc-btn-ghost', !!on);
			btn.textContent = on ? '复原为本地链接' : '一键替换为 CDN 链接';
		}
		var st = document.getElementById('jycDomainState');
		if (st) {
			st.className = 'jyc-opstate ' + (on ? 'is-on' : 'is-off');
			st.textContent = on ? 'CDN 加速中' : '本地直连';
		}
	}

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
	/* 运行中把当前任务的按钮就地变成「停止任务」（红色），其余按钮禁用；结束后复原文案。 */
	function jycBatchLock(on, type) {
		var sel = '.jyc-batch-btn, .jyc-sync-btn';
		document.querySelectorAll(sel).forEach(function (b) {
			var mine = on && b.getAttribute('data-batch') === type;
			b.disabled = on && !mine;
			b.classList.toggle('jyc-btn-danger', !!mine);
			b.textContent = mine ? '停止任务' : (b.getAttribute('data-label') || b.textContent);
		});
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
	/* 批量按钮统一入口：空闲 = 启动对应任务；该任务运行中 = 同一按钮变停止。 */
	window.jycBatchAction = function (btn, type) {
		if (jycBatch.running && jycBatch.type === type) { window.jycStorageStop(); return; }
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd;
		if (type === 'sync') {
			var ta = document.getElementById('jyc-syncPaths');
			if (!ta) { return; }
			if (!ta.value.trim()) { jycToast('请先填写要同步的文件路径'); ta.focus(); return; }
			fd = new FormData(form);
			fd.append('action', 'jinyu_storage_sync_selected');
			fd.set('sync_paths', ta.value);
		} else {
			fd = new FormData(form);
			fd.append('action', type === 'pull' ? 'jinyu_storage_pull' : 'jinyu_storage_push');
		}
		jycBatchStart(type, fd);
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
	 * 原生 option 弹层浏览器禁止样式化（Windows Chrome 恒白底黑字），改为自绘列表。
	 * 原生 select 隐藏保留在表单内（display:none 仍随表单提交），值双向同步。
	 * 定位：弹层挂到 .jyc-app 下并 position:fixed —— 面板容器（.jyc-panel / .jyc-sl-card /
	 * .jperf-wv-chip / .jyc-bento .jyc-tile）普遍 overflow:hidden，absolute 弹层必被裁掉；
	 * fixed 不受任何祖先裁剪，同时留在 .jyc-app 内以继承设计令牌。下方空间不足自动上翻，
	 * 滚动 / 缩放时按 rAF 节流重定位，点击外部或 Esc 关闭。 */
	var SEL_CHEV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
	var SEL_CHECK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
	var SEL_GAP = 6, SEL_MAX = 240;

	var selOpen = null, selPlace = new WeakMap(), selRaf = 0;

	function selClose() {
		if (!selOpen) { return; }
		selOpen = null;
		document.querySelectorAll('.jyc-select-list.jyc-open').forEach(function (l) { l.classList.remove('jyc-open'); });
		document.querySelectorAll('.jyc-select.jyc-open').forEach(function (w) { w.classList.remove('jyc-open'); });
	}
	function selMove() {
		if (!selOpen || selRaf) { return; }
		selRaf = requestAnimationFrame(function () {
			selRaf = 0;
			if (!selOpen) { return; }
			var place = selPlace.get(selOpen);
			if (place) { place(); }
		});
	}
	/* .jyc-app 被祖先 transform / filter / backdrop-filter / contain 困住时，fixed 会以它为
	   包含块，定位需减去其视口偏移；正常情况下走 0 偏移分支（面板外壳无这些属性）。 */
	function selOrigin() {
		function none(v) { return !v || 'none' === v; }
		var cs = window.getComputedStyle(app);
		if (none(cs.transform) && none(cs.filter) && none(cs.perspective) &&
			none(cs.contain) && none(cs.backdropFilter) && none(cs.webkitBackdropFilter)) {
			return { x: 0, y: 0, h: 0 };
		}
		var r = app.getBoundingClientRect();
		return { x: r.left, y: r.top, h: r.bottom };
	}
	window.addEventListener('scroll', selMove, true);
	window.addEventListener('resize', selMove);
	document.addEventListener('click', selClose);
	document.addEventListener('keydown', function (e) { if ('Escape' === e.key) { selClose(); } });

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
		btn.insertAdjacentHTML('beforeend', SEL_CHEV);
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
		/* 先量自然高度再决定展开方向：下方放不下整张列表且上方更宽裕 → 上翻。
		   宽度随按钮，左右做 8px 视口内缩，避免窄屏贴边出屏。 */
		function place() {
			var r = btn.getBoundingClientRect();
			var vw = document.documentElement.clientWidth;
			var vh = document.documentElement.clientHeight;
			var org = selOrigin();
			list.style.width = r.width + 'px';
			list.style.maxHeight = 'none';
			var natural = list.offsetHeight;
			var below = vh - r.bottom - SEL_GAP;
			var above = r.top - SEL_GAP;
			var up = below < Math.min(natural, SEL_MAX) && above > below;
			list.classList.toggle('jyc-up', up);
			list.style.maxHeight = Math.round(Math.max(120, up ? above : below)) + 'px';
			list.style.left = (Math.min(Math.max(8, r.left), Math.max(8, vw - r.width - 8)) - org.x) + 'px';
			if (up) {
				list.style.top = 'auto';
				list.style.bottom = ((org.h || vh) - (r.top - org.y) + SEL_GAP) + 'px';
			} else {
				list.style.bottom = 'auto';
				list.style.top = (r.bottom + SEL_GAP - org.y) + 'px';
			}
		}
		function closeList() {
			if (selOpen === list) { selOpen = null; }
			wrap.classList.remove('jyc-open');
			list.classList.remove('jyc-open');
		}
		function openList() {
			selClose();
			wrap.classList.add('jyc-open');
			place();                 // 先定位再显形，避免在旧位置闪一帧
			list.classList.add('jyc-open');
			selOpen = list;
		}

		Array.prototype.forEach.call(sel.options, function (o, i) {
			var it = document.createElement('div');
			it.className = 'jyc-select-item' + (o.selected ? ' jyc-sel' : '');
			it.setAttribute('role', 'option');
			var t = document.createElement('span');
			t.textContent = o.textContent;
			it.appendChild(t);
			it.insertAdjacentHTML('beforeend', SEL_CHECK);
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
			e.stopPropagation();     // 否则 document 上的关闭监听会立刻收起
			if (list.classList.contains('jyc-open')) { closeList(); } else { openList(); }
		});
		// 键盘：上下改值（同步显示）；开合与 Esc 由全局监听处理
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
			}
		});

		sel.style.display = 'none';
		sel.parentNode.insertBefore(wrap, sel);
		wrap.appendChild(btn);
		wrap.appendChild(sel);
		app.appendChild(list);       // 弹层交给 .jyc-app 托管（脱离所有裁剪容器）
		selPlace.set(list, place);
		renderLabel();
	});

	/* ---------------- 社交登录：复制回调地址 ---------------- */
	document.querySelectorAll('.jyc-sl-copy').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var id = btn.getAttribute('data-copy');
			var el = id ? document.getElementById(id) : null;
			if (!el) { return; }
			var done = function () {
				var old = btn.textContent;
				btn.textContent = '已复制';
				if (window.jycToast) { window.jycToast('已复制到剪贴板'); }
				setTimeout(function () { btn.textContent = old; }, 1400);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(el.value).then(done, function () {
					el.select();
					try { document.execCommand('copy'); } catch (e) {}
					done();
				});
			} else {
				el.select();
				try { document.execCommand('copy'); } catch (e) {}
				done();
			}
		});
	});

	/* ---------------- 分享素材：媒体库选图 + 尺寸校验 + 卡片预览联动 ---------------- */
	var ogInput = document.getElementById('jyc-ogImage');
	if (ogInput) {
		var ogForm    = document.getElementById('jyc-form');
		var ogThumb   = document.getElementById('jyc-ogThumb');
		var ogImg     = document.getElementById('jyc-ogThumbImg');
		var ogSizeEl  = document.getElementById('jyc-ogSize');
		var pvBox     = document.getElementById('jyc-pvImgBox');
		var pvImg     = document.getElementById('jyc-pvImg');
		var pvTitle   = document.getElementById('jyc-pvTitle');
		var ogSiteEl  = document.getElementById('jyc-ogSite');
		var SIZE_HINT = ogSizeEl ? ogSizeEl.textContent : '';

		function setSize(text, state) {
			if (!ogSizeEl) { return; }
			ogSizeEl.textContent = text;
			ogSizeEl.classList.toggle('is-ok', state === 'ok');
			ogSizeEl.classList.toggle('is-warn', state === 'warn');
		}

		// 缩略图与预览图同源：URL 为空时退回占位形态，非空则同图加载（缩略图额外做比例校验）。
		function renderImage(url) {
			var has = !!url;
			ogThumb.classList.toggle('has-img', has);
			pvBox.classList.toggle('has-img', has);
			if (!has) {
				ogImg.removeAttribute('src');
				pvImg.removeAttribute('src');
				setSize(SIZE_HINT, '');
				return;
			}
			ogImg.src = url;
			pvImg.src = url;
		}

		// 容差 6% + 最小宽度 600：比例接近 1.9:1 即视为合规（微信 / 微博大图卡片）。
		function measureThumb() {
			if (!ogImg.getAttribute('src')) { return; }
			var w = ogImg.naturalWidth, h = ogImg.naturalHeight;
			if (!w || !h) {
				setSize('图片无法加载，请确认地址可公开访问（勿用本地路径）', 'warn');
				return;
			}
			var ok = Math.abs(w / h - 1200 / 630) < 0.06 && w >= 600;
			setSize(w + '×' + h + (ok ? ' ✓ 比例合规' : ' ⚠ 建议 1200×630'), ok ? 'ok' : 'warn');
		}
		ogImg.addEventListener('load', measureThumb);
		ogImg.addEventListener('error', measureThumb);
		// 缓存命中时 load 事件早于本脚本，直接补测一次，否则量不出尺寸（实测踩坑）。
		if (ogImg.complete) { measureThumb(); }

		/* 标签清单实时同步：读输入框现值（而非已保存值），未保存也能看到「哪些标签会生效」。 */
		function syncTags() {
			document.querySelectorAll('.jyc-og-tag[data-dep]').forEach(function (chip) {
				var parts = chip.getAttribute('data-dep').split(':');
				var el = ogForm ? ogForm.querySelector('[name="' + parts[1] + '"]') : null;
				var on = el ? ('check' === parts[0] ? el.checked : !!el.value.trim()) : false;
				chip.classList.toggle('is-on', on);
				chip.classList.toggle('is-off', !on);
			});
		}

		ogInput.addEventListener('input', function () { renderImage(ogInput.value.trim()); });

		/* 站点名称 → 预览卡标题；留空回退默认站点名 */
		if (ogSiteEl && pvTitle) {
			ogSiteEl.addEventListener('input', function () {
				pvTitle.textContent = ogSiteEl.value.trim() || pvTitle.getAttribute('data-default') || '';
			});
		}
		if (ogForm) {
			ogForm.addEventListener('input', syncTags);
			ogForm.addEventListener('change', syncTags);
		}
		syncTags();

		/* 媒体库选图：复用 WP 媒体弹窗（本页已 wp_enqueue_media） */
		var ogPick = document.getElementById('jyc-ogPick');
		if (ogPick && window.wp && window.wp.media) {
			var ogFrame = null;
			ogPick.addEventListener('click', function () {
				if (!ogFrame) {
					ogFrame = window.wp.media({
						title: ogPick.getAttribute('data-title') || '选择分享图',
						button: { text: ogPick.getAttribute('data-btn') || '使用这张图' },
						library: { type: 'image' },
						multiple: false
					});
					ogFrame.on('select', function () {
						var att = ogFrame.state().get('selection').first().toJSON();
						var url = (att.sizes && att.sizes.full && att.sizes.full.url) || att.url || '';
						ogInput.value = url;
						ogInput.dispatchEvent(new Event('input', { bubbles: true }));
					});
				}
				ogFrame.open();
			});
		}
		var ogClear = document.getElementById('jyc-ogClear');
		if (ogClear) {
			ogClear.addEventListener('click', function () {
				ogInput.value = '';
				ogInput.dispatchEvent(new Event('input', { bubbles: true }));
			});
		}
	}

	/* 社交登录：恢复默认回调地址 */
	var resetRedirBtn = document.getElementById('jyc-sl-reset-redirect');
	var redirInput = document.getElementById('jyc-sl-redirect');
	if (resetRedirBtn && redirInput) {
		resetRedirBtn.addEventListener('click', function () {
			var def = redirInput.getAttribute('data-default') || '';
			if (!def) { return; }
			redirInput.value = def;
			redirInput.focus();
			if (window.jycToast) { window.jycToast('已恢复为默认回调地址'); }
		});
	}

})();
