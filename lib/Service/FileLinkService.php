<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * A link to a file in the user's Nextcloud, for writing into a note — e.g. a lab
 * notebook entry linking the data and scripts that live in a project folder.
 *
 * The link must work for whoever opens the note: the author, and everyone the
 * notebook is shared with. On a single server, Nextcloud's own
 * /index.php/f/<file id> does that. On a files_sharding cluster that id exists
 * only on the owner's node, so the link is files_sharding's cluster link
 * instead, which the master resolves to each visitor's own copy (the app is
 * referenced by name so this one stays installable without it).
 *
 * Links always name the file as its OWNER has it: a file in a share the user
 * received is linked by the sharer's id and path (from another node, the
 * owner's node supplies them), so the link does not depend on where the
 * recipient happens to keep it, and works for everyone the owner shared with.
 */
class FileLinkService {
	private const CLUSTER_LINKS = 'OCA\\FilesSharding\\Service\\ClusterLinkService';
	private const FEDERATED_STORAGE = 'OCA\\Files_Sharing\\External\\Storage';

	public function __construct(
		private IRootFolder     $rootFolder,
		private IAppManager     $appManager,
		private IConfig         $config,
		private IURLGenerator   $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{link: string, name: string, personal: bool}
	 *         personal: the link works only for this user (a file received from
	 *         another node, whose id and path on the owner's node are not known here)
	 * @throws \OCP\Files\NotFoundException
	 */
	public function linkFor(string $uid, string $path): array {
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$node = $userFolder->get(ltrim($path, '/'));
		$name = $node->getName();

		[$owner, $ownerPath, $personal] = $this->ownersView($uid, $node, $userFolder->getRelativePath($node->getPath()) ?? $path);
		$fileId = (int)$node->getId();

		$cluster = $this->clusterLinks();
		if ($cluster !== null && $personal) {
			// Received from another node: that node can name the owner's copy.
			$prefix = '/' . $uid . '/files';
			$mount = rtrim($node->getMountPoint()->getMountPoint(), '/');
			$received = str_starts_with($mount, $prefix)
				? $cluster->linkForReceived($uid, substr($mount, strlen($prefix)), $node->getInternalPath())
				: null;
			if ($received !== null) {
				return ['link' => $received, 'name' => $name, 'personal' => false];
			}
		}
		if ($cluster !== null) {
			return ['link' => $cluster->linkFor($owner, $fileId, $ownerPath), 'name' => $name, 'personal' => $personal];
		}
		return [
			'link' => $this->urlGenerator->linkToRouteAbsolute('files.view.showFile', ['fileid' => (string)$fileId]),
			'name' => $name,
			'personal' => $personal,
		];
	}

	/** @return array{0: string, 1: string, 2: bool} owner uid, path in the owner's files, personal */
	private function ownersView(string $uid, Node $node, string $ownPath): array {
		$storage = $node->getStorage();
		if ($storage->instanceOfStorage(self::FEDERATED_STORAGE)) {
			return [$uid, $ownPath, true];
		}
		$owner = $node->getOwner()?->getUID() ?? $uid;
		if ($owner === $uid) {
			return [$uid, $ownPath, false];
		}
		// Shared with the user on this node: the owner's own path.
		try {
			$ownerFolder = $this->rootFolder->getUserFolder($owner);
			$theirs = $ownerFolder->getFirstNodeById($node->getId());
			if ($theirs !== null) {
				return [$owner, (string)$ownerFolder->getRelativePath($theirs->getPath()), false];
			}
		} catch (\Throwable $e) {
			$this->logger->info('markdown_notes: file link: owner path of ' . $node->getId() . ' not found: ' . $e->getMessage(), ['app' => 'markdown_notes']);
		}
		return [$owner, '', false];
	}

	/** files_sharding's link builder, when this server is part of a cluster. */
	private function clusterLinks(): ?object {
		if (!$this->appManager->isEnabledForAnyone('files_sharding') || !class_exists(self::CLUSTER_LINKS)
			|| trim((string)$this->config->getSystemValue('files_sharding_master_url', '')) === '') {
			return null;
		}
		try {
			return \OCP\Server::get(self::CLUSTER_LINKS);
		} catch (\Throwable) {
			return null;
		}
	}
}
