<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The file-tree model: notebooks are folders, notes are .md files (Joplin
 * footer via NoteFormat). All paths in this API are RELATIVE to the user's
 * notes folder. Templates and attachments live in visible sibling folders that
 * are excluded from the notebook tree.
 */
class NotesService {
	/** Visible, special folders kept out of the notebook tree. */
	public const SPECIAL = ['Templates', 'attachments'];

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function notesFolderName(string $uid): string {
		$name = $this->config->getUserValue($uid, 'markdown_notes', 'notesdir', 'Notes');
		return trim($name, '/') ?: 'Notes';
	}

	public function setNotesFolderName(string $uid, string $name): void {
		$this->config->setUserValue($uid, 'markdown_notes', 'notesdir', trim($name, '/') ?: 'Notes');
	}

	private function notesFolder(string $uid): Folder {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$name = $this->notesFolderName($uid);
		if ($userFolder->nodeExists($name)) {
			$node = $userFolder->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
			throw new NotesException('Notes path is not a folder.');
		}
		return $userFolder->newFolder($name);
	}

	private function relNode(string $uid, string $rel): Node {
		$rel = trim($rel, '/');
		$folder = $this->notesFolder($uid);
		if ($rel === '') {
			return $folder;
		}
		return $folder->get($rel);
	}

	// ── Notebooks (folders) ─────────────────────────────────────────────────

	/** Nested notebook tree under the notes folder (folders only). */
	public function notebookTree(string $uid): array {
		return $this->walkNotebooks($this->notesFolder($uid), '', true);
	}

