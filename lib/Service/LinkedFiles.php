<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;

/**
 * The files a note links to outside its notebook — a project's data, scripts
 * and plots, linked with "Link to a file or folder" (FileLinkService).
 *
 * Two users of this: the timestamp manifest, which must cover what the note
 * names and not just the note, and the share dialog, which offers to share the
 * folders a notebook links to (sharing a notebook does not share them).
 *
 * A link is resolved FOR A USER: on a files_sharding cluster the same link
 * names the owner's file and reaches each reader's own copy; on a single
 * server it is Nextcloud's /index.php/f/<id>. Links to anything else (web
 * pages, relative links inside the notebook) are not file links here.
 */
class LinkedFiles {
	private const CLUSTER_LINKS = 'OCA\\FilesSharding\\Service\\ClusterLinkService';
	/** A linked folder is expanded to its files; beyond these, stamping refuses. */
	public const MAX_FILES = 2000;
	public const MAX_BYTES = 20 * 1024 * 1024 * 1024;

	/** @var array<string, ?Node> resolved links, per user and link, for one request */
	private array $cache = [];

	public function __construct(
		private IRootFolder  $rootFolder,
		private IAppManager  $appManager,
		private IConfig      $config,
		private NotesService $notes,
	) {
	}

	/** File links in a note body: markdown link targets and href/src attributes. */
	public function linksIn(string $body): array {
		$targets = [];
		if (preg_match_all('/!?\[[^\]]*\]\(<?([^)\s>]+)>?/', $body, $m)) {
			$targets = $m[1];
		}
		if (preg_match_all('/(?:src|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $body, $hm, PREG_SET_ORDER)) {
			foreach ($hm as $h) {
				$targets[] = ($h[1] ?? '') !== '' ? $h[1] : ($h[2] ?? '');
			}
		}
		$out = [];
		foreach ($targets as $t) {
			$t = strtok($t, '#') ?: '';
			if ($t !== '' && $this->isFileLink($t)) {
				$out[$t] = true;
			}
		}
		return array_keys($out);
	}

	public function isFileLink(string $url): bool {
		return (bool)preg_match('#/index\.php/(?:apps/files_sharding/f/[^/]+/\d+|f/\d+)(?:[/?]|$)#', $url);
	}

	/** $uid's own node for a file link, or null (not theirs, or gone). */
	public function resolve(string $uid, string $url): ?Node {
		$key = $uid . "\n" . $url;
		if (array_key_exists($key, $this->cache)) {
			return $this->cache[$key];
		}
		$node = null;
		try {
			$cluster = $this->cluster();
			$path = (string)parse_url($url, PHP_URL_PATH);
			if ($cluster !== null) {
				$node = $cluster->nodeForLink($uid, $url);
			} elseif (preg_match('#/index\.php/f/(\d+)$#', $path, $m) && $this->isThisServer($url)) {
				$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById((int)$m[1]);
			} elseif (preg_match('#/index\.php/apps/files_sharding/f/([^/]+)/(\d+)(?:/(.*))?$#', $path, $m)) {
				// A cluster link read where files_sharding is not running: the
				// user's own file (by id, then by the owner's path), or one shared
				// with them on this server (by id). Enough to verify one's own
				// timestamps; other people's copies need files_sharding.
				$folder = $this->rootFolder->getUserFolder($uid);
				$node = $folder->getFirstNodeById((int)$m[2]);
				if ($node === null && strcasecmp(rawurldecode($m[1]), $uid) === 0 && ($m[3] ?? '') !== '') {
					$rel = implode('/', array_map('rawurldecode', explode('/', trim($m[3], '/'))));
					$node = $folder->nodeExists($rel) ? $folder->get($rel) : null;
				}
			}
		} catch (\Throwable) {
			$node = null;
		}
		return $this->cache[$key] = $node;
	}

	/**
	 * Every file a note's links cover, each named as the manifest names it: the
	 * link itself, or for a file inside a linked folder the link plus
	 * `#<path inside it>` (segments URL-encoded, so the line has no spaces).
	 *
	 * @return array<string, File> name => file, sorted by name
	 * @throws NotesException when a linked folder is too big to stamp
	 */
	public function filesOf(string $uid, string $body): array {
		$out = [];
		$bytes = 0;
		foreach ($this->linksIn($body) as $url) {
			$node = $this->resolve($uid, $url);
			if ($node instanceof File) {
				$out[$url] = $node;
				$bytes += (int)$node->getSize();
			} elseif ($node instanceof Folder) {
				foreach ($this->walk($node, '') as $sub => $file) {
					$out[$url . '#' . implode('/', array_map('rawurlencode', explode('/', $sub)))] = $file;
					$bytes += (int)$file->getSize();
					if (count($out) > self::MAX_FILES) {
						throw new NotesException('The linked folders hold more than ' . self::MAX_FILES
							. ' files, too many to timestamp. Link the files that matter, or a smaller folder.');
					}
				}
			}
			if ($bytes > self::MAX_BYTES) {
				throw new NotesException('The linked files are larger than 20 GB, too much to timestamp. Link the files that matter, or a smaller folder.');
			}
		}
		ksort($out, SORT_STRING);
		return $out;
	}

	/** The file a manifest line names, for $uid now; null if it cannot be reached. */
	public function fileFor(string $uid, string $name): ?File {
		[$url, $sub] = array_pad(explode('#', $name, 2), 2, '');
		$node = $this->resolve($uid, $url);
		try {
			if ($node instanceof Folder && $sub !== '') {
				$node = $node->get(implode('/', array_map('rawurldecode', explode('/', $sub))));
			}
		} catch (\Throwable) {
			return null;
		}
		return $node instanceof File ? $node : null;
	}

	/**
	 * The folders of $uid's OWN files, outside the notes folder, that the notes
	 * of a notebook link into — one per top-level folder, since that is what a
	 * project folder is. For the share dialog's offer.
	 *
	 * @return list<array{path: string, name: string, links: int}>
	 */
	public function projectFolders(string $uid, string $notebookRel): array {
		$notes = $this->notes->getNotesFolder($uid);
		$notebook = $notes->get(trim($notebookRel, '/'));
		if (!($notebook instanceof Folder)) {
			return [];
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$notesPath = rtrim($notes->getPath(), '/') . '/';
		$found = [];
		foreach ($this->notesIn($notebook, true) as $note) {
			$body = NoteFormat::parse($this->notes->readContent($note))['body'];
			foreach ($this->linksIn($body) as $url) {
				$node = $this->resolve($uid, $url);
				if ($node === null || str_starts_with($node->getPath() . '/', $notesPath)
					|| !$node->getStorage()->instanceOfStorage(IHomeStorage::class)
					|| $node->getOwner()?->getUID() !== $uid) {
					continue; // not the user's own, or inside the notes folder
				}
				$rel = ltrim((string)$userFolder->getRelativePath($node->getPath()), '/');
				$top = explode('/', $rel)[0];
				if ($top === '') {
					continue;
				}
				// A file at the top of the user's files is offered by itself.
				$found[$top] = ($found[$top] ?? 0) + 1;
			}
		}
		ksort($found, SORT_STRING);
		$out = [];
		foreach ($found as $top => $n) {
			$out[] = ['path' => '/' . $top, 'name' => $top, 'links' => $n];
		}
		return $out;
	}

	/** @return \Generator<string, File> path inside $dir => file; hidden entries skipped */
	private function walk(Folder $dir, string $base): \Generator {
		foreach ($dir->getDirectoryListing() as $n) {
			$name = $n->getName();
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			$rel = $base === '' ? $name : $base . '/' . $name;
			if ($n instanceof Folder) {
				yield from $this->walk($n, $rel);
			} elseif ($n instanceof File) {
				yield $rel => $n;
			}
		}
	}

	/** @return \Generator<File> the .md notes under a notebook */
	private function notesIn(Folder $dir, bool $top): \Generator {
		foreach ($dir->getDirectoryListing() as $n) {
			$name = $n->getName();
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			if ($n instanceof Folder) {
				if (!NotesService::isSpecialDir($name, false)) {
					yield from $this->notesIn($n, false);
				}
			} elseif ($n instanceof File && str_ends_with($name, '.md')) {
				yield $n;
			}
		}
	}

	private function isThisServer(string $url): bool {
		$host = parse_url($url, PHP_URL_HOST);
		if ($host === null || $host === false) {
			return true; // relative
		}
		$own = parse_url((string)$this->config->getSystemValue('overwrite.cli.url', ''));
		$p = parse_url($url);
		return strcasecmp((string)($own['host'] ?? ''), (string)$host) === 0
			&& (int)($own['port'] ?? 0) === (int)($p['port'] ?? 0);
	}

	private function cluster(): ?object {
		if (!$this->appManager->isEnabledForAnyone('files_sharding') || !class_exists(self::CLUSTER_LINKS)
			|| trim((string)$this->config->getSystemValue('files_sharding_master_url', '')) === '') {
			return null;
		}
		try {
			return \OCP\Server::get(self::CLUSTER_LINKS);
		} catch (\Throwable) {
			return null;
		}
	}
}
