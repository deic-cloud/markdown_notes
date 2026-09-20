<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Listener;

use OCA\MarkdownNotes\BackgroundJob\ReindexJob;
use OCA\MarkdownNotes\Service\NotesService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * When a NOTEBOOK is shared, put it in the recipient's own notes folder.
 *
 * A received share is mounted at the top of the recipient's files, where the
 * Notes app does not look — so a shared notebook would be invisible until the
 * recipient found it and moved it themselves, and our own DAV conceal gate
 * hides received shares from sync clients, so they could only do that in the
 * browser. Moving it for them is both kinder and more reliable. Their Joplin
 * needs the notebook in their index too, so a reindex is queued.
 *
 * Only shares of a folder that lies inside the SHARER's notes folder are
 * touched; everything else is left exactly as Nextcloud placed it. Failures are
 * logged and never break the sharing operation.
 *
 * @implements IEventListener<ShareCreatedEvent>
 */
class ShareCreatedListener implements IEventListener {
	public function __construct(
		private NotesService    $notesService,
		private IRootFolder     $rootFolder,
		private IShareManager   $shareManager,
		private IGroupManager   $groupManager,
		private IJobList        $jobList,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof ShareCreatedEvent)) {
			return;
		}
		$share = $event->getShare();
		$type  = $share->getShareType();
		try {
			if (!$this->isNotebook($share)) {
				return;
			}
			// Mark it whatever kind of share this is: when the share crosses to
			// another silo, the marker is the only thing telling that node this is
			// a notebook (see PlaceSharedNotebookJob).
			$node = $share->getNode();
			if ($node instanceof Folder) {
				$this->notesService->markAsNotebook($node);
			}
			if ($type !== IShare::TYPE_USER && $type !== IShare::TYPE_GROUP) {
				return; // a federated recipient is placed by their own node
			}
			foreach ($this->recipients($share) as $uid) {
				$this->place($share, $uid);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: could not place a shared notebook: ' . $e->getMessage(),
				['app' => 'markdown_notes']);
		}
	}

	/** Is the shared node a folder inside the sharer's notes folder? */
	private function isNotebook(IShare $share): bool {
		$node = $share->getNode();
		if (!($node instanceof Folder)) {
			return false;
		}
		$owner = $share->getShareOwner();
		if ($owner === '') {
			return false;
		}
		$notesPath = rtrim($this->notesService->getNotesFolder($owner)->getPath(), '/');
		$nodePath  = rtrim($node->getPath(), '/');
		return $nodePath !== $notesPath && str_starts_with($nodePath, $notesPath . '/');
	}

	/** @return string[] uids that will receive the share */
	private function recipients(IShare $share): array {
		if ($share->getShareType() === IShare::TYPE_USER) {
			return [$share->getSharedWith()];
		}
		$group = $this->groupManager->get($share->getSharedWith());
		if ($group === null) {
			return [];
		}
		$uids = [];
		foreach ($group->getUsers() as $user) {
			if ($user->getUID() !== $share->getShareOwner()) {
				$uids[] = $user->getUID();
			}
		}
		return $uids;
	}

	/** Move one recipient's copy into their notes folder and queue their reindex. */
	private function place(IShare $share, string $uid): void {
		if ($uid === '' || $uid === $share->getShareOwner()) {
			return;
		}
		try {
			$notesName = $this->notesService->notesFolderName($uid);
			$userFolder = $this->rootFolder->getUserFolder($uid);
			if (!$userFolder->nodeExists($notesName)) {
				$userFolder->newFolder($notesName);
			}
			$base = $share->getNode()->getName();
			$target = '/' . $notesName . '/' . $base;
			// Do not land on top of something the recipient already has there.
			for ($i = 2; $userFolder->nodeExists(ltrim($target, '/')) && $i < 50; $i++) {
				$target = '/' . $notesName . '/' . $base . ' (' . $i . ')';
			}
			$share->setTarget($target);
			$this->shareManager->moveShare($share, $uid);
			$this->jobList->add(ReindexJob::class, ['uid' => $uid]);
			$this->logger->info('markdown_notes: placed shared notebook at ' . $target . ' for ' . $uid,
				['app' => 'markdown_notes']);
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: could not place the shared notebook for ' . $uid . ': '
				. $e->getMessage(), ['app' => 'markdown_notes']);
		}
	}
}
