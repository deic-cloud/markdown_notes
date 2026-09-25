/**
 * Files integration: clicking a Markdown file opens it in the same EasyMDE
 * editor the Notes app uses — plain source with light styling, no preview
 * pane — instead of the Text app's rich-text editor.
 *
 * Registered as the DEFAULT action for text/markdown with a low order, so it
 * wins over the Viewer/Text action on click; Text stays available under the
 * file's "…" menu. The file is read and written over WebDAV (the node's own
 * DAV address); saves carry If-Match with the ETag so a concurrent change
 * results in a conflict message instead of a silent overwrite.
 *
 * Bundled (registerFileAction is not exposed to plain JS). EasyMDE itself is
 * the app's js/easymde.min.js, loaded alongside by LoadFilesScriptsListener.
 */
import { registerFileAction, DefaultType, Permission } from '@nextcloud/files'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

const APP = 'markdown_notes'
const MIMES = ['text/markdown', 'text/x-markdown']

const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M20.56 18H3.44C2.65 18 2 17.37 2 16.59V7.41C2 6.63 2.65 6 3.44 6h17.12c.79 0 1.44.63 1.44 1.41v9.18c0 .78-.65 1.41-1.44 1.41M6.81 15.19v-3.66l1.92 2.35 1.92-2.35v3.66h1.93V8.81h-1.93l-1.92 2.35-1.92-2.35H4.89v6.38h1.92M19.69 12h-1.92V8.81h-1.92V12h-1.93l2.89 3.28L19.69 12"/>'
	+ '</svg>'

function el(tag, attrs, children) {
	const e = document.createElement(tag)
	for (const k in (attrs || {})) {
		if (k === 'text') e.textContent = attrs[k]
		else e.setAttribute(k, attrs[k])
	}
	;(children || []).forEach((c) => e.appendChild(c))
	return e
}

function davHeaders(extra) {
	return Object.assign({ requesttoken: window.OC?.requestToken || '' }, extra || {})
}

async function load(node) {
	const r = await fetch(node.source, { headers: davHeaders(), credentials: 'same-origin' })
	if (!r.ok) throw new Error(t(APP, 'Could not read the file') + ' (' + r.status + ')')
	return { text: await r.text(), etag: r.headers.get('ETag') || '' }
}

async function save(node, text, etag) {
	const headers = davHeaders({ 'Content-Type': 'text/markdown; charset=utf-8' })
	if (etag) headers['If-Match'] = etag
	const r = await fetch(node.source, { method: 'PUT', headers, body: text, credentials: 'same-origin' })
	if (r.status === 412) throw Object.assign(new Error(t(APP, 'The file was changed by someone else meanwhile. Copy your text, reload the file and paste it back.')), { conflict: true })
	if (!r.ok) throw new Error(t(APP, 'Could not save the file') + ' (' + r.status + ')')
	return r.headers.get('ETag') || ''
}

// ── Images and media ─────────────────────────────────────────────────────
// Inserted as links RELATIVE to the Markdown file, which is what a site served
// by the Websites app (or any Markdown renderer) resolves. Markdown has image
// syntax only; sound and video go in as HTML elements.
const MEDIA_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp', 'image/bmp', 'image/avif',
	'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav',
	'audio/webm', 'audio/flac', 'audio/mp4', 'audio/aac']
const EXT_KIND = { mp4: 'video', m4v: 'video', webm: 'video', ogv: 'video', mov: 'video', mp3: 'audio', ogg: 'audio', oga: 'audio', wav: 'audio', flac: 'audio', m4a: 'audio', aac: 'audio' }

function dirOf(path) { return path.replace(/\/[^/]*$/, '') || '' }

/** Path of $target relative to directory $fromDir (both user-root paths). */
function relativePath(fromDir, target) {
	const from = fromDir.split('/').filter(Boolean)
	const to = target.split('/').filter(Boolean)
	let i = 0
	while (i < from.length && i < to.length - 1 && from[i] === to[i]) i++
	return [...Array(from.length - i).fill('..'), ...to.slice(i)].map(encodeURIComponent).join('/')
}

