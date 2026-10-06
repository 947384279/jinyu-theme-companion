/* =========================================================================
 * Jinyu Theme Companion · 设置面板交互 (admin.js)
 * 分区导航 / 深色切换 / 验证码分段 / TTL 滑杆人类可读 / 颜色 chip / 概览联动 / toast
 * 仅作用于 .jyc-app 作用域，避免与 WordPress 后台原生脚本冲突。
 * ========================================================================= */
(function () {
	'use strict';

	var app = document.querySelector('.jyc-app');
	if (!app) { return; }

	/* 初始还原折叠态时关闭侧栏相关过渡，避免硬刷出现「先展开再折叠」的入场动画；
	   折叠类由下方 localStorage 还原加上，js 在下一帧移除本类，之后用户手动切换才正常动画。 */
	app.classList.add('jyc-no-anim');

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
		wechat: document.getElementById('pane-wechat'),
		io: document.getElementById('pane-io'),
		media: document.getElementById('pane-media')
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
		try { localStorage.setItem('jinyu_pane', mod); } catch (e) {}
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
			var s = localStorage.getItem('jinyu_pane');
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
		try { localStorage.setItem('jinyu_theme', dark ? 'dark' : 'light'); } catch (e) {}
	}

	if (tog) {
		tog.addEventListener('click', function () {
			var dark = app.getAttribute('data-theme') === 'dark';
			applyTheme(!dark);
		});
	}
	try {
		var saved = localStorage.getItem('jinyu_theme');
		if (saved) { applyTheme(saved === 'dark'); }
	} catch (e) {}

	/* ---------------- 侧栏折叠 / 展开 ----------------
	 * 点击 #jyc-railToggle 切换 .jyc-rail-collapsed（视觉收窄到图标列，仅 ≥880px 左栏模式生效）；
	 * 同步 aria-expanded，并用 localStorage 记忆偏好，刷新后仍保持。 */
	var railTog = document.getElementById('jyc-railToggle');
	function applyRail(collapsed) {
		app.classList.toggle('jyc-rail-collapsed', collapsed);
		if (railTog) { railTog.setAttribute('aria-expanded', String(!collapsed)); }
		try { localStorage.setItem('jinyu_rail', collapsed ? '1' : '0'); } catch (e) {}
		jycHideTip();
	}
	if (railTog) {
		railTog.addEventListener('click', function () {
			applyRail(!app.classList.contains('jyc-rail-collapsed'));
		});
	}
	try {
		var railSaved = localStorage.getItem('jinyu_rail');
		if (railSaved) { applyRail(railSaved === '1'); }
	} catch (e) {}
	/* 过渡抑制类已随初始折叠类生效；下一帧（样式提交后）移除，恢复手动切换动画。 */
	requestAnimationFrame(function () {
		requestAnimationFrame(function () { app.classList.remove('jyc-no-anim'); });
	});

	/* ---------------- 侧栏 hover 提示泡（统一样式） ---------------- */
	/* 两类来源：
	 * ① .jyc-nav-item——仅折叠态触发（展开态标签已内联），文案取内层 <span>；
	 * ② 底栏 [title] 按钮（折叠键/主题键/保存键）——任何时候都触发，替代原生 title
	 *    系统气泡以统一观感；hover 期间临时摘除 title 防止双气泡，移出即还原。
	 * 气泡挂 body、fixed 定位，不被 .jyc-topnav 的 overflow:hidden + contain:paint 裁切。 */
	var navTip = document.createElement('div');
	navTip.className = 'jyc-nav-tip';
	navTip.setAttribute('role', 'tooltip');
	document.body.appendChild(navTip);
	var tipCur = null, tipSavedTitle = null;
	function jycShowTip(el) {
		var label;
		if (el.classList.contains('jyc-nav-item')) {
			if (!app.classList.contains('jyc-rail-collapsed')) { return; }
			var span = el.querySelector('span');
			label = span ? span.textContent.trim() : '';
		} else {
			label = (el.getAttribute('title') || '').trim();
		}
		if (!label) { return; }
		if (el.hasAttribute('title')) { tipSavedTitle = el.getAttribute('title'); el.removeAttribute('title'); }
		tipCur = el;
		var r = el.getBoundingClientRect();
		navTip.textContent = label;
		navTip.style.top = (r.top + r.height / 2) + 'px';
		navTip.style.left = (r.right + 12) + 'px';
		navTip.classList.add('jyc-show');
	}
	function jycHideTip() {
		navTip.classList.remove('jyc-show');
		if (tipCur && tipSavedTitle !== null) { tipCur.setAttribute('title', tipSavedTitle); }
		tipSavedTitle = null; tipCur = null;
	}
	/* 触屏判定（共享）：触屏 tap 会先模拟 mouseover / mouseenter / focus 再 click，
	 * 悬浮类交互若不过滤，会把「悬停显示」和「点按切换」拧在一起（说明气泡要点两次的根因）。
	 * 约定：悬浮类事件只对 pointerType==='mouse' 生效；触屏统一走 click 语义。
	 * jycLastPointerDown 供 focus 类事件区分「键盘 Tab」与「触屏 tap」（tap 后 500ms 内不算键盘）。 */
	var jycLastPointerDown = 0;
	document.addEventListener('pointerdown', function () { jycLastPointerDown = Date.now(); }, true);
	function jycByMouse(e) { return !e || !e.pointerType || 'mouse' === e.pointerType; }

	var topnavEl = document.getElementById('jyc-topnav');
	if (topnavEl) {
		if (typeof window.PointerEvent === 'function') {
			topnavEl.addEventListener('pointerover', function (e) {
				if (!jycByMouse(e)) { return; }
				var el = e.target.closest ? e.target.closest('.jyc-nav-item, [title]') : null;
				if (!el || !topnavEl.contains(el)) { return; }
				if (el === tipCur && navTip.classList.contains('jyc-show')) { return; }
				jycShowTip(el);
			});
			topnavEl.addEventListener('pointerout', function (e) {
				if (!jycByMouse(e)) { return; }
				if (!tipCur) { return; }
				if (e.relatedTarget && tipCur.contains(e.relatedTarget)) { return; }
				jycHideTip();
			});
		} else {
			topnavEl.addEventListener('mouseover', function (e) {
				var el = e.target.closest ? e.target.closest('.jyc-nav-item, [title]') : null;
				if (!el || !topnavEl.contains(el)) { return; }
				if (el === tipCur && navTip.classList.contains('jyc-show')) { return; }
				jycShowTip(el);
			});
			topnavEl.addEventListener('mouseout', function (e) {
				if (!tipCur) { return; }
				if (e.relatedTarget && tipCur.contains(e.relatedTarget)) { return; }
				jycHideTip();
			});
		}
		topnavEl.addEventListener('focusin', function (e) {
			if (Date.now() - jycLastPointerDown < 500) { return; } // 触屏 tap 引发的 focus，非键盘导航
			var el = e.target.closest ? e.target.closest('.jyc-nav-item, [title]') : null;
			if (el) { jycShowTip(el); }
		});
		topnavEl.addEventListener('focusout', jycHideTip);
	}
	window.addEventListener('scroll', jycHideTip, true);

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
		/* 触屏双坑（实测「说明按钮要点两次」的根因）：
		 * ① 触屏 tap 会先模拟 mouseenter 再触发 click —— 第 1 次点 = enter(show) + click(此时已开 → hideNow)，
		 *    开关互斥抵消；第 2 次点不再有 enter，只剩 click 才真正打开。
		 * ② 安卓 tap 也会先 focus 再 click，focus → show 与 click → hideNow 同理互斥。
		 * 对策见上方共享约定：hover 只认 pointerType==='mouse'（无 PointerEvent 才回退 mouseenter），
		 * focus 过滤触屏 tap；触屏统一走 click 切换 + 点空白收起。 */
		if (typeof window.PointerEvent === 'function') {
			btn.addEventListener('pointerenter', function (e) { if (jycByMouse(e)) { show(); } });
			btn.addEventListener('pointerleave', function (e) { if (jycByMouse(e)) { hideSoon(); } });
			pop.addEventListener('pointerenter', function (e) { if (jycByMouse(e)) { clearTimeout(hideT); } });
			pop.addEventListener('pointerleave', function (e) { if (jycByMouse(e)) { hideSoon(); } });
		} else {
			btn.addEventListener('mouseenter', show);
			btn.addEventListener('mouseleave', hideSoon);
			pop.addEventListener('mouseenter', function () { clearTimeout(hideT); });
			pop.addEventListener('mouseleave', hideSoon);
		}
		btn.addEventListener('focus', function () { if (Date.now() - jycLastPointerDown > 500) { show(); } });
		btn.addEventListener('blur', hideNow);
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
		/* 值 → 高亮同步：独立成函数供「放弃更改」复用（discard 回填隐藏域后派发 change 走到这里） */
		function syncSeg() {
			if (!capInput) { return; }
			segBtns.forEach(function (x) { x.classList.toggle('jyc-active', x.getAttribute('data-v') === capInput.value); });
		}
		segBtns.forEach(function (b) {
			b.addEventListener('click', function () {
				segBtns.forEach(function (x) { x.classList.remove('jyc-active'); });
				b.classList.add('jyc-active');
				if (capInput) { capInput.value = b.getAttribute('data-v'); }
				computeOverview();
			});
		});
		if (capInput) { capInput.addEventListener('change', function () { syncSeg(); computeOverview(); }); }
	}

	/* ---------------- 缓存有效期：预设胶囊 + 滑杆 ---------------- */
	var ttlHidden  = document.getElementById('jyc-ttlHidden');
	var ttlValEl   = document.getElementById('jyc-ttlVal');
	var ttlSecEl   = document.getElementById('jyc-ttlSec');
	var ttlRange   = document.getElementById('jyc-ttlRange');
	var ttlPresets = document.getElementById('jyc-ttlPresets');
	var TTL_MIN = 60, TTL_MAX = 2592000;
	function humanTtl(s) {
		s = +s;
		if (s < 3600) { return Math.round(s / 60) + ' 分钟'; }
		if (s < 86400) { return (s / 3600).toFixed(s % 3600 ? 1 : 0) + ' 小时'; }
		return (s / 86400).toFixed(1) + ' 天';
	}
	function setTtl(s) {
		s = Math.round(+s);
		if (!isFinite(s) || s < TTL_MIN) { s = TTL_MIN; }
		if (s > TTL_MAX) { s = TTL_MAX; }
		if (ttlHidden) { ttlHidden.value = s; }
		if (ttlRange) {
			if (+ttlRange.value !== s) { ttlRange.value = s; }
			ttlRange.style.setProperty('--p', (s - TTL_MIN) / (TTL_MAX - TTL_MIN) * 100 + '%');
		}
		if (ttlValEl) { ttlValEl.textContent = humanTtl(s); }
		if (ttlSecEl) { ttlSecEl.textContent = s + ' 秒'; }
		if (ttlPresets) {
			ttlPresets.querySelectorAll('.jyc-ttl-chip').forEach(function (c) {
				c.classList.toggle('is-active', +c.dataset.sec === s);
			});
		}
	}
	if (ttlPresets) {
		ttlPresets.querySelectorAll('.jyc-ttl-chip').forEach(function (c) {
			c.addEventListener('click', function () { setTtl(+c.dataset.sec); });
		});
	}
	if (ttlRange) { ttlRange.addEventListener('input', function () { setTtl(+ttlRange.value); }); }
	if (ttlHidden) { setTtl(+ttlHidden.value); }
	/* 放弃更改：discard 回填隐藏域后派发 change → 滑杆 / 文案 / 预设胶囊跟着回滚 */
	if (ttlHidden) { ttlHidden.addEventListener('change', function () { setTtl(+ttlHidden.value); }); }

	/* ---------------- 整页缓存：模式切换显隐边缘配置 ---------------- */
	/* 选中态由原生 :checked + 相邻兄弟 CSS 驱动，无需 JS 打类；此处只管边缘配置的显隐。 */
	window.jycToggleCacheMode = function () {
		var checked = document.querySelector('input[name="page_cache_mode"]:checked');
		var box = document.getElementById('jycEdgeBox');
		if (!box) { return; }
		var isEdge = !!(checked && checked.value === 'edge');
		box.hidden = !isEdge;
		box.classList.toggle('js-edge-hidden', !isEdge);
	};
	window.jycToggleCacheMode();

	/* ---------------- 整页缓存：边缘配置片段折叠 ---------------- */
	/* 默认收起，展开时才占高度，避免常驻片段撑高「整页缓存」卡片。 */
	window.jycEdgeSnippetToggle = function (btn) {
		var box = document.getElementById('jycEdgeSnippet');
		var body = document.getElementById('jycEdgeSnippetBody');
		if (!box || !body) { return; }
		var open = box.classList.toggle('is-open');
		body.hidden = !open;
		if (btn) { btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
	};

	/* ---------------- 整页缓存：配置片段标签页（Nginx / Apache） ---------------- */
	var codeText = document.getElementById('jycCodeText');
	var codeViewer = document.getElementById('jycCodeViewer');
	var codeCache = null;
	if (codeViewer) {
		try { codeCache = JSON.parse(codeViewer.getAttribute('data-snippets') || '') || {}; } catch (e) { codeCache = {}; }
	}
	function edgeCodeRender(key) {
		if (!codeViewer || !codeText || !codeCache) { return; }
		var val = codeCache[key] || '';
		codeText.value = val;
		codeViewer.setAttribute('data-active', key);
		var tabs = codeViewer.querySelectorAll('.jyc-code-tab');
		for (var i = 0; i < tabs.length; i++) {
			tabs[i].classList.toggle('is-active', tabs[i].getAttribute('data-code') === key);
		}
		var hints = codeViewer.querySelectorAll('.jyc-code-hint');
		for (var j = 0; j < hints.length; j++) {
			hints[j].classList.toggle('is-shown', hints[j].getAttribute('data-code') === key);
		}
	}
	window.jycEdgeCodeTab = function (key) { edgeCodeRender(key); };
	window.jycEdgeCodeCopy = function (btn) {
		if (!codeText) { return; }
		var done = function () {
			if (!btn) { return; }
			var old = btn.textContent;
			btn.textContent = '已复制';
			if (window.jycToast) { window.jycToast('已复制到剪贴板'); }
			setTimeout(function () { btn.textContent = old; }, 1400);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(codeText.value).then(done, function () {
				codeText.select();
				try { document.execCommand('copy'); } catch (e) {}
				done();
			});
		} else {
			codeText.select();
			try { document.execCommand('copy'); } catch (e) {}
			done();
		}
	};
	if (codeViewer) { edgeCodeRender(codeViewer.getAttribute('data-active') || 'nginx'); }

	/* 服务器类型下拉切换时，同步把片段查看器切到对应标签 */
	var edgeServerSel = document.querySelector('select[name="page_cache_edge_server"]');
	if (edgeServerSel) {
		edgeServerSel.addEventListener('change', function () {
			var v = edgeServerSel.value;
			if ('nginx' === v || 'apache' === v) { edgeCodeRender(v); }
		});
	}

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
	var featureGroups = [
		{
			key: 'seo',
			label: 'SEO / 社交',
			icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M11 8v6M8 11h6"/></svg>',
			items: [
				['seo_open', 'SEO / OG', 'seo'],
				['twitter_card_enable', 'Twitter 卡片', 'seo'],
				['llms_enable', 'llms.txt', 'seo'],
				['no_category_enable', '去除 /category/', 'seo'],
				['ld_json_enable', 'JSON-LD', 'seo'],
				['img_alt_enable', '图片 alt 补全', 'seo']
			]
		},
		{
			key: 'content',
			label: '内容 / 推送',
			icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
			items: [
				['auto_link_enable', '自动内链', 'content'],
				['indexnow_enable', 'IndexNow', 'content']
			]
		},
		{
			key: 'perf',
			label: '性能加速',
			icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
			items: [
				['speculation_enable', 'Speculation 预取', 'perf'],
				['page_cache_enable', '整页缓存', 'perf']
			]
		},
		{
			key: 'extra',
			label: '评论 / 媒体 / 存储',
			icon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
			items: [
				['close_comments_old', '旧文关评', 'comment'],
				['img_wm_enable', '图片水印', 'media'],
				['storage_auto_upload', '附件自动上云', 'storage'],
				['storage_delete_local', '推送后删本地', 'storage']
			]
		}
	];

		function computeOverview() {
		var checks = document.getElementById('jyc-pillwall');
		var grpRow = document.getElementById('jyc-grpRow');
		if (checks) { checks.innerHTML = ''; }
		if (grpRow) { grpRow.innerHTML = ''; }

		var total = 0, on = 0;
		var arrowSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>';
		featureGroups.forEach(function (g) {
			var gOn = 0;
			var pills = [];
			g.items.forEach(function (it) {
				var el = document.querySelector('input[name="' + it[0] + '"]');
				var isOn = !!(el && el.checked);
				if (isOn) { gOn++; on++; }
				total++;
				var pill = document.createElement('span');
				pill.className = 'jyc-stat-pill ' + (isOn ? 'on' : 'off');
				pill.setAttribute('data-goto', it[2]);
				pill.setAttribute('role', 'button');
				pill.setAttribute('tabindex', '0');
				pill.title = '点击跳转至“' + it[1] + '”设置';
				pill.innerHTML = '<span class="dot"></span>';
				pill.appendChild(document.createTextNode(it[1]));
				var ar = document.createElement('span');
				ar.className = 'arrow';
				ar.innerHTML = arrowSvg;
				ar.setAttribute('aria-hidden', 'true');
				pill.appendChild(ar);
				pills.push(pill);
			});
			if (grpRow) {
				var pctG = g.items.length ? Math.round(gOn / g.items.length * 100) : 0;
				var grp = document.createElement('div');
				grp.className = 'jyc-grp';
				grp.innerHTML = '<div class="jyc-grp-top"><span class="jyc-grp-name">' + g.label
					+ '</span><span class="jyc-grp-cnt">' + gOn + '/' + g.items.length + '</span></div>'
					+ '<div class="jyc-grp-bar"><div class="jyc-grp-fill" style="width:' + pctG + '%"></div></div>';
				grpRow.appendChild(grp);
			}
			pills.forEach(function (p) { if (checks) { checks.appendChild(p); } });
		});
		var pct = total ? Math.round(on / total * 100) : 0;
		var set = function (id, v) { var e = document.getElementById(id); if (e) { e.textContent = v; } };
		set('jyc-bentoPct', pct + '%');
		set('jyc-pc-on', on);
		set('jyc-pc-total', total);
		set('jyc-pct', on + '/' + total + ' · 启用率 ' + pct + '%');
		set('jyc-hsOn', on);
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

		/* 功能明细项点击跳转（药丸墙 + 原分组项） */
	(function () {
		var box = document.getElementById('jyc-pillwall');
		if (!box) { return; }
		box.addEventListener('click', function (e) {
			var item = e.target.closest('.jyc-stat-pill, .jyc-check');
			if (item) {
				var g = item.getAttribute('data-goto');
				if (g) { gotoPane(g); }
			}
		});
		box.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') { return; }
			var item = e.target.closest('.jyc-stat-pill, .jyc-check');
			if (item && item.getAttribute('data-goto')) {
				e.preventDefault();
				gotoPane(item.getAttribute('data-goto'));
			}
		});
	})();

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

	/* ---------------- AI 爬虫统计清零 ---------------- */
	window.jycAiCrawlReset = function (btn) {
		if (!window.confirm('确定清零 AI 爬虫到访统计？')) { return; }
		var form = document.getElementById('jyc-form');
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		var fd = new FormData(form);
		fd.append('action', 'jinyu_ai_crawl_reset');
		var oldTxt = btn.textContent;
		btn.disabled = true; btn.textContent = '清零中…';
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res && res.success) {
					jycToast(res.data && res.data.msg ? res.data.msg : '已清零');
					// 统计区与各品牌行内的「N 次到访」标签是两处独立 DOM，必须一起重置。
					// 旧实现只 closest('.jyc-crawlers') 去找容器，但那个容器在按钮所在
					// 行的上方且早已闭合，closest 恒为 null —— 服务端已清零，界面却纹丝不动，
					// 用户只会以为清零失败再点一次。
					var box = document.getElementById('jyc-aiCrawlStatBox');
					if (box) { box.innerHTML = jycAiEmptyHtml(); }
					Array.prototype.forEach.call(document.querySelectorAll('.jyc-aistat'), function (el) {
						el.textContent = '近期无到访';
						el.className = 'jyc-aistat jyc-aistat--none';
					});
				} else { jycToast(jycMsg(res, '清零失败，请重试')); }
			})
			.catch(function () { jycToast('请求失败，请重试'); })
			.then(function () { btn.disabled = false; btn.textContent = oldTxt; });
	};

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
				else { jycToast(jycMsg(res, '优化失败，请重试')); }
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
					jycToast(jycMsg(res, '扫描失败，请重试'));
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
					jycToast(jycMsg(res, '操作失败，请重试'));
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
					box.innerHTML = '<div class="jyc-tp-empty">' + esc(jycMsg(res, '请求失败，请重试')) + '</div>';
					jycToast(jycMsg(res, '请求失败，请重试'));
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
					jycToast(jycMsg(res, '扫描失败，请重试'));
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
					jycToast(jycMsg(res, '删除失败，请重试'));
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

	/**
	 * 从 AJAX 响应里取可展示的错误/提示文本。
	 *
	 * 为什么要这个：wp_send_json_error( $msg ) 与 wp_send_json_error( ['msg'=>$msg] )
	 * 两种形态在插件里都存在。前端若一律 String(res.data)，遇到数组形态就会显示
	 * 字面量 "[object Object]"——用户既看不到「权限不足」也看不到「磁盘不可写」，
	 * 只知道失败了。此处统一兼容：数组取 msg，字符串直接用，其余走兜底。
	 *
	 * @param {Object} res  fetch + json() 的结果
	 * @param {string} [fallback] 取不到内容时的兜底文案
	 * @return {string}
	 */
	function jycMsg(res, fallback) {
		var d = res && res.data;
		if (d && typeof d === 'object') { return String(d.msg || fallback || ''); }
		if (typeof d === 'string' && d) { return d; }
		return fallback || '';
	}
	window.jycMsg = jycMsg;

	/** AI 爬虫统计清零后的空态 HTML（与服务端 else 分支保持同一套样式与文案）。 */
	function jycAiEmptyHtml() {
		return '<div style="font-size:13px;color:var(--ink-3);margin-top:6px">暂无记录——AI 爬虫到访后，计数会出现在上方对应品牌行内。</div>';
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
					jycToast(jycMsg(res, '诊断失败，请重试'));
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
				else { jycToast(jycMsg(res, '刷新失败，请重试')); }
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
				} else { jycToast(jycMsg(res, '清空失败，请重试')); }
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
					jycToast(jycMsg(res, '推送失败，请重试'));
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
					jycToast(jycMsg(res, '体检失败，请重试'));
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
				else { jycToast(jycMsg(res, '发送失败，请检查配置')); }
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
				else { jycToast(jycMsg(res, '连接失败，请检查配置')); }
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
		// 二次确认：这是全站影响最大的一次点击。apply 会把所有文章的正文图片链接
		// 重写成 CDN 域名并清空整页缓存，unapply 再原路改回。点错一次的代价是
		// 「全站图片临时失效 + 批量重写/回滚」，且两个方向都没有确认太容易误触。
		var toCdn = (action === 'jinyu_storage_apply_domain');
		var tip = toCdn
			? '将把全站文章的附件链接重写为 CDN 域名，并清空整页缓存。继续？'
			: '将把全站文章的附件链接改回本地地址，并清空整页缓存。继续？';
		if (!window.confirm(tip)) { return; }
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
				} else { jycToast(jycMsg(res, '操作失败')); }
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
		if (jycBatch.poll) { clearTimeout(jycBatch.poll); jycBatch.poll = null; }
		jycBatchLock(false, jycBatch.type || 'push');
		jycBatch.type = null;
		fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				// 只有服务端确认收到停止指令才报成功。此前无论请求成败都弹「已停止」，
				// 服务器 500 或网络中断时用户以为停了，实际任务还在跑并继续改写文件——
				// 误报比不报危险得多。
				if (res && res.success) {
					if (e.msg) { e.msg.textContent = '已停止（进度保留，可重新开始）'; }
					jycToast('任务已停止，已处理的进度保留');
				} else {
					var why = jycMsg(res, '');
					if (e.msg) { e.msg.textContent = '停止请求失败：' + (why || '服务端未确认'); }
					jycToast('停止请求失败，任务可能仍在运行，请刷新页面确认');
				}
			})
			.catch(function () {
				if (e.msg) { e.msg.textContent = '停止请求失败（网络错误），任务可能仍在运行'; }
				jycToast('停止请求失败，任务可能仍在运行，请刷新页面确认');
			});
	};
	/**
	 * 启动存储批量任务。
	 *
	 * @param {string}  type    'push' | 'pull' | 'sync'
	 * @param {FormData} fd     已组装好的请求体
	 * @param {boolean} resumed true = 页面加载时的自动续跑，跳过确认（那是恢复中断的任务，
	 *                          不是用户的新决策；弹窗反而会让用户以为任务丢了要重���）
	 */
	function jycBatchStart(type, fd, resumed) {
		if (jycBatch.running) { return; }
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		// 破坏性确认：push 会搬整个媒体库；pull 会用云端版本覆盖本地同名文件——
		// 用户本地新上传但未备份的图会被旧版盖掉，且没有任何提示。两个方向都要确认。
		if (!resumed) {
			var tips = {
				push: '将把媒体库文件批量上传到对象存储。数据量大时可能持续较长时间，继续？',
				pull: '将从对象存储拉回文件并**覆盖本地同名文件**。本地未备份的新图会被云端旧版替换，继续？',
				sync: '将按填写的路径同步到对象存储。继续？'
			};
			if (!window.confirm(tips[type] || '确认执行该批量任务？')) { return; }
		}
		jycBatch.running = true;
		jycBatch.type = type;
		jycBatchLock(true, type);
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		// 进度轮询失败计数：连续失败 3 次即中止并提示，不再无限静默轮询。
		// 静默 .catch 的后果是进度条永久停在最后一次的百分比（会话过期时 nonce 失效，
		// 每次都返回 HTML 而非 JSON），用户以为卡死，只能手动关页。
		var pollFail = 0;
		(function poll() {
			if (!jycBatch.running) { return; }
			var sfd = new FormData(form);
			sfd.append('action', 'jinyu_storage_status');
			fetch(aurl, { method: 'POST', body: sfd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!jycBatch.running) { return; }
					if (res && res.success) {
						pollFail = 0;
						if (res.data && res.data.active) { jycBatchRender(res.data); }
					} else {
						pollFail++;
						if (pollFail >= 3) {
							jycBatch.running = false;
							jycToast('进度刷新连续失败：' + jycMsg(res, '会话可能已过期') + '，请刷新页面');
							return;
						}
					}
				})
				.catch(function () {
					if (!jycBatch.running) { return; }
					pollFail++;
					if (pollFail >= 3) {
						jycBatch.running = false;
						jycToast('进度刷新连续失败（网络错误），请刷新页面确认任务状态');
						return;
					}
				})
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
						jycToast(jycMsg(res, '任务失败'));
						if (jycBatchEls().msg) { jycBatchEls().msg.textContent = jycMsg(res, '任务失败'); }
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
				jycBatchStart(type, ffd, true);   // 续跑：不弹确认框
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

	/* 配置备份：拖放上传区。点击/拖入都落到同一个隐藏 file input；
	   选了文件切 multipart，纯粘贴文本保持默认编码。 */
	var form = document.getElementById('jyc-form');
	var ioFile = document.getElementById('jyc-import-file');
	var ioDrop = document.getElementById('jyc-drop');
	var ioBox = document.getElementById('jyc-drop-file');
	var ioName = document.getElementById('jyc-drop-name');
	var ioSize = document.getElementById('jyc-drop-size');
	var ioClear = document.getElementById('jyc-drop-clear');

	function ioHumanSize(bytes) {
		if (bytes < 1024) { return bytes + ' B'; }
		if (bytes < 1048576) { return (bytes / 1024).toFixed(1) + ' KB'; }
		return (bytes / 1048576).toFixed(2) + ' MB';
	}

	function ioApplyFile(file) {
		if (!file) { return; }
		if (ioName) { ioName.textContent = file.name; }
		if (ioSize) { ioSize.textContent = ioHumanSize(file.size); }
		if (ioBox) { ioBox.hidden = false; }
		if (ioDrop) { ioDrop.hidden = true; }
		if (form) { form.setAttribute('enctype', 'multipart/form-data'); }
	}

	function ioResetFile() {
		if (ioFile) { ioFile.value = ''; }
		if (ioBox) { ioBox.hidden = true; }
		if (ioDrop) { ioDrop.hidden = false; }
		if (form) { form.setAttribute('enctype', 'application/x-www-form-urlencoded'); }
	}

	if (form && ioFile) {
		ioFile.addEventListener('change', function () {
			if (this.value) { ioApplyFile(this.files && this.files[0]); } else { ioResetFile(); }
		});
	}
	if (ioDrop && ioFile) {
		ioDrop.addEventListener('click', function () { ioFile.click(); });
		ioDrop.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ioFile.click(); }
		});
		['dragenter', 'dragover'].forEach(function (ev) {
			ioDrop.addEventListener(ev, function (e) { e.preventDefault(); ioDrop.classList.add('is-drag'); });
		});
		['dragleave', 'dragend'].forEach(function (ev) {
			ioDrop.addEventListener(ev, function () { ioDrop.classList.remove('is-drag'); });
		});
		ioDrop.addEventListener('drop', function (e) {
			e.preventDefault();
			ioDrop.classList.remove('is-drag');
			var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
			if (!file) { return; }
			if (!/\.json$/i.test(file.name) && file.type !== 'application/json') {
				if (window.jycToast) { window.jycToast('仅支持 .json 备份文件'); }
				return;
			}
			try {
				var dt = new DataTransfer();
				dt.items.add(file);
				ioFile.files = dt.files;
			} catch (err) { /* 老内核无 DataTransfer：仅回显，提交时仍走原 input */ }
			ioApplyFile(file);
		});
	}
	if (ioClear) {
		ioClear.addEventListener('click', function (e) { e.stopPropagation(); ioResetFile(); });
	}

	/* 导出含凭据模式：勾选风险确认后，下载链接切到完整备份 URL 并换警示态 */
	var ioFullCk = document.getElementById('jyc-export-full');
	var ioExpBtn = document.getElementById('jyc-export-btn');
	if (ioFullCk && ioExpBtn && ioExpBtn.dataset.hrefSafe && ioExpBtn.dataset.hrefFull) {
		ioFullCk.addEventListener('change', function () {
			if (this.checked) {
				ioExpBtn.setAttribute('href', ioExpBtn.dataset.hrefFull);
				ioExpBtn.textContent = '下载完整备份（含凭据）';
				ioExpBtn.classList.add('jyc-export-full-mode');
				ioExpBtn.classList.remove('jyc-btn-soft');
			} else {
				ioExpBtn.setAttribute('href', ioExpBtn.dataset.hrefSafe);
				ioExpBtn.textContent = '下载 JSON 备份';
				ioExpBtn.classList.remove('jyc-export-full-mode');
				ioExpBtn.classList.add('jyc-btn-soft');
			}
		});
	}

	/* ================= 图片水印：实时预览 / 九宫格 / 批量任务 =================
	 * 预览在浏览器端按与引擎同源的参数绘制（字号=短边比例、边距、九宫格、不透明度），
	 * 保存前即可看到实际效果；批量走 start → step 轮询，进度存用户级 transient。 */
	var wmCanvas = document.getElementById('jyc-wmCanvas');
	var wmPosInput = document.getElementById('jyc-wmPos');
	var wmGrid = document.getElementById('jyc-wmGrid');
	var wmSizesBox = document.getElementById('jyc-wmSizes');
	var wmPane = document.getElementById('pane-media');

	function wmVal(name, def) {
		var el = document.querySelector('[name="' + name + '"]');
		if (!el || el.value === null || el.value === '') { return def; }
		return el.value;
	}
	function wmDraw() {
		if (!wmCanvas || !wmCanvas.getContext) { return; }
		var text = wmVal('img_wm_text', '');
		var want = parseInt(wmVal('img_wm_size', '18'), 10) || 18;
		/* 与引擎 normalize_hex() 同一套归一化：#abc / #aabbcc / 非法值→白。
		 * 预览必须和线上画出来一致，否则用户照着预览调完，保存后颜色又变样。 */
		var fillHex = (function () {
			var raw = String(wmVal('img_wm_color', '#ffffff') || '').trim().replace(/^#/, '').toLowerCase();
			if (raw.length === 3) { raw = raw[0] + raw[0] + raw[1] + raw[1] + raw[2] + raw[2]; }
			return /^[0-9a-f]{6}$/.test(raw) ? '#' + raw : '#ffffff';
		})();
		var margin = parseFloat(wmVal('img_wm_margin', '0.02')) || 0.02;
		var opacity = parseInt(wmVal('img_wm_opacity', '60'), 10) || 60;
		var pos = parseInt(wmVal('img_wm_pos', '9'), 10) || 9;
		var ctx = wmCanvas.getContext('2d');

		/* 位图分辨率跟随容器 CSS 宽度 × 设备像素比：
		 * 写死 1200×800 在高 DPI 屏上会发虚、在窄栏里又浪费像素。
		 * 上限 1600 兼顾「够清晰」与「别让一次重绘太久」。 */
		var cssW = wmCanvas.clientWidth || 720;
		var dpr = Math.min(window.devicePixelRatio || 1, 2);
		var bw = Math.round(Math.max(360, Math.min(cssW * dpr, 1600)));
		if (wmCanvas.width !== bw) {
			wmCanvas.width = bw;
			wmCanvas.height = Math.round(bw * 2 / 3); // 与 CSS aspect-ratio 3/2 保持一致
		}
		var iw = wmCanvas.width, ih = wmCanvas.height, short = Math.min(iw, ih);

		// 底图用模拟照片：浅 / 深底都有大色块，水印对比度一眼可辨
		var g = ctx.createLinearGradient(0, 0, iw, ih);
		g.addColorStop(0, '#dfe7f5'); g.addColorStop(0.55, '#9db1cf'); g.addColorStop(1, '#f4f7fc');
		ctx.fillStyle = g; ctx.fillRect(0, 0, iw, ih);
		ctx.fillStyle = 'rgba(255,255,255,0.5)';
		ctx.fillRect(Math.round(iw * 0.08), Math.round(ih * 0.1), Math.round(iw * 0.38), Math.round(ih * 0.45));
		ctx.fillStyle = 'rgba(28,96,243,0.16)';
		ctx.fillRect(Math.round(iw * 0.56), Math.round(ih * 0.46), Math.round(iw * 0.36), Math.round(ih * 0.36));

		/* 与引擎同源的自适应上限：Jinyu_Watermark::MAX_SHORT_RATIO。
		 * 面板填的是固定 px，预览必须如实反映「小图会被收缩」，否则预览和线上不一致。 */
		var cap = Math.floor(short * 0.18);
		var size = Math.max(8, Math.min(want, cap));
		var pad = Math.round(short * margin);
		ctx.font = '600 ' + size + 'px -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif';
		var tw = ctx.measureText(text).width || size * text.length;
		var th = Math.round(size * 1.35);
		var col = pos % 3, row = Math.floor((pos - 1) / 3);
		/* col = pos%3 ∈ {1,2,0} = 左/中/右（0 是右列：3/6/9 取模余 0）。
		 * 旧代码写成 3===col 判右列，恒假 → 右列三个全画在中间，与引擎 position() 不一致。 */
		var x = (1 === col) ? pad : (0 === col ? Math.max(0, iw - tw - pad) : Math.round((iw - tw) / 2));
		var y = (0 === row) ? pad : (2 === row ? Math.max(0, ih - th - pad) : Math.round((ih - th) / 2));
		ctx.globalAlpha = Math.max(0.1, Math.min(1, opacity / 100));
		ctx.lineJoin = 'round';
		ctx.lineWidth = Math.max(1, Math.round(size / 22));
		ctx.strokeStyle = 'rgba(0,0,0,0.55)';
		ctx.strokeText(text, x, y + size);
		ctx.fillStyle = fillHex;
		ctx.fillText(text, x, y + size);
		ctx.globalAlpha = 1;

		// 把「填的值」和「实际画的值」分开说明，用户才知道小图为什么变小了
		var hint = document.getElementById('jyc-wmSizeHint');
		if (hint) {
			hint.textContent = size < want
				? '实际绘制 ' + size + 'px（已按小图上限收缩，设定 ' + want + 'px）'
				: '实际绘制 ' + size + 'px';
		}
	}

	if (wmGrid && wmPosInput) {
		wmGrid.querySelectorAll('.jyc-wm-cell').forEach(function (cell) {
			cell.addEventListener('click', function () {
				wmGrid.querySelectorAll('.jyc-wm-cell').forEach(function (o) {
					o.classList.remove('jyc-on');
					o.setAttribute('aria-checked', 'false');
				});
				cell.classList.add('jyc-on');
				cell.setAttribute('aria-checked', 'true');
				wmPosInput.value = cell.getAttribute('data-pos');
				wmDraw();
			});
		});
		/* 放弃更改：discard 回填隐藏域后派发 change → 九宫格高亮与预览跟着回滚 */
		wmPosInput.addEventListener('change', function () {
			var v = String(parseInt(wmPosInput.value, 10) || 9);
			wmGrid.querySelectorAll('.jyc-wm-cell').forEach(function (o) {
				var on = o.getAttribute('data-pos') === v;
				o.classList.toggle('jyc-on', on);
				o.setAttribute('aria-checked', on ? 'true' : 'false');
			});
			wmDraw();
		});
	}
	if (wmPane) {
		wmPane.querySelectorAll('[name="img_wm_text"], [name="img_wm_size"], [name="img_wm_margin"], [name="img_wm_opacity"]').forEach(function (el) {
			el.addEventListener('input', wmDraw);
		});
	}
	/* 文字颜色：取色器 + hex 输入框双向同步。取色器 input/change 都绑（部分内核只在
	 * change 提交）；hex 框手输合法值即回填取色器并重绘预览，非法值失焦时回显当前值。 */
	(function () {
		var color = document.getElementById('jyc-wmColor');
		var hex = document.getElementById('jyc-wmColorHex');
		if (!color || !hex) { return; }
		function norm(v) {
			var raw = String(v || '').trim().replace(/^#/, '').toLowerCase();
			if (raw.length === 3) { raw = raw[0] + raw[0] + raw[1] + raw[1] + raw[2] + raw[2]; }
			return /^[0-9a-f]{6}$/.test(raw) ? '#' + raw : null;
		}
		function fromColor() { hex.value = color.value; wmDraw(); }
		color.addEventListener('input', fromColor);
		color.addEventListener('change', fromColor);
		hex.addEventListener('input', function () {
			var c = norm(hex.value);
			if (c) { color.value = c; wmDraw(); }
		});
		hex.addEventListener('change', function () {
			var c = norm(hex.value);
			if (c) { hex.value = c; } else { hex.value = color.value; }
			wmDraw();
		});
	})();
	// 尺寸勾选沿用面板统一的「圆点」视觉：隐藏 input 不动，只同步 .jyc-dot 状态
	if (wmSizesBox) {
		wmSizesBox.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
			cb.addEventListener('change', function () {
				var dot = cb.parentNode.querySelector('.jyc-dot');
				if (dot) { dot.classList.toggle('jyc-on', cb.checked); }
			});
		});
	}
	/* 视口变化 / 切回本分区时重绘：canvas 宽度变了，位图也要跟着重建。
	 * rAF 节流，避免 resize 拖动时连续重建位图。 */
	var wmRaf = 0;
	function wmSchedule() {
		if (wmRaf) { return; }
		wmRaf = window.requestAnimationFrame(function () { wmRaf = 0; wmDraw(); });
	}
	window.addEventListener('resize', wmSchedule);
	Array.prototype.forEach.call(document.querySelectorAll('#jyc-nav .jyc-nav-item[data-mod="media"]'), function (n) {
		n.addEventListener('click', function () { wmRaf = 0; wmDraw(); });
	});
	wmDraw();

	var wmTask = { running: false, mode: null, timer: null, retries: 0 };
	function wmEls() {
		return {
			prog: document.getElementById('jyc-wmProg'),
			fill: document.getElementById('jyc-wmFill'),
			msg: document.getElementById('jyc-wmMsg')
		};
	}
	/* 运行中把当前模式（加 / 去）的按钮就地变「停止任务」，其余禁用，避免两个任务并行。 */
	function wmLock(on, mode) {
		Array.prototype.forEach.call(document.querySelectorAll('.jyc-wm-btn'), function (b) {
			var mine = on && b.getAttribute('data-mode') === mode;
			b.disabled = on && !mine;
			b.textContent = mine ? '停止任务' : (b.getAttribute('data-label') || b.textContent);
		});
	}
	function wmRender(d, prefix) {
		var e = wmEls();
		if (e.prog) { e.prog.hidden = false; }
		if (e.fill && d.total) { e.fill.style.width = Math.min(100, Math.round(d.done / d.total * 100)) + '%'; }
		if (e.msg) { e.msg.textContent = (prefix ? prefix + '：' : '') + (d.message || (d.done + '/' + d.total)); }
	}
	function wmAbort() {
		if (wmTask.timer) { clearTimeout(wmTask.timer); wmTask.timer = null; }
		wmTask.running = false;
		wmLock(false, wmTask.mode);
		wmTask.mode = null;
	}
	function wmFetch(fd, onOk) {
		var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
		fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				wmTask.retries = 0;
				if (!wmTask.running) { return; }
				onOk(res);
			})
			.catch(function () {
				if (!wmTask.running) { return; }
				wmTask.retries++;
				// 单次失败不断链：待处理清单与进度都在 transient，重试同一动作即可续跑
				if (wmTask.retries >= 3) {
					jycToast('请求连续失败，任务已暂停；再点一次即可继续');
					wmAbort(); return;
				}
				wmTask.timer = setTimeout(function () { wmFetch(fd, onOk); }, 2000);
			});
	}
	function wmStep() {
		var fd = new FormData(document.getElementById('jyc-form'));
		fd.append('action', 'jinyu_companion_wm_step');
		wmFetch(fd, function (res) {
			if (!wmTask.running) { return; }
			if (!(res && res.success)) {
				jycToast(jycMsg(res, '任务失败'));
				wmAbort(); return;
			}
			var d = res.data || {};
			wmRender(d);
			if ('done' === d.status) {
				jycToast(d.errors > 0 ? (d.message || '完成，但有失败项') : (d.message || '任务完成'));
				wmAbort();
				window.jycWmStats(null);
				return;
			}
			wmTask.timer = setTimeout(wmStep, 600);
		});
	}
	/* 统一入口：空闲 = 启动任务；该任务运行中 = 停止。scope 为 ids 时读「自选附件」输入框。 */
	window.jycWmAction = function (btn) {
		if (wmTask.running) { wmAbort(); jycToast('任务已停止，未处理的图片下次可继续'); return; }
		var mode = btn.getAttribute('data-mode') || 'apply';
		var scope = btn.getAttribute('data-wm') || 'all';
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		// 破坏性确认：scope=all + mode=remove 会对整站媒体库批量剥离水印。
		// 用户想「停一下」时容易误点这一项（运行时按钮已变成「停止任务」，
		// 但停止之后按钮复原成破坏性文案，再点一次就是全库还原）。
		// 有 -jywmo 备份可回滚，但用户不知道，只能靠提示告知。
		if ('all' === scope && 'remove' === mode) {
			if (!window.confirm('将对**全部**已打水印的图片执行还原（逐张覆盖回原始文件）。继续？')) { return; }
		}
		var fd = new FormData(form);
		fd.append('action', 'jinyu_companion_wm_start');
		fd.set('mode', mode);
		fd.set('scope', scope);
		if ('ids' === scope) {
			var ta = document.getElementById('jyc-wmIds');
			var ids = (ta ? ta.value : '').split(/[\s,;\n]+/).filter(Boolean);
			if (!ids.length) { jycToast('请先填写附件 ID（逗号或空格分隔）'); if (ta) { ta.focus(); } return; }
			// 用 ids[] 重复字段提交数组形态。服务端同时兼容逗号分隔字符串，
			// 但数组是 HTML 表单的标准形态，不依赖服务端的兼容分支。
			fd.delete('ids');
			ids.forEach(function (id) { fd.append('ids[]', id); });
		}
		wmTask.running = true; wmTask.mode = mode; wmTask.retries = 0;
		wmLock(true, mode);
		var e = wmEls();
		if (e.prog) { e.prog.hidden = false; }
		if (e.msg) { e.msg.textContent = '正在准备任务…'; }
		wmFetch(fd, function (res) {
			if (!wmTask.running) { return; }
			if (!(res && res.success)) {
				jycToast(jycMsg(res, '任务失败'));
				wmAbort(); return;
			}
			if (!res.data.total) { jycToast('没有需要处理的新图片'); wmAbort(); return; }
			wmStep();
		});
	};
	window.jycWmStats = function (btn) {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_companion_wm_stats');
		var old = btn ? btn.textContent : '';
		if (btn) { btn.textContent = '统计中…'; }
		fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var txt = document.getElementById('jyc-wmStatTxt');
				// 统计请求失败时直接返回，不动 DOM。此前会把 '—' 写回去，
				// 覆盖掉原本正确的数字，用户以为水印被清空了。
				if (!(res && res.success && res.data)) { return; }
				var d = res.data;
				if (txt) {
					txt.textContent = '媒体库图片 ' + d.total + ' 张，已加水印 ' + d.done + ' 张';
				}
				var st = document.getElementById('jyc-wmState');
				if (st && d) {
					st.className = 'jyc-opstate ' + (d.enabled ? 'is-on' : 'is-off');
					st.textContent = d.editor ? (d.enabled ? '引擎可用 · 已启用' : '引擎可用 · 未启用') : '环境不支持图像处理';
				}
			})
			.catch(function () { jycToast('统计刷新失败'); })
			.then(function () { if (btn) { btn.textContent = old; } });
	};
	// 页面加载即检测未完成任务：有则自动续跑（关闭页面 / 切走再回来不丢进度）
	(function () {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }
		var fd = new FormData(form);
		fd.append('action', 'jinyu_companion_wm_status');
		fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var d = (res && res.success) ? res.data : null;
				if (!d || 'running' !== d.status) { return; }
				var mode = d.mode === 'remove' ? 'remove' : 'apply';
				wmTask.running = true; wmTask.mode = mode; wmTask.retries = 0;
				wmLock(true, mode);
				wmRender(d, '检测到未完成的' + (mode === 'remove' ? '还原' : '加水印') + '任务，已自动继续');
				wmStep();
			})
			.catch(function () {});
	})();

	/* ---------------- 悬浮保存（脏检测浮条） ----------------
	 * 与主题「有未保存的更改」同一套交互：改动即提示，保存走 admin-ajax 不刷新页面，
	 * 失败保留脏状态并提示，绝不静默吞掉。
	 * 收集口径直接取 FormData —— 与整表保存同源，不存在第二套序列化实现。
	 */
	(function () {
		var form = document.getElementById('jyc-form');
		if (!form) { return; }

		/* 控制域不参与脏比较：它们只决定「提交哪套动作 / 落到哪个分区 / 走哪条通道」，
		 * 改它们不代表用户改了配置，纳入比较会把「点了下导入确认框」误判成未保存。 */
		var SKIP = /^(jinyu_companion_nonce|jinyu_companion_save|jinyu_companion_ajax|jinyu_active_pane|jinyu_import|jinyu_import_file|jinyu_import_text|jinyu_import_confirm|action|jinyu_import_submit)$/;

		var bar = document.getElementById('jyc-dirtybar');
		var cntEl = document.getElementById('jyc-db-count');
		var saveBtn = document.getElementById('jyc-db-save');
		var discBtn = document.getElementById('jyc-db-discard');
		var saving = false, restoring = false, pending = 0;

		function collect() {
			var o = {}, fd = new FormData(form);
			fd.forEach(function (v, k) { if (!SKIP.test(k)) { o[k] = v; } });
			return o;
		}
		/* 差异计数：键缺失当作空串比较，未勾选的复选框在 FormData 里直接缺席，必须能比出来。
		 * 键集必须去重 —— 直接 concat 两份键名会让「仅在单边出现的新键」被计两次。 */
		function diffCount(a, b) {
			var seen = {}, n = 0;
			Object.keys(a).concat(Object.keys(b)).forEach(function (k) {
				if (seen[k]) { return; }
				seen[k] = 1;
				if ((a[k] || '') !== (b[k] || '')) { n++; }
			});
			return n;
		}

		var snap = JSON.stringify(collect());
		var saved = snap;

		/* 字段 → 分区索引：从 DOM 位置反查，避免手写「95 个字段 → 12 个分区」的映射表（必然漂移）。
		 * 必须遍历真实控件而非快照键 —— 初始未勾选的复选框在 FormData 里缺席，按快照建索引
		 * 会让这些开关首次勾选时分区角标永不点亮（浮条计数正常、导航不标色）。
		 * 保存成功后必须重建：角标基准必须始终跟随「上次保存」的快照，否则保存 / 放弃后
		 * 仍在与页面初始值比对，橙色高亮永不熄灭（实测踩坑）。 */
		var groups = {};
		function rebuildGroups() {
			var base = JSON.parse(saved);
			groups = {};
			form.querySelectorAll('input, select, textarea').forEach(function (el) {
				var k = el.getAttribute('name');
				if (!k || SKIP.test(k)) { return; }
				var pane = el.closest('.jyc-pane');
				if (!pane || !pane.id) { return; }
				if (!groups[pane.id]) { groups[pane.id] = {}; }
				groups[pane.id][k] = Object.prototype.hasOwnProperty.call(base, k) ? base[k] : '';
			});
		}
		rebuildGroups();

		function refresh() {
			if (restoring) { return; }
			var cur = collect(), n = diffCount(cur, JSON.parse(snap));
			if (n > 0) {
				bar.classList.add('jyc-db-on');
				bar.hidden = false;
				if (cntEl) { cntEl.textContent = n; cntEl.hidden = false; }
			} else {
				bar.classList.remove('jyc-db-on');
				if (cntEl) { cntEl.hidden = true; }
			}
			// 分区角标：逐分区只比该分区自己的字段，改 A 分区不会给 B 分区打点
			Object.keys(groups).forEach(function (pid) {
				var pane = document.getElementById(pid);
				var nav = pane && document.querySelector('.jyc-nav-item[data-mod="' + pid.replace(/^pane-/, '') + '"]');
				if (!nav) { return; }
				var sub = {};
				Object.keys(groups[pid]).forEach(function (k) { sub[k] = cur[k]; });
				nav.classList.toggle('is-modified', diffCount(sub, groups[pid]) > 0);
			});
		}

		function markDirty() {
			if (pending) { return; }
			pending = 1;
			requestAnimationFrame(function () { pending = 0; refresh(); });
		}

		// input/change 覆盖原生控件；click(capture) 兜底自定义控件（九宫格选位、颜色 chip 等）
		// 写隐藏 input 后不派发事件的情况，否则那些改动永远不会被识别为「脏」。
		['input', 'change', 'click'].forEach(function (t) {
			form.addEventListener(t, markDirty, true);
		});

		/* 放弃更改的回填基准：优先「上次保存」的状态 —— 初始状态在「改完又改回来且已保存过」
		 * 的场景下会误伤用户手动改回的值。上次保存里没有的键说明当时该控件就是空的
		 * （未勾选的复选框 / 未选中的单选项在 FormData 里直接缺席），按空值还原。
		 * 只在初始化时算一次，保存成功后跟着 saved 一起更新。 */
		var baseline = {};
		function rebuildBaseline() {
			var base = JSON.parse(saved);
			baseline = {};
			form.querySelectorAll('input, select, textarea').forEach(function (el) {
				var k = el.getAttribute('name');
				if (!k || SKIP.test(k)) { return; }
				baseline[k] = Object.prototype.hasOwnProperty.call(base, k) ? base[k] : '';
			});
		}
		rebuildBaseline();

		/* 放弃更改：把表单回填到基准状态，并让自定义控件的可见部分跟着回滚。 */
		function discard() {
			restoring = true;
			var prev = baseline, changed = [];
			form.querySelectorAll('input, select, textarea').forEach(function (el) {
				var k = el.getAttribute('name');
				if (!k || SKIP.test(k) || !Object.prototype.hasOwnProperty.call(prev, k)) { return; }
				var v = prev[k], before = el.type === 'checkbox' ? el.checked : el.value;
				if (el.type === 'checkbox') { el.checked = (el.value === v) || (v === 'on' && !el.value); }
				else if (el.type === 'radio') { el.checked = (el.value === v); }
				else { el.value = v; }
				var after = el.type === 'checkbox' ? el.checked : el.value;
				if (String(before) !== String(after)) { changed.push(el); }
			});
			// 让自定义控件的可见部分跟着回滚：对所有回填过的元素（含隐藏域）change + input 双派发 ——
			// change 供「值 → UI 同步」挂在隐藏域上的九宫格 / 验证码分段 / TTL 滑杆；
			// input 供只监听 input 的预览类（分享图 renderImage、预览卡标题、水印 canvas）。
			// 漏掉任何一类，放弃后视觉都会停在用户改过的位置（实测踩坑）。
			changed.forEach(function (el) {
				el.dispatchEvent(new Event('change', { bubbles: true }));
				el.dispatchEvent(new Event('input', { bubbles: true }));
			});
			restoring = false;
			markDirty();
			jycToast('已放弃未保存的更改');
		}

		function saveNow() {
			if (saving) { return; }
			saving = true;
			var fd = new FormData(form);
			// 锁定「本次请求真正提交的值」：响应期间的后续编辑不算已保存，否则会被
			// 静默标成干净、浮条熄灭，刷新后这些改动凭空丢失（保存竞态，实测踩坑）。
			var posted = JSON.stringify(collect());
			fd.append('action', 'jinyu_companion_save');
			// 专属标志位：区分「测试连接 / 优化数据库」等同表单提交的 admin-ajax 请求
			fd.append('jinyu_companion_ajax', '1');
			var busy = saveBtn || document.querySelector('.jyc-top-actions .jyc-btn-primary');
			var oldTxt = busy ? busy.textContent : '';
			if (busy) { busy.disabled = true; busy.textContent = busy.getAttribute('data-loading') || '保存中…'; }
			function idle() {
				saving = false;
				if (busy) { busy.disabled = false; busy.textContent = oldTxt; }
			}
			// 网络层失败（超时 / 500 拦截页）无法判定落库结果，退回整页提交这条永远可用的老路径，
			// 而不是把用户改动留在页面上却没写进库。
			function fallback(msg) {
				idle();
				jycToast(msg, 3200);
				form.submit();
			}
			fetch((typeof ajaxurl !== 'undefined') ? ajaxurl : '', { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.text(); })
				.then(function (txt) {
					var res = null;
					try { res = JSON.parse(txt); } catch (e) { res = null; }
					idle();
					if (!res) { fallback('保存失败，已回退为整页提交'); return; }
					if (!res.success) { jycToast(res.msg || '保存失败', 3200); return; }
					saved = posted;
					rebuildBaseline();
					rebuildGroups();
					snap = saved;
					markDirty();   // 保存期间又改过字段的话，这里会重新点亮浮条并保留角标
					jycToast(res.msg || '设置已保存', 2400);
				})
				.catch(function () { fallback('保存失败，已回退为整页提交'); });
		}

		if (bar) {
			if (saveBtn) { saveBtn.addEventListener('click', saveNow); }
			if (discBtn) { discBtn.addEventListener('click', discard); }
			// 未保存就离开：与 WP 核心一致地拦一次，避免整页提交刷新时丢失改动
			window.addEventListener('beforeunload', function (e) {
				if (bar.hidden || !bar.classList.contains('jyc-db-on')) { return; }
				e.preventDefault();
				e.returnValue = '';
				return '';
			});
		}

		// Ctrl/⌘+S：只在真的有改动时拦截，否则放给浏览器「保存网页」
		document.addEventListener('keydown', function (e) {
			if (!(e.ctrlKey || e.metaKey) || 's' !== String(e.key).toLowerCase()) { return; }
			if (!bar || bar.hidden || !bar.classList.contains('jyc-db-on')) { return; }
			e.preventDefault();
			saveNow();
		});

		// 顶栏保存按钮 / 无 JS 降级：拦截整页提交，改走 ajax。
		// 排除导入按钮（name=jinyu_import，整表单动作按钮），它必须走原生提交。
		form.addEventListener('submit', function (e) {
			var sub = e.submitter || e.target;
			if (sub && ('jinyu_import' === sub.getAttribute('name') || 'jyc-import-submit' === sub.id)) { return; }
			e.preventDefault();
			saveNow();
		});
	}());

	/* ---------------- 整页缓存状态卡：刷新 / 单 URL 查询 / 分组清除 ---------------- */
	(function () {
		var statBox = document.getElementById('jycCacheStat');
		if (!statBox) { return; }

		function jycCacheBytes(n) {
			n = +n; var u = ['B', 'KB', 'MB', 'GB']; var i = 0;
			while (n >= 1024 && i < u.length - 1) { n = n / 1024; i++; }
			return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
		}
		function jycCacheAgo(t) {
			t = +t; var d = Math.floor((Date.now() / 1000) - t);
			if (d < 60) { return '刚刚'; }
			if (d < 3600) { return Math.floor(d / 60) + ' 分钟前'; }
			if (d < 86400) { return Math.floor(d / 3600) + ' 小时前'; }
			return Math.floor(d / 86400) + ' 天前';
		}
		function jycCacheTtlLabel(s) {
			s = +s;
			if (s >= 86400) { return (s / 86400).toFixed(s % 86400 ? 1 : 0) + ' 天'; }
			if (s >= 3600) { return (s / 3600).toFixed(s % 3600 ? 1 : 0) + ' 小时'; }
			return Math.round(s / 60) + ' 分钟';
		}
		function jycCacheHumanSec(s) {
			s = +s;
			if (s < 60) { return s + ' 秒'; }
			if (s < 3600) { return Math.floor(s / 60) + ' 分钟'; }
			if (s < 86400) { return (s / 3600).toFixed(1) + ' 小时'; }
			return (s / 86400).toFixed(1) + ' 天';
		}
		function jycCacheSet(id, v) {
			var el = document.getElementById(id);
			if (el) { el.textContent = v; }
		}
		function jycCacheFill(s) {
			if (!s) { return; }
			// 边缘模式：缓存文件 / 占用空间由服务器持有、插件读不到，恒显示「—」；
			// 命中率则来自服务器统计日志（$upstream_cache_status），有值就显示真值。
			var edge = s.reason === 'edge';
			jycCacheSet('jycCsHit', (s.hit_rate === null || s.hit_rate === undefined) ? '—' : s.hit_rate + '%');
			jycCacheSet('jycCsFiles', edge ? '—' : (s.files || 0).toLocaleString());
			jycCacheSet('jycCsBytes', edge ? '—' : (s.bytes ? jycCacheBytes(s.bytes) : '0 B'));
			jycCacheSet('jycCsFlush', (s.last_flush > 0) ? jycCacheAgo(s.last_flush) : '从未');
			jycCacheSet('jycCsCustom', (s.custom_count || 0).toLocaleString());
			jycCacheSet('jycCsTtl', jycCacheTtlLabel(s.ttl_default || 3600));
			jycCacheFillSrc(s.hit_source);
		}
		// 命中率来源标签：server=服务器日志（边缘模式），plugin=插件自采（简单模式）。
		function jycCacheFillSrc(src) {
			var el = document.querySelector('#jycCacheStat .jyc-cs-tag');
			if (!el) { return; }
			if (src === 'server') { el.textContent = '服务器日志'; el.style.display = ''; }
			else if (src === 'plugin') { el.textContent = '插件自采'; el.style.display = ''; }
			else { el.style.display = 'none'; }
		}

		function jycCachePost(action, extra, btn, loadingTxt) {
			var form = document.getElementById('jyc-form');
			if (!form) { return Promise.reject(); }
			var fd = new FormData(form);
			fd.append('action', action);
			if (extra) {
				for (var k in extra) {
					if (Object.prototype.hasOwnProperty.call(extra, k)) { fd.append(k, extra[k]); }
				}
			}
			var aurl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '';
			var oldTxt = '', had = false;
			if (btn) { oldTxt = btn.textContent; btn.disabled = true; btn.textContent = loadingTxt || '处理中…'; had = true; }
			return fetch(aurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (had && btn) { btn.disabled = false; btn.textContent = oldTxt; }
					return res;
				})
				.catch(function () {
					if (had && btn) { btn.disabled = false; btn.textContent = oldTxt; }
					jycToast('请求失败，请重试');
					return null;
				});
		}

		function jycCacheClear(scope, url, btn, txt) {
			jycCachePost('jinyu_page_cache_clear', url ? { scope: scope, url: url } : { scope: scope }, btn, txt)
				.then(function (res) {
					if (res && res.success) {
						jycCacheFill(res.data && res.data.status ? res.data.status : null);
						jycToast('已清除缓存');
					} else { jycToast('清除失败，请重试'); }
				});
		}

		var refreshBtn = document.getElementById('jycCacheRefresh');
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function () {
				jycCachePost('jinyu_page_cache_stat', { force: 1 }, refreshBtn, '刷新中…')
					.then(function (res) {
						if (res && res.success) { jycCacheFill(res.data); jycToast('已刷新'); }
						else { jycToast('刷新失败'); }
					});
			});
		}

		// 重置命中统计：只把统计偏移推进到日志末尾，不影响缓存本身。
		var resetBtn = document.getElementById('jycCacheReset');
		if (resetBtn) {
			resetBtn.addEventListener('click', function () {
				if (!window.confirm('重置命中率统计？将从此刻之后的新请求重新累计（不影响缓存本身，也不会删除缓存文件）。')) { return; }
				jycCachePost('jinyu_page_cache_reset_stats', { force: 1 }, resetBtn, '重置中…')
					.then(function (res) {
						if (res && res.success) { jycCacheFill(res.data); jycToast('已重置统计'); }
						else { jycToast('重置失败，请重试'); }
					});
			});
		}

		var lookupBtn = document.getElementById('jycCacheLookup');
		var lookupInput = document.getElementById('jycCacheUrl');
		var luBox = document.getElementById('jycCacheLuResult');
		if (lookupBtn) {
			lookupBtn.addEventListener('click', function () {
				var url = lookupInput ? lookupInput.value.trim() : '';
				if (!url) { jycToast('请输入要查询的 URL'); return; }
				jycCachePost('jinyu_page_cache_lookup', { url: url }, lookupBtn, '查询中…')
					.then(function (res) {
						if (!res) { return; }
						if (!res.success) {
							luBox.hidden = false;
							luBox.innerHTML = '<span class="jyc-lu-warn">' + (jycMsg(res, '查询失败') || '查询失败') + '</span>';
							return;
						}
						var d = res.data;
						if (d && d.blind) {
							luBox.hidden = false;
							luBox.innerHTML = '<span class="jyc-lu-warn">边缘缓存（Apache）布局不透明，无法按 URL 查询，请改用「清全站」刷新</span>';
							return;
						}
						if (!d || !d.found) {
							luBox.hidden = false;
							luBox.innerHTML = '<span class="jyc-lu-warn">未缓存：该 URL 当前无缓存文件</span>';
							return;
						}
						var age = d.written ? jycCacheAgo(d.written) : '—';
						var remain = d.remaining > 0 ? jycCacheHumanSec(d.remaining) : '0';
						var cls = d.expired ? 'jyc-lu-expired' : 'jyc-lu-ok';
						var tag = (d.expired ? '已过期' : '有效') + (d.mode === 'edge' ? '（边缘缓存）' : '');
						luBox.hidden = false;
						luBox.innerHTML = '<span class="' + cls + '">' + tag + '</span> · 写入 ' + age +
							' · 时长 ' + jycCacheTtlLabel(d.ttl) + ' · 剩余 ' + remain;
					});
			});
		}

		var clearHome = document.getElementById('jycCacheClearHome');
		var clearAll = document.getElementById('jycCacheClearAll');
		var clearUrlBtn = document.getElementById('jycCacheClearUrlBtn');
		var clearUrlInput = document.getElementById('jycCacheClearUrl');
		if (clearHome) { clearHome.addEventListener('click', function () { jycCacheClear('home', '', this, '清首页中…'); }); }
		if (clearAll) {
			clearAll.addEventListener('click', function () {
				if (window.confirm('确定清全站缓存？清空后访客再次访问会重新生成缓存（瞬时略慢）。')) {
					jycCacheClear('all', '', this, '清全站中…');
				}
			});
		}
		if (clearUrlBtn) {
			clearUrlBtn.addEventListener('click', function () {
				var u = clearUrlInput ? clearUrlInput.value.trim() : '';
				if (!u) { jycToast('请输入要清除的 URL'); return; }
				jycCacheClear('url', u, this, '清除中…');
			});
		}
	}());

})();
