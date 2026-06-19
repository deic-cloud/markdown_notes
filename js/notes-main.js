/* Notes web UI — vanilla JS over the OCS API + EasyMDE. First iteration;
 * tagging/organising/editing get refined from here. */
(function () {
	'use strict';

	var OCS = (OC.webroot || '') + '/ocs/v2.php/apps/markdown_notes/api/v1';
	var mde = null;
	var state = { mode: 'all', notebook: '', tag: '', notePath: null, notes: [], templates: [], selected: [], notesFolder: 'Notes', viewMode: 0, tagColors: {}, editorTags: [], vocab: [], sortMode: 'updated' };
	// epoch-ms (Joplin) ↔ <input type=datetime-local> value (local time)
	function msToInput(ms) {
		var d = new Date(Number(ms));
		function pad(x) { return (x < 10 ? '0' : '') + x; }
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}
	function inputToMs(v) { if (!v) { return ''; } var ms = new Date(v).getTime(); return isNaN(ms) ? '' : String(ms); }
	var draggedPaths = [];
	var draggedNotebook = '';

	// MathJax (bundled, all-packages build incl. mhchem) for LaTeX/chemistry in
	// the editor preview. Self-hosted — no CDN. Loaded once, lazily typeset.
	window.MathJax = {
		tex: {
			inlineMath: [['$', '$'], ['\\(', '\\)']],
			displayMath: [['$$', '$$'], ['\\[', '\\]']],
			packages: { '[+]': ['mhchem'] },
		},
		options: { skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'] },
		startup: { typeset: false },
	};
	(function () {
		var s = document.createElement('script');
		// Full build: mhchem (\ce, \pu) is inlined, so \ce{} works offline without
		// MathJax trying to lazy-load an extension we don't host.
		s.src = (OC.webroot || '') + '/apps/markdown_notes/3rdparty/mathjax/tex-chtml-full.js';
		s.async = true;
		document.head.appendChild(s);
	})();
	// Protect LaTeX/chem spans from the markdown step, then restore them so
	// MathJax can typeset the raw source. This lets a display block ($$…$$ / \[…\])
	// contain blank lines (markdown would otherwise split it across paragraphs)
	// and keeps markdown specials (_, *) literal inside math.
	function renderMarkdownWithMath(text) {
		var store = [];
		function stash(m) { store.push(m); return '@@MJX' + (store.length - 1) + '@@'; }
		// In a bare display block, MathJax ignores `\\` (line breaks only work
		// inside an alignment environment). If the user wrote `\\` without their
		// own \begin{…}, wrap the body so `\\` just works: `aligned` when they use
		// `&` columns, otherwise `gathered` (centred lines).
		function wrapDisplay(m, open, close) {
			var inner = m.slice(open.length, m.length - close.length);
			if (/\\\\/.test(inner) && !/\\begin\{/.test(inner)) {
				var env = /&/.test(inner) ? 'aligned' : 'gathered';
				inner = '\\begin{' + env + '}' + inner + '\\end{' + env + '}';
			}
			return open + inner + close;
		}
		var protectedText = text
			.replace(/\$\$[\s\S]+?\$\$/g, function (m) { return stash(wrapDisplay(m, '$$', '$$')); })   // $$ … $$ display (blank lines OK)
			.replace(/\\\[[\s\S]+?\\\]/g, function (m) { return stash(wrapDisplay(m, '\\[', '\\]')); })  // \[ … \] display
			.replace(/\\\([\s\S]+?\\\)/g, stash)                                    // \( … \) inline
			.replace(/(^|[^\\$])\$([^\n$]+?)\$/g, function (m, pre, inner) {         // $ … $ inline
				return pre + stash('$' + inner + '$');
			});
		var html = (mde && mde.markdown) ? mde.markdown(protectedText) : protectedText;
		return html.replace(/@@MJX(\d+)@@/g, function (m, i) { return store[Number(i)]; });
	}
	function typesetMath(elem) {
		if (window.MathJax && window.MathJax.typesetPromise) {
			try { window.MathJax.typesetClear && window.MathJax.typesetClear([elem]); } catch (e) { /* ignore */ }
			window.MathJax.typesetPromise([elem]).catch(function () { /* ignore */ });
		}
	}

	// ── OCS helpers ───────────────────────────────────────────────────────────
	function req(method, path, params) {
		var opts = {
			method: method,
			cache: 'no-store', // never serve a stale list/note from the HTTP cache
			headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': OC.requestToken },
		};
		var url = OCS + path;
		if (method === 'GET' && params) {
			url += (path.indexOf('?') < 0 ? '?' : '&') + params.toString();
		} else if (params) {
			opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
			opts.body = params;
		}
		url += (url.indexOf('?') < 0 ? '?' : '&') + 'format=json';
		return fetch(url, opts).then(function (r) { return r.json(); }).then(function (j) {
			if (!j.ocs || !j.ocs.meta || j.ocs.meta.status !== 'ok') {
				throw new Error((j.ocs && j.ocs.data && j.ocs.data.message) || 'Request failed');
			}
			return j.ocs.data;
		});
	}
	function get(path, params) { return req('GET', path, params); }
	function post(path, params) { return req('POST', path, params); }
	function p() { var u = new URLSearchParams(); for (var i = 0; i < arguments.length; i += 2) { u.append(arguments[i], arguments[i + 1]); } return u; }
	function el(id) { return document.getElementById(id); }
	function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

	// ── Navigation ──────────────────────────────────────────────────────────
	function loadTree() {
		return get('/tree').then(function (d) {
			state.notesFolder = d.notesFolder || 'Notes';
			// Colours + autocomplete come from the FULL systemtag vocabulary, not
			// just tags currently on notes; the sidebar still lists in-use tags.
			var vocab = d.vocabulary || d.tags || [];
			state.tagColors = {};
			vocab.forEach(function (tg) { state.tagColors[tg.name] = tg.color || ''; });
			state.vocab = vocab.map(function (tg) { return tg.name; });
			renderNotebooks(d.notebooks || []);
			renderTags(d.tags || []);
		});
	}
	function renderNotebooks(tree) {
		var root = el('notes-notebooks');
		root.innerHTML = '';
		root.appendChild(buildNbList(tree));
	}
	function buildNbList(nodes) {
		var ul = document.createElement('ul');
		nodes.forEach(function (n) {
			var li = document.createElement('li');
			li.dataset.notebook = n.path;
			var row = document.createElement('div');
			row.className = 'notes-nb-row';
			row.innerHTML = '<span class="icon-folder"></span><span class="notes-nb-name">' + esc(n.name) + '</span>';
			row.addEventListener('click', function (e) { e.stopPropagation(); selectNotebook(n.path); });
			row.draggable = true;
			row.addEventListener('dragstart', function (e) {
				e.stopPropagation();
				draggedNotebook = n.path; draggedPaths = [];
				e.dataTransfer.effectAllowed = 'move';
				try { e.dataTransfer.setData('text/plain', n.path); } catch (ex) { /* ignore */ }
			});
			row.addEventListener('dragend', function () { draggedNotebook = ''; });
			makeDropTarget(row, function () {
				if (draggedNotebook) { dropMoveNotebook(draggedNotebook, n.path); }
				else { dropMove(draggedPaths, n.path); }
			});
			li.appendChild(row);
			if (n.children && n.children.length) { li.appendChild(buildNbList(n.children)); }
			ul.appendChild(li);
		});
		return ul;
	}
	function normColor(c) { return !c ? '' : (c[0] === '#' ? c : '#' + c); }
	function renderTags(tags) {
		var ul = el('notes-tags');
		ul.innerHTML = '';
		tags.forEach(function (tg) {
			var name = tg.name;
			var color = normColor(tg.color);
			var li = document.createElement('li');
			li.dataset.tag = name;
			// A <span>, not an <a>: a draggable anchor swallows note drops before
			// they reach our handler, so the pill is a plain clickable span.
			var a = el2('span', 'notes-tag-pill');
			a.textContent = name;
			if (color) { a.style.background = color; }
			a.addEventListener('click', function () { selectTag(name); });
			li.appendChild(a);
			makeDropTarget(a, function () { if (!draggedNotebook) { dropTag(draggedPaths, name); } });
			ul.appendChild(li);
		});
	}
	function el2(tag, cls) { var e = document.createElement(tag); if (cls) { e.className = cls; } return e; }
	function setActiveNav() {
		document.querySelectorAll('#app-navigation li').forEach(function (li) { li.classList.remove('active'); });
		// The notebook/all context and an optional tag FILTER are both highlighted.
		if (state.mode === 'all') { document.querySelector('.notes-nav-all').classList.add('active'); }
		if (state.mode === 'notebook') { var n = document.querySelector('#notes-notebooks li[data-notebook="' + cssEsc(state.notebook) + '"]'); if (n) n.classList.add('active'); }
		if (state.tag) { var tEl = document.querySelector('#notes-tags li[data-tag="' + cssEsc(state.tag) + '"]'); if (tEl) { tEl.classList.add('active'); } }
	}
	function cssEsc(s) { return (s || '').replace(/["\\]/g, '\\$&'); }

	// ── List ────────────────────────────────────────────────────────────────
	// Context = a notebook (or "all"); a tag is a FILTER layered on top of it
	// (refines, doesn't replace). Selecting a context clears the filter; clicking
	// a tag toggles it.
	function selectAll() { state.mode = 'all'; state.notebook = ''; state.tag = ''; loadList(); }
	function selectNotebook(path) { state.mode = 'notebook'; state.notebook = path; state.tag = ''; loadList(); }
	function selectTag(tag) { state.tag = (state.tag === tag) ? '' : tag; loadList(); }

	function loadList() {
		setActiveNav();
		var params = state.mode === 'all' ? p('recursive', '1') : p('notebook', state.notebook);
		if (state.tag) { params.append('tag', state.tag); }
		var ctx = state.mode === 'all' ? t('markdown_notes', 'All notes') : state.notebook;
		if (state.tag) { ctx += '  ·  #' + state.tag; }
		var cEl = el('notes-list-context');
		cEl.innerHTML = '';
		var lbl = el2('label', 'notes-selectall');
		var m = document.createElement('input');
		m.type = 'checkbox'; m.id = 'notes-master-check'; m.title = t('markdown_notes', 'Select all');
		m.addEventListener('change', toggleSelectAll);
		lbl.appendChild(m);
		lbl.appendChild(document.createTextNode(' ' + ctx));
		cEl.appendChild(lbl);
		return get('/notes', params).then(function (notes) {
			state.notes = notes;
			renderList();
		}).catch(showError);
	}
	function dueMs(n) { var d = n.is_todo && n.todo_due ? Number(n.todo_due) : 0; return d > 0 ? d : 0; }
	function sortNotes(list) {
		var arr = list.slice();
		switch (state.sortMode) {
		case 'title':
			arr.sort(function (a, b) { return (a.title || '').localeCompare(b.title || ''); }); break;
		case 'created':
			arr.sort(function (a, b) { return (Date.parse(b.created) || 0) - (Date.parse(a.created) || 0); }); break;
		case 'due':
			// To-dos with a due date first (earliest due first); everything else by updated.
			arr.sort(function (a, b) {
				var da = dueMs(a), db = dueMs(b);
				if (da && db) { return da - db; }
				if (da) { return -1; }
				if (db) { return 1; }
				return (b.modified || 0) - (a.modified || 0);
			}); break;
		default: // updated
			arr.sort(function (a, b) { return (b.modified || 0) - (a.modified || 0); });
		}
		return arr;
	}
	function formatDue(ms) {
		var d = new Date(ms);
		function pad(x) { return (x < 10 ? '0' : '') + x; }
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}
	function renderList() {
		var ul = el('notes-list');
		ul.innerHTML = '';
		var q = (el('notes-search').value || '').toLowerCase();
		var visible = state.notes.filter(function (n) {
			return !q || (n.title + ' ' + n.excerpt + ' ' + (n.tags || []).join(' ')).toLowerCase().indexOf(q) >= 0;
		});
		sortNotes(visible).forEach(function (n) {
			var li = document.createElement('li');
			li.dataset.path = n.path;
			li.draggable = true;
			if (n.path === state.notePath) { li.classList.add('active'); }
			var checked = state.selected.indexOf(n.path) >= 0;
			if (checked) { li.classList.add('selected'); }
			var done = n.is_todo && n.todo_completed;
			// "Done" box lives on the RIGHT of the row (away from the selection checkbox).
			var doneBox = n.is_todo ? '<span class="notes-todo-box" role="button" title="' + esc(t('markdown_notes', 'Mark done / not done')) + '">' + (done ? '☑' : '☐') + '</span>' : '';
			var titleCls = done ? ' notes-todo-done' : '';
			var due = '';
			if (dueMs(n)) {
				var overdue = !done && dueMs(n) < Date.now();
				due = '<div class="notes-item-due' + (overdue ? ' notes-overdue' : '') + '">⏰ ' + esc(formatDue(dueMs(n))) + '</div>';
			}
			var tags = (n.tags || []).map(function (tg) {
				var c = normColor(state.tagColors[tg]);
				return '<span class="notes-tag"' + (c ? ' style="background:' + c + ';color:#fff"' : '') + '>' + esc(tg) + '</span>';
			}).join('');
			li.innerHTML =
				'<input type="checkbox" class="notes-item-check"' + (checked ? ' checked' : '') + ' title="' + esc(t('markdown_notes', 'Select')) + '" />' +
				'<div class="notes-item-main">' +
					'<div class="notes-item-title' + titleCls + '">' + esc(n.title) + '</div>' +
					'<div class="notes-item-excerpt">' + esc(n.excerpt) + '</div>' +
					due +
					(tags ? '<div class="notes-item-tags">' + tags + '</div>' : '') +
				'</div>' +
				doneBox;
			var cb = li.querySelector('.notes-item-check');
			cb.addEventListener('click', function (e) { e.stopPropagation(); });
			cb.addEventListener('change', function () { toggleSelect(n.path, cb.checked); });
			var box = li.querySelector('.notes-todo-box');
			if (box) { box.addEventListener('click', function (e) { e.stopPropagation(); toggleCompleted(n.path, !done); }); }
			li.addEventListener('click', function (e) { if (e.target !== cb && e.target !== box) { openNote(n.path); } });
			li.addEventListener('dragstart', function (e) { onNoteDragStart(e, n.path, li); });
			li.addEventListener('dragend', function () { li.classList.remove('dragging'); });
			ul.appendChild(li);
		});
		updateSelectionUI();
	}
	function toggleCompleted(path, completed) {
		post('/note/complete', p('path', path, 'completed', completed ? '1' : '0'))
			.then(function () { return refreshAfterChange(); }).catch(showError);
	}

	// ── Editor ────────────────────────────────────────────────────────────────
	function ensureEditor() {
		if (mde) { return; }
		mde = new EasyMDE({
			element: el('notes-editor'),
			autoDownloadFontAwesome: false,
			spellChecker: false,
			status: false,
			minHeight: '300px',
			// Keep side-by-side INSIDE our editor pane instead of a fullscreen
			// overlay (the overlay couldn't be dismissed via the toolbar).
			sideBySideFullscreen: false,
			toolbar: ['bold', 'italic', 'heading', '|', 'quote', 'unordered-list', 'ordered-list',
				'|', 'link',
				{ name: 'image', className: 'fa fa-image', title: t('markdown_notes', 'Insert image'), action: function () { pickImage(); } },
				'table', 'code', '|',
				{ name: 'viewmode', className: 'fa fa-edit notes-vm', title: t('markdown_notes', 'View: edit / side-by-side / rendered'), action: function () { cycleView(); } },
				'guide'],
			// Render markdown, then typeset LaTeX/mhchem with MathJax. EasyMDE sets
			// the returned HTML synchronously, so we typeset on the next tick once
			// the preview element is populated.
			previewRender: function (plainText, preview) {
				var html = renderMarkdownWithMath(plainText);
				setTimeout(function () { typesetMath(preview); }, 0);
				return html;
			},
		});
	}
	// Three view modes on one toolbar button: 0 edit · 1 side-by-side · 2 rendered.
	// The mode persists across notes so you can browse rendered, not just edit.
	function cycleView() { state.viewMode = (state.viewMode + 1) % 3; applyViewMode(); }
	function applyViewMode() {
		if (!mde || !window.EasyMDE) { return; }
		try { if (mde.isSideBySideActive && mde.isSideBySideActive()) { EasyMDE.toggleSideBySide(mde); } } catch (e) { /* ignore */ }
		try { if (mde.isPreviewActive && mde.isPreviewActive()) { EasyMDE.togglePreview(mde); } } catch (e) { /* ignore */ }
		if (state.viewMode === 1) { try { EasyMDE.toggleSideBySide(mde); } catch (e) { /* ignore */ } }
		else if (state.viewMode === 2) { try { EasyMDE.togglePreview(mde); } catch (e) { /* ignore */ } }
		updateViewButton();
	}
	function updateViewButton() {
		var marker = document.querySelector('.editor-toolbar .notes-vm');
		if (!marker) { return; }
		// EasyMDE puts the icon classes on BOTH the <button> and an inner <i>,
		// which doubles the glyph. Keep the icon ONLY on the inner <i>; the
		// button carries just the marker (+ active).
		var button = marker.tagName === 'BUTTON' ? marker : (marker.closest ? marker.closest('button') : marker.parentNode) || marker;
		var icon = state.viewMode === 2 ? 'fa-eye' : (state.viewMode === 1 ? 'fa-columns' : 'fa-edit');
		var label = state.viewMode === 2 ? t('markdown_notes', 'Rendered') : (state.viewMode === 1 ? t('markdown_notes', 'Side by side') : t('markdown_notes', 'Edit'));
		button.className = 'notes-vm' + (state.viewMode ? ' active' : '');
		var i = button.querySelector('i');
		if (!i) { i = document.createElement('i'); button.appendChild(i); }
		i.className = 'fa ' + icon;
		button.title = t('markdown_notes', 'View') + ': ' + label;
	}

	function openNote(path) {
		return get('/note', p('path', path)).then(function (note) {
			state.notePath = note.path;
			el('notes-editor-empty').style.display = 'none';
			el('notes-editor-wrap').style.display = 'flex';
			ensureEditor();
			el('notes-title').value = note.title;
			mde.value(note.body);
			applyViewMode();
			state.editorTags = (note.tags || []).slice();
			el('notes-tags-input').value = '';
			renderTagChips();
			applyTodoFields(note.meta);
			renderFooter(note.meta);
			el('notes-status').textContent = '';
			renderList();
		}).catch(showError);
	}
	// Reflect the note's to-do state into the editor controls.
	function applyTodoFields(meta) {
		meta = meta || {};
		var isTodo = !!meta.is_todo && meta.is_todo !== '0';
		el('notes-is-todo').checked = isTodo;
		el('notes-due').value = (isTodo && meta.todo_due && Number(meta.todo_due) > 0) ? msToInput(meta.todo_due) : '';
		el('notes-due-wrap').style.display = isTodo ? 'inline-flex' : 'none';
	}
	function renderFooter(meta) {
		var legend = '# id: stable note id  ·  created_time/updated_time  ·  is_todo/todo_due/todo_completed  ·  tags\n';
		var lines = Object.keys(meta || {}).map(function (k) { return k + ': ' + meta[k]; }).join('\n');
		el('notes-footer-view').textContent = legend + lines;
	}
	// Tag chips in the editor: colored chips with ×, plus an autocomplete input.
	// Add/remove apply immediately (like drag), so tagging is discoverable.
	function renderTagChips() {
		var box = el('notes-tag-chips');
		box.innerHTML = '';
		state.editorTags.forEach(function (tg) {
			var chip = el2('span', 'notes-editor-tag');
			var c = normColor(state.tagColors[tg]);
			if (c) { chip.style.background = c; }
			chip.appendChild(document.createTextNode(tg));
			var x = el2('button', '');
			x.type = 'button'; x.textContent = '×'; x.title = t('markdown_notes', 'Remove tag');
			x.addEventListener('click', function () { removeEditorTag(tg); });
			chip.appendChild(x);
			box.appendChild(chip);
		});
		var dl = el('notes-tags-datalist');
		dl.innerHTML = '';
		state.vocab.forEach(function (name) {
			if (state.editorTags.indexOf(name) < 0) { var o = document.createElement('option'); o.value = name; dl.appendChild(o); }
		});
	}
	function addEditorTag(name) {
		name = (name || '').trim();
		if (!name || !state.notePath) { return; }
		// Reuse an existing tag if it matches case-insensitively, so we don't
		// create near-duplicates (e.g. "Todo" when "todo" already exists).
		var lower = name.toLowerCase();
		var match = state.vocab.filter(function (k) { return k.toLowerCase() === lower; });
		if (match.length) { name = match[0]; }
		if (state.editorTags.some(function (t2) { return t2.toLowerCase() === lower; })) { return; }
		state.editorTags.push(name);
		renderTagChips();
		post('/note/tag', p('path', state.notePath, 'tags[]', name))
			.then(function (note) { renderFooter(note.meta); return refreshAfterChange(); })
			.then(renderTagChips) // re-colour the chip once the refreshed vocabulary has its colour
			.catch(showError);
	}
	function removeEditorTag(name) {
		if (!state.notePath) { return; }
		state.editorTags = state.editorTags.filter(function (t2) { return t2 !== name; });
		renderTagChips();
		post('/note/untag', p('path', state.notePath, 'tags[]', name))
			.then(function (note) { renderFooter(note.meta); return refreshAfterChange(); })
			.then(renderTagChips)
			.catch(showError);
	}

	function saveNote() {
		if (!state.notePath) { return; }
		var isTodo = el('notes-is-todo').checked;
		var params = p('path', state.notePath, 'title', el('notes-title').value, 'body', mde.value(),
			'is_todo', isTodo ? '1' : '0', 'todo_due', isTodo ? inputToMs(el('notes-due').value) : '');
		state.editorTags.forEach(function (t) { params.append('tags[]', t); });
		el('notes-status').textContent = 'Saving…';
		post('/note/save', params).then(function (note) {
			el('notes-status').textContent = 'Saved ✓';
			applyTodoFields(note.meta);
			renderFooter(note.meta);
			setTimeout(function () { el('notes-status').textContent = ''; }, 1500);
			return refreshAfterChange();
		}).catch(showError);
	}
	function deleteNote() {
		if (!state.notePath || !confirm(t('markdown_notes', 'Delete this note?'))) { return; }
		post('/note/delete', p('path', state.notePath)).then(function () {
			state.notePath = null;
			el('notes-editor-wrap').style.display = 'none';
			el('notes-editor-empty').style.display = 'block';
			return refreshAfterChange();
		}).catch(showError);
	}
	function refreshAfterChange() { return Promise.all([loadList(), loadTree()]); }

	// ── Create ────────────────────────────────────────────────────────────────
	function newNote() { createFromTemplate(false); }
	function newTodo() { createFromTemplate(true); }
	// Create a note or to-do, optionally from a template. Templates may define a
	// title (template_title) and custom variables we prompt for (Joplin-style).
	function createFromTemplate(isTodo) {
		var template = el('notes-template').value || '';
		var notebook = state.mode === 'notebook' ? state.notebook : '';
		function send(title, vars) {
			var params = p('notebook', notebook, 'title', title || '', 'template', template,
				'is_todo', isTodo ? '1' : '0', 'vars', vars ? JSON.stringify(vars) : '');
			post('/note/create', params).then(function (note) {
				return refreshAfterChange().then(function () { return openNote(note.path); });
			}).catch(showError);
		}
		function askTitle() {
			return prompt(t('markdown_notes', isTodo ? 'To-do title' : 'Note title'),
				t('markdown_notes', isTodo ? 'New to-do' : 'New note'));
		}
		if (!template) {
			var title = askTitle();
			if (title === null) { return; }
			send(title, null);
			return;
		}
		get('/template/info', p('path', template)).then(function (info) {
			var vars = {};
			var list = info.variables || [];
			for (var i = 0; i < list.length; i++) {
				var v = list[i];
				var label = v.label || v.name;
				if (v.type === 'dropdown' && v.options && v.options.length) { label += ' (' + v.options.join(' / ') + ')'; }
				var ans = prompt(label, '');
				if (ans === null) { return; } // cancelled
				vars[v.name] = ans;
			}
			var title = '';
			if (!info.title) { title = askTitle(); if (title === null) { return; } } // template sets no title → ask
			send(title, vars);
		}).catch(showError);
	}
	function newNotebook() {
		var name = prompt(t('markdown_notes', 'Notebook name'));
		if (!name) { return; }
		var parent = state.mode === 'notebook' ? state.notebook : '';
		post('/notebook/create', p('parent', parent, 'name', name)).then(loadTree).catch(showError);
	}
	function loadTemplates() {
		return get('/templates').then(function (tpls) {
			state.templates = tpls;
			var sel = el('notes-template');
			tpls.forEach(function (tp) {
				var o = document.createElement('option');
				o.value = tp.path; o.textContent = tp.title;
				sel.appendChild(o);
			});
		});
	}

	// ── Image insert (upload into the visible attachments/ folder) ───────────
	function davBase() {
		var uid = (window.OC && OC.getCurrentUser) ? OC.getCurrentUser().uid : OC.currentUser;
		var seg = (state.notesFolder || 'Notes').split('/').filter(Boolean).map(encodeURIComponent).join('/');
		return (OC.webroot || '') + '/remote.php/dav/files/' + encodeURIComponent(uid) + '/' + seg;
	}
	// Pick an existing image from the user's NC files (native file picker) and
	// insert a markdown reference. Falls back to a local upload if the legacy
	// picker isn't available.
	function pickImage() {
		if (window.OC && OC.dialogs && OC.dialogs.filepicker) {
			var img = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp', 'image/bmp'];
			OC.dialogs.filepicker(t('markdown_notes', 'Insert image'), insertImageFromPath, false, img, true,
				(OC.dialogs.FILEPICKER_TYPE_CHOOSE || 1));
		} else {
			pickAndUpload();
		}
	}
	function insertImageFromPath(path) {
		if (!path) { return; }
		var uid = (window.OC && OC.getCurrentUser) ? OC.getCurrentUser().uid : OC.currentUser;
		var davUrl = (OC.webroot || '') + '/remote.php/dav/files/' + encodeURIComponent(uid)
			+ path.split('/').map(encodeURIComponent).join('/');
		var alt = path.split('/').pop().replace(/\.[^.]+$/, '');
		if (mde) { mde.codemirror.replaceSelection('![' + alt + '](' + davUrl + ')'); mde.codemirror.focus(); }
	}
	function pickAndUpload() {
		var input = document.createElement('input');
		input.type = 'file';
		input.accept = 'image/*';
		input.addEventListener('change', function () { if (input.files && input.files[0]) { uploadImage(input.files[0]); } });
		input.click();
	}
	function uploadImage(file) {
		var attDir = davBase() + '/attachments';
		var name = (file.name || 'image').replace(/[\\/]/g, '_');
		var url = attDir + '/' + encodeURIComponent(name);
		var hdr = { requesttoken: OC.requestToken };
		el('notes-status').textContent = t('markdown_notes', 'Uploading…');
		fetch(attDir, { method: 'MKCOL', headers: hdr })
			.catch(function () {})
			.then(function () { return fetch(url, { method: 'PUT', headers: hdr, body: file }); })
			.then(function (r) {
				if (!r.ok && r.status !== 201 && r.status !== 204) { throw new Error('Upload failed (' + r.status + ')'); }
				if (mde) { mde.codemirror.replaceSelection('![' + name + '](' + url + ')'); }
				el('notes-status').textContent = '';
			}).catch(showError);
	}

	// ── Math toolbar: wrap selection (or insert empty delimiters) ─────────────
	function insertMath(before, after) {
		if (!mde) { return; }
		var cm = mde.codemirror;
		var sel = cm.getSelection();
		cm.replaceSelection(before + sel + after);
		if (!sel) {
			var pos = cm.getCursor();
			cm.setCursor(pos.line, pos.ch - after.length); // cursor between delimiters
		}
		cm.focus();
	}

	// ── Selection + drag & drop ───────────────────────────────────────────────
	function toggleSelect(path, checked) {
		var i = state.selected.indexOf(path);
		if (checked && i < 0) { state.selected.push(path); }
		else if (!checked && i >= 0) { state.selected.splice(i, 1); }
		updateSelectionUI();
	}
	function updateSelectionUI() {
		document.querySelectorAll('#notes-list li').forEach(function (li) {
			var sel = state.selected.indexOf(li.dataset.path) >= 0;
			li.classList.toggle('selected', sel);
			var cb = li.querySelector('.notes-item-check');
			if (cb) { cb.checked = sel; }
		});
		var master = el('notes-master-check');
		if (master) {
			var vis = visibleNotePaths();
			var selCount = vis.filter(function (pth) { return state.selected.indexOf(pth) >= 0; }).length;
			master.checked = selCount > 0 && selCount === vis.length;
			master.indeterminate = selCount > 0 && selCount < vis.length;
		}
		var bar = el('notes-selection-bar');
		if (state.selected.length) {
			bar.style.display = 'flex';
			bar.innerHTML = '<strong>' + state.selected.length + '</strong> ' + esc(t('markdown_notes', 'selected'))
				+ ' <span class="notes-sel-hint">' + esc(t('markdown_notes', '— drag onto a notebook or tag')) + '</span>'
				+ '<a href="#" class="notes-sel-clear">' + esc(t('markdown_notes', 'Clear')) + '</a>';
			bar.querySelector('.notes-sel-clear').addEventListener('click', function (e) { e.preventDefault(); state.selected = []; updateSelectionUI(); });
		} else {
			bar.style.display = 'none';
			bar.innerHTML = '';
		}
	}
	// Drag the checked set if the dragged note is part of it; otherwise just this one.
	function onNoteDragStart(e, path, li) {
		draggedPaths = (state.selected.length && state.selected.indexOf(path) >= 0) ? state.selected.slice() : [path];
		li.classList.add('dragging');
		e.dataTransfer.effectAllowed = 'copyMove';
		try { e.dataTransfer.setData('text/plain', draggedPaths.join('\n')); } catch (ex) { /* ignore */ }
	}
	// Run drop ops SEQUENTIALLY (thunks) — concurrent moves into the same folder
	// collide on NC file locks / oc_filecache. Always refresh, even on a failure.
	function runDrop(thunks) {
		return thunks.reduce(function (chain, thunk) {
			return chain.then(function () { return thunk().catch(showError); });
		}, Promise.resolve()).then(function () {
			state.selected = []; draggedPaths = []; draggedNotebook = '';
			return refreshAfterChange();
		});
	}
	function dropMove(paths, notebookPath) {
		if (!paths.length) { return; }
		runDrop(paths.map(function (pth) {
			return function () {
				var base = pth.split('/').pop();
				var target = notebookPath ? notebookPath + '/' + base : base;
				return target === pth ? Promise.resolve() : post('/rename', p('path', pth, 'target', target));
			};
		}));
	}
	function dropTag(paths, tag) {
		if (!paths.length) { return; }
		runDrop(paths.map(function (pth) { return function () { return post('/note/tag', p('path', pth, 'tags[]', tag)); }; }));
	}
	function visibleNotePaths() {
		var q = (el('notes-search').value || '').toLowerCase();
		return state.notes.filter(function (n) {
			return !q || (n.title + ' ' + n.excerpt + ' ' + (n.tags || []).join(' ')).toLowerCase().indexOf(q) >= 0;
		}).map(function (n) { return n.path; });
	}
	function toggleSelectAll() {
		var paths = visibleNotePaths();
		var allSelected = paths.length && paths.every(function (pth) { return state.selected.indexOf(pth) >= 0; });
		state.selected = allSelected ? [] : paths.slice();
		updateSelectionUI();
	}
	function makeDropTarget(elem, onDrop) {
		function over(e) {
			if (!draggedPaths.length && !draggedNotebook) { return; }
			e.preventDefault(); // both dragenter + dragover, so Firefox accepts the drop
			try { e.dataTransfer.dropEffect = draggedNotebook ? 'move' : 'copy'; } catch (ex) { /* ignore */ }
			elem.classList.add('notes-drop-target');
		}
		elem.addEventListener('dragenter', over);
		elem.addEventListener('dragover', over);
		elem.addEventListener('dragleave', function () { elem.classList.remove('notes-drop-target'); });
		elem.addEventListener('drop', function (e) { e.preventDefault(); e.stopPropagation(); elem.classList.remove('notes-drop-target'); onDrop(); });
	}
	// Move a notebook (and its notes) into another notebook (or the root).
	function dropMoveNotebook(nbPath, targetParent) {
		// Can't move a notebook into itself or one of its own descendants.
		if (targetParent === nbPath || (targetParent + '/').indexOf(nbPath + '/') === 0) { return; }
		var base = nbPath.split('/').pop();
		var target = targetParent ? targetParent + '/' + base : base;
		if (target === nbPath) { return; }
		runDrop([function () { return post('/rename', p('path', nbPath, 'target', target)); }]);
	}

	function showError(e) {
		var msg = (e && e.message) ? e.message : String(e);
		if (window.OC && OC.Notification && OC.Notification.showTemporary) { OC.Notification.showTemporary(msg); }
		else { console.error('[notes]', msg); el('notes-status').textContent = msg; }
	}

	// ── Wire up ─────────────────────────────────────────────────────────────
	document.addEventListener('DOMContentLoaded', function () {
		var navAll = document.querySelector('.notes-nav-all');
		var navAllLink = navAll.querySelector('a');
		navAllLink.addEventListener('click', function (e) { e.preventDefault(); selectAll(); });
		// The anchor is draggable by default and would swallow drops onto "All
		// notes" (used to move a notebook back to the top level) — disable it.
		navAllLink.draggable = false;
		makeDropTarget(navAll, function () {
			if (draggedNotebook) { dropMoveNotebook(draggedNotebook, ''); } else { dropMove(draggedPaths, ''); }
		});
		// The "Notebooks" header is an explicit "move to top level" target for a
		// dragged (possibly deeply nested) notebook.
		var nbHeader = el('notes-nb-header');
		if (nbHeader) { makeDropTarget(nbHeader, function () { if (draggedNotebook) { dropMoveNotebook(draggedNotebook, ''); } }); }
		el('notes-new-notebook').addEventListener('click', newNotebook);
		el('notes-new-note').addEventListener('click', newNote);
		el('notes-new-todo').addEventListener('click', newTodo);
		el('notes-sort').addEventListener('change', function () { state.sortMode = this.value; renderList(); });
		// To-do toggle reveals the due-date picker; clearing the due date.
		el('notes-is-todo').addEventListener('change', function () {
			el('notes-due-wrap').style.display = this.checked ? 'inline-flex' : 'none';
		});
		el('notes-due-clear').addEventListener('click', function () { el('notes-due').value = ''; });
		el('notes-save').addEventListener('click', saveNote);
		// Ctrl+S (Linux/Win) / Cmd+S (Mac) saves the open note instead of the
		// browser's "save page" dialog. Document-level so it works whether focus
		// is in the editor, the title field, or anywhere on the page.
		document.addEventListener('keydown', function (e) {
			if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 's' || e.key === 'S')) {
				if (state.notePath) { e.preventDefault(); saveNote(); }
			}
		});
		el('notes-delete').addEventListener('click', deleteNote);
		el('notes-search').addEventListener('input', renderList);
		el('notes-show-footer').addEventListener('change', function () {
			el('notes-footer-view').style.display = this.checked ? 'block' : 'none';
		});
		var tagInput = el('notes-tags-input');
		tagInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addEditorTag(tagInput.value); tagInput.value = ''; }
		});
		tagInput.addEventListener('change', function () { if (tagInput.value) { addEditorTag(tagInput.value); tagInput.value = ''; } });
		// Math toolbar buttons: data-mb holds "before|after" delimiters.
		document.querySelectorAll('#notes-math-bar button[data-mb]').forEach(function (b) {
			b.addEventListener('click', function () {
				var parts = b.dataset.mb.split('|');
				var before = parts[0], after = parts[1] || '';
				if (b.dataset.block) { before += '\n'; after = '\n' + after; } // block math on its own lines
				insertMath(before, after);
			});
		});
		Promise.all([loadTree(), loadTemplates()]).then(selectAll).catch(showError);
	});
})();
