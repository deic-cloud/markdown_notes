<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Index for the Joplin sync endpoint: maps a Joplin item id (32-hex) to where
 * the item lives in our world — a note/folder path in the tree, a systemtag, a
 * note↔tag link, or a resource blob. Rebuildable by scanning note footers (for
 * notes), so it is a cache/accelerator, not the sole source of truth.
 *
 *   type: 1 note · 2 folder(notebook) · 4 resource · 5 tag · 6 note_tag link
 *
 * rel_path  — for notes/folders: path under the user's notes folder
 * ref_id    — for tags: the systemtag id
 * link_note / link_tag — for note_tag links: the two Joplin ids joined
 * updated_ms — Joplin updated_time (epoch ms); drives PROPFIND getlastmodified
 * meta      — JSON sidecar for resources (mime, filename, size) and any Joplin
 *             item fields we must preserve but don't model natively
 */
class Version001Date20260620000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('markdown_notes_joplin')) {
			$table = $schema->createTable('markdown_notes_joplin');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('jid', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('type', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('rel_path', Types::STRING, ['notnull' => false, 'length' => 4000, 'default' => '']);
			$table->addColumn('ref_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
			$table->addColumn('link_note', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => '']);
			$table->addColumn('link_tag', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => '']);
			$table->addColumn('updated_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('meta', Types::TEXT, ['notnull' => false, 'default' => '']);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uid', 'jid'], 'mdn_joplin_uid_jid');
			$table->addIndex(['uid', 'type'], 'mdn_joplin_uid_type');
		}

		// Opaque blobs Joplin owns and we store verbatim (info.json, locks/*,
		// temp/*, .resource/<id> binaries). Keyed by their raw sync-target path.
		if (!$schema->hasTable('markdown_notes_jblob')) {
			$table = $schema->createTable('markdown_notes_jblob');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 1024]);
			$table->addColumn('updated_ms', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('is_dir', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('content', Types::BLOB, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uid', 'path'], 'mdn_jblob_uid_path');
		}

		return $schema;
	}
}
