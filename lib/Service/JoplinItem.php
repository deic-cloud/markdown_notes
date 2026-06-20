<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

/**
 * Serialise / parse a Joplin sync item — the on-the-wire format Joplin's
 * WebDAV/file sync target stores as `<32-hex-id>.md`:
 *
 *     <title>
 *
 *     <body>
 *
 *     id: 0123…
 *     parent_id: …
 *     created_time: 2026-06-17T09:12:00.000Z
 *     updated_time: 2026-06-17T09:12:00.000Z
 *     …
 *     type_: 1
 *
 * The metadata block is the trailing run of `key: value` lines and always ends
 * with `type_`. Title and body are the dedicated leading sections (notes have
 * both; folders/tags/resources/links generally have only a title). Unknown keys
 * are preserved so items round-trip losslessly.
 *
 * Joplin ModelType: 1 note, 2 folder, 3 setting, 4 resource, 5 tag,
 * 6 note_tag(link), 9 master_key, 13 revision.
 *
 * Pure functions, no Nextcloud dependencies — unit-testable in isolation.
 */
class JoplinItem {
	public const TYPE_NOTE = 1;
	public const TYPE_FOLDER = 2;
	public const TYPE_RESOURCE = 4;
	public const TYPE_TAG = 5;
	public const TYPE_NOTE_TAG = 6;

	private const META_LINE = '/^([a-zA-Z0-9_]+):[ \t]?(.*)$/';

	/**
	 * Parse a serialised item into a flat field map (including title, body,
	 * type_ as int when present). Mirrors Joplin's unserialize().
	 *
	 * @return array<string,mixed>
	 */
	public static function parse(string $raw): array {
		$raw = str_replace("\r\n", "\n", $raw);
		$lines = explode("\n", $raw);

		// Trailing contiguous run of key:value lines = metadata block.
		$i = count($lines) - 1;
		while ($i >= 0 && trim($lines[$i]) === '') {
			$i--;
		}
		$end = $i;
		$meta = [];
		while ($i >= 0 && preg_match(self::META_LINE, $lines[$i], $m)) {
			$meta[$m[1]] = $m[2];
			$i--;
		}
		// A real metadata block must contain type_ (else it's all body).
		if (!isset($meta['type_'])) {
			$meta = [];
			$bodyEnd = $end;
		} else {
			$bodyEnd = $i; // body/title is lines 0..$i
			// drop blank separators before the block
			while ($bodyEnd >= 0 && trim($lines[$bodyEnd]) === '') {
				$bodyEnd--;
			}
		}

		$head = array_slice($lines, 0, $bodyEnd + 1);
		$title = count($head) ? array_shift($head) : '';
		while (count($head) && trim($head[0]) === '') {
			array_shift($head);
		}
		$body = implode("\n", $head);

		$out = $meta;
		$out['title'] = $title;
		$out['body'] = $body;
		if (isset($out['type_'])) {
			$out['type_'] = (int)$out['type_'];
		}
		return $out;
	}

	/**
	 * Serialise a field map to the Joplin form. `title` and `body` are pulled
	 * out as the leading sections; every other field becomes a metadata line,
	 * with `type_` forced last (Joplin's convention).
	 *
	 * @param array<string,mixed> $fields
	 */
	public static function serialize(array $fields): string {
		$title = (string)($fields['title'] ?? '');
		$body = (string)($fields['body'] ?? '');
		unset($fields['title'], $fields['body']);

		$type = $fields['type_'] ?? null;
		unset($fields['type_']);

		$out = $title;
		if ($body !== '') {
			$out .= "\n\n" . $body;
		}
		$metaLines = [];
		foreach ($fields as $k => $v) {
			if ($v === null) {
				continue;
			}
			$metaLines[] = $k . ': ' . $v;
		}
		if ($type !== null) {
			$metaLines[] = 'type_: ' . $type;
		}
		if (!empty($metaLines)) {
			$out .= "\n\n" . implode("\n", $metaLines);
		}
		return $out;
	}

	/** A new 32-hex Joplin id. */
	public static function newId(): string {
		return bin2hex(random_bytes(16));
	}

	/** Joplin timestamp (ISO-8601, ms) ↔ epoch milliseconds. */
	public static function timeToMs(string $iso): int {
		if ($iso === '') {
			return 0;
		}
		$ts = strtotime($iso);
		return $ts === false ? 0 : $ts * 1000;
	}

	public static function msToTime(int $ms): string {
		return gmdate('Y-m-d\TH:i:s', (int)floor($ms / 1000)) . sprintf('.%03dZ', $ms % 1000);
	}
}
