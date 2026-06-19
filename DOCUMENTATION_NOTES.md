# Documentation notes — markdown_notes

Working notes toward the eventual user and admin documentation. Not user-facing
yet; this is the source material (features, behaviours, caveats, gotchas) to be
turned into proper docs later. Kept current as the app evolves.

App id: `markdown_notes` · display name: **Notes** · repo: `deic-cloud/markdown_notes`.

---

## 1. What it is

A Nextcloud web UI for markdown notes stored as **ordinary `.md` files in a real
folder tree**, designed to stay in sync with [Joplin](https://joplinapp.org/) on
desktop/mobile. Nextcloud provides the web editing Joplin lacks; Joplin provides
offline/mobile sync. Nothing is hidden in a database or sidecar dotfiles — the
files are the source of truth and remain editable anywhere.

## 2. Data model (document for users)

- **Notebooks are folders** (nest to any depth).
- **A note is a `.md` file**: first line = title, blank line, body, then a short
  trailing Joplin-compatible **footer** of `key: value` lines:
  ```
  id: <32 hex>
  created_time: 2026-06-17T09:12:00.000Z
  updated_time: 2026-06-18T14:03:11.000Z
  is_todo: 1
  todo_due: 1750000000000
  todo_completed: 0
  tags: groceries, home
  ```
- The footer is detected only when it contains an `id:` line; **unknown Joplin
  keys are preserved losslessly** (latitude, markup_language, …).
- The footer `tags:` line is the **single source of truth** for a note's tags.
- `Templates/` and `attachments/` are ordinary **visible** folders, excluded from
  the notebook tree.
- Timestamps: `created_time`/`updated_time` are ISO-8601 UTC; `todo_due` /
  `todo_completed` are **epoch milliseconds** (Joplin convention; 0/absent = not
  set / not completed).

## 3. Web UI

- Three panes: navigation (notebooks + tags), notes list, editor.
- Editor view modes on one toolbar button: **edit · side-by-side · rendered**
  (browse rendered notes, not just edit). Follows the NC dark/light theme.
- **Ctrl+S / Cmd+S** saves the open note.
- Search box filters the current list; **sort** dropdown: Updated / Created /
  Title / Due date.

## 4. Tags

- Footer is canonical; tags are mirrored to NC **system tags** (the Files-sidebar
  surface) bidirectionally and loop-safe. Works with no extra apps.
- Tag colours in the app come from the system tags.
- Add tags from the editor (chips + autocomplete over the whole tag vocabulary)
  or by dragging notes onto a tag pill.
- **A tag is a *filter*, not a separate view:** select a notebook (or "All
  notes") as the context, then click a tag to refine within it; click it again to
  clear. The list header shows "Notebook · #tag".
- Caveat to document: if you edit a note's footer `tags:` line **directly on disk**
  (outside this app), the change reconciles into system tags only the next time
  the app reads that note.

## 5. To-dos

- A to-do is a note with `is_todo: 1` — the body renders identically; only the
  list treatment differs (Joplin's model).
- Create with the **New to-do** button (any template can be used for either a
  note or a to-do — to-do-ness is chosen at creation, not in the template).
- Editor has a **To-do** toggle and a **due-date** picker.
- In the list: a clickable ☐/☑ box (right edge) marks done/undone; the due date
  shows with an **overdue** highlight; sort by **Due date** floats dated, open
  to-dos to the top.

## 6. Templates (Joplin-compatible)

Templates use the format of Joplin's Templates plugin, so the same `.md` works in
both apps. Stored in the visible `Templates/` folder; six ship by default.

- **YAML front matter** (`---` delimited):
  - `template_title:` — the new note's title (may contain variables)
  - `template_tags:` — comma-separated tags
  - **custom variables** — `name: type` (simple) or `name:` + indented
    `label:`/`type:` (advanced). Types: `text`, `number`, `boolean`, `date`,
    `time`, `dropdown(a, b, c)`. The user is prompted for these at creation.
- **Built-in variables** (Handlebars `{{ }}`): `{{date}}`, `{{time}}`,
  `{{#custom_datetime}}<moment.js format>{{/custom_datetime}}`, `{{bowm}}`/`{{bows}}`
  (beginning of week Mon/Sun), `{{eowm}}`/`{{eows}}` (end of week). Unknown
  variables are left literal.
- **Portability caveat to document:** the template *file format* is portable, but
  Joplin keeps its templates outside the synced note tree (in its profile's
  `templates/` dir), so a template placed in our `Templates/` folder does **not**
  automatically appear in Joplin — it's a copy-the-file affair.
- Legacy `%date%`/`%time%` tokens from pre-Joplin templates are still substituted
  (so old templates keep working); new templates should use `{{ }}`.

## 7. Math & chemistry (rendered preview)

- Delimiters: inline `$…$` or `\(…\)`; display (block) `$$…$$` or `\[…\]`.
- **Chemistry (mhchem):** `\ce{…}` and `\pu{…}` inside math, e.g.
  `$\ce{2 H2 + O2 -> 2 H2O}$`. Self-hosted MathJax **full build**
  (`tex-chtml-full.js`) — mhchem is inlined, fully offline (no CDN).
- A bare display block can contain **blank lines** (for readability) and bare
  `\\` for line breaks — the app protects math spans from the markdown step and
  auto-wraps a `\\`-using block in `gathered` (or `aligned` if it uses `&`).
- Known limitation to document: **`_` collision.** Math spans are protected from
  markdown, so `$a_b_c$` is fine now; if you ever see a subscript turn into
  emphasis, use braces `a_{b}` or `\(…\)`.
- **chemfig is NOT supported** (it needs TikZ/PGF, outside MathJax's scope). Only
  mhchem-style formulae/equations render, not 2D structural diagrams. (A future
  option would be a SMILES-based JS renderer; not implemented.)

## 8. Typed metadata columns (optional, via the meta_data app)

- If the optional [`meta_data`](https://github.com/deic-cloud/meta_data) app is
  installed and the active **tag filter** has typed fields defined, the notes
  list expands full-width over the editor as an **editable table** — one column
  per field. `controlled` fields render as a dropdown (their allowed values),
  others as a text input; edits save immediately. Opening a note gives it the
  full width with a "← List" button back to the table.
- Without meta_data (or for tags with no fields) there are no columns — the app
  is fully usable on its own.
- These typed attributes live in meta_data's tables (keyed by the system-tag id),
  **not** in the note file, and do **not** map to Joplin.

## 9. Images / attachments

- The editor's image button inserts an existing Nextcloud file (picker) or
  uploads into the note folder's `attachments/`.

## 10. Admin / install notes

- Requirements: Nextcloud 34+, PHP 8.2+. **No build step** — plain JS over the
  OCS API; EasyMDE, FontAwesome and MathJax are bundled (no CDNs).
- Optional: `meta_data` (typed metadata columns). The integration is guarded, so
  the app installs and runs without it.
- The note folder defaults to `Notes/` (per-user, configurable via the
  `markdown_notes` `notesdir` user value).

## 11. Planned — Joplin sync (Phase 2)

Not yet built. A fast sync endpoint backed by an **id↔path index** (rebuildable by
scanning footers), preserving the on-disk format, presenting the human folder
tree as Joplin's id-based store. Acceptance target: migrate a 200+ note
collection existing-service → fresh Joplin → this app.
