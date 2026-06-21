<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Controller;

use OCA\MarkdownNotes\Service\JoplinSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves a Joplin resource (image/attachment) binary to the web UI, so a note
 * body's `:/<resourceid>` links can render inline. The bytes live in the Joplin
 * blob store (.resource/<id>) and the MIME comes from the resource's metadata
 * item. Also registers new resources when an image is inserted in the web UI,
 * so they sync to Joplin as real resources rather than dead WebDAV URLs.
 * Session-authenticated (the web UI).
 */
class ResourceController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private JoplinSyncService $sync,
		private IRootFolder $rootFolder,
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

	/**
	 * Register an image as a Joplin resource and return its id + a suggested alt
	 * text. Accepts either an uploaded `file` (multipart) or a `path` to an
	 * existing file in the user's Nextcloud files. The web UI then inserts
	 * `![alt](:/<id>)` into the note body.
	 */
	#[NoAdminRequired]
	public function create(string $path = ''): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$bytes = null;
		$filename = '';
		$mime = '';

		$upload = $this->request->getUploadedFile('file');
		if (is_array($upload) && ($upload['error'] ?? \UPLOAD_ERR_NO_FILE) === \UPLOAD_ERR_OK) {
			$bytes = (string)file_get_contents($upload['tmp_name']);
			$filename = (string)($upload['name'] ?? 'image');
			$mime = (string)($upload['type'] ?? '');
		} elseif ($path !== '') {
			try {
				$node = $this->rootFolder->getUserFolder($uid)->get(ltrim($path, '/'));
			} catch (\OCP\Files\NotFoundException $e) {
				return new JSONResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
			}
			if (!($node instanceof File)) {
				return new JSONResponse(['message' => 'Not a file'], Http::STATUS_BAD_REQUEST);
			}
			$bytes = $node->getContent();
			$filename = $node->getName();
			$mime = $node->getMimeType();
		}

		if ($bytes === null) {
			return new JSONResponse(['message' => 'No file or path provided'], Http::STATUS_BAD_REQUEST);
		}

		$res = $this->sync->createResource($uid, $bytes, $filename, $mime);
		return new JSONResponse($res);
	}
}
