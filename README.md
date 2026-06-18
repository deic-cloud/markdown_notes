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
* **`Templates/` and `attachments/`** are ordinary visible folders, excluded from
  the notebook tree.
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
  optional `tags:` line that auto-tags new notes. Six templates ship by default
  (todo, diary, lab log, lab note, recipe, science note); add your own to
  `Templates/`.
* Insert images by picking an existing file from Nextcloud or uploading into
  `attachments/`.

## Tags & metadata

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

> **Caveat:** if you edit a note's footer `tags:` line directly on disk (outside
> this app), the change reconciles into system tags only the next time the app
> reads that note.

## Known limitation: markdown vs. LaTeX `_`

In the preview, markdown is rendered *before* MathJax. A single subscript like
`$C_p$` is fine, but a pair of underscores inside inline math (e.g. `$a_b_c$`) can
be turned into `<em>` emphasis by the markdown step before MathJax sees it. Use
braces (`C_{p}`) or the `\(…\)` delimiters to be safe.

## Joplin synchronisation

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
