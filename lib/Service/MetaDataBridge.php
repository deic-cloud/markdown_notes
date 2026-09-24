<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Optional integration with the meta_data app: when a tag carries typed
 * metadata fields, the notes list can show them as editable columns. Resolved
 * lazily and guarded, so markdown_notes stays installable without meta_data.
 *
 * meta_data keys a tag's fields by the systemtag id (getTagIdByName uses the
 * systemtag manager), so it lines up with our footer↔systemtag model.
 */
class MetaDataBridge {
	private const TAG_SERVICE = 'OCA\\MetaData\\Service\\TagService';

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	public function available(): bool {
		return $this->service() !== null;
	}

	/**
	 * Column definitions for a tag's metadata fields.
	 *
	 * @return array{tagId: int, keys: array<int, array{id:int,name:string,type:string,options:string[]}>}|null
	 */
	public function columnsFor(string $tagName): ?array {
		$ts = $this->service();
		if ($ts === null) {
			return null;
		}
		try {
			$tagId = $ts->getTagIdByName($tagName);
			if (!$tagId) {
				return null;
			}
			$keys = [];
			foreach ($ts->getKeys((int)$tagId) as $k) {
				$options = [];
				if (($k['type'] ?? '') === 'controlled' && ($k['allowed_values'] ?? '') !== '') {
					$decoded = json_decode((string)$k['allowed_values'], true);
					if (is_array($decoded)) {
						$options = array_values(array_map('strval', $decoded));
					}
				}
				$keys[] = ['id' => (int)$k['id'], 'name' => (string)$k['name'], 'type' => (string)($k['type'] ?? ''), 'options' => $options];
			}
			return ['tagId' => (int)$tagId, 'keys' => $keys];
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: meta_data columnsFor failed: ' . $e->getMessage(), ['app' => 'markdown_notes']);
			return null;
		}
	}

	/**
	 * A file's metadata values for a tag, as keyId(string) => value.
	 *
	 * Values live on the node of the file's OWNER. For a note in a notebook
	 * shared from another node there is nothing here, so ask meta_data to read
	 * them through from the owner's node (translated to this node's key ids).
	 *
	 * @return array<string,string>
	 */
	public function valuesFor(int $fileId, int $tagId, string $uid = ''): array {
		$ts = $this->service();
		if ($ts === null) {
			return [];
		}
		try {
			// For a note shared from another node the owner's node holds the values.
			$rows = ($uid !== '' && method_exists($ts, 'getFileKeysFor'))
				? $ts->getFileKeysFor($fileId, $tagId, $uid)
				: $ts->getFileKeys($fileId, $tagId);
			$out = [];
			foreach ($rows as $row) {
				$out[(string)$row['keyid']] = (string)$row['value'];
			}
			return $out;
		} catch (\Throwable $e) {
			return [];
		}
	}

	/**
	 * Id of an EXISTING meta_data field of a tag, by name — or null if the tag or
	 * the field does not exist. This app never creates or changes schema fields:
	 * the Metadata app is the design authority; a template only says which of a
	 * tag's fields it fills and shows.
	 */
	public function keyId(string $tagName, string $name): ?int {
		$ts = $this->service();
		if ($ts === null) {
			return null;
		}
		try {
			$tagId = $ts->getTagIdByName($tagName);
			if (!$tagId) {
				return null;
			}
			foreach ($ts->getKeys((int)$tagId) as $k) {
				if ((string)$k['name'] === $name) {
					return (int)$k['id'];
				}
			}
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: meta_data keyId failed: ' . $e->getMessage(), ['app' => 'markdown_notes']);
			return null;
		}
	}

	/** @return string|null null when stored; otherwise why not (for the user) */
	public function setValue(string $tagName, int $fileId, int $keyId, string $value, string $uid = ''): ?string {
		$ts = $this->service();
		if ($ts === null) {
			return 'The Metadata app is not available';
		}
		try {
			$tagId = $ts->getTagIdByName($tagName);
			if (!$tagId) {
				return 'Unknown tag ' . $tagName;
			}
			// $uid lets meta_data write a note shared from another node on its
			// owner's node (write-through), where everyone reads it.
			$ts->updateFileKey($fileId, (int)$tagId, $keyId, $value, $uid);
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: meta_data setValue failed: ' . $e->getMessage(), ['app' => 'markdown_notes']);
			return $e instanceof \RuntimeException ? $e->getMessage() : 'The value could not be saved';
		}
	}

	/** The meta_data TagService, or null when the app isn't present/enabled. */
	private function service(): ?object {
		if (!$this->appManager->isInstalled('meta_data') || !class_exists(self::TAG_SERVICE)) {
			return null;
		}
		try {
			return \OCP\Server::get(self::TAG_SERVICE);
		} catch (\Throwable $e) {
			return null;
		}
	}
}
