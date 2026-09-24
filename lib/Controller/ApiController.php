<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Controller;

use OCA\MarkdownNotes\Service\FileLinkService;
use OCA\MarkdownNotes\Service\LinkedFiles;
use OCA\MarkdownNotes\Service\MetaDataBridge;
use OCA\MarkdownNotes\Service\NotesException;
use OCA\MarkdownNotes\Service\NotesService;
use OCA\MarkdownNotes\Service\SystemTagSync;
use OCA\MarkdownNotes\Service\TimestampService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private NotesService $notesService,
		private SystemTagSync $systemTagSync,
		private MetaDataBridge $metaBridge,
		private TimestampService $timestamps,
		private FileLinkService $fileLinks,
		private LinkedFiles $linkedFiles,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function tree(): DataResponse {
		return $this->run(function () {
			$names = $this->notesService->allTags($this->uid());
			$colors = $this->systemTagSync->tagColors($names);
			// Autocomplete vocabulary = all system tags + tags referenced by
			// templates (which may not be system tags yet), so it guards against
			// spelling/capitalisation slips when adding a tag.
			$vocab = $this->systemTagSync->allSystemTags();
			$seen = [];
			foreach ($vocab as $v) {
				$seen[mb_strtolower($v['name'])] = true;
			}
			foreach ($this->notesService->templateTags($this->uid()) as $tg) {
				if (!isset($seen[mb_strtolower($tg)])) {
					$vocab[] = ['name' => $tg, 'color' => ''];
					$seen[mb_strtolower($tg)] = true;
				}
			}
			usort($vocab, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
			$tree = $this->notesService->notebookTree($this->uid());
			return [
				'notesFolder' => $this->notesService->notesFolderName($this->uid()),
				// Whether this server has a timestamp authority at all: the Timestamp
				// button stays hidden where there is none.
				'timestamping' => $this->timestamps->isConfigured(),
				'notebooks'   => $tree,
				'noteCount'   => $this->notesService->totalNoteCount($this->uid(), $tree),
				'tags'        => array_map(static fn ($n) => ['name' => $n, 'color' => $colors[$n] ?? ''], $names),
				'vocabulary'  => $vocab,
			];
		});
	}

	#[NoAdminRequired]
	public function listNotes(string $notebook = '', string $recursive = '', string $tag = ''): DataResponse {
		return $this->run(function () use ($notebook, $recursive, $tag) {
			$notes = $this->notesService->listNotes(
				$this->uid(), $notebook, $recursive === '1' || $recursive === 'true', $tag);
			// When a tag with meta_data fields is the filter, attach editable columns.
			$columns = [];
			if ($tag !== '') {
				$cols = $this->metaBridge->columnsFor($tag);
				if ($cols !== null && !empty($cols['keys'])) {
					$columns = $cols['keys'];
					// Columns are for overview, so only a template decides them: a
					// template naming this tag lists (as its variables) WHICH of the
					// tag's existing meta_data fields to show; a tag no template describes
					// shows no columns at all (a schema may have a dozen fields — Frederik,
					// 2026-09-16). Fields are never created or changed here — the
					// Metadata app owns the schema.
					$tmap = [];
					foreach ($this->notesService->templateVariablesForTag($this->uid(), $tag) as $v) {
						$tmap[$v['name']] = $v['type'];
					}
					$columns = array_values(array_filter($columns, static fn ($c) => isset($tmap[$c['name']])));
					// meta_data datetime fields show date-only or date+time in the
					// list per the template's declared type (date vs datetime).
					foreach ($columns as &$c) {
						if (($c['type'] ?? '') === 'datetime') {
							$c['display'] = (($tmap[$c['name']] ?? '') === 'date') ? 'date' : 'datetime';
						}
					}
					unset($c);
					if ($columns !== []) {
						foreach ($notes as &$n) {
							$n['cols'] = $this->metaBridge->valuesFor((int)$n['fileid'], $cols['tagId']);
						}
						unset($n);
					}
				}
			}
			return ['notes' => $notes, 'columns' => $columns];
		});
	}

	#[NoAdminRequired]
	public function getNote(string $path): DataResponse {
		return $this->run(fn () => $this->notesService->getNote($this->uid(), $path));
	}

	#[NoAdminRequired]
	public function templates(): DataResponse {
		return $this->run(fn () => $this->notesService->listTemplates($this->uid()));
	}

	#[NoAdminRequired]
	public function saveNote(string $path, string $title = '', string $body = '', array $tags = [], string $is_todo = '', string $todo_due = ''): DataResponse {
		return $this->run(function () use ($path, $title, $body, $tags, $is_todo, $todo_due) {
			$todo = $is_todo === '' ? null : ($is_todo === '1' || $is_todo === 'true');
			$note = $this->notesService->saveNote($this->uid(), $path, $title, $body, $tags, $todo, $todo_due);
			$this->systemTagSync->push($this->uid(), (int)$note['fileid'], $note['tags']);
			return $note;
		});
	}

	#[NoAdminRequired]
	public function createNote(string $notebook = '', string $title = '', string $template = '', string $is_todo = '', string $vars = ''): DataResponse {
		return $this->run(function () use ($notebook, $title, $template, $is_todo, $vars) {
			$varsArr = $vars !== '' ? (json_decode($vars, true) ?: []) : [];
			$isTodo = $is_todo === '1' || $is_todo === 'true';
			$note = $this->notesService->createNote($this->uid(), $notebook, $title, $template, $isTodo, $varsArr);
			$this->systemTagSync->push($this->uid(), (int)$note['fileid'], $note['tags']);
			// Store the entered template variables as metadata values — only where
			// one of the note's tags already HAS a field of that name in the Metadata
			// app. Fields are never created or changed by this app; a variable with
			// no matching field just fills the note body. No-op without meta_data.
			if ($template !== '' && !empty($varsArr)) {
				$info = $this->notesService->templateInfo($this->uid(), $template);
				foreach ($info['variables'] as $v) {
					if (!array_key_exists($v['name'], $varsArr) || $varsArr[$v['name']] === '') {
						continue;
					}
					foreach ($note['tags'] as $tagName) {
						$keyId = $this->metaBridge->keyId($tagName, $v['name']);
						if ($keyId !== null) {
							$this->metaBridge->setValue($tagName, (int)$note['fileid'], $keyId, (string)$varsArr[$v['name']]);
						}
					}
				}
			}
			return $note;
		});
	}

	#[NoAdminRequired]
	public function setCompleted(string $path, string $completed = '1'): DataResponse {
		return $this->run(fn () => $this->notesService->setCompleted(
			$this->uid(), $path, $completed === '1' || $completed === 'true'));
	}

	#[NoAdminRequired]
	public function setDue(string $path, string $due = ''): DataResponse {
		return $this->run(fn () => $this->notesService->setDue($this->uid(), $path, $due));
	}

	#[NoAdminRequired]
	public function setTodo(string $path, string $is_todo = '1'): DataResponse {
		return $this->run(fn () => $this->notesService->setTodo(
			$this->uid(), $path, $is_todo === '1' || $is_todo === 'true'));
	}

	#[NoAdminRequired]
	public function templateInfo(string $path): DataResponse {
		return $this->run(fn () => $this->notesService->templateInfo($this->uid(), $path));
	}

	#[NoAdminRequired]
	public function setMeta(string $path, string $tag, int $keyId, string $value = ''): DataResponse {
		return $this->run(function () use ($path, $tag, $keyId, $value) {
			$note = $this->notesService->getNote($this->uid(), $path); // resolves fileid
			$ok = $this->metaBridge->setValue($tag, (int)$note['fileid'], $keyId, $value);
			return ['ok' => $ok];
		});
	}

	#[NoAdminRequired]
	public function addTags(string $path, array $tags = []): DataResponse {
		return $this->run(function () use ($path, $tags) {
			$note = $this->notesService->addTags($this->uid(), $path, $tags);
			$this->systemTagSync->push($this->uid(), (int)$note['fileid'], $note['tags']);
			return $note;
		});
	}

	#[NoAdminRequired]
	public function untag(string $path, array $tags = []): DataResponse {
		return $this->run(function () use ($path, $tags) {
			$note = $this->notesService->removeTags($this->uid(), $path, $tags);
			$this->systemTagSync->push($this->uid(), (int)$note['fileid'], $note['tags']);
			return $note;
		});
	}

	#[NoAdminRequired]
	public function deleteNote(string $path): DataResponse {
		return $this->run(function () use ($path) {
			$this->notesService->deleteNote($this->uid(), $path);
			return ['ok' => true];
		});
	}

	/**
	 * Delete many notes in ONE request. Idempotent per note: an already-gone path
	 * is counted as missing, not an error, so a retry never storms 404s. Reclaims
	 * orphaned attachments once at the end. Lets a bulk delete of hundreds of notes
	 * be a single round-trip instead of one POST per note (which raced itself).
	 *
	 * @param string[] $paths
	 */
	#[NoAdminRequired]
	public function deleteNotes(array $paths = []): DataResponse {
		return $this->run(function () use ($paths) {
			$uid = $this->uid();
			$deleted = 0;
			$missing = 0;
			$candidates = [];
			foreach ($paths as $path) {
				try {
					// Collect first, clean once at the end: one scan for the batch.
					$candidates = array_merge($candidates, $this->notesService->deleteNote($uid, (string)$path, false));
					$deleted++;
				} catch (NotFoundException $e) {
					$missing++;
				} catch (NotesException $e) {
					$missing++;
				}
			}
			$reclaimed = $deleted > 0 ? $this->notesService->cleanupAttachments($uid, $candidates) : 0;
			return ['deleted' => $deleted, 'missing' => $missing, 'reclaimed' => $reclaimed];
		});
	}

	/**
	 * Full sweep for attachments no note references any more. No longer called
	 * automatically — deleting a note or notebook now cleans up exactly what it
	 * referenced (NotesService::cleanupAttachments). Kept for the one case a
	 * scoped cleanup cannot see: an image unlinked by *editing* a note rather
	 * than deleting it.
	 */
	#[NoAdminRequired]
	public function gc(): DataResponse {
		return $this->run(fn () => ['deleted' => $this->notesService->gcOrphanAttachments($this->uid())]);
	}

	#[NoAdminRequired]
	public function createNotebook(string $parent = '', string $name = ''): DataResponse {
		return $this->run(fn () => $this->notesService->createNotebook($this->uid(), $parent, $name));
	}

	#[NoAdminRequired]
	public function deleteNotebook(string $path): DataResponse {
		return $this->run(function () use ($path) {
			$this->notesService->deleteNotebook($this->uid(), $path);
			return ['ok' => true];
		});
	}

	/**
	 * Delete many notebooks (and their contents) in ONE request. Idempotent: a
	 * path already gone — e.g. a child whose selected parent was deleted first —
	 * counts as missing, not an error. Reclaims orphaned attachments once at end.
	 *
	 * @param string[] $paths
	 */
	#[NoAdminRequired]
	public function deleteNotebooks(array $paths = []): DataResponse {
		return $this->run(function () use ($paths) {
			$uid = $this->uid();
			$deleted = 0;
			$missing = 0;
			$candidates = [];
			foreach ($paths as $path) {
				try {
					$candidates = array_merge($candidates, $this->notesService->deleteNotebook($uid, (string)$path, false));
					$deleted++;
				} catch (NotFoundException $e) {
					$missing++;
				} catch (NotesException $e) {
					$missing++;
				}
			}
			$reclaimed = $deleted > 0 ? $this->notesService->cleanupAttachments($uid, $candidates) : 0;
			return ['deleted' => $deleted, 'missing' => $missing, 'reclaimed' => $reclaimed];
		});
	}

	/**
	 * Every trusted timestamp of one note, each with its verification. Also says
	 * whether this server has an authority at all, so the UI can hide the button.
	 */
	#[NoAdminRequired]
	public function timestamps(string $path): DataResponse {
		return $this->run(fn () => [
			'configured' => $this->timestamps->isConfigured(),
			'stamps'     => $this->timestamps->listFor($this->uid(), $path),
		]);
	}

	#[NoAdminRequired]
	public function timestamp(string $path): DataResponse {
		return $this->run(fn () => $this->timestamps->stamp($this->uid(), $path));
	}

	/** A link to a file or folder of the user's, to insert into a note (FileLinkService). */
	#[NoAdminRequired]
	public function fileLink(string $path): DataResponse {
		return $this->run(fn () => $this->fileLinks->linkFor($this->uid(), $path));
	}

	/** The user's own folders a notebook's notes link into — offered for sharing with the notebook. */
	#[NoAdminRequired]
	public function linkedFolders(string $notebook): DataResponse {
		return $this->run(fn () => $this->linkedFiles->projectFolders($this->uid(), $notebook));
	}

	#[NoAdminRequired]
	public function rename(string $path, string $target): DataResponse {
		return $this->run(fn () => $this->notesService->rename($this->uid(), $path, $target));
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function run(callable $fn): DataResponse {
		try {
			return new DataResponse($fn());
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => 'Not found'], 404);
		} catch (NotesException $e) {
			return new DataResponse(['message' => $e->getMessage()], 400);
		}
	}
}