// Web pages (the Websites app) get the service's image classes, the same in
// every theme: a sized image that opens full size on click. Notes do not —
// Joplin and the Notes preview would show the {…} as text.
let NOTES_DIR = 'Notes'
try { NOTES_DIR = loadState(APP, 'notes_folder', 'Notes') } catch (e) { /* default */ }
function isNote(node) {
	return ('/' + (node.path || '').replace(/^\/+/, '')).startsWith('/' + NOTES_DIR + '/')
}

function mediaMarkup(name, rel, mime, web) {
	const ext = (name.split('.').pop() || '').toLowerCase()
	const kind = (mime || '').startsWith('video/') ? 'video' : (mime || '').startsWith('audio/') ? 'audio' : (EXT_KIND[ext] || 'image')
	if (kind === 'video') return '<video controls' + (web ? ' class="medium-image"' : '') + ' src="' + rel + '"></video>'
	if (kind === 'audio') return '<audio controls src="' + rel + '"></audio>'
	const alt = name.replace(/\.[^.]*$/, '').replace(/[[\]]/g, '')
	return '![' + alt + '](' + rel + ')' + (web ? '{.modal-image .small-image}' : '')
}

function pickMedia(node, insert) {
	const d = window.OC?.dialogs
	if (!d?.filepicker) return
	d.filepicker(t(APP, 'Insert image or media'), (path) => {
		if (!path) return
		insert(mediaMarkup(path.split('/').pop(), relativePath(dirOf(node.path), path), '', !isNote(node)))
	}, false, MEDIA_MIMES, true, d.FILEPICKER_TYPE_CHOOSE || 1, dirOf(node.path) || '/')
}

/** Upload next to the Markdown file; an existing name gets " (2)", " (3)", … */
function uploadMedia(node, insert, report) {
	const input = document.createElement('input')
	input.type = 'file'
	input.accept = 'image/*,video/*,audio/*'
	input.addEventListener('change', async () => {
		const file = input.files && input.files[0]
		if (!file) return
		const base = node.source.replace(/\/[^/]*$/, '')
		const clean = file.name.replace(/[\\/]/g, '_')
		const stem = clean.replace(/(\.[^.]*)?$/, '')
		const ext = clean.slice(stem.length)
		report(t(APP, 'Uploading…'))
		try {
			for (let n = 1; n < 100; n++) {
				const name = n === 1 ? clean : stem + ' (' + n + ')' + ext
				const r = await fetch(base + '/' + encodeURIComponent(name), {
					method: 'PUT', body: file, credentials: 'same-origin',
					headers: davHeaders({ 'If-None-Match': '*', 'Content-Type': file.type || 'application/octet-stream' }),
				})
				if (r.status === 412) continue   // taken: try the next name
				if (!r.ok) throw new Error(t(APP, 'Upload failed') + ' (' + r.status + ')')
				insert(mediaMarkup(name, encodeURIComponent(name), file.type, !isNote(node)))
				report('')
				return
			}
			throw new Error(t(APP, 'Upload failed'))
		} catch (e) {
			report(e.message, true)
		}
	})
	input.click()
}

