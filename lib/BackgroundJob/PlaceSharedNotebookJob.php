<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\BackgroundJob;

use OCA\MarkdownNotes\Service\JoplinSyncService;
use OCA\MarkdownNotes\Service\NotebookPlacer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
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
		private NotebookPlacer    $placer,
		private JoplinSyncService $sync,
		private IJobList          $jobList,
		private LoggerInterface   $logger,
	) {
		parent::__construct($time);
	}

	/** A failed placement is tried again on later cron runs, this many times in all. */
	private const ATTEMPTS = 3;

	/** @param array{uid?: string, mountPoint?: string, attempt?: int} $argument */
	protected function run($argument): void {
		$uid   = (string)($argument['uid'] ?? '');
		$mount = trim((string)($argument['mountPoint'] ?? ''), '/');
		if ($uid === '' || $mount === '') {
			return;
		}
		try {
			if ($this->placer->place($uid, $mount) === NotebookPlacer::MOVED) {
				// Their Joplin only sees what their index holds.
				$this->sync->rebuildIndex($uid);
			}
		} catch (\Throwable $e) {
			// Seen once, 2026-09-23, and not reproducible since: a placement failed
			// with "getUID() on null" in the cron run right after a deploy. A queued
			// job is removed once it has run, so without this the notebook would stay
			// where the share landed for good. Try again on a later run instead.
			$attempt = (int)($argument['attempt'] ?? 1);
			$again = $attempt < self::ATTEMPTS;
			$this->logger->warning('markdown_notes: could not place the shared notebook ' . $mount . ' for '
				. $uid . ' (attempt ' . $attempt . ' of ' . self::ATTEMPTS . ($again ? ', will retry' : ', giving up')
				. '): ' . $e->getMessage(), ['app' => 'markdown_notes', 'exception' => $e]);
			if ($again) {
				$this->jobList->add(self::class, ['uid' => $uid, 'mountPoint' => $mount, 'attempt' => $attempt + 1]);
			}
		}
	}
}
