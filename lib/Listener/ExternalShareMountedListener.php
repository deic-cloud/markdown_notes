<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Listener;

use OCA\MarkdownNotes\BackgroundJob\PlaceSharedNotebookJob;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * A folder shared from another silo has been mounted for one of our users
 * (files_sharding's ExternalShareMountedEvent — core has no event for this,
 * because the mount comes from our mirror of the master's share table). Queue
 * the job that decides whether it is a notebook and, if so, places it.
 *
 * Registered only when files_sharding is present; without it this app is
 * single-node and the ShareCreatedListener covers everything.
 *
 * @implements IEventListener<Event>
 */
class ExternalShareMountedListener implements IEventListener {
	public function __construct(
		private IJobList $jobList,
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
		$this->jobList->add(PlaceSharedNotebookJob::class, ['uid' => $uid, 'mountPoint' => $mount]);
	}
}
