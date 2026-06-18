<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Listener;

use OCA\MarkdownNotes\Service\SystemTagSync;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\SystemTag\MapperEvent;

/**
 * When a file's systemtags change (via the Files sidebar or meta_data), pull
 * the change into the note's footer. @template-implements IEventListener<MapperEvent>
 */
class SystemTagMapperListener implements IEventListener {
	public function __construct(private SystemTagSync $sync) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof MapperEvent) || $event->getObjectType() !== 'files') {
			return;
		}
		$this->sync->pull((int)$event->getObjectId());
	}
}
