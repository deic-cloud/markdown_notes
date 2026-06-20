<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IDBConnection;
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
	public function __construct(
		private IDBConnection $db,
		private NotesService $notesService,
		private SystemTagSync $systemTagSync,
		private JoplinStore $store,
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
				default:
					// resources (4) and anything else: keep the item verbatim so
					// the round-trip is lossless; index it for enumeration.
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
		$parentRel = isset($f['parent_id']) && $f['parent_id'] !== '' ? $this->folderRel($uid, (string)$f['parent_id']) : '';
		$folder = $this->notesService->getNotesFolder($uid);
		$dir = $parentRel === '' ? $folder : $this->ensureDir($folder, $parentRel);

		// Build our footer, preserving Joplin's id/times/todo and any tags the
		// link items will (re)assert. Unmanaged Joplin keys are dropped here but
		// the canonical ones are kept so Joplin re-reads consistently.
		$meta = ['id' => $jid];
		foreach (['created_time', 'updated_time', 'is_todo', 'todo_due', 'todo_completed'] as $k) {
			if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== '0') {
				$meta[$k] = $f[$k];
			}
		}
		$existingTags = $this->tagsForNote($uid, $jid);
		if (!empty($existingTags)) {
			$meta = NoteFormat::withTags($meta, $existingTags);
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
			$this->indexUpsert($uid, $jid, JoplinItem::TYPE_NOTE, $existingRel, '', '', '', $this->mtime($f), '');
			return;
		}
		$fname = $this->uniqueChild($dir, $fname);
		$dir->newFile($fname, NoteFormat::serialize($title, $body, $meta));
		$rel = ($parentRel === '' ? '' : $parentRel . '/') . $fname;
		$this->indexUpsert($uid, $jid, JoplinItem::TYPE_NOTE, $rel, '', '', '', $this->mtime($f), '');
	}

	private function putFolder(string $uid, string $jid, array $f): void {
		$title = $this->safeName((string)($f['title'] ?? $jid));
		$parentRel = isset($f['parent_id']) && $f['parent_id'] !== '' ? $this->folderRel($uid, (string)$f['parent_id']) : '';
		$rel = ($parentRel === '' ? '' : $parentRel . '/') . $title;
		$folder = $this->notesService->getNotesFolder($uid);
		$this->ensureDir($folder, $rel);
		$this->indexUpsert($uid, $jid, JoplinItem::TYPE_FOLDER, $rel, '', '', '', $this->mtime($f), '');
	}

	private function putTag(string $uid, string $jid, array $f): void {
		$name = trim((string)($f['title'] ?? ''));
		$tagId = '';
		if ($name !== '') {
			try {
				$tagId = (string)$this->systemTagSync->ensureTagPublic($name);
			} catch (\Throwable $e) {
				// best effort
			}
		}
		$this->indexUpsert($uid, $jid, JoplinItem::TYPE_TAG, '', $tagId, '', '', $this->mtime($f), $name);
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
		if (($row['meta'] ?? '') !== '' && !in_array($type, [JoplinItem::TYPE_NOTE, JoplinItem::TYPE_FOLDER, JoplinItem::TYPE_TAG], true)) {
			return (string)$row['meta']; // verbatim (resources etc.)
		}
		if ($type === JoplinItem::TYPE_NOTE) {
			$folder = $this->notesService->getNotesFolder($uid);
			if (!$folder->nodeExists($row['rel_path'])) {
				return null;
			}
			$parsed = NoteFormat::parse($this->notesService->readContent($folder->get($row['rel_path'])));
			$parentRel = dirname($row['rel_path']);
			$fields = [
				'title' => $parsed['title'],
				'body' => $parsed['body'],
				'id' => $jid,
				'parent_id' => $parentRel === '.' ? '' : $this->folderJid($uid, $parentRel),
				'created_time' => $parsed['meta']['created_time'] ?? JoplinItem::msToTime((int)$row['updated_ms']),
				'updated_time' => $parsed['meta']['updated_time'] ?? JoplinItem::msToTime((int)$row['updated_ms']),
				'is_todo' => !empty($parsed['meta']['is_todo']) ? '1' : '0',
				'todo_due' => $parsed['meta']['todo_due'] ?? '0',
				'todo_completed' => $parsed['meta']['todo_completed'] ?? '0',
				'type_' => JoplinItem::TYPE_NOTE,
			];
			return JoplinItem::serialize($fields);
		}
		if ($type === JoplinItem::TYPE_FOLDER) {
			$parentRel = dirname($row['rel_path']);
			return JoplinItem::serialize([
				'title' => basename($row['rel_path']),
				'id' => $jid,
				'parent_id' => $parentRel === '.' ? '' : $this->folderJid($uid, $parentRel),
				'updated_time' => JoplinItem::msToTime((int)$row['updated_ms']),
				'type_' => JoplinItem::TYPE_FOLDER,
			]);
		}
		if ($type === JoplinItem::TYPE_TAG) {
			return JoplinItem::serialize([
				'title' => (string)$row['meta'],
				'id' => $jid,
				'updated_time' => JoplinItem::msToTime((int)$row['updated_ms']),
				'type_' => JoplinItem::TYPE_TAG,
			]);
		}
		if ($type === JoplinItem::TYPE_NOTE_TAG) {
			return JoplinItem::serialize([
				'id' => $jid,
				'note_id' => (string)$row['link_note'],
				'tag_id' => (string)$row['link_tag'],
				'updated_time' => JoplinItem::msToTime((int)$row['updated_ms']),
				'type_' => JoplinItem::TYPE_NOTE_TAG,
			]);
		}
		return (string)($row['meta'] ?? '');
	}

	public function deleteItem(string $uid, string $jid): void {
		$row = $this->indexRow($uid, $jid);
		if ($row !== null
			&& in_array((int)$row['type'], [JoplinItem::TYPE_NOTE, JoplinItem::TYPE_FOLDER], true)
			&& $row['rel_path'] !== '') {
			try {
				$folder = $this->notesService->getNotesFolder($uid);
				if ($folder->nodeExists($row['rel_path'])) {
					$folder->get($row['rel_path'])->delete();
				}
			} catch (\Throwable $e) {
				// ignore
			}
		}
		$this->indexDelete($uid, $jid);
	}

	/** @return array<int, array{path:string,updated_ms:int,size:int}> item files for PROPFIND. */
	public function enumerate(string $uid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('jid', 'updated_ms')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
		$r = $q->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$item = $this->getItem($uid, (string)$row['jid']);
			if ($item === null) {
				continue;
			}
			$out[] = ['path' => $row['jid'] . '.md', 'updated_ms' => (int)$row['updated_ms'], 'size' => strlen($item)];
		}
		$r->closeCursor();
		return $out;
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
		$rel = $this->indexRelPath($uid, $folderJid);
		return $rel ?? '';
	}

	private function folderJid(string $uid, string $rel): string {
		$q = $this->db->getQueryBuilder();
		$q->select('jid')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_FOLDER)))
			->andWhere($q->expr()->eq('rel_path', $q->createNamedParameter($rel)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		return $row ? (string)$row['jid'] : '';
	}

	private function tagName(string $uid, string $tagJid): ?string {
		$row = $this->indexRow($uid, $tagJid);
		return ($row !== null && (int)$row['type'] === JoplinItem::TYPE_TAG) ? (string)$row['meta'] : null;
	}

	private function indexRelPath(string $uid, string $jid): ?string {
		$row = $this->indexRow($uid, $jid);
		return $row !== null ? (string)$row['rel_path'] : null;
	}

	/** @return array<string,mixed>|null */
	private function indexRow(string $uid, string $jid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('jid', $q->createNamedParameter($jid)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		return $row ?: null;
	}

	private function indexUpsert(string $uid, string $jid, int $type, string $relPath, string $refId, string $linkNote, string $linkTag, int $updatedMs, string $meta): void {
		$exists = $this->indexRow($uid, $jid) !== null;
		$q = $this->db->getQueryBuilder();
		if ($exists) {
			$q->update('markdown_notes_joplin')
				->set('type', $q->createNamedParameter($type))
				->set('rel_path', $q->createNamedParameter($relPath))
				->set('ref_id', $q->createNamedParameter($refId))
				->set('link_note', $q->createNamedParameter($linkNote))
				->set('link_tag', $q->createNamedParameter($linkTag))
				->set('updated_ms', $q->createNamedParameter($updatedMs))
				->set('meta', $q->createNamedParameter($meta))
				->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
				->andWhere($q->expr()->eq('jid', $q->createNamedParameter($jid)));
			$q->executeStatement();
			return;
		}
		$q->insert('markdown_notes_joplin')->values([
			'uid' => $q->createNamedParameter($uid),
			'jid' => $q->createNamedParameter($jid),
			'type' => $q->createNamedParameter($type),
			'rel_path' => $q->createNamedParameter($relPath),
			'ref_id' => $q->createNamedParameter($refId),
			'link_note' => $q->createNamedParameter($linkNote),
			'link_tag' => $q->createNamedParameter($linkTag),
			'updated_ms' => $q->createNamedParameter($updatedMs),
			'meta' => $q->createNamedParameter($meta),
		]);
		$q->executeStatement();
	}

	private function indexDelete(string $uid, string $jid): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('jid', $q->createNamedParameter($jid)));
		$q->executeStatement();
	}
}
