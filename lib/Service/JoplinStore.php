<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\IDBConnection;

/**
 * A filesystem-like store backing the Joplin sync endpoint, persisted in the
 * markdown_notes_jblob table (per user, keyed by sync-target-relative path).
 *
 * Phase 2a treats the endpoint as a correct but "dumb" WebDAV target: every
 * Joplin file/dir (items <id>.md, info.json, locks/*, temp/*, .resource/*) is
 * stored verbatim. This proves the transport (WebDAV verbs + Basic auth) and is
 * a working self-hosted sync target on its own. The note-tree translation layer
 * is then built on top, reusing this for the opaque files Joplin owns.
 *
 * Paths are normalised to no leading/trailing slash; '' is the root.
 */
class JoplinStore {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function norm(string $path): string {
		return trim(str_replace('\\', '/', $path), '/');
	}

	/** @return array{path:string,is_dir:bool,size:int,updated_ms:int}|null */
	public function stat(string $uid, string $path): ?array {
		$path = $this->norm($path);
		if ($path === '') {
			return ['path' => '', 'is_dir' => true, 'size' => 0, 'updated_ms' => 0];
		}
		$q = $this->db->getQueryBuilder();
		$q->select('path', 'is_dir', 'updated_ms', $q->func()->octetLength('content', 'len'))
			->from('markdown_notes_jblob')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('path', $q->createNamedParameter($path)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!$row) {
			return null;
		}
		return [
			'path' => $path,
			'is_dir' => (int)$row['is_dir'] === 1,
			'size' => (int)($row['len'] ?? 0),
			'updated_ms' => (int)$row['updated_ms'],
		];
	}

	public function exists(string $uid, string $path): bool {
		return $this->stat($uid, $path) !== null;
	}

	public function getContent(string $uid, string $path): ?string {
		$path = $this->norm($path);
		$q = $this->db->getQueryBuilder();
		$q->select('content')
			->from('markdown_notes_jblob')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('path', $q->createNamedParameter($path)));
		$r = $q->executeQuery();
		$row = $r->fetch();
		$r->closeCursor();
		if (!$row) {
			return null;
		}
		$c = $row['content'];
		if (is_resource($c)) {
			$c = stream_get_contents($c);
		}
		return (string)$c;
	}

	public function put(string $uid, string $path, string $content, int $nowMs): void {
		$path = $this->norm($path);
		$this->ensureParents($uid, $path, $nowMs);
		$this->upsert($uid, $path, false, $content, $nowMs);
	}

	public function mkcol(string $uid, string $path, int $nowMs): void {
		$path = $this->norm($path);
		if ($path === '' || $this->exists($uid, $path)) {
			return;
		}
		$this->ensureParents($uid, $path, $nowMs);
		$this->upsert($uid, $path, true, null, $nowMs);
	}

	public function delete(string $uid, string $path): void {
		$path = $this->norm($path);
		$q = $this->db->getQueryBuilder();
		$q->delete('markdown_notes_jblob')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->orX(
				$q->expr()->eq('path', $q->createNamedParameter($path)),
				$q->expr()->like('path', $q->createNamedParameter($this->db->escapeLikeParameter($path) . '/%')),
			));
		$q->executeStatement();
	}

	public function move(string $uid, string $from, string $to, int $nowMs): void {
		$from = $this->norm($from);
		$to = $this->norm($to);
		$content = $this->getContent($uid, $from);
		$st = $this->stat($uid, $from);
		if ($st === null) {
			return;
		}
		$this->delete($uid, $to);
		if ($st['is_dir']) {
			$this->mkcol($uid, $to, $nowMs);
		} else {
			$this->put($uid, $to, (string)$content, $nowMs);
		}
		$this->delete($uid, $from);
	}

	/**
	 * Immediate children of a directory (one level). Root = '' lists top-level.
	 *
	 * @return array<int, array{path:string,name:string,is_dir:bool,size:int,updated_ms:int}>
	 */
	public function children(string $uid, string $dir): array {
		$dir = $this->norm($dir);
		$prefix = $dir === '' ? '' : $dir . '/';
		$q = $this->db->getQueryBuilder();
		$q->select('path', 'is_dir', 'updated_ms', $q->func()->octetLength('content', 'len'))
			->from('markdown_notes_jblob')
			->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
		if ($prefix !== '') {
			$q->andWhere($q->expr()->like('path', $q->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')));
		}
		$r = $q->executeQuery();
		$out = [];
		while ($row = $r->fetch()) {
			$p = (string)$row['path'];
			$rest = $prefix === '' ? $p : substr($p, strlen($prefix));
			if ($rest === '' || strpos($rest, '/') !== false) {
				continue; // not an immediate child
			}
			$out[] = [
				'path' => $p,
				'name' => $rest,
				'is_dir' => (int)$row['is_dir'] === 1,
				'size' => (int)($row['len'] ?? 0),
				'updated_ms' => (int)$row['updated_ms'],
			];
		}
		$r->closeCursor();
		return $out;
	}

	private function ensureParents(string $uid, string $path, int $nowMs): void {
		$parts = explode('/', $path);
		array_pop($parts);
		$acc = '';
		foreach ($parts as $seg) {
			$acc = $acc === '' ? $seg : $acc . '/' . $seg;
			if (!$this->exists($uid, $acc)) {
				$this->upsert($uid, $acc, true, null, $nowMs);
			}
		}
	}

	private function upsert(string $uid, string $path, bool $isDir, ?string $content, int $nowMs): void {
		if ($this->exists($uid, $path)) {
			$q = $this->db->getQueryBuilder();
			$q->update('markdown_notes_jblob')
				->set('updated_ms', $q->createNamedParameter($nowMs))
				->set('is_dir', $q->createNamedParameter($isDir ? 1 : 0))
				->set('content', $q->createNamedParameter($content));
			$q->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
				->andWhere($q->expr()->eq('path', $q->createNamedParameter($path)));
			$q->executeStatement();
			return;
		}
		$q = $this->db->getQueryBuilder();
		$q->insert('markdown_notes_jblob')->values([
			'uid' => $q->createNamedParameter($uid),
			'path' => $q->createNamedParameter($path),
			'is_dir' => $q->createNamedParameter($isDir ? 1 : 0),
			'updated_ms' => $q->createNamedParameter($nowMs),
			'content' => $q->createNamedParameter($content),
		]);
		$q->executeStatement();
	}
}
