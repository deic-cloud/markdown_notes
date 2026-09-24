<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\AppInfo;

use OCA\MarkdownNotes\Listener\LoadFilesScriptsListener;
use OCA\MarkdownNotes\Listener\SystemTagMapperListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\SystemTag\MapperEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'markdown_notes';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Tags changed via the Files sidebar / meta_data → mirror into the footer.
		// Core dispatches MapperEvent under these NAMES, not its class name, so a
		// class-name registration never fires (it silently didn't until 2026-09-24).
		$context->registerEventListener(MapperEvent::EVENT_ASSIGN, SystemTagMapperListener::class);
		$context->registerEventListener(MapperEvent::EVENT_UNASSIGN, SystemTagMapperListener::class);
		// Any write to a note (a sharee on another node, Joplin, WebDAV) → footer tags
		// re-applied to the owner's systemtags on this, the owner's, node.
		$context->registerEventListener(\OCP\Files\Events\Node\NodeWrittenEvent::class, \OCA\MarkdownNotes\Listener\NoteWrittenListener::class);
		// Files app: our EasyMDE source editor as the click action for .md files.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptsListener::class);
		// A shared notebook belongs in the recipient's own notes folder — on this
		// node when the recipient lives here, and on THEIR node when the share
		// crossed silos (files_sharding tells us; referenced by name so the app
		// stays installable without it).
		$context->registerEventListener(\OCP\Share\Events\ShareCreatedEvent::class, \OCA\MarkdownNotes\Listener\ShareCreatedListener::class);
		$context->registerEventListener('OCA\\FilesSharding\\Event\\ExternalShareMountedEvent', \OCA\MarkdownNotes\Listener\ExternalShareMountedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
