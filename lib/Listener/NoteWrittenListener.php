<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Listener;

use OCA\MarkdownNotes\Service\NoteFormat;
use OCA\MarkdownNotes\Service\NotesService;
use OCA\MarkdownNotes\Service\SystemTagSync;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\IHomeStorage;

/**
 * Footer → systemtags on the OWNER's node, whoever wrote the note.
 *
 * A note's tags live in its footer, which travels with the file; the system
 * tags (Files app chips, colours, meta_data) are per node and belong to the
 * owner's copy. Writes through this app sync them already, but a note in a
 * notebook shared with someone on another node is written by THEIR node (and
 * Joplin or WebDAV writes go around this app), so the owner's node would never
 * hear of a changed tag. Every write to a note in its owner's notes folder
 * therefore re-applies the footer here — a no-op when nothing changed.
 *
 * @template-implements IEventListener<NodeWrittenEvent>
 */
class NoteWrittenListener implements IEventListener {
	public function __construct(
		private NotesService  $notes,
		private SystemTagSync $sync,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeWrittenEvent)) {
			return;
		}
		$node = $event->getNode();
		if (!($node instanceof File) || !str_ends_with($node->getName(), '.md')) {
			return;
		}
		try {
			// Only the owner's own copy: a sharee's node holds a mount of it.
			if (!$node->getStorage()->instanceOfStorage(IHomeStorage::class)) {
				return;
			}
			$owner = $node->getOwner()?->getUID();
			if ($owner === null || $owner === '') {
				return;
			}
			$root = '/' . $owner . '/files/' . trim($this->notes->notesFolderName($owner), '/') . '/';
			if (!str_starts_with($node->getPath(), $root)
				|| str_starts_with($node->getPath(), $root . 'Templates/')) {
				return;
			}
			$parsed = NoteFormat::parse($this->notes->readContent($node));
			$this->sync->push($owner, (int)$node->getId(), $parsed['tags']);
		} catch (\Throwable) {
			// never break a write over tag bookkeeping
		}
	}
}
