# Notes

A Nextcloud app for markdown notes kept as **ordinary `.md` files in a real folder
tree**, designed to stay in sync with [Joplin](https://joplinapp.org/) on desktop
and mobile.

This is a ground-up NC34 rebuild of an older ownCloud notes app. The guiding idea
is that your notes should remain plain, portable files you can edit anywhere — not
rows in a database or content hidden behind per-item sidecar files. Nextcloud
provides the web UI that Joplin lacks; Joplin provides the offline editing and
sync that the web lacks.

## How notes are stored

* **Notebooks are folders.** Nesting works to any depth.
* **A note is a `.md` file** whose first line is the title, followed by the body,
  followed by a short Joplin-compatible footer of `key: value` lines:

  ```markdown
  Shopping list

  - milk
  - eggs

  id: 9f1c3a8e5b7d4f2a8c6e0b1d2f3a4b5c
  created_time: 2026-06-17T09:12:00.000Z
  updated_time: 2026-06-18T14:03:11.000Z
  is_todo: 1
  todo_due: 2026-06-20T17:00:00.000Z
  tags: groceries, home
  ```

  The footer is the **single source of truth for a note's tags**. Unknown Joplin
  keys (e.g. `latitude`, `markup_language`) are preserved untouched, so round-trips
  through this app are lossless.
* **`Templates/`** is an ordinary visible folder at the notes root, excluded from
  the notebook tree.
* **`attachments/`** — one per notebook, at the root of each top-level notebook
  (and one at the notes root for notes lying directly there); excluded from the
  notebook tree at any level. Per notebook rather than one per user so a notebook
  is **self-contained**: with a single root-level folder, a link like
  `../attachments/x.png` resolves — for every member of a *shared* notebook — to
  their own notes root, where the file is not, so only the member who inserted an
  image could see it. It also lets a notebook be published, deposited or handed
  over as one folder. Inbound Joplin resources are moved into the referencing
  note's notebook when the note arrives. Resource links are converted in both
  directions for **markdown links and HTML `src=`/`href=` attributes** — web
  clippings embed their images as `<img src=":/<id>">`, and handling only
  markdown left those unresolvable outside Joplin (and invisible to anything
  scanning markdown links, which is what once made the garbage collector think
  they were orphaned).
* There are **no hidden per-item dotfiles** — everything you see in Files is what
  the app sees.

## Features

* Three-pane web UI: notebook tree + tag list, note list (with search, todo state,
  tag chips), and an EasyMDE markdown editor.
* **Editor view modes** on one toolbar button: edit · side-by-side · rendered, so
  you can browse rendered notes, not just edit them. The editor follows your
  Nextcloud dark/light theme.
* **LaTeX & chemistry** in the preview via a self-hosted MathJax (all-packages
  build incl. `mhchem`): `$…$`, `$$…$$`, `\(…\)`, `\[…\]`, `\ce{}`, `\pu{}`.
* **Tags** are colour-matched to Nextcloud system tags and kept in sync both ways
  (see below). Add tags from the editor (with autocomplete over the full tag
  vocabulary) or by dragging notes onto a tag.
* **Drag & drop**: drag notes onto a notebook to move them, or onto a tag to assign
  it; select multiple with the checkboxes and drag the whole set. Drag a notebook
  onto another to nest it, or onto the **Notebooks** header (or **All notes**) to
  move it back to the top level.
* **Templates** with variable substitution (`%date%`, `%me%`, `%place%`) and an
  optional `tags:` line that auto-tags new notes. Three templates ship by
  default — **to-do**, **lab notebook** and **science note**, the terms most
  researchers already use; add your own to `Templates/`. The lab-notebook
  template fills the seeded `lab_notebook` schema (`project`, `date`), so those
  two become the overview columns when the list is filtered on that tag.
  Dropped from the bundle 2026-09-19: recipe and diary (personal-life templates
  on a research service) and lab log / lab note (merged into lab notebook).
  A user's existing copies in their own `Templates/` folder are untouched.
* Insert images by picking an existing file from Nextcloud or uploading into
  `attachments/`.

## History of a note

The editor's **History** button lists the note's earlier versions with their
time, size and **author**, and offers *View* (read-only) and *Restore*. There is
no bookkeeping of our own behind it: Nextcloud stores a version on every write
through **any** path — this app, Joplin, WebDAV, the sync client — and core's
`VersionAuthorListener` records who made it. The button reads the versions DAV
API (`/remote.php/dav/versions/<uid>/versions/<fileid>`, asking for
`nc:version-author`) and restores by MOVEing a version onto
`…/versions/<uid>/restore/target`, exactly as the Files sidebar does. Restoring
keeps the replaced text as a version of its own, so nothing is lost. A blank
author means the write had no logged-in user (a daemon, the importer, a CLI run)
or the row was reconstructed by core from version files found on disk.

## Tags & metadata

### Attachments are cleaned up when a note is deleted

Deleting a note or a notebook deletes the attachments it referenced, unless some
remaining note still references them (`NotesService::cleanupAttachments`, called
from the web UI, the bulk endpoints and the Joplin delete path). It is scoped on
purpose: only the deleted item's own attachments can ever be removed, so a gap in
the link scanner cannot reach the rest of the collection. Two further guards:
nothing is deleted when a reference anywhere cannot be resolved, and a file the
index has no row for is never deleted, because without its identity a `:/id`
link may well mean it.

The full sweep (`gcOrphanAttachments`, OCS `POST /gc`) is **no longer automatic**.
It remains for the one case a scoped cleanup cannot see: an image unlinked by
*editing* a note rather than deleting it.

Tags live in the note footer (authoritative) and are mirrored to Nextcloud's core
**system tags** — the same tags shown in the Files sidebar:

* Saving or tagging in this app pushes the footer tags to system tags.
* Changing a note's tags via the Files sidebar (or the optional `meta_data` app)
  pushes back into the footer, driven by tag-assignment events.

The app uses **core system tags only**, so it works with no extra apps installed.
If the [`meta_data`](https://github.com/deic-cloud/meta_data) app is present, it
adds an optional layer of *typed* tag attributes on top of the same system tags
(useful for lab-notebook–style structured fields). Those typed attributes do not
travel in the file and do not map to Joplin — they are a power-user layer.

**This app never creates or changes metadata fields** — the Metadata app owns
the schemas. A template's variables only (a) get stored as metadata values on
the new note where one of its `template_tags` already has a field of that name,
and (b) select which of the tag's existing fields are shown as columns when the
list is filtered on that tag — only those; a tag no template describes shows no
columns (a schema may have a dozen fields; columns are for overview).
A variable without a matching field simply fills the note body.

> **Caveat:** if you edit a note's footer `tags:` line directly on disk (outside
> this app), the change reconciles into system tags only the next time the app
> reads that note.

## Known limitation: markdown vs. LaTeX `_`

In the preview, markdown is rendered *before* MathJax. A single subscript like
`$C_p$` is fine, but a pair of underscores inside inline math (e.g. `$a_b_c$`) can
be turned into `<em>` emphasis by the markdown step before MathJax sees it. Use
braces (`C_{p}`) or the `\(…\)` delimiters to be safe.

## Joplin synchronisation

Joplin's WebDAV target is `https://<host>/remote.php/notes/` (a remote service,
`appinfo/notes.php`, which accepts HTTP Basic auth with an app/device password
and dispatches to `Controller/WebDavController`); the same controller also
answers at `/index.php/apps/markdown_notes/joplin`. The Notes app shows the
address in its "Joplin sync URL" box.

