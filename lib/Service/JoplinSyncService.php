<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\Files\Folder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Translates Joplin sync items <-> our world, backed by the
 * markdown_notes_joplin index (jid -> note/folder path, systemtag, link, or
 * resource). The WebDavController calls this for `<32hex>.md` item paths;
 * opaque files (info.json, locks/, temp/, .resource/<id>) stay in JoplinStore.
 *
 *   type 1 note   -> a .md file in the tree (our footer format; jid = footer id)
 *   type 2 folder -> a notebook (directory); jid tracked in the index
 *   type 5 tag    -> an NC system tag; jid -> systemtag id
 *   type 6 link   -> a note<->tag assignment; jid -> (note jid, tag jid)
 *   type 4 resource + .resource/<id> blob -> kept verbatim (shown inline later)
 *
 * Enumeration for PROPFIND comes from the index, so every item we have stored
 * is listed with its updated_time as getlastmodified.
 */
class JoplinSyncService {
	/** Notes-root folder that holds image/file resources (a visible, non-notebook dir). */
	private const ATTACH_DIR = 'attachments';

	public function __construct(
		private NotesService $notesService,
		private SystemTagSync $systemTagSync,
		private JoplinStore $store,
		private JoplinIndex $index,
		private LoggerInterface $logger,
	) {
	}

	public function isItemPath(string $path): bool {
		return (bool)preg_match('/^[0-9a-f]{32}\.md$/', $path);
	}

	public function jidFromPath(string $path): string {
		return substr($path, 0, 32);
	}

	// ── PUT ────────────────────────────────────────────────────────────────

	/** Materialise an incoming Joplin item by type. Returns false on failure. */
	public function putItem(string $uid, string $jid, string $raw): bool {
		try {
			$f = JoplinItem::parse($raw);
			$type = (int)($f['type_'] ?? 0);
			switch ($type) {
				case JoplinItem::TYPE_NOTE:   $this->putNote($uid, $jid, $f); break;
				case JoplinItem::TYPE_FOLDER: $this->putFolder($uid, $jid, $f); break;
				case JoplinItem::TYPE_TAG:    $this->putTag($uid, $jid, $f); break;
				case JoplinItem::TYPE_NOTE_TAG: $this->putLink($uid, $jid, $f); break;
				case JoplinItem::TYPE_RESOURCE: $this->materializeResource($uid, $jid, $f, $raw); break;
				default:
					// anything else: keep the item verbatim so the round-trip is
					// lossless; index it for enumeration.
					$this->indexUpsert($uid, $jid, $type ?: 0, '', '', '', '', $this->mtime($f), $raw);
			}
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('joplin putItem ' . $jid . ': ' . $e->getMessage(), ['app' => 'markdown_notes']);
			return false;
		}
	}

	private function putNote(string $uid, string $jid, array $f): void {
		$title = (string)($f['title'] ?? '');
		$body = (string)($f['body'] ?? '');
		$parentId = (string)($f['parent_id'] ?? '');
		$parentRel = $parentId !== '' ? $this->folderRel($uid, $parentId) : '';
		$folder = $this->notesService->getNotesFolder($uid);
		$dir = $parentRel === '' ? $folder : $this->ensureDir($folder, $parentRel);

		// Joplin `:/<id>` image links → portable relative paths on disk, computed
		// against the note's NEW location (parent from this item's parent_id) so a
		// cross-depth move from the Joplin client lands the right `../` prefix.
		// (Unresolved ids stay `:/id` and still render in our preview, converging
		// on the next reindex/edit.)
		$noteRel = ($parentRel === '' ? '' : $parentRel . '/') . $this->safeName($title !== '' ? $title : $jid) . '.md';
		$body = $this->bodyFromJoplin($uid, $noteRel, $body);

		// Build our footer, preserving Joplin's id/times/todo and any tags the
		// link items will (re)assert. Unmanaged Joplin keys are dropped here but
		// the canonical ones are kept so Joplin re-reads consistently.
		$meta = ['id' => $jid];
		foreach (['created_time', 'updated_time', 'is_todo', 'todo_due', 'todo_completed'] as $k) {
			if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== '0') {
				$meta[$k] = $f[$k];
			}
		}
		// Union the note's current footer tags with any tags from links Joplin
		// already pushed for this note — so a note↔tag link that arrived BEFORE
		// the note (bulk-import ordering) still lands its tag.
		$tags = array_values(array_unique(array_merge(
			$this->tagsForNote($uid, $jid),
			$this->tagsFromLinks($uid, $jid),
		)));
		if (!empty($tags)) {
			$meta = NoteFormat::withTags($meta, $tags);
		}

