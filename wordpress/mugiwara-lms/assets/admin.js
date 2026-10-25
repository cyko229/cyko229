/* Mugiwara LMS : constructeur de formation (modules, leçons, ressources) et import Google Drive. */
(function ($) {
	'use strict';

	var cfg = window.MLA_ADMIN || {};
	var root = document.querySelector('[data-mla-builder]');
	if (!root) { return; }

	var input = root.querySelector('[data-mla-structure]');
	var state;
	try { state = JSON.parse(input.value) || {}; } catch (e) { state = {}; }
	state.modules = state.modules || [];
	state.resources = state.resources || [];
	state.download_all = state.download_all || '';

	var openModules = {};
	state.modules.forEach(function (m, i) { if (i === 0) { openModules[m.id] = true; } });

	function uid() { return Math.random().toString(36).slice(2, 12); }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function kind(url) {
		if (!url) { return ''; }
		if (/drive\.google\.com\/(drive\/(u\/\d+\/)?folders|embeddedfolderview)/.test(url)) { return 'Dossier Drive'; }
		if (/docs\.google\.com\/(document|spreadsheets|presentation)/.test(url)) { return 'Google Docs'; }
		if (/(drive|docs)\.google\.com\/(file\/|open\?|uc\?)|drive\.usercontent\.google\.com/.test(url)) { return 'Fichier Drive'; }
		if (/youtube\.com|youtu\.be/.test(url)) { return 'YouTube'; }
		return 'Lien externe';
	}

	function sync() {
		input.value = JSON.stringify(state);
	}

	function typeOptions(selected) {
		return Object.keys(cfg.types || {}).map(function (k) {
			return '<option value="' + k + '"' + (k === selected ? ' selected' : '') + '>' + esc(cfg.types[k]) + '</option>';
		}).join('');
	}

	function lessonRow(l, mi, li) {
		var k = kind(l.url);
		return '<div class="mla-lesson-row" data-li="' + li + '">' +
			'<span class="mla-handle dashicons dashicons-menu" title="Glisser pour réordonner"></span>' +
			'<div class="mla-lesson-row__main">' +
				'<div class="mla-lesson-row__line">' +
					'<input type="text" class="mla-lesson-title" placeholder="Titre de la leçon" value="' + esc(l.title) + '" data-field="title">' +
					'<select data-field="type">' + typeOptions(l.type) + '</select>' +
					'<input type="text" class="mla-lesson-duration" placeholder="Durée (12:30)" value="' + esc(l.duration) + '" data-field="duration">' +
				'</div>' +
				'<div class="mla-lesson-row__line">' +
					'<input type="url" class="mla-lesson-url" placeholder="Lien Google Drive (fichier, dossier, Docs) ou YouTube" value="' + esc(l.url) + '" data-field="url">' +
					'<span class="mla-kind' + (k ? '' : ' is-empty') + '">' + esc(k || 'Aucun lien') + '</span>' +
					'<label class="mla-dl"><input type="checkbox" data-field="downloadable"' + (l.downloadable ? ' checked' : '') + '> Téléchargeable</label>' +
				'</div>' +
				'<details class="mla-notes"' + (l.description ? ' open' : '') + '><summary>Notes de la leçon</summary>' +
					'<textarea rows="3" placeholder="Résumé, points clés, consignes…" data-field="description">' + esc(l.description) + '</textarea>' +
				'</details>' +
			'</div>' +
			'<button type="button" class="button-link mla-remove" data-remove-lesson title="Supprimer la leçon">✕</button>' +
		'</div>';
	}

	function moduleCard(m, mi) {
		var open = !!openModules[m.id];
		return '<div class="mla-module-card' + (open ? ' is-open' : '') + '" data-mi="' + mi + '">' +
			'<div class="mla-module-card__head">' +
				'<span class="mla-handle dashicons dashicons-menu" title="Glisser pour réordonner"></span>' +
				'<span class="mla-module-card__num">' + (mi + 1) + '</span>' +
				'<input type="text" class="mla-module-title" placeholder="Titre du module" value="' + esc(m.title) + '" data-module-title>' +
				'<span class="mla-module-card__count">' + m.lessons.length + ' leçon' + (m.lessons.length > 1 ? 's' : '') + '</span>' +
				'<button type="button" class="button-link" data-toggle-module>' + (open ? 'Replier' : 'Déplier') + '</button>' +
				'<button type="button" class="button-link mla-remove" data-remove-module title="Supprimer le module">✕</button>' +
			'</div>' +
			'<div class="mla-module-card__body"' + (open ? '' : ' hidden') + '>' +
				'<div class="mla-lessons-list">' + m.lessons.map(function (l, li) { return lessonRow(l, mi, li); }).join('') + '</div>' +
				'<p><button type="button" class="button" data-add-lesson>+ Ajouter une leçon</button></p>' +
			'</div>' +
		'</div>';
	}

	function resourceRow(r, ri) {
		var k = kind(r.url);
		return '<div class="mla-resource-row" data-ri="' + ri + '">' +
			'<span class="mla-handle dashicons dashicons-menu"></span>' +
			'<input type="text" placeholder="Nom de la ressource" value="' + esc(r.title) + '" data-rfield="title">' +
			'<input type="url" placeholder="Lien Google Drive ou URL" value="' + esc(r.url) + '" data-rfield="url">' +
			'<input type="text" placeholder="Note (facultatif)" value="' + esc(r.note) + '" data-rfield="note">' +
			'<span class="mla-kind' + (k ? '' : ' is-empty') + '">' + esc(k || 'Aucun lien') + '</span>' +
			'<button type="button" class="button-link mla-remove" data-remove-resource>✕</button>' +
		'</div>';
	}

	var modulesEl = root.querySelector('[data-mla-modules]');
	var resourcesEl = root.querySelector('[data-mla-resources]');

	function render() {
		modulesEl.innerHTML = state.modules.length ? state.modules.map(moduleCard).join('') :
			'<p class="mla-empty-hint">Aucun module. Importez un dossier Drive ou ajoutez un premier module.</p>';
		resourcesEl.innerHTML = state.resources.map(resourceRow).join('');
		root.querySelector('[data-mla-download-all]').value = state.download_all;
		initSortable();
		sync();
	}

	function initSortable() {
		if (!$.fn.sortable) { return; }
		$(modulesEl).sortable({
			handle: '.mla-module-card__head > .mla-handle',
			items: '> .mla-module-card',
			update: function () {
				state.modules = $(modulesEl).children('.mla-module-card').map(function () { return state.modules[this.dataset.mi]; }).get();
				render();
			}
		});
		$(modulesEl).find('.mla-lessons-list').each(function () {
			var list = this;
			$(list).sortable({
				handle: '.mla-handle',
				items: '> .mla-lesson-row',
				connectWith: '.mla-lessons-list',
				update: function (e, ui) {
					// Entre deux modules, les deux listes déclenchent update : seule celle qui reçoit la leçon recalcule.
					if (this !== ui.item.parent()[0]) { return; }
					state.modules = $(modulesEl).children('.mla-module-card').map(function () {
						var mod = Object.assign({}, state.modules[this.dataset.mi]);
						mod.lessons = $(this).find('.mla-lesson-row').map(function () {
							return state.modules[this.dataset.srcMi].lessons[this.dataset.li];
						}).get();
						return mod;
					}).get();
					render();
				},
				start: function () {
					$(modulesEl).find('.mla-lesson-row').each(function () {
						this.dataset.srcMi = this.closest('.mla-module-card').dataset.mi;
					});
				}
			});
		});
		$(resourcesEl).sortable({
			handle: '.mla-handle',
			update: function () {
				state.resources = $(resourcesEl).children().map(function () { return state.resources[this.dataset.ri]; }).get();
				render();
			}
		});
	}

	/* Saisie */
	function onEdit(e) {
		var t = e.target;
		var card = t.closest('[data-mi]');
		if (t.hasAttribute('data-module-title') && card) {
			state.modules[card.dataset.mi].title = t.value;
		} else if (t.dataset.field && card) {
			var lesson = state.modules[card.dataset.mi].lessons[t.closest('[data-li]').dataset.li];
			lesson[t.dataset.field] = t.type === 'checkbox' ? t.checked : t.value;
			if (t.dataset.field === 'url') {
				var badge = t.parentNode.querySelector('.mla-kind');
				var k = kind(t.value);
				badge.textContent = k || 'Aucun lien';
				badge.classList.toggle('is-empty', !k);
			}
		} else if (t.dataset.rfield) {
			state.resources[t.closest('[data-ri]').dataset.ri][t.dataset.rfield] = t.value;
		} else if (t.hasAttribute('data-mla-download-all')) {
			state.download_all = t.value;
		}
		sync();
	}
	root.addEventListener('input', onEdit);
	root.addEventListener('change', onEdit);

	/* Actions */
	root.addEventListener('click', function (e) {
		var t = e.target;
		var card = t.closest('[data-mi]');
		if (t.closest('[data-mla-add-module]')) {
			var m = { id: uid(), title: 'Module ' + (state.modules.length + 1), lessons: [] };
			state.modules.push(m);
			openModules[m.id] = true;
			render();
			var inputs = modulesEl.querySelectorAll('[data-module-title]');
			inputs[inputs.length - 1].select();
		} else if (t.closest('[data-toggle-module]') && card) {
			var id = state.modules[card.dataset.mi].id;
			openModules[id] = !openModules[id];
			render();
		} else if (t.closest('[data-remove-module]') && card) {
			var mod = state.modules[card.dataset.mi];
			if (!mod.lessons.length || window.confirm('Supprimer le module « ' + mod.title + ' » et ses ' + mod.lessons.length + ' leçons ?')) {
				state.modules.splice(card.dataset.mi, 1);
				render();
			}
		} else if (t.closest('[data-add-lesson]') && card) {
			state.modules[card.dataset.mi].lessons.push({ id: uid(), title: '', type: 'video', url: '', duration: '', description: '', downloadable: true });
			render();
			var rows = modulesEl.querySelectorAll('[data-mi="' + card.dataset.mi + '"] .mla-lesson-title');
			rows[rows.length - 1].focus();
		} else if (t.closest('[data-remove-lesson]') && card) {
			state.modules[card.dataset.mi].lessons.splice(t.closest('[data-li]').dataset.li, 1);
			render();
		} else if (t.closest('[data-mla-add-resource]')) {
			state.resources.push({ id: uid(), title: '', url: '', note: '' });
			render();
		} else if (t.closest('[data-remove-resource]')) {
			state.resources.splice(t.closest('[data-ri]').dataset.ri, 1);
			render();
		}
	});

	/* Import Google Drive */
	var importBtn = root.querySelector('[data-mla-import]');
	var importUrl = root.querySelector('[data-mla-import-url]');
	var status = root.querySelector('[data-mla-import-status]');
	// Ctrl+Entrée dans le champ d'import lance l'import.
	importUrl.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); importBtn.click(); }
	});
	importBtn.addEventListener('click', function () {
		var url = importUrl.value.trim();
		if (!url) { importUrl.focus(); return; }
		if (state.modules.length && !window.confirm('L’import remplace les modules et ressources actuels. Continuer ?')) { return; }
		importBtn.disabled = true;
		status.className = 'mla-admin__status';
		status.textContent = 'Lecture du dossier Google Drive…';
		$.post(cfg.ajax, { action: 'mla_drive_import', _ajax_nonce: cfg.nonce, url: url })
			.done(function (res) {
				if (!res.success) {
					status.className = 'mla-admin__status is-error';
					status.textContent = res.data || 'Import impossible.';
					return;
				}
				state = res.data;
				openModules = {};
				if (state.modules[0]) { openModules[state.modules[0].id] = true; }
				render();
				var count = state.modules.reduce(function (n, m) { return n + m.lessons.length; }, 0);
				status.className = 'mla-admin__status is-ok';
				status.textContent = '✓ ' + state.modules.length + ' modules, ' + count + ' leçons et ' + state.resources.length + ' ressources importés. Vérifiez puis cliquez sur « Mettre à jour ».';
			})
			.fail(function (xhr) {
				status.className = 'mla-admin__status is-error';
				status.textContent = (xhr.responseJSON && xhr.responseJSON.data) || 'Erreur réseau pendant l’import.';
			})
			.always(function () { importBtn.disabled = false; });
	});

	var form = document.getElementById('post');
	if (form) { form.addEventListener('submit', sync); }

	render();
})(jQuery);