The on-disk format above is Joplin-compatible by design. A fast sync layer backed
by an id↔path index is planned (Phase 2); the acceptance target is migrating a
real 200+ note collection from an existing service through a fresh Joplin install
into this app.

## Requirements

* Nextcloud 34+
* PHP 8.2+

No build step: the frontend is plain JavaScript over the OCS API, and EasyMDE,
FontAwesome and MathJax are bundled (no CDNs).

## Licence

AGPL-3.0-or-later.

## Files integration: the click action for Markdown files

Clicking a `.md` file in the Files app opens it in this app's EasyMDE editor —
plain Markdown source with light styling, no preview pane — instead of the
Text app's rich-text editor (`src/files-editor.js` → `js/files-editor.js`,
loaded with EasyMDE by `Listener/LoadFilesScriptsListener`). The action is
registered as the default for `text/markdown` with order −100, so it wins over
the Viewer/Text action; Text remains available in the file's "…" menu. Reading
and saving go over WebDAV (the node's own address); saves send `If-Match` with
the file's ETag, so a concurrent change gives a conflict message instead of a
silent overwrite. Ctrl/Cmd+S saves, Escape closes (asks if unsaved). Read-only
files open read-only. Build: `webpack` via the sibling `user_group_admin`
node_modules, like files_publish (`package.json`, `webpack.config.js`).
