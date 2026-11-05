/* Mugiwara LMS : tableau de bord (recherche, filtres) et lecteur de formation. */
(function () {
	'use strict';

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	// Icônes SVG (mêmes tracés que MLA_Icons côté PHP).
	var ICON = {
		check: '<path d="M20 6 9 17l-5-5"/>',
		clock: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		left: '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
		right: '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>'
	};
	function icon(name, size) {
		return '<svg class="mla-icon" width="' + (size || 16) + '" height="' + (size || 16) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[name] + '</svg>';
	}
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
				if (label === 'prev' || label === 'next') { b.innerHTML = icon(label === 'prev' ? 'left' : 'right', 16); } else { b.textContent = label; }
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
			btn('prev', state.page - 1, { disabled: state.page === 1, label: 'Page précédente' });
			for (var i = 1; i <= pages; i++) { btn(String(i), i, { current: i === state.page }); }
			btn('next', state.page + 1, { disabled: state.page === pages, label: 'Page suivante' });
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
			viewerDl: $('[data-mla-viewer-dl]', root),
			wrap: $('[data-mla-viewer-wrap]', root),
			bar: $('[data-mla-viewer-bar]', root),
			fsClose: $('[data-mla-fs-close]', root),
			fullscreen: $('[data-mla-fullscreen]', root),
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

		function frame(src, title) {
			var f = document.createElement('iframe');
			f.src = src;
			f.title = title;
			f.allow = 'autoplay; fullscreen; encrypted-media';
			f.setAttribute('allowfullscreen', '');
			return f;
		}

		// Vidéo Drive illisible par le lecteur natif (fichier non partagé, quota Google…) : retour au lecteur Drive.
		function watchVideo(video) {
			if (!video) { return; }
			var fallback = function () {
				var src = video.dataset.mlaFallback;
				if (!src || !video.parentNode) { return; }
				var holder = video.parentNode;
				holder.replaceChild(frame(src, video.title || ''), video);
				holder.classList.add('is-drive');
				fitViewer();
			};
			video.addEventListener('error', fallback);
			// L'erreur a pu survenir avant le chargement de ce script (vidéo affichée par le serveur).
			if (video.error) { fallback(); }
		}

		function renderViewer(l) {
			if (!el.viewer) { return; }
			var isDrive = !l.stream && !!l.embed && l.embed.indexOf('drive.google.com/file/') !== -1;
			el.viewer.className = 'mla-viewer mla-viewer--' + l.type + (isDrive ? ' is-drive' : '');
			el.viewer.innerHTML = '';
			if (l.stream) {
				var v = document.createElement('video');
				v.className = 'mla-video';
				v.src = l.stream;
				if (l.poster) { v.poster = l.poster; }
				v.controls = true;
				v.preload = 'metadata';
				v.setAttribute('playsinline', '');
				v.setAttribute('controlslist', 'nodownload');
				v.title = l.title;
				v.dataset.mlaFallback = l.embed || '';
				el.viewer.appendChild(v);
				watchVideo(v);
			} else if (l.embed) {
				el.viewer.appendChild(frame(l.embed, l.title));
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

		// Sur mobile, le lecteur Google Drive ajoute une barre d'environ 48 px au-dessus de la vidéo :
		// on agrandit le cadre d'autant pour que la vidéo et ses commandes restent entières.
		function fitViewer() {
			if (!el.viewer) { return; }
			var drive = el.viewer.classList.contains('is-drive') && el.viewer.classList.contains('mla-viewer--video');
			var inFs = document.fullscreenElement || document.webkitFullscreenElement;
			if (drive && !inFs && window.matchMedia('(max-width: 768px)').matches) {
				el.viewer.style.aspectRatio = 'auto';
				el.viewer.style.height = Math.round(el.viewer.clientWidth * 9 / 16 + 48) + 'px';
			} else {
				el.viewer.style.aspectRatio = '';
				el.viewer.style.height = '';
			}
		}
		window.addEventListener('resize', fitViewer);
		window.addEventListener('orientationchange', function () { setTimeout(fitViewer, 250); });

		function pseudoFullscreen(on) {
			el.wrap.classList.toggle('is-pseudo-fs', on);
			document.documentElement.classList.toggle('mla-noscroll', on);
			if (el.fsClose) { el.fsClose.hidden = !on; }
			fitViewer();
		}

		if (el.fullscreen && el.wrap) {
			el.fullscreen.addEventListener('click', function () {
				var video = el.viewer && el.viewer.querySelector('video');
				var target = video || el.wrap;
				var request = target.requestFullscreen || target.webkitRequestFullscreen;
				if (request) {
					var p = request.call(target);
					var lock = function () {
						if (screen.orientation && screen.orientation.lock && current && current.type === 'video') {
							screen.orientation.lock('landscape').catch(function () {});
						}
					};
					if (p && p.then) { p.then(lock).catch(function () { pseudoFullscreen(true); }); } else { lock(); }
					return;
				}
				// iPhone : plein écran natif de la vidéo, ou à défaut le lecteur occupe tout l'écran.
				if (video && video.webkitEnterFullscreen) {
					try { video.webkitEnterFullscreen(); return; } catch (e) {}
				}
				pseudoFullscreen(true);
			});
			if (el.fsClose) { el.fsClose.addEventListener('click', function () { pseudoFullscreen(false); }); }
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && el.wrap.classList.contains('is-pseudo-fs')) { pseudoFullscreen(false); }
			});
			var onFs = function () {
				var on = !!(document.fullscreenElement || document.webkitFullscreenElement);
				el.wrap.classList.toggle('is-fullscreen', on);
				if (!on && screen.orientation && screen.orientation.unlock) { try { screen.orientation.unlock(); } catch (e) {} }
				fitViewer();
			};
			document.addEventListener('fullscreenchange', onFs);
			document.addEventListener('webkitfullscreenchange', onFs);
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
			el.doneBtn.innerHTML = isDone ? icon('check', 16) + ' Terminée' : 'Marquer comme terminée';
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
			if (el.duration) {
				el.duration.innerHTML = '';
				if (l.duration) {
					el.duration.innerHTML = icon('clock', 14) + ' ';
					el.duration.appendChild(document.createTextNode(l.duration));
				}
			}
			if (el.description) {
				var hasText = l.description && l.description.replace(/<[^>]*>/g, '').trim();
				el.description.innerHTML = hasText ? l.description : '<p class="mla-muted">Aucune note pour cette leçon.</p>';
			}
			// Vidéo : petite icône sur le lecteur (au survol, sur ordinateur) ; autres contenus : bouton sous le lecteur.
			var isVideo = l.type === 'video';
			if (el.download) {
				el.download.hidden = !l.download || isVideo;
				el.download.href = l.download || '#';
			}
			if (el.viewerDl) {
				el.viewerDl.hidden = !l.download || !isVideo;
				el.viewerDl.href = l.download || '#';
			}
			if (el.bar) { el.bar.hidden = !l.embed && !l.stream; }
			if (el.wrap && el.wrap.classList.contains('is-pseudo-fs') && !l.embed && !l.stream) { pseudoFullscreen(false); }
			fitViewer();
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

		watchVideo(el.viewer && el.viewer.querySelector('video'));
		fitViewer();
		if (current) {
			history.replaceState({ lesson: current.id }, '', lessonUrl(current));
			save(current, { viewed: true });
		}
	}

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); }
	}
	/* ---------------- Page Ressources ---------------- */

	function initResources() {
		var grid = $('[data-mla-res-grid]');
		if (!grid) { return; }
		var search = $('[data-mla-res-search]');
		var empty = $('[data-mla-res-noresult]');
		var filter = 'all';
		function apply() {
			var q = norm(search ? search.value : '');
			var shown = 0;
			$$('.mla-res-card', grid).forEach(function (card) {
				var ok = (!q || card.dataset.title.indexOf(q) !== -1) && (filter === 'all' || card.dataset.type === filter);
				card.hidden = !ok;
				if (ok) { shown++; }
			});
			if (empty) { empty.hidden = shown > 0; }
		}
		if (search) { search.addEventListener('input', apply); }
		$$('[data-mla-res-filter]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				filter = btn.dataset.mlaResFilter;
				$$('[data-mla-res-filter]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
				apply();
			});
		});
	}

	/* ---------------- Parrainage ---------------- */

	function copyText(text, btn) {
		var done = function () {
			var label = btn.querySelector('span');
			var before = label ? label.textContent : '';
			btn.classList.add('is-copied');
			if (label) { label.textContent = 'Copié !'; }
			setTimeout(function () { btn.classList.remove('is-copied'); if (label) { label.textContent = before; } }, 1600);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, function () { fallback(); });
		} else { fallback(); }
		function fallback() {
			var t = document.createElement('textarea');
			t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
			document.body.appendChild(t); t.select();
			try { document.execCommand('copy'); done(); } catch (e) {}
			document.body.removeChild(t);
		}
	}

	function initAffiliate() {
		var root = $('.mla-aff');
		if (!root) { return; }
		var tabs = $$('[data-mla-aff-tab]', root);
		function openTab(name) {
			if (!$('[data-mla-aff-panel="' + name + '"]', root)) { return; }
			tabs.forEach(function (t) { t.classList.toggle('is-active', t.dataset.mlaAffTab === name); });
			$$('[data-mla-aff-panel]', root).forEach(function (p) {
				var on = p.dataset.mlaAffPanel === name;
				p.hidden = !on; p.classList.toggle('is-active', on);
			});
		}
		tabs.forEach(function (t) {
			t.addEventListener('click', function () {
				openTab(t.dataset.mlaAffTab);
				history.replaceState(null, '', '#' + t.dataset.mlaAffTab);
			});
		});
		var wanted = (window.location.hash || '').replace('#', '') || new URL(window.location.href).searchParams.get('onglet');
		if (wanted) { openTab(wanted); }

		root.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-mla-copy-btn]');
			if (btn) {
				var input = btn.closest('[data-mla-copy]').querySelector('input');
				input.select();
				copyText(input.value, btn);
				return;
			}
			var direct = e.target.closest('[data-mla-copy-text]');
			if (direct) { copyText(direct.dataset.mlaCopyText, direct); }
		});

		var search = $('[data-mla-aff-search]', root);
		if (search) {
			search.addEventListener('input', function () {
				var q = norm(search.value);
				$$('[data-mla-aff-products] li', root).forEach(function (li) { li.hidden = q && li.dataset.search.indexOf(q) === -1; });
			});
		}

		// Équivalent en FCFA des Berry saisis.
		var value = parseFloat(root.dataset.mlaBerryValue) || 100;
		$$('[data-mla-berry-form]', root).forEach(function (form) {
			var input = $('[data-mla-berry-input]', form);
			var out = $('[data-mla-berry-fcfa]', form);
			if (!input || !out) { return; }
			input.addEventListener('input', function () {
				var fcfa = Math.round((parseFloat(input.value) || 0) * value);
				out.textContent = fcfa.toLocaleString('fr-FR') + ' FCFA';
			});
		});
	}

	ready(function () { initDashboard(); initPlayer(); initResources(); initAffiliate(); });
})();
