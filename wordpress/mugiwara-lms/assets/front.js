/* Mugiwara LMS : tableau de bord (recherche, filtres) et lecteur de formation. */
(function () {
	'use strict';

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

	/* ---------------- Tableau de bord ---------------- */

	function initDashboard() {
		var grid = $('[data-mla-grid]');
		if (!grid) { return; }
		var search = $('[data-mla-search]');
		var sortSel = $('[data-mla-sort]');
		var empty = $('[data-mla-noresult]');
		var pager = $('[data-mla-pages]');
		var countLabel = $('[data-mla-count-label]');
		var cards = $$('.mla-card', grid);
		var PER_PAGE = 9;
		var state = { filter: 'all', page: 1, sort: 'recent' };

		// Le tri choisi est mémorisé dans ce navigateur.
		try { state.sort = localStorage.getItem('mlaSort') || 'recent'; } catch (e) {}
		if (sortSel) { sortSel.value = state.sort; }

		var num = function (card, key) { return parseInt(card.dataset[key], 10) || 0; };
		var sorters = {
			recent: function (a, b) { return num(b, 'seen') - num(a, 'seen') || num(b, 'enrolled') - num(a, 'enrolled'); },
			progress: function (a, b) { return num(b, 'progress') - num(a, 'progress'); },
			title: function (a, b) { return a.dataset.title.localeCompare(b.dataset.title, 'fr'); },
			enrolled: function (a, b) { return num(b, 'enrolled') - num(a, 'enrolled'); }
		};

		function renderPager(pages) {
			if (!pager) { return; }
			pager.hidden = pages < 2;
			pager.innerHTML = '';
			if (pages < 2) { return; }
			function btn(label, page, opts) {
				var b = document.createElement('button');
				b.type = 'button';
				b.textContent = label;
				b.disabled = !!(opts && opts.disabled);
				if (opts && opts.current) { b.className = 'is-active'; b.setAttribute('aria-current', 'page'); }
				if (opts && opts.label) { b.setAttribute('aria-label', opts.label); }
				b.addEventListener('click', function () {
					state.page = page;
					apply();
					grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
				});
				pager.appendChild(b);
			}
			btn('←', state.page - 1, { disabled: state.page === 1, label: 'Page précédente' });
			for (var i = 1; i <= pages; i++) { btn(String(i), i, { current: i === state.page }); }
			btn('→', state.page + 1, { disabled: state.page === pages, label: 'Page suivante' });
		}

		function apply() {
			var q = norm(search ? search.value : '');
			var visible = cards.filter(function (card) {
				return (!q || card.dataset.title.indexOf(q) !== -1) && (state.filter === 'all' || card.dataset.state === state.filter);
			});
			visible.sort(sorters[state.sort] || sorters.recent);
			var pages = Math.max(1, Math.ceil(visible.length / PER_PAGE));
			state.page = Math.min(state.page, pages);
			var from = (state.page - 1) * PER_PAGE;
			var onPage = visible.slice(from, from + PER_PAGE);

			cards.forEach(function (card) { card.hidden = onPage.indexOf(card) === -1; });
			onPage.forEach(function (card) { grid.appendChild(card); });

			if (empty) { empty.hidden = visible.length > 0; }
			if (countLabel) {
				countLabel.textContent = visible.length + ' formation' + (visible.length > 1 ? 's' : '') +
					(pages > 1 ? ' · page ' + state.page + '/' + pages : '');
			}
			renderPager(pages);
		}

		// Affichage grille ou liste, mémorisé dans ce navigateur.
		function setView(view) {
			grid.classList.toggle('mla-grid--list', view === 'list');
			$$('[data-mla-view]').forEach(function (b) {
				b.classList.toggle('is-active', b.dataset.mlaView === view);
				b.setAttribute('aria-pressed', b.dataset.mlaView === view ? 'true' : 'false');
			});
		}
		var view = 'grid';
		try { view = localStorage.getItem('mlaView') || 'grid'; } catch (e) {}
		setView(view);
		$$('[data-mla-view]').forEach(function (b) {
			b.addEventListener('click', function () {
				setView(b.dataset.mlaView);
				try { localStorage.setItem('mlaView', b.dataset.mlaView); } catch (e) {}
			});
		});

		if (search) { search.addEventListener('input', function () { state.page = 1; apply(); }); }
		if (sortSel) {
			sortSel.addEventListener('change', function () {
				state.sort = sortSel.value;
				state.page = 1;
				try { localStorage.setItem('mlaSort', state.sort); } catch (e) {}
				apply();
			});
		}
		$$('[data-mla-filter]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				state.filter = btn.dataset.mlaFilter;
				state.page = 1;
				$$('[data-mla-filter]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
				apply();
			});
		});
		apply();
	}

	/* ---------------- Lecteur ---------------- */

	function initPlayer() {
		var root = $('[data-mla-player]');
		if (!root) { return; }
		var cfg;
		try { cfg = JSON.parse(root.dataset.mlaPlayer); } catch (e) { return; }

		var lessons = cfg.lessons;
		var byId = {};
		lessons.forEach(function (l, i) { l.index = i; byId[l.id] = l; });
		var done = {};
		cfg.done.forEach(function (id) { done[id] = true; });
		var current = byId[cfg.current] || lessons[0];

		var el = {
			viewer: $('[data-mla-viewer]', root),
			title: $('[data-mla-title]', root),
			module: $('[data-mla-module]', root),
			duration: $('[data-mla-duration]', root),
			description: $('[data-mla-description]', root),
			download: $('[data-mla-download]', root),
			doneBtn: $('[data-mla-done]', root),
			prev: $('[data-mla-prev]', root),
			next: $('[data-mla-next]', root),
			bar: $('[data-mla-bar]', root),
			count: $('[data-mla-count]', root),
			outline: $('[data-mla-outline]', root)
		};

		function lessonUrl(l) {
			var u = new URL(cfg.baseUrl, window.location.href);
			u.searchParams.set('lecon', l.id);
			return u.toString();
		}

		function save(lesson, payload) {
			var body = Object.assign({ course: cfg.course, lesson: lesson.id }, payload || {});
			return fetch(cfg.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(body)
			}).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
		}

		function renderViewer(l) {
			if (!el.viewer) { return; }
			el.viewer.className = 'mla-viewer mla-viewer--' + l.type;
			el.viewer.innerHTML = '';
			if (l.embed) {
				var f = document.createElement('iframe');
				f.src = l.embed;
				f.title = l.title;
				f.allow = 'autoplay; fullscreen; encrypted-media';
				f.setAttribute('allowfullscreen', '');
				el.viewer.appendChild(f);
			} else {
				var box = document.createElement('div');
				box.className = 'mla-viewer__fallback';
				var p = document.createElement('p');
				p.textContent = 'Ce contenu s’ouvre dans un nouvel onglet.';
				var a = document.createElement('a');
				a.className = 'mla-btn';
				a.href = l.open || l.download;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = 'Ouvrir le contenu';
				box.appendChild(p);
				box.appendChild(a);
				el.viewer.appendChild(box);
			}
		}

		function renderProgress() {
			var total = lessons.length;
			var count = lessons.filter(function (l) { return done[l.id]; }).length;
			var pct = total ? Math.floor(100 * count / total) : 0;
			if (el.bar) { el.bar.style.width = pct + '%'; }
			if (el.count) { el.count.textContent = count + '/' + total + ' leçons · ' + pct + '%'; }
			var perModule = {};
			$$('[data-mla-lesson]', root).forEach(function (a) {
				var m = a.dataset.mlaInModule;
				perModule[m] = perModule[m] || [0, 0];
				perModule[m][1]++;
				if (done[a.dataset.mlaLesson]) { perModule[m][0]++; }
				a.classList.toggle('is-done', !!done[a.dataset.mlaLesson]);
			});
			Object.keys(perModule).forEach(function (m) {
				var c = $('[data-mla-module-count="' + m + '"]', root);
				if (c) { c.textContent = perModule[m][0] + '/' + perModule[m][1]; }
			});
		}

		function renderDoneBtn() {
			if (!el.doneBtn) { return; }
			var isDone = !!done[current.id];
			el.doneBtn.classList.toggle('is-done', isDone);
			el.doneBtn.textContent = isDone ? '✓ Terminée' : 'Marquer comme terminée';
		}

		function setPager(a, l) {
			if (!a) { return; }
			a.hidden = !l;
			if (l) { a.href = lessonUrl(l); a.dataset.target = l.id; }
		}

		function show(l, push) {
			current = l;
			renderViewer(l);
			if (el.title) { el.title.textContent = l.title; }
			if (el.module) { el.module.textContent = l.module; }
			if (el.duration) { el.duration.textContent = l.duration ? '⏱ ' + l.duration : ''; }
			if (el.description) {
				var hasText = l.description && l.description.replace(/<[^>]*>/g, '').trim();
				el.description.innerHTML = hasText ? l.description : '<p class="mla-muted">Aucune note pour cette leçon.</p>';
			}
			if (el.download) {
				el.download.hidden = !l.download;
				el.download.href = l.download || '#';
			}
			setPager(el.prev, lessons[l.index - 1]);
			setPager(el.next, lessons[l.index + 1]);
			renderDoneBtn();

			$$('[data-mla-lesson]', root).forEach(function (a) {
				var on = a.dataset.mlaLesson === l.id;
				a.classList.toggle('is-current', on);
				if (on) {
					var d = a.closest('details');
					if (d) { d.open = true; }
				}
			});
			if (push) {
				history.pushState({ lesson: l.id }, '', lessonUrl(l));
				var stage = $('.mla-stage', root);
				if (stage && stage.getBoundingClientRect().top < 0) { stage.scrollIntoView({ behavior: 'smooth' }); }
			}
			document.body.classList.remove('mla-outline-open');
			save(l, { viewed: true });
		}

		root.addEventListener('click', function (e) {
			var link = e.target.closest('[data-mla-lesson], [data-mla-prev], [data-mla-next]');
			if (link && !e.metaKey && !e.ctrlKey) {
				var id = link.dataset.mlaLesson || link.dataset.target || new URL(link.href).searchParams.get('lecon');
				if (byId[id]) {
					e.preventDefault();
					show(byId[id], true);
				}
				return;
			}
			var toggle = e.target.closest('[data-mla-outline-toggle]');
			if (toggle) {
				var open = document.body.classList.toggle('mla-outline-open');
				$$('[data-mla-outline-toggle]', root).forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
				return;
			}
			var tab = e.target.closest('[data-mla-tab]');
			if (tab) {
				$$('[data-mla-tab]', root).forEach(function (t) { t.classList.toggle('is-active', t === tab); });
				$$('[data-mla-panel]', root).forEach(function (p) {
					var on = p.dataset.mlaPanel === tab.dataset.mlaTab;
					p.hidden = !on;
					p.classList.toggle('is-active', on);
				});
			}
		});

		if (el.doneBtn) {
			el.doneBtn.addEventListener('click', function () {
				var lesson = current;
				var value = !done[lesson.id];
				if (value) { done[lesson.id] = true; } else { delete done[lesson.id]; }
				renderDoneBtn();
				renderProgress();
				save(lesson, { done: value });
				// Leçon terminée : on enchaîne sur la suivante après un court délai.
				var nextLesson = lessons[lesson.index + 1];
				if (value && nextLesson) {
					setTimeout(function () { if (current === lesson) { show(nextLesson, true); } }, 700);
				}
			});
		}

		window.addEventListener('popstate', function () {
			var id = new URL(window.location.href).searchParams.get('lecon');
			if (byId[id]) { show(byId[id], false); }
		});

		document.addEventListener('keydown', function (e) {
			if (!current || e.altKey || e.ctrlKey || e.metaKey || e.target.closest('input, textarea, select, [contenteditable]')) { return; }
			if (e.key === 'ArrowRight' && lessons[current.index + 1]) { show(lessons[current.index + 1], true); }
			if (e.key === 'ArrowLeft' && lessons[current.index - 1]) { show(lessons[current.index - 1], true); }
		});

		if (current) {
			history.replaceState({ lesson: current.id }, '', lessonUrl(current));
			save(current, { viewed: true });
		}
	}

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
	}
	ready(function () { initDashboard(); initPlayer(); });
})();
