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
	 * @return array<string,string>
	 */
	public function valuesFor(int $fileId, int $tagId): array {
		$ts = $this->service();
		if ($ts === null) {
			return [];
		}
		try {
			$out = [];
			foreach ($ts->getFileKeys($fileId, $tagId) as $row) {
				$out[(string)$row['keyid']] = (string)$row['value'];
			}
			return $out;
		} catch (\Throwable $e) {
			return [];
		}
	}

	/**
	 * Ensure a metadata field (key) exists on a tag, returning its id. A template
	 * variable type `dropdown` maps to meta_data's `controlled` (with allowed
	 * values); other types map to a plain field. Returns null if meta_data is
	 * absent or the tag has no system tag yet.
	 *
	 * @param string[] $options
	 */
	public function ensureKey(string $tagName, string $name, string $type, array $options): ?int {
		$ts = $this->service();
		if ($ts === null) {
			return null;
		}
		try {
			$tagId = $ts->getTagIdByName($tagName);
			if (!$tagId) {
				return null;
			}
			// Map a template variable type to a meta_data field type. meta_data has
			// a single temporal type, 'datetime'; both template `date` and
			// `datetime` use it (the date-only vs date+time display is decided in
			// the notes list from the template). dropdown -> controlled; else plain.
			$mdType = $type === 'dropdown' ? 'controlled' : (($type === 'date' || $type === 'datetime') ? 'datetime' : '');
			$allowed = ($type === 'dropdown' && !empty($options)) ? (string)json_encode(array_values($options)) : '';
			foreach ($ts->getKeys((int)$tagId) as $k) {
				if ($k['name'] === $name) {
					$kid = (int)$k['id'];
					// Bring an existing field in line with the template's current
					// definition (e.g. first created as plain text, later given a
					// dropdown/date type in the template).
					$needsUpdate = $mdType !== '' && (string)($k['type'] ?? '') !== $mdType;
					if ($mdType === 'controlled' && (string)($k['allowed_values'] ?? '') !== $allowed) {
						$needsUpdate = true;
					}
					if ($needsUpdate) {
						$ts->updateKey((int)$tagId, $kid, $name, $mdType, $allowed);
					}
					return $kid;
				}
			}
			$key = $ts->newKey((int)$tagId, $name, $mdType, $allowed);
			return $key ? (int)$key['id'] : null;
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: meta_data ensureKey failed: ' . $e->getMessage(), ['app' => 'markdown_notes']);
			return null;
		}
	}

	public function setValue(string $tagName, int $fileId, int $keyId, string $value): bool {
		$ts = $this->service();
		if ($ts === null) {
			return false;
		}
		try {
			$tagId = $ts->getTagIdByName($tagName);
			if (!$tagId) {
				return false;
			}
			$ts->updateFileKey($fileId, (int)$tagId, $keyId, $value);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: meta_data setValue failed: ' . $e->getMessage(), ['app' => 'markdown_notes']);
			return false;
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