		$fname = $this->safeName($title !== '' ? $title : $jid) . '.md';
		$existingRel = $this->indexRelPath($uid, $jid);
		if ($existingRel !== null && $folder->nodeExists($existingRel)) {
			$node = $folder->get($existingRel);
			$node->putContent(NoteFormat::serialize($title, $body, $meta));
			// move if the parent folder changed
			$wantRel = ($parentRel === '' ? '' : $parentRel . '/') . basename($existingRel);
			if ($wantRel !== $existingRel) {
				$node->move($dir->getPath() . '/' . basename($existingRel));
				$existingRel = $wantRel;
			}
			$this->indexUpsert($uid, $jid, JoplinItem::TYPE_NOTE, $existingRel, $parentId, '', '', $this->mtime($f), '');
			return;
		}
		$fname = $this->uniqueChild($dir, $fname);
		$dir->newFile($fname, NoteFormat::serialize($title, $body, $meta));
		$rel = ($parentRel === '' ? '' : $parentRel . '/') . $fname;
		$this->indexUpsert($uid, $jid, JoplinItem::TYPE_NOTE, $rel, $parentId, '', '', $this->mtime($f), '');
	}

	private function putFolder(string $uid, string $jid, array $f): void {
		$title = $this->safeName((string)($f['title'] ?? $jid));
		$parentId = (string)($f['parent_id'] ?? '');
		// Parent may not have been uploaded yet → place at root for now; the
		// parent's own putFolder will re-home us when it arrives.
		$base = $parentId !== '' ? $this->folderRel($uid, $parentId) : '';
		$folder = $this->notesService->getNotesFolder($uid);
		$rel = ($base === '' ? '' : $base . '/') . $title;
		// Don't collide with a DIFFERENT folder already at this path (two folders
		// can share a title under different/unresolved parents). The real title is
		// kept in meta, so a temporary unique disk name is corrected on re-home.
		if ($folder->nodeExists($rel) && $this->index->folderJidByRel($uid, $rel) !== $jid) {
			$parentNode = $base === '' ? $folder : $this->ensureDir($folder, $base);
			$rel = ($base === '' ? '' : $base . '/') . $this->uniqueChild($parentNode, $title);
		}
		$this->ensureDir($folder, $rel);
		$this->indexUpsert($uid, $jid, JoplinItem::TYPE_FOLDER, $rel, $parentId, '', '', $this->mtime($f), $title);
		// Pull in any notes/subfolders that arrived before this folder existed.
		$this->rehomeChildren($uid, $jid, $rel);
	}

	/**
	 * Move notes/subfolders recorded as children of $folderJid (ref_id) into
	 * $folderRel — handles items that arrived before their parent folder, and
	 * cascades (moving a subfolder brings its whole subtree). Uses NotesService
	 * ::rename, which also rebases relative image links for the depth change.
	 */
	private function rehomeChildren(string $uid, string $folderJid, string $folderRel): void {
		$folder = $this->notesService->getNotesFolder($uid);
		if (!$folder->nodeExists($folderRel) || !($folder->get($folderRel) instanceof Folder)) {
			return;
		}
		$dest = $folder->get($folderRel);
		foreach ($this->index->childrenByParent($uid, $folderJid) as $child) {
			$cur = $child['rel_path'];
			if ($cur === '' || $cur === $folderRel || !$folder->nodeExists($cur)) {
				continue;
			}
			// Folders move under their REAL title (meta), correcting any temporary
			// collision-avoidance name; notes keep their filename.
			$name = ($child['type'] === JoplinItem::TYPE_FOLDER && $child['meta'] !== '')
				? $this->safeName($child['meta']) : basename($cur);
			if ($dest->nodeExists($name)) {
				$name = $this->uniqueChild($dest, $name);
			}
			$want = $folderRel . '/' . $name;
			if ($want === $cur) {
				continue;
			}
			try {
				$this->notesService->rename($uid, $cur, $want);
			} catch (\Throwable $e) {
				$this->logger->warning('joplin rehome ' . $cur . ' -> ' . $want . ': ' . $e->getMessage(), ['app' => 'markdown_notes']);
			}
		}
	}

	private function putTag(string $uid, string $jid, array $f): void {
		$name = trim((string)($f['title'] ?? ''));
		// Index the Joplin tag (per-user); do NOT auto-create a global system tag —
		// promotion is gated to template-referenced tags and handled by push().
		$this->indexUpsert($uid, $jid, JoplinItem::TYPE_TAG, '', '', '', '', $this->mtime($f), $name);
		if ($name === '') {
			return;
		}
		// A tag may arrive AFTER its note+link (when the link couldn't resolve the
		// name yet). Re-apply it to every linked note that now exists, so the
		// footer ends up correct regardless of upload order.
		$folder = $this->notesService->getNotesFolder($uid);
		foreach ($this->index->linksForTag($uid, $jid) as $lnk) {
			$rel = $this->indexRelPath($uid, $lnk['link_note']);
			if ($rel !== null && $folder->nodeExists($rel)) {
				try {
					$this->notesService->addTags($uid, $rel, [$name]);
				} catch (\Throwable $e) {
					// best effort
				}
			}
		}
	}

	/** Tag names from indexed note_tag links referencing this note (covers link-before-note ordering). */
	private function tagsFromLinks(string $uid, string $jid): array {
		$out = [];
		foreach ($this->index->linksForNote($uid, $jid) as $lnk) {
			$name = $this->tagName($uid, $lnk['link_tag']);
			if ($name !== null && $name !== '') {
				$out[] = $name;
			}
		}
		return $out;
	}

	private function putLink(string $uid, string $jid, array $f): void {
		$noteJid = (string)($f['note_id'] ?? '');
		$tagJid = (string)($f['tag_id'] ?? '');
		$this->indexUpsert($uid, $jid, JoplinItem::TYPE_NOTE_TAG, '', '', $noteJid, $tagJid, $this->mtime($f), '');
		// Apply the assignment to the note's footer (+ systemtag) if both ends known.
		$rel = $this->indexRelPath($uid, $noteJid);
		$tagName = $this->tagName($uid, $tagJid);
		if ($rel !== null && $tagName !== null) {
			try {
				$this->notesService->addTags($uid, $rel, [$tagName]);
			} catch (\Throwable $e) {
				// note may not exist yet; link will re-apply on next note read
			}
		}
	}

	// ── GET ────────────────────────────────────────────────────────────────

	/** Serialise an item back to Joplin form, or null if unknown. */
	public function getItem(string $uid, string $jid): ?string {
		$row = $this->indexRow($uid, $jid);
		if ($row === null) {
			return null;
		}
		$type = (int)$row['type'];
		if ($type === JoplinItem::TYPE_NOTE) {
			$folder = $this->notesService->getNotesFolder($uid);
			if (!$folder->nodeExists($row['rel_path'])) {
				return null;
			}
			$parsed = NoteFormat::parse($this->notesService->readContent($folder->get($row['rel_path'])));
			$parentRel = dirname($row['rel_path']);
			$created = $parsed['meta']['created_time'] ?? JoplinItem::msToTime((int)$row['updated_ms']);
			$updated = $parsed['meta']['updated_time'] ?? JoplinItem::msToTime((int)$row['updated_ms']);
			$fields = [
				'title' => $parsed['title'],
				'body' => $this->bodyToJoplin($uid, (string)$row['rel_path'], $parsed['body']),
				'id' => $jid,
				'parent_id' => $parentRel === '.' ? '' : $this->folderJid($uid, $parentRel),
				'created_time' => $created,
				'updated_time' => $updated,
				'user_created_time' => $created,
				'user_updated_time' => $updated,
				'is_todo' => !empty($parsed['meta']['is_todo']) ? '1' : '0',
				'todo_due' => $parsed['meta']['todo_due'] ?? '0',
				'todo_completed' => $parsed['meta']['todo_completed'] ?? '0',
				'type_' => JoplinItem::TYPE_NOTE,
			];
			return JoplinItem::serialize($fields);
		}
		if ($type === JoplinItem::TYPE_FOLDER) {
			$parentRel = dirname($row['rel_path']);
			$t = JoplinItem::msToTime((int)$row['updated_ms']);
			return JoplinItem::serialize([
				'title' => (string)($row['meta'] ?? '') !== '' ? (string)$row['meta'] : basename($row['rel_path']),
				'id' => $jid,
				'parent_id' => $parentRel === '.' ? '' : $this->folderJid($uid, $parentRel),
				'created_time' => $t,
				'updated_time' => $t,
				'user_created_time' => $t,
				'user_updated_time' => $t,
				'type_' => JoplinItem::TYPE_FOLDER,
			]);
		}
		if ($type === JoplinItem::TYPE_TAG) {
			$t = JoplinItem::msToTime((int)$row['updated_ms']);
			return JoplinItem::serialize([
				'title' => (string)$row['meta'],
				'id' => $jid,
				'created_time' => $t,
				'updated_time' => $t,
				'user_created_time' => $t,
				'user_updated_time' => $t,
				'type_' => JoplinItem::TYPE_TAG,
			]);
		}
		if ($type === JoplinItem::TYPE_NOTE_TAG) {
			$t = JoplinItem::msToTime((int)$row['updated_ms']);
			return JoplinItem::serialize([
				'id' => $jid,
				'note_id' => (string)$row['link_note'],
				'tag_id' => (string)$row['link_tag'],
				'created_time' => $t,
				'updated_time' => $t,
				'user_created_time' => $t,
				'user_updated_time' => $t,
				'type_' => JoplinItem::TYPE_NOTE_TAG,
			]);
		}
		if ($type === JoplinItem::TYPE_RESOURCE) {
			return $this->resourceItem($uid, $jid, $row);
		}
		$meta = (string)($row['meta'] ?? '');
		return $meta !== '' ? $meta : null;
	}

	/**
	 * Bytes of a resource. Resources are stored as REAL files under attachments/
	 * (rel_path on the index row); we read them straight off disk. Falls back to
	 * the .resource/<id> transport buffer for an inbound blob not yet materialised.
	 */
	public function resourceBlob(string $uid, string $id): ?string {
		if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
			return null;
		}
		$rel = $this->resourceFileRel($uid, $id);
		if ($rel !== null) {
			try {
				$folder = $this->notesService->getNotesFolder($uid);
				if ($folder->nodeExists($rel)) {
					return $this->notesService->readContent($folder->get($rel));
				}
			} catch (\Throwable $e) {
				// fall through to the transport buffer
			}
		}
		return $this->store->getContent($uid, '.resource/' . $id);
	}

	/** MIME type of a resource — from the on-disk file, else the stored metadata. */
	public function resourceMime(string $uid, string $id): string {
		$rel = $this->resourceFileRel($uid, $id);
		if ($rel !== null) {
			try {
				$folder = $this->notesService->getNotesFolder($uid);
				if ($folder->nodeExists($rel)) {
					$m = $folder->get($rel)->getMimetype();
					if ($m !== '') {
						return $m;
					}
				}
			} catch (\Throwable $e) {
			}
		}
		$row = $this->indexRow($uid, $id);
		if ($row !== null && (string)($row['meta'] ?? '') !== '') {
			$mime = trim((string)(JoplinItem::parse((string)$row['meta'])['mime'] ?? ''));
			if ($mime !== '') {
				return $mime;
			}
		}
		return 'application/octet-stream';
	}

	/** attachments/ path for a resource id, or null if it isn't a materialised resource. */
	private function resourceFileRel(string $uid, string $id): ?string {
		$row = $this->indexRow($uid, $id);
		if ($row === null || (int)$row['type'] !== JoplinItem::TYPE_RESOURCE || (string)$row['rel_path'] === '') {
			return null;
		}
		return (string)$row['rel_path'];
	}

	/**
	 * Register bytes as an image attachment: stores a REAL file under attachments/
	 * and indexes it as a resource. The web UI then inserts a portable relative
	 * link (`![alt](attachments/name)`); the Joplin layer maps that to `:/<id>`
	 * on the fly. Returns the stored filename + suggested alt text.
	 *
	 * @return array{name:string, alt:string}
	 */
	public function createResource(string $uid, string $bytes, string $filename, string $mime): array {
		$folder = $this->notesService->getNotesFolder($uid);
		$att = $this->ensureDir($folder, self::ATTACH_DIR);
		$filename = $this->safeName(trim($filename) !== '' ? $filename : 'image');
		if (strpos($filename, '.') === false) {
			$ext = $this->extForMime($mime);
			if ($ext !== '') {
				$filename .= '.' . $ext;
			}
		}
		$name = $this->uniqueChild($att, $filename);
		$att->newFile($name, $bytes);
		$rel = self::ATTACH_DIR . '/' . $name;
		$this->index->getOrCreateResourceJid($uid, $rel, (int)round(microtime(true) * 1000));
		$alt = (string)preg_replace('/\.[^.]+$/', '', $name);
		return ['name' => $name, 'alt' => $alt !== '' ? $alt : $name];
	}

	/** Materialise an incoming Joplin resource (type-4) as a real attachments/ file. */
	private function materializeResource(string $uid, string $jid, array $f, string $raw): void {
		$folder = $this->notesService->getNotesFolder($uid);
		$att = $this->ensureDir($folder, self::ATTACH_DIR);
		$updatedMs = $this->mtime($f);
		$existingRel = $this->indexRelPath($uid, $jid);
		if ($existingRel !== null && $existingRel !== '' && $folder->nodeExists($existingRel)) {
			// already materialised — just refresh the metadata snapshot
			$this->index->upsert($uid, $jid, JoplinItem::TYPE_RESOURCE, $existingRel, '', '', '', $updatedMs, $raw);
			return;
		}
		// choose a target filename from the Joplin metadata
		$name = trim((string)($f['filename'] ?? ''));
		if ($name === '') {
			$name = trim((string)($f['title'] ?? ''));
		}
		if ($name === '') {
			$name = $jid;
		}
		$name = $this->safeName($name);
		$ext = strtolower(trim((string)($f['file_extension'] ?? '')));
		if ($ext !== '' && !preg_match('/\.' . preg_quote($ext, '/') . '$/i', $name)) {
			$name .= '.' . $ext;
		}
		$rel = ($existingRel !== null && $existingRel !== '') ? $existingRel : self::ATTACH_DIR . '/' . $this->uniqueChild($att, $name);
		// If the blob already arrived (buffered), write the real file now.
		$buffer = $this->store->getContent($uid, '.resource/' . $jid);
		if ($buffer !== null) {
			$this->writeAttachment($uid, $rel, $buffer);
			$this->store->delete($uid, '.resource/' . $jid);
		}
		$this->index->upsert($uid, $jid, JoplinItem::TYPE_RESOURCE, $rel, '', '', '', $updatedMs, $raw);
	}

	/** Joplin PUT of the .resource/<id> binary: materialise into the real file, or buffer. */
	public function putResourceBlob(string $uid, string $id, string $bytes): void {
		if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
			return;
		}
		$nowMs = (int)round(microtime(true) * 1000);
		$rel = $this->resourceFileRel($uid, $id);
		if ($rel !== null) {
			$this->writeAttachment($uid, $rel, $bytes);
			return;
		}
		// type-4 metadata not seen yet — buffer until materializeResource runs
		$this->store->put($uid, '.resource/' . $id, $bytes, $nowMs);
	}

	private function writeAttachment(string $uid, string $rel, string $bytes): void {
		$folder = $this->notesService->getNotesFolder($uid);
		if ($folder->nodeExists($rel)) {
			$folder->get($rel)->putContent($bytes);
			return;
		}
		$dir = dirname($rel);
		$parent = ($dir === '.' || $dir === '') ? $folder : $this->ensureDir($folder, $dir);
		$parent->newFile(basename($rel), $bytes);
	}

	/** Serialise a materialised resource (type-4) from its on-disk file, or null. */
	private function resourceItem(string $uid, string $jid, array $row): ?string {
		$rel = (string)$row['rel_path'];
		if ($rel !== '') {
			try {
				$folder = $this->notesService->getNotesFolder($uid);
				if ($folder->nodeExists($rel)) {
					$file = $folder->get($rel);
					$name = basename($rel);
					$t = JoplinItem::msToTime((int)$row['updated_ms']);
					return JoplinItem::serialize([
						'title' => $name,
						'id' => $jid,
						'mime' => $file->getMimetype() ?: 'application/octet-stream',
						'filename' => '',
						'created_time' => $t,
						'updated_time' => $t,
						'user_created_time' => $t,
						'user_updated_time' => $t,
						'file_extension' => strtolower((string)pathinfo($name, PATHINFO_EXTENSION)),
						'encryption_cipher_text' => '',
						'encryption_applied' => '0',
						'encryption_blob_encrypted' => '0',
						'size' => (string)$file->getSize(),
						'blob_updated_time' => $t,
						'is_shared' => '0',
						'type_' => JoplinItem::TYPE_RESOURCE,
					]);
				}
			} catch (\Throwable $e) {
			}
		}
		$meta = (string)($row['meta'] ?? '');
		return $meta !== '' ? $meta : null;
	}

	/** @return array<int, array{id:string, updated_ms:int, size:int}> materialised resource blobs, for PROPFIND of .resource/. */
	public function resourceBlobs(string $uid): array {
		$out = [];
		$folder = $this->notesService->getNotesFolder($uid);
		foreach ($this->index->listResources($uid) as $r) {
			if ($r['rel_path'] === '') {
				continue;
			}
			try {
				if ($folder->nodeExists($r['rel_path'])) {
					$out[] = ['id' => $r['jid'], 'updated_ms' => $r['updated_ms'], 'size' => $folder->get($r['rel_path'])->getSize()];
				}
			} catch (\Throwable $e) {
			}
		}
		return $out;
	}

	private function extForMime(string $mime): string {
		$map = [
			'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
			'image/svg+xml' => 'svg', 'image/webp' => 'webp', 'image/bmp' => 'bmp',
		];
		return $map[strtolower(trim($mime))] ?? '';
	}

	// ── image-link mapping (relative file path <-> Joplin :/id) ───────────────

	/** '../' repeated for the note's folder depth, to reach the notes root. */
	private function relPrefixForNote(string $noteRel): string {
		return str_repeat('../', substr_count(trim($noteRel, '/'), '/'));
	}

	/** Collapse a path (resolving '.'/'..'); null if it escapes the root. */
	private function normalizePath(string $path): ?string {
		$out = [];
		foreach (explode('/', $path) as $seg) {
			if ($seg === '' || $seg === '.') {
				continue;
			}
			if ($seg === '..') {
				if (empty($out)) {
					return null;
				}
				array_pop($out);
				continue;
			}
			$out[] = $seg;
		}
		return implode('/', $out);
	}

	/** Resolve a note-relative link to a notes-root attachments path, or null. */
	private function resolveAttachment(string $noteRel, string $link): ?string {
		if ($link === '' || str_starts_with($link, ':/') || str_starts_with($link, '/') || str_starts_with($link, '#')
			|| preg_match('#^[a-z][a-z0-9+.-]*:#i', $link)) {
			return null;
		}
		$dir = trim((string)dirname($noteRel), '/');
		$combined = ($dir === '' || $dir === '.') ? $link : $dir . '/' . $link;
		$norm = $this->normalizePath($combined);
		return ($norm !== null && str_starts_with($norm, self::ATTACH_DIR . '/')) ? $norm : null;
	}

	/**
	 * Rewrite portable resource links (relative attachments paths) to Joplin
	 * `:/<id>`. Matches both `![img](…)` and `[file](…)` (Joplin uses the latter
	 * for video/audio/other); resolveAttachment() gates it so note-to-note links
	 * are left untouched.
	 */
	private function bodyToJoplin(string $uid, string $noteRel, string $body): string {
		$folder = $this->notesService->getNotesFolder($uid);
		return (string)preg_replace_callback('/(!?\[[^\]]*\]\()([^)\s]+)(\s+"[^"]*")?(\))/', function ($m) use ($uid, $noteRel, $folder) {
			$att = $this->resolveAttachment($noteRel, rawurldecode($m[2]));
			if ($att === null || !$folder->nodeExists($att)) {
				return $m[0];
			}
			$id = $this->index->getOrCreateResourceJid($uid, $att, $folder->get($att)->getMTime() * 1000);
			return $m[1] . ':/' . $id . ($m[3] ?? '') . $m[4];
		}, $body);
	}

	/** Rewrite Joplin `:/<id>` resource links (image `![]` or file `[]`) to portable relative paths. */
	private function bodyFromJoplin(string $uid, string $noteRel, string $body): string {
		return (string)preg_replace_callback('/(!?\[[^\]]*\]\()(:\/[0-9a-f]{32})(\s+"[^"]*")?(\))/', function ($m) use ($uid, $noteRel) {
			$rel = $this->resourceFileRel($uid, substr($m[2], 2));
			if ($rel === null) {
				return $m[0]; // resource not materialised yet — leave :/id (preview still resolves it)
			}
			$link = $this->relPrefixForNote($noteRel) . implode('/', array_map('rawurlencode', explode('/', $rel)));
			return $m[1] . $link . ($m[3] ?? '') . $m[4];
		}, $body);
	}

	public function deleteItem(string $uid, string $jid): void {
		$row = $this->indexRow($uid, $jid);
		if ($row === null) {
			return;
		}
		$type = (int)$row['type'];
		if (in_array($type, [JoplinItem::TYPE_NOTE, JoplinItem::TYPE_FOLDER], true) && $row['rel_path'] !== '') {
			try {
				$folder = $this->notesService->getNotesFolder($uid);
				if ($folder->nodeExists($row['rel_path'])) {
					$folder->get($row['rel_path'])->delete();
				}
			} catch (\Throwable $e) {
				// ignore
			}
		} elseif ($type === JoplinItem::TYPE_NOTE_TAG) {
			// Joplin removed a note↔tag link: strip that tag from the note's footer
			// (the footer is what the web UI reads), not just the index row.
			$this->untagNoteFooter($uid, (string)$row['link_note'], (string)$row['link_tag']);
		} elseif ($type === JoplinItem::TYPE_TAG) {
			// Joplin deleted the tag from all notes: strip it from every linked note.
			$name = (string)$row['meta'];
			foreach ($this->index->linksForTag($uid, $jid) as $lnk) {
				$this->untagNoteFooterByName($uid, $lnk['link_note'], $name);
			}
		}
		$this->indexDelete($uid, $jid);
	}

	private function untagNoteFooter(string $uid, string $noteJid, string $tagJid): void {
		$name = $this->tagName($uid, $tagJid);
		if ($name !== null) {
			$this->untagNoteFooterByName($uid, $noteJid, $name);
		}
	}

	private function untagNoteFooterByName(string $uid, string $noteJid, string $tagName): void {
		if ($tagName === '') {
			return;
		}
		$rel = $this->indexRelPath($uid, $noteJid);
		if ($rel === null) {
			return;
		}
		try {
			$folder = $this->notesService->getNotesFolder($uid);
			if ($folder->nodeExists($rel)) {
				// removeTags rewrites the footer and reconciles the link row.
				$this->notesService->removeTags($uid, $rel, [$tagName]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('joplin untag ' . $rel . ': ' . $e->getMessage(), ['app' => 'markdown_notes']);
		}
	}

	/** @return array<int, array{path:string,updated_ms:int,size:int}> item files for PROPFIND. */
	public function enumerate(string $uid): array {
		$out = [];
		foreach ($this->index->allJids($uid) as $row) {
			$item = $this->getItem($uid, $row['jid']);
			if ($item === null) {
				continue;
			}
			$out[] = ['path' => $row['jid'] . '.md', 'updated_ms' => $row['updated_ms'], 'size' => strlen($item)];
		}
		return $out;
	}

	// ── rebuild ──────────────────────────────────────────────────────────────

	/**
	 * Rebuild the index from the on-disk notes tree: folders, notes (by footer
	 * id), tags (from footers) and note<->tag links. Resources are preserved.
	 * This is what makes notes/tags created in the web UI visible to a Joplin
	 * download; the web-UI write paths keep the index live afterwards.
	 *
	 * @return array{folders:int,notes:int,tags:int,links:int,resources:int}
	 */
	public function rebuildIndex(string $uid): array {
		$counts = ['folders' => 0, 'notes' => 0, 'tags' => 0, 'links' => 0, 'resources' => 0];
		$root = $this->notesService->getNotesFolder($uid);
		// CRITICAL: reuse existing rows by natural key (rel_path / tag name /
		// note+tag) so folder/tag/link/resource JIDS STAY STABLE across rebuilds.
		// Regenerating them makes a Joplin client treat the old items as deleted —
		// and deleting a folder cascades to its notes. We collect every jid we
		// touch, then prune ONLY rows that no longer exist on disk.
		$seen = [];
		// Resources first, so note-body normalisation below can resolve :/id links.
		$this->reindexResources($uid, $root, $seen);
		$this->reindexWalk($uid, $root, '', $counts, true, $seen);
		$this->index->pruneExcept($uid, array_keys($seen));
		$counts['tags'] = $this->index->countByType($uid, JoplinItem::TYPE_TAG);
		$counts['resources'] = $this->index->countByType($uid, JoplinItem::TYPE_RESOURCE);
		return $counts;
	}

	/** Index every file under attachments/ as a resource (stable jids by rel_path). */
	private function reindexResources(string $uid, Folder $root, array &$seen): void {
		if ($root->nodeExists(self::ATTACH_DIR) && $root->get(self::ATTACH_DIR) instanceof Folder) {
			$att = $root->get(self::ATTACH_DIR);
			foreach ($att->getDirectoryListing() as $node) {
				$name = $node->getName();
				if ($node instanceof Folder || $name === '' || $name[0] === '.') {
					continue;
				}
				$seen[$this->index->getOrCreateResourceJid($uid, self::ATTACH_DIR . '/' . $name, $node->getMTime() * 1000)] = true;
			}
		}
	}

	private function reindexWalk(string $uid, Folder $dir, string $base, array &$counts, bool $top, array &$seen): void {
		foreach ($dir->getDirectoryListing() as $node) {
			$name = $node->getName();
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			$rel = $base === '' ? $name : $base . '/' . $name;
			if ($node instanceof Folder) {
				// Templates/ and attachments/ are not notebooks — skip them.
				if ($top && in_array($name, NotesService::SPECIAL, true)) {
					continue;
				}
				$seen[$this->index->getOrCreateFolderJid($uid, $rel, $node->getMTime() * 1000)] = true;
				$counts['folders']++;
				$this->reindexWalk($uid, $node, $rel, $counts, false, $seen);
				continue;
			}
			if (substr($name, -3) !== '.md') {
				continue;
			}
			$this->reindexNote($uid, $node, $rel, $counts, $seen);
		}
	}

	private function reindexNote(string $uid, Node $file, string $rel, array &$counts, array &$seen): void {
		$parsed = NoteFormat::parse($this->notesService->readContent($file));
		$meta = $parsed['meta'];
		$body = $parsed['body'];
		$jid = (string)($meta['id'] ?? '');
		$dirty = false;
		if (!preg_match('/^[0-9a-f]{32}$/', $jid)) {
			// No (or malformed) footer id: assign a stable one and persist it so
			// the jid survives future rebuilds and Joplin sees one identity.
			$jid = JoplinItem::newId();
			$meta = ['id' => $jid] + $meta;
			$dirty = true;
		}
		// Converge any Joplin :/id image links to portable relative paths on disk.
		$norm = $this->bodyFromJoplin($uid, $rel, $body);
		if ($norm !== $body) {
			$body = $norm;
			$dirty = true;
		}
		if ($dirty) {
			$file->putContent(NoteFormat::serialize($parsed['title'], $body, $meta));
		}
		$updatedMs = isset($meta['updated_time']) && $meta['updated_time'] !== ''
			? JoplinItem::timeToMs((string)$meta['updated_time'])
			: $file->getMTime() * 1000;
		$this->index->upsert($uid, $jid, JoplinItem::TYPE_NOTE, $rel, '', '', '', $updatedMs, '');
		$seen[$jid] = true;
		$counts['notes']++;
		foreach ($parsed['tags'] as $tagName) {
			$tagName = trim((string)$tagName);
			if ($tagName === '') {
				continue;
			}
			// Joplin tag/link items index ALL footer tags (per-user, not global);
			// global system-tag promotion is gated and handled by push() below.
			$tagJid = $this->index->getOrCreateTagJid($uid, $tagName, '', $updatedMs);
			$seen[$tagJid] = true;
			$seen[$this->index->getOrCreateLinkJid($uid, $jid, $tagJid, $updatedMs)] = true;
			$counts['links']++;
		}
		// Eventual system-tag reconciliation: push() assigns template-referenced
		// tags and unassigns the rest (gates to promotable tags internally).
		$this->systemTagSync->push($uid, (int)$file->getId(), $parsed['tags']);
	}

	// ── tree helpers ─────────────────────────────────────────────────────────

	private function ensureDir(Folder $root, string $rel): Folder {
		$cur = $root;
		foreach (explode('/', $rel) as $seg) {
			if ($seg === '') {
				continue;
			}
			$cur = $cur->nodeExists($seg) && $cur->get($seg) instanceof Folder ? $cur->get($seg) : $cur->newFolder($seg);
		}
		return $cur;
	}

	private function uniqueChild(Folder $dir, string $name): string {
		if (!$dir->nodeExists($name)) {
			return $name;
		}
		$dot = strrpos($name, '.');
		$stem = $dot !== false ? substr($name, 0, $dot) : $name;
		$ext = $dot !== false ? substr($name, $dot) : '';
		for ($i = 1; $i < 1000; $i++) {
			$c = $stem . ' (' . $i . ')' . $ext;
			if (!$dir->nodeExists($c)) {
				return $c;
			}
		}
		return $stem . ' (' . JoplinItem::newId() . ')' . $ext;
	}

	private function safeName(string $name): string {
		$name = str_replace(['/', '\\', "\0", "\n", "\r"], ' ', trim($name));
		$name = trim($name);
		return $name === '' ? 'untitled' : mb_substr($name, 0, 120);
	}

	private function tagsForNote(string $uid, string $jid): array {
		$rel = $this->indexRelPath($uid, $jid);
		if ($rel === null) {
			return [];
		}
		try {
			$folder = $this->notesService->getNotesFolder($uid);
			if ($folder->nodeExists($rel)) {
				return NoteFormat::parse($this->notesService->readContent($folder->get($rel)))['tags'];
			}
		} catch (\Throwable $e) {
		}
		return [];
	}

	private function mtime(array $f): int {
		$t = (string)($f['updated_time'] ?? '');
		return $t !== '' ? JoplinItem::timeToMs($t) : (int)round(microtime(true) * 1000);
	}

	// ── index ─────────────────────────────────────────────────────────────

	private function folderRel(string $uid, string $folderJid): string {
		return $this->index->relPath($uid, $folderJid) ?? '';
	}

	private function folderJid(string $uid, string $rel): string {
		return $this->index->folderJidByRel($uid, $rel);
	}

	private function tagName(string $uid, string $tagJid): ?string {
		return $this->index->tagName($uid, $tagJid);
	}

	private function indexRelPath(string $uid, string $jid): ?string {
		return $this->index->relPath($uid, $jid);
	}

	/** @return array<string,mixed>|null */
	private function indexRow(string $uid, string $jid): ?array {
		return $this->index->row($uid, $jid);
	}

	private function indexUpsert(string $uid, string $jid, int $type, string $relPath, string $refId, string $linkNote, string $linkTag, int $updatedMs, string $meta): void {
		$this->index->upsert($uid, $jid, $type, $relPath, $refId, $linkNote, $linkTag, $updatedMs, $meta);
	}

	private function indexDelete(string $uid, string $jid): void {
		$this->index->delete($uid, $jid);
	}
}
