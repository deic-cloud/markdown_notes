<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

/**
 * Parse and serialise a note's on-disk form — the Joplin-compatible layout:
 *
 *     <title>
 *
 *     <body…>
 *
 *     id: 0123…
 *     created_time: 2026-06-17T…Z
 *     updated_time: 2026-06-17T…Z
 *     tags: lab, todo
 *     type_: 1
 *
 * The footer is the trailing contiguous block of `key: value` lines, and we
 * only treat it as a footer if it contains an `id:` line (so a body that
 * happens to end in colon-lines isn't mistaken for metadata). All footer keys
 * are preserved in order on write — including ones we don't manage (latitude,
 * markup_language, source, …) — so notes synced from Joplin round-trip
 * losslessly. The web UI shows only title + body; the footer is revealed on
 * request with a short legend.
 *
 * Pure functions, no Nextcloud dependencies — unit-testable in isolation.
 */
class NoteFormat {
	private const META_LINE = '/^([A-Za-z0-9_]+):[ \t]?(.*)$/';

	/**
	 * @return array{title: string, body: string, meta: array<string,string>, tags: string[]}
	 */
	public static function parse(string $raw): array {
		$raw = str_replace("\r\n", "\n", $raw);
		$lines = explode("\n", $raw);

		// Walk back over a trailing run of `key: value` lines.
		$footer = [];
		$i = count($lines) - 1;
		// Skip trailing blank lines.
		while ($i >= 0 && trim($lines[$i]) === '') {
			$i--;
		}
		$end = $i;
		$hasId = false;
		while ($i >= 0 && preg_match(self::META_LINE, $lines[$i], $m)) {
			$footer[$i] = [$m[1], $m[2]];
			if (strtolower($m[1]) === 'id') {
				$hasId = true;
			}
			$i--;
		}

		$meta = [];
		if ($hasId) {
			// Footer is lines ($i+1 .. $end), in order.
			ksort($footer);
			foreach ($footer as $kv) {
				$meta[$kv[0]] = $kv[1];
			}
			// Body is everything up to and including line $i; drop the blank
			// separator(s) before the footer.
			$bodyLines = array_slice($lines, 0, $i + 1);
			while (!empty($bodyLines) && trim(end($bodyLines)) === '') {
				array_pop($bodyLines);
			}
		} else {
			// No recognised footer: it's all title + body.
			$bodyLines = array_slice($lines, 0, $end + 1);
		}

		$title = '';
		if (!empty($bodyLines)) {
			$title = array_shift($bodyLines);
		}
		// Drop the blank line that separates title from body.
		while (!empty($bodyLines) && trim($bodyLines[0]) === '') {
			array_shift($bodyLines);
		}
		$body = implode("\n", $bodyLines);

		return [
			'title' => $title,
			'body'  => $body,
			'meta'  => $meta,
			'tags'  => self::tags($meta),
		];
	}

	/**
	 * Rebuild the on-disk form from title, body and an ordered metadata map.
	 *
	 * @param array<string,string> $meta
	 */
	public static function serialize(string $title, string $body, array $meta): string {
		$out = $title;
		$body = rtrim($body, "\n");
		if ($body !== '') {
			$out .= "\n\n" . $body;
		}
		$footerLines = [];
		foreach ($meta as $key => $value) {
			if ($value === null) {
				continue;
			}
			$footerLines[] = $key . ': ' . $value;
		}
		if (!empty($footerLines)) {
			$out .= "\n\n" . implode("\n", $footerLines);
		}
		return $out . "\n";
	}

	/** Tags from a meta map (the `tags:` footer line), comma-separated. @return string[] */
	public static function tags(array $meta): array {
		if (empty($meta['tags'])) {
			return [];
		}
		return array_values(array_filter(array_map('trim', explode(',', (string)$meta['tags']))));
	}

	/**
	 * Set the `tags:` footer value from an array (dropped from meta when empty).
	 *
	 * @param array<string,string> $meta
	 * @param string[] $tags
	 * @return array<string,string>
	 */
	public static function withTags(array $meta, array $tags): array {
		$tags = array_values(array_unique(array_filter(array_map('trim', $tags))));
		if (empty($tags)) {
			unset($meta['tags']);
		} else {
			$meta['tags'] = implode(', ', $tags);
		}
		return $meta;
	}
}
