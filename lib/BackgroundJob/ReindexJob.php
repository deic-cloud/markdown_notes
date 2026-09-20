<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\BackgroundJob;

use OCA\MarkdownNotes\Service\JoplinSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Rebuild a user's Joplin index out of band. Queued when a notebook is shared
 * with someone: their Joplin only sees what the index holds, so a notebook that
 * arrives by share would otherwise stay invisible there until the next manual
 * reindex. Doing it inline would make the share request wait for a full walk of
 * the recipient's notes tree.
 *
 * @psalm-suppress UnusedClass — registered by name in the job list
 */
class ReindexJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private JoplinSyncService $sync,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/** @param array{uid?: string} $argument */
	protected function run($argument): void {
		$uid = (string)($argument['uid'] ?? '');
		if ($uid === '') {
			return;
		}
		try {
			\OC_Util::setupFS($uid);
			$counts = $this->sync->rebuildIndex($uid);
			$this->logger->info('markdown_notes: reindexed ' . $uid . ' after a notebook share: '
				. json_encode($counts), ['app' => 'markdown_notes']);
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: reindex of ' . $uid . ' failed: ' . $e->getMessage(),
				['app' => 'markdown_notes']);
		}
	}
}
