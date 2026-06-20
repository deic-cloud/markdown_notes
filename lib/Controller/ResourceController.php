<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Controller;

use OCA\MarkdownNotes\Service\JoplinSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves a Joplin resource (image/attachment) binary to the web UI, so a note
 * body's `:/<resourceid>` links can render inline. The bytes live in the Joplin
 * blob store (.resource/<id>) and the MIME comes from the resource's metadata
 * item — both populated by sync. Session-authenticated (the web UI).
 */
class ResourceController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private JoplinSyncService $sync,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $id): Response {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return new DataDisplayResponse('', Http::STATUS_UNAUTHORIZED);
		}
		$blob = $this->sync->resourceBlob($uid, $id);
		if ($blob === null) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$resp = new DataDisplayResponse($blob, Http::STATUS_OK, [
			'Content-Type' => $this->sync->resourceMime($uid, $id),
		]);
		$resp->cacheFor(3600);
		return $resp;
	}
}
