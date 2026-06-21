<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Command;

use OCA\MarkdownNotes\Service\JoplinSyncService;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ markdown_notes:reindex [user]
 *
 * Rebuilds the Joplin sync index from the on-disk notes tree so that notes,
 * notebooks and tags created in the web UI become visible to a Joplin
 * download. Safe to re-run (idempotent); resources are preserved.
 */
class Reindex extends Command {
	public function __construct(
		private JoplinSyncService $sync,
		private IUserManager $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('markdown_notes:reindex')
			->setDescription('Rebuild the Joplin sync index (folders, notes, tags, links) from the notes folder.')
			->addArgument('user', InputArgument::OPTIONAL, 'User to reindex; omit to reindex every user');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getArgument('user');
		$uids = [];
		if ($user !== null && $user !== '') {
			if (!$this->userManager->userExists((string)$user)) {
				$output->writeln('<error>No such user: ' . $user . '</error>');
				return 1;
			}
			$uids[] = (string)$user;
		} else {
			$this->userManager->callForAllUsers(static function (IUser $u) use (&$uids): void {
				$uids[] = $u->getUID();
			});
		}

		$status = 0;
		foreach ($uids as $uid) {
			try {
				$c = $this->sync->rebuildIndex($uid);
				$output->writeln(sprintf(
					'%s: %d folders, %d notes, %d tags, %d links, %d resources',
					$uid, $c['folders'], $c['notes'], $c['tags'], $c['links'], $c['resources'],
				));
			} catch (\Throwable $e) {
				$output->writeln('<error>' . $uid . ': ' . $e->getMessage() . '</error>');
				$status = 1;
			}
		}
		return $status;
	}
}
