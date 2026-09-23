<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Listener;

use OCA\MarkdownNotes\BackgroundJob\PlaceSharedNotebookJob;
use OCA\MarkdownNotes\BackgroundJob\ReindexJob;
use OCA\MarkdownNotes\Service\NotebookPlacer;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * A folder shared from another silo has just been mounted for one of our users
 * (files_sharding's ExternalShareMountedEvent — core has no event for this,
 * because the mount comes from our mirror of the master's share table). If it
 * is a notebook, put it in their notes folder NOW, in the request that mounted
 * it, so it is there when they look rather than after the next cron run.
 *
 * Deciding costs one read of the owner's folder across the network, for the
 * `.notebook` marker. If anything fails, the background job tries again on
 * later cron runs. The recipient's Joplin index is always rebuilt out of band:
 * it walks the whole notes tree.
 *
 * Registered only when files_sharding is present; without it this app is
 * single-node and the ShareCreatedListener covers everything.
 *
 * @implements IEventListener<Event>
 */
class ExternalShareMountedListener implements IEventListener {
	public function __construct(
		private NotebookPlacer  $placer,
		private IJobList        $jobList,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getUserId') || !method_exists($event, 'getMountPoint')) {
			return;
		}
		$uid = (string)$event->getUserId();
		$mount = (string)$event->getMountPoint();
		if ($uid === '' || $mount === '') {
			return;
		}
		try {
			$result = $this->placer->place($uid, $mount);
			if ($result === NotebookPlacer::MOVED) {
				$this->jobList->add(ReindexJob::class, ['uid' => $uid]);
			} elseif ($result === NotebookPlacer::NOT_VISIBLE) {
				$this->logger->info('markdown_notes: ' . $mount . ' is not yet visible to ' . $uid
					. ' at reception; leaving it to the background job', ['app' => 'markdown_notes']);
				$this->jobList->add(PlaceSharedNotebookJob::class, ['uid' => $uid, 'mountPoint' => $mount]);
			}
		} catch (\Throwable $e) {
			$this->logger->info('markdown_notes: placing ' . $mount . ' for ' . $uid . ' at reception failed ('
				. $e->getMessage() . '); leaving it to the background job', ['app' => 'markdown_notes', 'exception' => $e]);
			$this->jobList->add(PlaceSharedNotebookJob::class, ['uid' => $uid, 'mountPoint' => $mount]);
		}
	}
}