	private function walkNotebooks(Folder $folder, string $base, bool $top): array {
		$out = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!($node instanceof Folder)) {
				continue;
			}
			$name = $node->getName();
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			if ($top && in_array($name, self::SPECIAL, true)) {
				continue;
			}
			$rel = $base === '' ? $name : $base . '/' . $name;
			$out[] = [
				'name'     => $name,
				'path'     => $rel,
				'children' => $this->walkNotebooks($node, $rel, false),
			];
		}
		usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return $out;
	}

	public function createNotebook(string $uid, string $parentRel, string $name): array {
		$name = $this->sanitizeName($name);
		if ($name === '') {
			throw new NotesException('Invalid notebook name.');
		}
		$parent = $this->relNode($uid, $parentRel);
		if (!($parent instanceof Folder)) {
			throw new NotesException('Parent is not a notebook.');
		}
		$name = $this->uniqueName($parent, $name, true);
		$parent->newFolder($name);
		$rel = trim($parentRel, '/');
		return ['path' => $rel === '' ? $name : $rel . '/' . $name, 'name' => $name];
	}

	public function deleteNotebook(string $uid, string $rel): void {
		$rel = trim($rel, '/');
		if ($rel === '' || in_array($rel, self::SPECIAL, true)) {
			throw new NotesException('Refusing to delete this folder.');
		}
		$this->relNode($uid, $rel)->delete();
	}

	public function rename(string $uid, string $rel, string $targetRel): array {
		$node = $this->relNode($uid, $rel);
		$folder = $this->notesFolder($uid);
		$targetRel = trim($targetRel, '/');
		$node->move($folder->getPath() . '/' . $targetRel);
		return ['path' => $targetRel];
	}

	// ── Notes (.md files) ───────────────────────────────────────────────────

	/**
	 * Note metadata for a notebook. With $recursive, descends into sub-notebooks
	 * (for an "All notes" view); with $tag, only notes carrying that footer tag.
	 */
	public function listNotes(string $uid, string $notebookRel, bool $recursive = false, string $tag = ''): array {
		$node = $this->relNode($uid, $notebookRel);
		if (!($node instanceof Folder)) {
			throw new NotesException('Not a notebook.');
		}
		$out = [];
		$this->collectNotes($node, trim($notebookRel, '/'), $recursive, $tag, $out, true);
		usort($out, static fn ($a, $b) => $b['modified'] <=> $a['modified']);
		return $out;
	}

	private function collectNotes(Folder $folder, string $base, bool $recursive, string $tag, array &$out, bool $top): void {
		foreach ($folder->getDirectoryListing() as $file) {
			$name = $file->getName();
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			if ($file instanceof Folder) {
				if ($recursive && !($top && in_array($name, self::SPECIAL, true))) {
					$this->collectNotes($file, $base === '' ? $name : $base . '/' . $name, true, $tag, $out, false);
				}
				continue;
			}
			if (substr($name, -3) !== '.md') {
				continue;
			}
			$parsed = NoteFormat::parse($this->readContent($file));
			if ($tag !== '' && !in_array($tag, $parsed['tags'], true)) {
				continue;
			}
			$meta = $parsed['meta'];
			$rel = $base === '' ? $name : $base . '/' . $name;
			$out[] = [
				'path'           => $rel,
				'fileid'         => $file->getId(),
				'name'           => $name,
				'notebook'       => $base,
				'title'          => $parsed['title'] !== '' ? $parsed['title'] : substr($name, 0, -3),
				'tags'           => $parsed['tags'],
				'is_todo'        => !empty($meta['is_todo']),
				'todo_due'       => $meta['todo_due'] ?? '',
				'todo_completed' => !empty($meta['todo_completed']) && $meta['todo_completed'] !== '0',
				'excerpt'        => $this->excerpt($parsed['body']),
				'modified'       => $file->getMTime(),
				'created'        => $meta['created_time'] ?? '',
			];
		}
	}

	public function getNote(string $uid, string $rel): array {
		$file = $this->relNode($uid, $rel);
		if ($file instanceof Folder) {
			throw new NotesException('Not a note.');
		}
		$parsed = NoteFormat::parse($this->readContent($file));
		return [
			'path'   => trim($rel, '/'),
			'fileid' => $file->getId(),
			'title'  => $parsed['title'],
			'body'   => $parsed['body'],
			'tags'   => $parsed['tags'],
			'meta'   => $parsed['meta'],
		];
	}

	/**
	 * Save title/body/tags into an existing note, preserving unmanaged footer
	 * keys. $isTodo null = leave to-do state untouched; true/false converts the
	 * note to/from a to-do. $todoDue is epoch-ms (Joplin), '' clears the due date.
	 */
	public function saveNote(string $uid, string $rel, string $title, string $body, array $tags, ?bool $isTodo = null, string $todoDue = ''): array {
		$file = $this->relNode($uid, $rel);
		$parsed = NoteFormat::parse($this->readContent($file));
		$meta = $parsed['meta'];
		$meta = NoteFormat::withTags($meta, $tags);
		$meta['updated_time'] = $this->now();
		if (empty($meta['id'])) {
			$meta = ['id' => $this->newId(), 'created_time' => $meta['updated_time']] + $meta;
		}
		if ($isTodo !== null) {
			if ($isTodo) {
				$meta['is_todo'] = '1';
				$meta['todo_due'] = $todoDue !== '' ? $todoDue : null; // null → key dropped
			} else {
				// No longer a to-do: drop all to-do footer keys.
				$meta['is_todo'] = null;
				$meta['todo_due'] = null;
				$meta['todo_completed'] = null;
			}
		}
		$file->putContent(NoteFormat::serialize($title, $body, $meta));
		return $this->getNote($uid, $rel);
	}

	/** Mark a to-do done/undone. Joplin stores todo_completed as epoch-ms (0/absent = open). */
	public function setCompleted(string $uid, string $rel, bool $completed): array {
		$file = $this->relNode($uid, $rel);
		$parsed = NoteFormat::parse($this->readContent($file));
		$meta = $parsed['meta'];
		if (empty($meta['is_todo'])) {
			$meta['is_todo'] = '1'; // completing implies it's a to-do
		}
		$meta['todo_completed'] = $completed ? (string)$this->nowMs() : null;
		$meta['updated_time'] = $this->now();
		$file->putContent(NoteFormat::serialize($parsed['title'], $parsed['body'], $meta));
		return $this->getNote($uid, $rel);
	}

	/** Add tags to a note's footer (union), without touching title/body. */
	public function addTags(string $uid, string $rel, array $add): array {
		$file = $this->relNode($uid, $rel);
		$parsed = NoteFormat::parse($this->readContent($file));
		$tags = array_merge($parsed['tags'], array_map('trim', $add));
		$meta = NoteFormat::withTags($parsed['meta'], $tags);
		$meta['updated_time'] = $this->now();
		if (empty($meta['id'])) {
			$meta = ['id' => $this->newId(), 'created_time' => $meta['updated_time']] + $meta;
		}
		$file->putContent(NoteFormat::serialize($parsed['title'], $parsed['body'], $meta));
		return $this->getNote($uid, $rel);
	}

	/** Remove tags from a note's footer, without touching title/body. */
	public function removeTags(string $uid, string $rel, array $remove): array {
		$file = $this->relNode($uid, $rel);
		$parsed = NoteFormat::parse($this->readContent($file));
		$rm = array_map('trim', $remove);
		$tags = array_values(array_filter($parsed['tags'], static fn ($t) => !in_array($t, $rm, true)));
		$meta = NoteFormat::withTags($parsed['meta'], $tags);
		$meta['updated_time'] = $this->now();
		$file->putContent(NoteFormat::serialize($parsed['title'], $parsed['body'], $meta));
		return $this->getNote($uid, $rel);
	}

	/**
	 * Create a note (or to-do). A template may set the title (template_title),
	 * tags and body; $vars supplies values for the template's custom variables.
	 *
	 * @param array<string,string> $vars
	 */
	public function createNote(string $uid, string $notebookRel, string $title, string $templateRel = '', bool $isTodo = false, array $vars = []): array {
		$parent = $this->relNode($uid, $notebookRel);
		if (!($parent instanceof Folder)) {
			throw new NotesException('Not a notebook.');
		}
		$body = '';
		$tags = [];
		$tplTitle = '';
		if ($templateRel !== '') {
			$tpl = $this->applyTemplate($uid, $templateRel, $vars);
			$body = $tpl['body'];
			$tags = $tpl['tags'];
			$tplTitle = $tpl['title'];
		}
		$title = trim($title);
		if ($title === '') {
			$title = $tplTitle !== '' ? $tplTitle : 'New note';
		}
		$now = $this->now();
		$meta = ['id' => $this->newId(), 'created_time' => $now, 'updated_time' => $now];
		if ($isTodo) {
			$meta['is_todo'] = '1';
		}
		$meta = NoteFormat::withTags($meta, $tags);

		$fname = $this->uniqueName($parent, $this->sanitizeName($title) . '.md', false);
		$parent->newFile($fname, NoteFormat::serialize($title, $body, $meta));
		$base = trim($notebookRel, '/');
		return $this->getNote($uid, $base === '' ? $fname : $base . '/' . $fname);
	}

	public function deleteNote(string $uid, string $rel): void {
		$this->relNode($uid, $rel)->delete();
	}

	// ── Templates ───────────────────────────────────────────────────────────

	/** Seed the bundled templates into a visible Templates/ folder, once. */
	public function ensureTemplates(string $uid): void {
		$notes = $this->notesFolder($uid);
		$dir = $notes->nodeExists('Templates') && $notes->get('Templates') instanceof Folder
			? $notes->get('Templates')
			: $notes->newFolder('Templates');
		$bundled = __DIR__ . '/../../templates/notetemplates';
		foreach (glob($bundled . '/*.md') ?: [] as $path) {
			$name = basename($path);
			if (!$dir->nodeExists($name)) {
				$dir->newFile($name, (string)file_get_contents($path));
			}
		}
	}

	public function listTemplates(string $uid): array {
		$this->ensureTemplates($uid);
		$notes = $this->notesFolder($uid);
		if (!$notes->nodeExists('Templates')) {
			return [];
		}
		$dir = $notes->get('Templates');
		$out = [];
		foreach ($dir->getDirectoryListing() as $file) {
			$name = $file->getName();
			if ($file instanceof Folder || substr($name, -3) !== '.md') {
				continue;
			}
			$tpl = TemplateFormat::parse($this->readContent($file));
			// Render built-ins for a friendly dropdown label (no literal {{date}}).
			$title = $tpl['title'] !== '' ? TemplateFormat::render($tpl['title'], [], time()) : substr($name, 0, -3);
			$out[] = ['path' => 'Templates/' . $name, 'title' => $title, 'hasVars' => !empty($tpl['variables'])];
		}
		usort($out, static fn ($a, $b) => strcasecmp($a['title'], $b['title']));
		return $out;
	}

	/**
	 * Distinct tag names referenced by templates' `template_tags`, even if no note
	 * carries them yet. Used to enrich the add-tag autocomplete vocabulary so a
	 * template's intended tags guard against spelling/capitalisation slips.
	 *
	 * @return string[]
	 */
	public function templateTags(string $uid): array {
		$notes = $this->notesFolder($uid);
		if (!$notes->nodeExists('Templates') || !($notes->get('Templates') instanceof Folder)) {
			return [];
		}
		$tags = [];
		foreach ($notes->get('Templates')->getDirectoryListing() as $file) {
			$name = $file->getName();
			if ($file instanceof Folder || substr($name, -3) !== '.md') {
				continue;
			}
			$tpl = TemplateFormat::parse($this->readContent($file));
			foreach (array_filter(array_map('trim', explode(',', $tpl['tags']))) as $tg) {
				$tags[$tg] = true;
			}
		}
		return array_keys($tags);
	}

	/** Front matter of a template: its (raw) title, tags and custom variables to prompt for. */
	public function templateInfo(string $uid, string $templateRel): array {
		$tpl = TemplateFormat::parse($this->readContent($this->relNode($uid, $templateRel)));
		return ['title' => $tpl['title'], 'tags' => $tpl['tags'], 'variables' => $tpl['variables']];
	}

	/**
	 * Render a template into title/body/tags, substituting built-in date/time
	 * variables and the user-supplied custom-variable values.
	 *
	 * @param array<string,string> $vars
	 * @return array{title: string, body: string, tags: string[]}
	 */
	private function applyTemplate(string $uid, string $templateRel, array $vars = []): array {
		$tpl = TemplateFormat::parse($this->readContent($this->relNode($uid, $templateRel)));
		$now = time();
		$tagsStr = TemplateFormat::render($tpl['tags'], $vars, $now);
		return [
			'title' => TemplateFormat::render($tpl['title'], $vars, $now),
			'body'  => trim(TemplateFormat::render($tpl['body'], $vars, $now), "\n"),
			'tags'  => array_values(array_filter(array_map('trim', explode(',', $tagsStr)))),
		];
	}

	/** Distinct tags across all notes (for the tag filter). @return string[] */
	public function allTags(string $uid): array {
		$tags = [];
		$this->collectTags($this->notesFolder($uid), $tags, true);
		$tags = array_keys($tags);
		sort($tags, SORT_FLAG_CASE | SORT_STRING);
		return $tags;
	}

	private function collectTags(Folder $folder, array &$tags, bool $top): void {
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if ($name === '' || $name[0] === '.') {
				continue;
			}
			if ($node instanceof Folder) {
				if ($top && in_array($name, self::SPECIAL, true)) {
					continue;
				}
				$this->collectTags($node, $tags, false);
			} elseif (substr($name, -3) === '.md') {
				foreach (NoteFormat::parse($this->readContent($node))['tags'] as $t) {
					$tags[$t] = true;
				}
			}
		}
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	/**
	 * Read a file's content WITHOUT acquiring an NC file lock.
	 *
	 * NotesService scans every note on most calls (listNotes, allTags, the
	 * tag-filter view), and Node::getContent() takes a SHARED lock per read.
	 * Aborted/overlapping fetches from the web UI leak those shared locks into
	 * oc_file_locks, and a later move (which needs an EXCLUSIVE lock) then fails
	 * with LockedException. Reading straight off the local/NFS path avoids the
	 * lock entirely; we fall back to getContent() for storages with no local
	 * file (e.g. object store).
	 */
	public function readContent(Node $file): string {
		try {
			$storage = $file->getStorage();
			$local = $storage->getLocalFile($file->getInternalPath());
			if (is_string($local) && $local !== '' && is_file($local)) {
				$data = file_get_contents($local);
				if ($data !== false) {
					return $data;
				}
			}
		} catch (\Throwable $e) {
			// fall through to the locking read
		}
		return $file->getContent();
	}

	private function excerpt(string $body): string {
		$body = trim(preg_replace('/[#>*_`\-]+/', '', $body) ?? '');
		$body = preg_replace('/\s+/', ' ', $body) ?? '';
		return mb_substr($body, 0, 140);
	}

	private function sanitizeName(string $name): string {
		$name = str_replace(['/', '\\', "\0"], '', trim($name));
		return trim($name, '.') === '' ? $name : $name;
	}

	private function uniqueName(Folder $parent, string $name, bool $folder): string {
		if (!$parent->nodeExists($name)) {
			return $name;
		}
		$ext = '';
		$stem = $name;
		if (!$folder && ($dot = strrpos($name, '.')) !== false) {
			$ext = substr($name, $dot);
			$stem = substr($name, 0, $dot);
		}
		for ($i = 1; $i < 1000; $i++) {
			$cand = $stem . ' (' . $i . ')' . $ext;
			if (!$parent->nodeExists($cand)) {
				return $cand;
			}
		}
		throw new NotesException('Could not find a free name.');
	}

	private function newId(): string {
		return bin2hex(random_bytes(16));
	}

	private function now(): string {
		return gmdate('Y-m-d\TH:i:s') . '.000Z';
	}

	/** Current time as Joplin-style epoch milliseconds. */
	private function nowMs(): int {
		return (int)round(microtime(true) * 1000);
	}
}
