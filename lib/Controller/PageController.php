<?php

declare(strict_types=1);

namespace OCA\Notes\Controller;

use OCA\Notes\Service\NotesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private NotesService $notesService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid !== '') {
			try {
				$this->notesService->ensureTemplates($uid);
			} catch (\Throwable $e) {
				// Non-fatal: the page still loads if seeding fails.
			}
		}
		Util::addStyle('notes', 'font-awesome');
		Util::addStyle('notes', 'easymde.min');
		Util::addStyle('notes', 'notes');
		Util::addScript('notes', 'easymde.min');
		Util::addScript('notes', 'notes-main');
		return new TemplateResponse('notes', 'index');
	}
}
