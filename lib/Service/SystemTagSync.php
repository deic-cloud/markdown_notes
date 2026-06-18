<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Keeps a note's footer `tags:` (authoritative) and NC systemtags (the live
 * Files-sidebar / meta_data surface) in sync.
 *
 *  push()  footer → systemtags   — after a save/create in the Notes app
 *  pull()  systemtags → footer   — when tags are changed via the Files sidebar
 *                                  or meta_data (driven by MapperEvent)
 *
 * Footer is the source of truth; systemtags mirror it. The push/pull pair is
 * loop-safe: push records the fileid as in-flight so the assign/unassign
 * events it triggers are ignored by pull, and both sides no-op when the tag
 * sets already match. Only user-visible+assignable tags are managed (system /
 * AI-generated tags are left untouched). Uses NC core systemtags only — works
 * without meta_data (which merely adds the typed-attribute layer on top).
 */
class SystemTagSync {
	/** fileids currently being pushed, so our own events don't echo back. */
	private static array $inFlight = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
		private NotesService $notesService,
		private LoggerInterface $logger,
	) {
	}

	/** Footer → systemtags: make the file's user tags exactly match $tags. */
	public function push(int $fileid, array $tags): void {
		if ($fileid <= 0) {
			return;
		}
		try {
			$desired = [];
			foreach ($this->normalize($tags) as $name) {
				$desired[$this->ensureTag($name)] = true;
			}
			$current = $this->managedTagIds($fileid);
			$toAssign = array_diff(array_keys($desired), $current);
			$toUnassign = array_diff($current, array_keys($desired));
			if (empty($toAssign) && empty($toUnassign)) {
				return;
			}
			self::$inFlight[$fileid] = true;
			try {
				if (!empty($toAssign)) {
					$this->tagMapper->assignTags((string)$fileid, 'files', array_map('strval', $toAssign));
				}
				if (!empty($toUnassign)) {
					$this->tagMapper->unassignTags((string)$fileid, 'files', array_map('strval', $toUnassign));
				}
			} finally {
				unset(self::$inFlight[$fileid]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('notes: footer→systemtags failed for ' . $fileid . ': ' . $e->getMessage(), ['app' => 'markdown_notes']);
		}
	}

	/** Systemtags → footer: rewrite a note's footer tags to match its user systemtags. */
	public function pull(int $fileid): void {
		if ($fileid <= 0 || isset(self::$inFlight[$fileid])) {
			return;
		}
		try {
			$nodes = $this->rootFolder->getById($fileid);
			if (empty($nodes)) {
				return;
			}
			$node = $nodes[0];
			if (!$this->isNote($node)) {
				return;
			}
			$names = array_values(array_map(static fn (ISystemTag $t) => $t->getName(), $this->managedTags($fileid)));
			$parsed = NoteFormat::parse($this->notesService->readContent($node));
			$a = $names;
			$b = $parsed['tags'];
			sort($a);
			sort($b);
			if ($a === $b) {
				return;
			}
			$meta = NoteFormat::withTags($parsed['meta'], $names);
			$meta['updated_time'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
			$node->putContent(NoteFormat::serialize($parsed['title'], $parsed['body'], $meta));
		} catch (\Throwable $e) {
			$this->logger->warning('notes: systemtags→footer failed for ' . $fileid . ': ' . $e->getMessage(), ['app' => 'markdown_notes']);
		}
	}

	/**
	 * Map tag name → systemtag colour (as meta_data shows them). '' when the
	 * tag has no systemtag yet (e.g. added via a raw footer edit) or no colour.
	 *
	 * @param string[] $names
	 * @return array<string,string>
	 */
	public function tagColors(array $names): array {
		$out = [];
		foreach ($names as $name) {
			try {
				$out[$name] = (string)($this->tagManager->getTag($name, true, true)->getColor() ?? '');
			} catch (\Throwable $e) {
				$out[$name] = '';
			}
		}
		return $out;
	}

	/**
	 * The full assignable tag vocabulary (all systemtags), for autocomplete and
	 * case-insensitive reuse — not just tags currently on a note.
	 *
	 * @return array{name: string, color: string}[]
	 */
	public function allSystemTags(): array {
		$out = [];
		try {
			foreach ($this->tagManager->getAllTags(true) as $tag) {
				if ($tag->isUserAssignable()) {
					$out[] = ['name' => $tag->getName(), 'color' => (string)($tag->getColor() ?? '')];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('notes: listing systemtags failed: ' . $e->getMessage(), ['app' => 'markdown_notes']);
		}
		usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	/** User-visible+assignable systemtags currently on the file. @return ISystemTag[] */
	private function managedTags(int $fileid): array {
		$map = $this->tagMapper->getTagIdsForObjects([(string)$fileid], 'files');
		$ids = $map[(string)$fileid] ?? [];
		if (empty($ids)) {
			return [];
		}
		$tags = $this->tagManager->getTagsByIds($ids);
		return array_filter($tags, static fn (ISystemTag $t) => $t->isUserVisible() && $t->isUserAssignable());
	}

	/** @return int[] tag ids of managed tags on the file */
	private function managedTagIds(int $fileid): array {
		return array_map(static fn (ISystemTag $t) => (int)$t->getId(), array_values($this->managedTags($fileid)));
	}

	private function ensureTag(string $name): int {
		try {
			return (int)$this->tagManager->getTag($name, true, true)->getId();
		} catch (TagNotFoundException $e) {
			return (int)$this->tagManager->createTag($name, true, true)->getId();
		}
	}

	private function isNote(Node $node): bool {
		$name = $node->getName();
		if ($name === '' || $name[0] === '.' || substr($name, -3) !== '.md') {
			return false;
		}
		$owner = $node->getOwner();
		if ($owner === null) {
			return false;
		}
		$notesName = $this->notesService->notesFolderName($owner->getUID());
		return (bool)preg_match('#/files/' . preg_quote($notesName, '#') . '/#', $node->getPath());
	}

	/** @return string[] */
	private function normalize(array $tags): array {
		return array_values(array_unique(array_filter(array_map('trim', $tags))));
	}
}