// ── Find in the note (Ctrl/⌘+F inside the editor) ────────────────────────
// Nextcloud binds Ctrl+F to its own search; inside the editor it should
// search the text being edited — all of it: CodeMirror draws only the
// visible lines, so the browser's own find would miss the rest. Uses the
// searchcursor addon bundled with EasyMDE. Elsewhere on the page Ctrl+F is
// left alone.
function installFind(cm, host, label) {
	var bar = null, input = null, count = null, marks = [], last = null;
	function clearMarks() { marks.forEach(function (m) { m.clear(); }); marks = []; }
	function matches(q) {
		var out = [];
		if (!q) { return out; }
		var c = cm.getSearchCursor(q, { line: 0, ch: 0 }, { caseFold: true });
		while (c.findNext() && out.length < 5000) { out.push({ from: c.from(), to: c.to() }); }
		return out;
	}
	function cmp(a, b) { return a.line - b.line || a.ch - b.ch; }
	function go(back) {
		var q = input.value;
		clearMarks();
		var all = matches(q);
		all.forEach(function (m) { marks.push(cm.markText(m.from, m.to, { className: 'mdn-find-hit' })); });
		if (!all.length) { count.textContent = q ? label.none : ''; bar.classList.toggle('mdn-find-miss', !!q); return; }
		bar.classList.remove('mdn-find-miss');
		var i = -1, k;
		if (last) {
			for (k = 0; k < all.length; k++) { if (cmp(all[k].from, last.from) === 0) { i = k; break; } }
		}
		if (i >= 0) {
			i = back ? (i - 1 + all.length) % all.length : (i + 1) % all.length;
		} else if (back) {
			var from = cm.getCursor('from');
			for (i = all.length - 1; i >= 0 && cmp(all[i].to, from) > 0; i--) { /* last match before the cursor */ }
			if (i < 0) { i = all.length - 1; }
		} else {
			var to = cm.getCursor('from');
			for (i = 0; i < all.length && cmp(all[i].from, to) < 0; i++) { /* first match from the cursor */ }
			if (i >= all.length) { i = 0; }
		}
		last = all[i];
		cm.setSelection(last.from, last.to);
		marks.push(cm.markText(last.from, last.to, { className: 'mdn-find-current' }));
		cm.scrollIntoView({ from: last.from, to: last.to }, 60);
		count.textContent = label.of.replace('{n}', String(i + 1)).replace('{total}', String(all.length));
	}
	function open() {
		if (!bar) {
			bar = document.createElement('div');
			bar.className = 'mdn-find';
			input = document.createElement('input');
			input.type = 'search';
			input.placeholder = label.placeholder;
			input.setAttribute('aria-label', label.placeholder);
			count = document.createElement('span');
			count.className = 'mdn-find-count';
			var prev = document.createElement('button'); prev.type = 'button'; prev.textContent = '↑'; prev.title = label.prev;
			var next = document.createElement('button'); next.type = 'button'; next.textContent = '↓'; next.title = label.next;
			var x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.title = label.close;
			bar.appendChild(input); bar.appendChild(count); bar.appendChild(prev); bar.appendChild(next); bar.appendChild(x);
			input.addEventListener('input', function () { last = null; go(false); });
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); go(e.shiftKey); }
				else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
			});
			prev.addEventListener('click', function () { go(true); });
			next.addEventListener('click', function () { go(false); });
			x.addEventListener('click', close);
			host.appendChild(bar);
		}
		var sel = cm.getSelection();
		if (sel && sel.indexOf('\n') < 0) { input.value = sel; }
		bar.style.display = '';
		input.focus();
		input.select();
		last = null;
		if (input.value) { go(false); }
	}
	function close() {
		if (!bar) { return; }
		clearMarks();
		bar.style.display = 'none';
		cm.focus();
	}
	// Window, capture phase: runs before Nextcloud's own Ctrl+F handler.
	function onKey(e) {
		if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'f' || e.key === 'F')
			&& (host.contains(document.activeElement) || cm.hasFocus())) {
			e.preventDefault();
			e.stopImmediatePropagation();
			open();
		}
	}
	window.addEventListener('keydown', onKey, true);
	return { open: open, destroy: function () { window.removeEventListener('keydown', onKey, true); clearMarks(); if (bar) { bar.remove(); } } };
}

