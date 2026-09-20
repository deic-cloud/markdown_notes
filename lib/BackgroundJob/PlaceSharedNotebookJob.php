<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\BackgroundJob;

use OCA\MarkdownNotes\Service\JoplinSyncService;
use OCA\MarkdownNotes\Service\NotesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * A folder shared from another silo has been mounted for one of our users. If
 * it is a notebook, move it into their notes folder and rebuild their index.
 *
 * Out of band on purpose: deciding means reading the mount, which for a
 * federated share is a request to the owner's node, and rebuilding the index
 * walks the whole tree. Neither belongs in the share-sync request.
 *
 * How we know it is a notebook: the `.notebook` marker the sharing side writes
 * into it. A marker travels with the data, so any node can read it — unlike the
 * folder's provenance, which only the owner's node knows.
 *
 * @psalm-suppress UnusedClass — registered by name in the job list
 */
class PlaceSharedNotebookJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private NotesService      $notesService,
		private JoplinSyncService $sync,
		private IRootFolder       $rootFolder,
		private LoggerInterface   $logger,
	) {
		parent::__construct($time);
	}

	/** @param array{uid?: string, mountPoint?: string} $argument */
	protected function run($argument): void {
		$uid   = (string)($argument['uid'] ?? '');
		$mount = trim((string)($argument['mountPoint'] ?? ''), '/');
		if ($uid === '' || $mount === '') {
			return;
		}
		try {
			\OC_Util::setupFS($uid);
			$userFolder = $this->rootFolder->getUserFolder($uid);
			if (!$userFolder->nodeExists($mount)) {
				return; // moved, declined or gone again
			}
			$node = $userFolder->get($mount);
			if (!($node instanceof Folder) || !$this->notesService->looksLikeNotebook($node)) {
				return;
			}
			$notesName = $this->notesService->notesFolderName($uid);
			if (str_starts_with($mount . '/', $notesName . '/')) {
				return; // already inside the notes folder
			}
			if (!$userFolder->nodeExists($notesName)) {
				$userFolder->newFolder($notesName);
			}
			$base = $node->getName();
			$target = $notesName . '/' . $base;
			for ($i = 2; $userFolder->nodeExists($target) && $i < 50; $i++) {
				$target = $notesName . '/' . $base . ' (' . $i . ')';
			}
			$node->move($userFolder->getPath() . '/' . $target);
			$this->logger->info('markdown_notes: placed the shared notebook ' . $mount . ' at ' . $target
				. ' for ' . $uid, ['app' => 'markdown_notes']);
			// Their Joplin only sees what their index holds.
			$this->sync->rebuildIndex($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: could not place the shared notebook ' . $mount . ' for '
				. $uid . ': ' . $e->getMessage(), ['app' => 'markdown_notes']);
		}
	}
}
