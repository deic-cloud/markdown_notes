<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\AppInfo;

use OCA\MarkdownNotes\Listener\SystemTagMapperListener;
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
		$context->registerEventListener(MapperEvent::class, SystemTagMapperListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
