<?php

declare(strict_types=1);

namespace OCA\Notes\Controller;

use OCA\Notes\Service\NotesException;
use OCA\Notes\Service\NotesService;
use OCA\Notes\Service\SystemTagSync;
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
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function tree(): DataResponse {
		return $this->run(function () {
			$names = $this->notesService->allTags($this->uid());
			$colors = $this->systemTagSync->tagColors($names);
			return [
				'notesFolder' => $this->notesService->notesFolderName($this->uid()),
				'notebooks'   => $this->notesService->notebookTree($this->uid()),
				'tags'        => array_map(static fn ($n) => ['name' => $n, 'color' => $colors[$n] ?? ''], $names),
				'vocabulary'  => $this->systemTagSync->allSystemTags(),
			];
		});
	}

	#[NoAdminRequired]
	public function listNotes(string $notebook = '', string $recursive = '', string $tag = ''): DataResponse {
		return $this->run(fn () => $this->notesService->listNotes(
			$this->uid(), $notebook, $recursive === '1' || $recursive === 'true', $tag));
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
	public function saveNote(string $path, string $title = '', string $body = '', array $tags = []): DataResponse {
		return $this->run(function () use ($path, $title, $body, $tags) {
			$note = $this->notesService->saveNote($this->uid(), $path, $title, $body, $tags);
			$this->systemTagSync->push((int)$note['fileid'], $note['tags']);
			return $note;
		});
	}

	#[NoAdminRequired]
	public function createNote(string $notebook = '', string $title = '', string $template = ''): DataResponse {
		return $this->run(function () use ($notebook, $title, $template) {
			$note = $this->notesService->createNote($this->uid(), $notebook, $title, $template);
			$this->systemTagSync->push((int)$note['fileid'], $note['tags']);
			return $note;
		});
	}

	#[NoAdminRequired]
	public function addTags(string $path, array $tags = []): DataResponse {
		return $this->run(function () use ($path, $tags) {
			$note = $this->notesService->addTags($this->uid(), $path, $tags);
			$this->systemTagSync->push((int)$note['fileid'], $note['tags']);
			return $note;
		});
	}

	#[NoAdminRequired]
	public function untag(string $path, array $tags = []): DataResponse {
		return $this->run(function () use ($path, $tags) {
			$note = $this->notesService->removeTags($this->uid(), $path, $tags);
			$this->systemTagSync->push((int)$note['fileid'], $note['tags']);
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