function openEditor(node) {
	if (typeof window.EasyMDE !== 'function') {
		window.OC?.dialogs?.alert?.(t(APP, 'The Markdown editor did not load. Please reload the page.'), t(APP, 'Markdown'))
		return
	}
	const canWrite = (node.permissions & Permission.UPDATE) !== 0
	let etag = ''
	let dirty = false
	let mde = null
	let find = null

	const overlay = el('div', { class: 'mdn-overlay', role: 'dialog', 'aria-label': node.basename })
	const box = el('div', { class: 'mdn-editor' })
	const title = el('span', { class: 'mdn-title', text: node.basename })
	const state = el('span', { class: 'mdn-state' })
	const msg = el('span', { class: 'mdn-msg' })
	const saveBtn = el('button', { class: 'primary mdn-save', text: t(APP, 'Save') })
	const closeBtn = el('button', { class: 'mdn-close', text: t(APP, 'Close') })
	const head = el('div', { class: 'mdn-head' }, [title, state, msg, saveBtn, closeBtn])
	const textarea = el('textarea', { class: 'mdn-textarea' })
	box.appendChild(head)
	box.appendChild(el('div', { class: 'mdn-body' }, [textarea]))
	overlay.appendChild(box)
	if (!canWrite) {
		saveBtn.style.display = 'none'
		state.textContent = t(APP, 'read-only')
	}

	const setDirty = (d) => {
		dirty = d
		if (canWrite) state.textContent = d ? t(APP, 'unsaved changes') : ''
		saveBtn.disabled = !d
	}

	const insert = (markup) => {
		if (!mde) return
		const cm = mde.codemirror
		cm.replaceSelection(markup)
		cm.focus()
	}
	const report = (text, isError) => {
		msg.textContent = text
		msg.classList.toggle('mdn-error', !!isError)
	}

	async function doSave() {
		if (!canWrite || !dirty) return
		msg.textContent = t(APP, 'Saving…')
		msg.classList.remove('mdn-error')
		try {
			const text = mde.value()
			etag = await save(node, text, etag)
			setDirty(false)
			msg.textContent = t(APP, 'Saved')
			setTimeout(() => { if (msg.textContent === t(APP, 'Saved')) msg.textContent = '' }, 2000)
			try {
				node.mtime = new Date()
				node.size = new Blob([text]).size
				emit('files:node:updated', node)
			} catch (e) { /* list refresh is cosmetic */ }
		} catch (e) {
			msg.textContent = e.message
			msg.classList.add('mdn-error')
		}
	}

	function close() {
		if (dirty && !window.confirm(t(APP, 'You have unsaved changes. Close anyway?'))) return
		document.removeEventListener('keydown', onKey, true)
		if (find) { find.destroy(); find = null }
		if (mde) { try { mde.toTextArea() } catch (e) { /* ignore */ } }
		overlay.remove()
	}

	function onKey(ev) {
		if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
			ev.preventDefault(); ev.stopPropagation(); doSave()
		} else if (ev.key === 'Escape') {
			ev.preventDefault(); ev.stopPropagation(); close()
		}
	}

	saveBtn.addEventListener('click', doSave)
	closeBtn.addEventListener('click', close)
	overlay.addEventListener('mousedown', (ev) => { if (ev.target === overlay) close() })
	document.addEventListener('keydown', onKey, true)
	document.body.appendChild(overlay)
	msg.textContent = t(APP, 'Loading…')

	load(node).then(({ text, etag: e }) => {
		etag = e
		textarea.value = text
		mde = new window.EasyMDE({
			element: textarea,
			autoDownloadFontAwesome: false,
			spellChecker: false,
			status: false,
			autofocus: true,
			lineNumbers: false,
			// Source editing only: no preview, no side-by-side, no fullscreen —
			// what a page finally looks like is decided by where it is served.
			toolbar: canWrite ? [
				'bold', 'italic', 'heading', '|', 'quote', 'unordered-list', 'ordered-list', '|',
				'link',
				{ name: 'media', className: 'fa fa-image', title: t(APP, 'Insert image or media from your files'), action: () => pickMedia(node, insert) },
				{ name: 'upload', className: 'fa fa-upload', title: t(APP, 'Upload an image or media file next to this file'), action: () => uploadMedia(node, insert, report) },
				'table', 'code', '|', 'guide',
			] : false,
		})
		if (!canWrite) mde.codemirror.setOption('readOnly', true)
		find = installFind(mde.codemirror, box, {
			placeholder: t(APP, 'Find in this file'), none: t(APP, 'No matches'),
			of: t(APP, '{n} of {total}'), prev: t(APP, 'Previous (Shift+Enter)'),
			next: t(APP, 'Next (Enter)'), close: t(APP, 'Close (Esc)'),
		})
		mde.codemirror.on('change', () => { if (!dirty) setDirty(true) })
		setDirty(false)
		msg.textContent = ''
	}).catch((e) => {
		msg.textContent = e.message
		msg.classList.add('mdn-error')
	})
}

registerFileAction({
	id: 'markdown-notes-edit',
	displayName: () => t(APP, 'Edit Markdown'),
	title: () => t(APP, 'Open in the Markdown editor'),
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => nodes.length === 1 && MIMES.includes(nodes[0].mime)
		&& (nodes[0].permissions & Permission.READ) !== 0,
	exec: async ({ nodes }) => { openEditor(nodes[0]); return null },
	// The click action: sorted by order, the first action with a default wins;
	// the Viewer's (Text's) action has order 0.
	default: DefaultType.DEFAULT,
	order: -100,
})
