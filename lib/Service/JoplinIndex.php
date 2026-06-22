<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Storage layer for the markdown_notes_joplin index
 * (jid -> note path / folder path / tag / note-tag link / resource).
 *
 * Deliberately depends on IDBConnection ONLY — no NotesService, no
 * SystemTagSync — so the web-UI path (NotesService) and the sync layer
 * (JoplinSyncService) can both maintain the index without a DI cycle
 * (SystemTagSync already depends on NotesService).
 */
class JoplinIndex {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function newId(): string {
		return JoplinItem::newId();
	}

	/** @return array<string,mixed>|null */
	public function row(string $uid, string $jid): ?array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('jid', $q->createNamedParameter($jid)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		return $row ?: null;
	}

	public function relPath(string $uid, string $jid): ?string {
		$row = $this->row($uid, $jid);
		return $row !== null ? (string)$row['rel_path'] : null;
	}

	public function upsert(string $uid, string $jid, int $type, string $relPath, string $refId, string $linkNote, string $linkTag, int $updatedMs, string $meta): void {
		$exists = $this->row($uid, $jid) !== null;
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

	public function delete(string $uid, string $jid): void {
		$q = $this->db->getQueryBuilder();
		$q->delete('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('jid', $q->createNamedParameter($jid)));
		$q->executeStatement();
	}

	/** @return array<int, array{jid:string, updated_ms:int}> every indexed item for PROPFIND enumeration. */
	public function allJids(string $uid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('jid', 'updated_ms')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
		$r = $q->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = ['jid' => (string)$row['jid'], 'updated_ms' => (int)$row['updated_ms']];
		}
		$r->closeCursor();
		return $out;
	}

	public function jidByRel(string $uid, string $rel, int $type): ?string {
		$q = $this->db->getQueryBuilder();
		$q->select('jid')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter($type)))
			->andWhere($q->expr()->eq('rel_path', $q->createNamedParameter($rel)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		return $row ? (string)$row['jid'] : null;
	}

	public function folderJidByRel(string $uid, string $rel): string {
		return $this->jidByRel($uid, $rel, JoplinItem::TYPE_FOLDER) ?? '';
	}

	public function getOrCreateFolderJid(string $uid, string $rel, int $updatedMs): string {
		$jid = $this->jidByRel($uid, $rel, JoplinItem::TYPE_FOLDER);
		if ($jid !== null) {
			return $jid;
		}
		$jid = $this->newId();
		$this->upsert($uid, $jid, JoplinItem::TYPE_FOLDER, $rel, '', '', '', $updatedMs, '');
		return $jid;
	}

	/**
	 * Resource (type-4) jid for an attachments file path, created if absent.
	 * rel_path is the file's path relative to the notes root (e.g.
	 * "attachments/foo.png"); the bytes live in that real file, not a blob.
	 */
	public function getOrCreateResourceJid(string $uid, string $rel, int $updatedMs): string {
		$jid = $this->jidByRel($uid, $rel, JoplinItem::TYPE_RESOURCE);
		if ($jid !== null) {
			return $jid;
		}
		$jid = $this->newId();
		$this->upsert($uid, $jid, JoplinItem::TYPE_RESOURCE, $rel, '', '', '', $updatedMs, '');
		return $jid;
	}

	public function resourceJidByRel(string $uid, string $rel): ?string {
		return $this->jidByRel($uid, $rel, JoplinItem::TYPE_RESOURCE);
	}

	/** @return array<int, array{jid:string, rel_path:string, updated_ms:int}> all resource (type-4) rows. */
	public function listResources(string $uid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('jid', 'rel_path', 'updated_ms')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_RESOURCE)));
		$r = $q->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = ['jid' => (string)$row['jid'], 'rel_path' => (string)$row['rel_path'], 'updated_ms' => (int)$row['updated_ms']];
		}
		$r->closeCursor();
		return $out;
	}

	public function tagJidByName(string $uid, string $name): ?string {
		$q = $this->db->getQueryBuilder();
		$q->select('jid')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_TAG)))
			->andWhere($q->expr()->eq('meta', $q->createNamedParameter($name)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		return $row ? (string)$row['jid'] : null;
	}

	/** Tag name (meta) keyed by jid, or null if not a tag. */
	public function tagName(string $uid, string $tagJid): ?string {
		$row = $this->row($uid, $tagJid);
		return ($row !== null && (int)$row['type'] === JoplinItem::TYPE_TAG) ? (string)$row['meta'] : null;
	}

	public function getOrCreateTagJid(string $uid, string $name, string $refId, int $updatedMs): string {
		$jid = $this->tagJidByName($uid, $name);
		if ($jid !== null) {
			return $jid;
		}
		$jid = $this->newId();
		$this->upsert($uid, $jid, JoplinItem::TYPE_TAG, '', $refId, '', '', $updatedMs, $name);
		return $jid;
	}

	public function getOrCreateLinkJid(string $uid, string $noteJid, string $tagJid, int $updatedMs): string {
		$q = $this->db->getQueryBuilder();
		$q->select('jid')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_NOTE_TAG)))
			->andWhere($q->expr()->eq('link_note', $q->createNamedParameter($noteJid)))
			->andWhere($q->expr()->eq('link_tag', $q->createNamedParameter($tagJid)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if ($row) {
			return (string)$row['jid'];
		}
		$jid = $this->newId();
		$this->upsert($uid, $jid, JoplinItem::TYPE_NOTE_TAG, '', '', $noteJid, $tagJid, $updatedMs, '');
		return $jid;
	}

	/** @return array<int, array{jid:string, link_note:string}> the note-tag link rows for a tag. */
	public function linksForTag(string $uid, string $tagJid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('jid', 'link_note')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_NOTE_TAG)))
			->andWhere($q->expr()->eq('link_tag', $q->createNamedParameter($tagJid)));
		$r = $q->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = ['jid' => (string)$row['jid'], 'link_note' => (string)$row['link_note']];
		}
		$r->closeCursor();
		return $out;
	}

	/** @return array<int, array{jid:string, link_tag:string}> the note-tag link rows for a note. */
	public function linksForNote(string $uid, string $noteJid): array {
		$q = $this->db->getQueryBuilder();
		$q->select('jid', 'link_tag')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_NOTE_TAG)))
			->andWhere($q->expr()->eq('link_note', $q->createNamedParameter($noteJid)));
		$r = $q->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = ['jid' => (string)$row['jid'], 'link_tag' => (string)$row['link_tag']];
		}
		$r->closeCursor();
		return $out;
	}

	/** Note jids whose rel_path is $rel or sits beneath $rel/ (a folder subtree). @return string[] */
	private function noteJidsUnder(string $uid, string $rel): array {
		$q = $this->db->getQueryBuilder();
		$q->select('jid')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_NOTE)))
			->andWhere($q->expr()->orX(
				$q->expr()->eq('rel_path', $q->createNamedParameter($rel)),
				$q->expr()->like('rel_path', $q->createNamedParameter($this->db->escapeLikeParameter($rel) . '/%')),
			));
		$r = $q->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$out[] = (string)$row['jid'];
		}
		$r->closeCursor();
		return $out;
	}

	/** Delete the note at $rel and its note-tag links. */
	public function deleteNoteByRel(string $uid, string $rel): void {
		$jid = $this->jidByRel($uid, $rel, JoplinItem::TYPE_NOTE);
		if ($jid === null) {
			return;
		}
		foreach ($this->linksForNote($uid, $jid) as $lnk) {
			$this->delete($uid, $lnk['jid']);
		}
		$this->delete($uid, $jid);
	}

	/** Delete the row at $rel plus everything beneath it (folder subtree), cleaning the notes' links too. */
	public function deleteByRelPrefix(string $uid, string $rel): void {
		$noteJids = $this->noteJidsUnder($uid, $rel);
		if (!empty($noteJids)) {
			$q = $this->db->getQueryBuilder();
			$q->delete('markdown_notes_joplin')
				->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
				->andWhere($q->expr()->eq('type', $q->createNamedParameter(JoplinItem::TYPE_NOTE_TAG)))
				->andWhere($q->expr()->in('link_note', $q->createNamedParameter($noteJids, IQueryBuilder::PARAM_STR_ARRAY)));
			$q->executeStatement();
		}
		$q = $this->db->getQueryBuilder();
		$q->delete('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->orX(
				$q->expr()->eq('rel_path', $q->createNamedParameter($rel)),
				$q->expr()->like('rel_path', $q->createNamedParameter($this->db->escapeLikeParameter($rel) . '/%')),
			));
		$q->executeStatement();
	}

	/**
	 * Move a note/folder (and a folder's subtree) in the index: rewrite the
	 * rel_path prefix. When $bumpMs is given, also bump updated_ms so a Joplin
	 * client re-fetches the moved items (their getlastmodified changes) and sees
	 * the new parent — otherwise a move would never propagate to the client.
	 */
	public function repath(string $uid, string $fromRel, string $toRel, ?int $bumpMs = null): void {
		$q = $this->db->getQueryBuilder();
		$q->select('jid', 'rel_path')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->orX(
				$q->expr()->eq('rel_path', $q->createNamedParameter($fromRel)),
				$q->expr()->like('rel_path', $q->createNamedParameter($this->db->escapeLikeParameter($fromRel) . '/%')),
			));
		$r = $q->executeQuery();
		$rows = $r->fetchAll();
		$r->closeCursor();
		foreach ($rows as $row) {
			$old = (string)$row['rel_path'];
			$new = $old === $fromRel ? $toRel : $toRel . substr($old, strlen($fromRel));
			$u = $this->db->getQueryBuilder();
			$u->update('markdown_notes_joplin')
				->set('rel_path', $u->createNamedParameter($new));
			if ($bumpMs !== null) {
				$u->set('updated_ms', $u->createNamedParameter($bumpMs));
			}
			$u->where($u->expr()->eq('uid', $u->createNamedParameter($uid)))
				->andWhere($u->expr()->eq('jid', $u->createNamedParameter((string)$row['jid'])));
			$u->executeStatement();
		}
	}

	public function countByType(string $uid, int $type): int {
		$q = $this->db->getQueryBuilder();
		$q->select($q->func()->count('*', 'c'))->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('type', $q->createNamedParameter($type)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		return (int)($row['c'] ?? 0);
	}

	/**
	 * Prune note/folder/tag/link/resource rows whose jid was NOT seen in a
	 * rebuild (i.e. no longer on disk), preserving the jids of everything that
	 * still exists. Joplin-owned verbatim items (settings/master_key/revisions)
	 * are never touched. This replaces a clear-then-rebuild, which would
	 * regenerate jids and make Joplin delete the old items.
	 *
	 * @param string[] $seenJids
	 */
	public function pruneExcept(string $uid, array $seenJids): int {
		$keep = array_fill_keys($seenJids, true);
		$q = $this->db->getQueryBuilder();
		$q->select('jid')->from('markdown_notes_joplin')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->in('type', $q->createNamedParameter(
				[JoplinItem::TYPE_NOTE, JoplinItem::TYPE_FOLDER, JoplinItem::TYPE_RESOURCE, JoplinItem::TYPE_TAG, JoplinItem::TYPE_NOTE_TAG],
				IQueryBuilder::PARAM_INT_ARRAY,
			)));
		$r = $q->executeQuery();
		$stale = [];
		while ($row = $r->fetch()) {
			if (!isset($keep[(string)$row['jid']])) {
				$stale[] = (string)$row['jid'];
			}
		}
		$r->closeCursor();
		foreach ($stale as $jid) {
			$this->delete($uid, $jid);
		}
		return count($stale);
	}
}
