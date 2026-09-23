<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\InvalidateMountCacheEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Move a notebook that has just been shared with someone into their notes
 * folder, so it shows up in Notes and in their Joplin without them hunting for
 * it at the top of their files.
 *
 * Called at reception, in the request that mounts the share, so the notebook
 * is in place by the time the recipient looks; the background job is the
 * fallback when that fails. Rebuilding the recipient's Joplin index is left to
 * the caller — it walks the whole notes tree and does not belong in a request.
 *
 * How we know it is a notebook: the `.notebook` marker the sharing side writes
 * into it. A marker travels with the data, so any node can read it.
 */
class NotebookPlacer {
	public function __construct(
		private NotesService    $notesService,
		private IRootFolder     $rootFolder,
		private IUserManager    $userManager,
		private IUserSession    $userSession,
		private IDBConnection   $db,
		private IEventDispatcher $dispatcher,
		private LoggerInterface $logger,
	) {
	}

	public const MOVED = 'moved';
	public const NOTHING = 'nothing';       // not a notebook, or already in place
	public const NOT_VISIBLE = 'not-visible'; // the mount is not (yet) in this user's view

	/**
	 * @return string MOVED, NOTHING or NOT_VISIBLE
	 * @throws \Throwable when the move itself failed — the caller decides whether
	 *                    to try again later
	 */
	public function place(string $uid, string $mount): string {
		$mount = trim($mount, '/');
		$user = $this->userManager->get($uid);
		if ($uid === '' || $mount === '' || $user === null || strcasecmp($user->getUID(), $uid) !== 0) {
			return self::NOTHING;
		}
		// Moving a federated mount goes through core's external-share manager,
		// which identifies the owner from the SESSION user: act as the recipient
		// for the duration, and put back whoever was there — in a request that is
		// the calling server's identity.
		$previous = $this->userSession->getUser();
		$this->userSession->setUser($user);
		try {
			\OC_Util::setupFS($uid);
			$userFolder = $this->rootFolder->getUserFolder($uid);
			if (!$userFolder->nodeExists($mount)) {
				// At reception this runs in the very request that inserted the mount,
				// and this user's view of their files may already have been built
				// without it (the share-scan warmer builds it). Build it once more.
				\OC_Util::tearDownFS();
				\OC_Util::setupFS($uid);
				$userFolder = $this->rootFolder->getUserFolder($uid);
				if (!$userFolder->nodeExists($mount)) {
					return self::NOT_VISIBLE;
				}
			}
			$node = $userFolder->get($mount);
			if (!($node instanceof Folder) || !$this->notesService->looksLikeNotebook($node)) {
				return self::NOTHING;
			}
			$notesName = $this->notesService->notesFolderName($uid);
			if (str_starts_with($mount . '/', $notesName . '/')) {
				return self::NOTHING; // already inside the notes folder
			}
			if (!$userFolder->nodeExists($notesName)) {
				$userFolder->newFolder($notesName);
			}
			$base = $node->getName();
			$target = $notesName . '/' . $base;
			for ($i = 2; $userFolder->nodeExists($target) && $i < 50; $i++) {
				$target = $notesName . '/' . $base . ' (' . $i . ')';
			}
			if ($this->isReceivedShareRoot($node)) {
				$this->moveReceivedShare($uid, '/' . $mount, '/' . $target);
			} else {
				$node->move($userFolder->getPath() . '/' . $target);
			}
			$this->logger->info('markdown_notes: placed the shared notebook ' . $mount . ' at ' . $target
				. ' for ' . $uid, ['app' => 'markdown_notes']);
			return self::MOVED;
		} finally {
			$this->userSession->setUser($previous);
		}
	}

	/** Is this node the root of a share received from another node? */
	private function isReceivedShareRoot(Folder $node): bool {
		return $node->getInternalPath() === ''
			&& is_a($node->getMountPoint(), 'OCA\\Files_Sharing\\External\\Mount');
	}

	/**
	 * Move a received share, doing what core's external-share manager does —
	 * update its row, then say the mount cache changed — but for a recipient
	 * named here rather than taken from the session.
	 *
	 * Why not just Node::move(): core's manager reads the current user ONCE, when
	 * it is first built, and keeps it. At reception (the master's sync request)
	 * and in a cron run it is built before we switch to the recipient, with no
	 * user at all, so the move dies with "getUID() on null" however carefully the
	 * session is set afterwards. That was the placement failure first seen on
	 * 2026-09-23; by hand it worked only because nothing had been built yet.
	 *
	 * Paths are relative to the user's files, with a leading slash, as the table
	 * keeps them: '/ReceptionProbe' -> '/Notes/ReceptionProbe'.
	 */
	private function moveReceivedShare(string $uid, string $from, string $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('share_external')
			->set('mountpoint', $qb->createNamedParameter($to))
			->set('mountpoint_hash', $qb->createNamedParameter(md5($to)))
			->where($qb->expr()->eq('mountpoint_hash', $qb->createNamedParameter(md5($from))))
			->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($uid)));
		if ($qb->executeStatement() !== 1) {
			throw new \RuntimeException('no received share at ' . $from . ' for ' . $uid);
		}
		$user = $this->userManager->get($uid);
		if ($user !== null) {
			$this->dispatcher->dispatchTyped(new InvalidateMountCacheEvent($user));
		}
	}
}
