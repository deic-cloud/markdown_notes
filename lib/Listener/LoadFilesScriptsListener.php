<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\Util;

/**
 * Files app: load the Markdown editor action (src/files-editor.js → js/files-editor.js)
 * together with EasyMDE and its styles, so clicking a .md file opens the Notes
 * app's source editor instead of the Text app's rich-text editor.
 *
 * @implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptsListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private IConfig $config,
		private IUserSession $userSession,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		Util::addStyle('markdown_notes', 'easymde.min');
		Util::addStyle('markdown_notes', 'font-awesome');
		Util::addStyle('markdown_notes', 'files-editor');
		Util::addScript('markdown_notes', 'easymde.min');
		// After the Files app so its action registry exists.
		Util::addScript('markdown_notes', 'files-editor', 'files');
		// The editor adds website image classes ({.modal-image .small-image}) to
		// inserted images — except in notes, where Joplin would show them as text.
		$uid = $this->userSession->getUser()?->getUID();
		$dir = $uid === null ? 'Notes' : trim($this->config->getUserValue($uid, 'markdown_notes', 'notesdir', 'Notes'), '/');
		$this->initialState->provideInitialState('notes_folder', $dir !== '' ? $dir : 'Notes');
	}
}
