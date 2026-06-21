<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

/**
 * Parse and render templates in the format used by Joplin's Templates plugin,
 * so the same .md file works in both this app and Joplin.
 *
 *     ---
 *     template_title: Lab note — {{date}}
 *     template_tags: lab_note
 *     project:
 *       label: Project
 *       type: text
 *     ---
 *     #### {{#custom_datetime}}YYYY-MM-DD{{/custom_datetime}}
 *     …body, may reference {{project}}…
 *
 * Front matter (YAML, --- delimited): reserved keys `template_title` and
 * `template_tags`; every other key is a custom variable the user is prompted
 * for (simple `name: type` or advanced `name:` + indented label/type; types
 * text/number/boolean/date/time/dropdown(a, b, c)).
 *
 * Body + title + tags are rendered with a Handlebars subset: built-ins
 * {{date}} {{time}} {{bowm}} {{bows}} {{eowm}} {{eows}} and
 * {{#custom_datetime}}<moment format>{{/custom_datetime}}, plus {{varName}}
 * from the supplied variable values. Templates with no front matter fall back
 * to the legacy form (first line = title, a `tags:` line = tags).
 *
 * Pure functions, no Nextcloud dependencies — unit-testable in isolation.
 */
class TemplateFormat {
	/**
	 * @return array{title: string, tags: string, body: string, variables: array<int, array{name:string,label:string,type:string,options:string[]}>}
	 */
	public static function parse(string $raw): array {
		$raw = str_replace("\r\n", "\n", $raw);
		if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $m)) {
			[$title, $tags, $vars] = self::parseFrontMatter($m[1]);
			return ['title' => $title, 'tags' => $tags, 'body' => ltrim($m[2], "\n"), 'variables' => $vars];
		}
		// Legacy fallback: first line is the title, a `tags:` line gives the tags.
		$lines = explode("\n", $raw);
		$title = array_shift($lines) ?? '';
		$tags = '';
		foreach ($lines as $i => $line) {
			if (preg_match('/^tags:\s*(.*)$/', $line, $tm)) {
				$tags = $tm[1];
				unset($lines[$i]);
			}
		}
		return ['title' => trim($title), 'tags' => $tags, 'body' => trim(implode("\n", $lines), "\n"), 'variables' => []];
	}

	/**
	 * @return array{0:string,1:string,2:array<int,array{name:string,label:string,type:string,options:string[]}>}
	 */
	private static function parseFrontMatter(string $fm): array {
		$title = '';
		$tags = '';
		$vars = [];
		$lines = explode("\n", $fm);
		$pending = null; // advanced var awaiting indented label/type
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			if (preg_match('/^\s+(label|type):\s*(.*)$/', $line, $im) && $pending !== null) {
				if ($im[1] === 'label') {
					$pending['label'] = trim($im[2]);
				} else {
					self::applyType($pending, trim($im[2]));
				}
				continue;
			}
			if ($pending !== null) {
				$vars[] = $pending;
				$pending = null;
			}
			if (!preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $line, $km)) {
				continue;
			}
			$key = $km[1];
			$val = trim($km[2]);
			if ($key === 'template_title') {
				$title = $val;
			} elseif ($key === 'template_tags') {
				$tags = $val;
			} elseif ($val === '') {
				// advanced variable: indented label/type follow
				$pending = ['name' => $key, 'label' => $key, 'type' => 'text', 'options' => []];
			} else {
				// simple variable: name: type
				$v = ['name' => $key, 'label' => $key, 'type' => 'text', 'options' => []];
				self::applyType($v, $val);
				$vars[] = $v;
			}
		}
		if ($pending !== null) {
			$vars[] = $pending;
		}
		return [$title, $tags, $vars];
	}

	private static function applyType(array &$var, string $type): void {
		if (preg_match('/^dropdown\s*\((.*)\)$/', $type, $dm)) {
			$var['type'] = 'dropdown';
			$var['options'] = array_values(array_filter(array_map('trim', explode(',', $dm[1]))));
		} else {
			$var['type'] = in_array($type, ['text', 'number', 'boolean', 'date', 'datetime', 'time'], true) ? $type : 'text';
		}
	}

	/**
	 * Render a template string: built-in date/time variables + user-supplied
	 * variable values. $now is a Unix timestamp (so callers can stay testable).
	 *
	 * @param array<string,string> $vars
	 */
	public static function render(string $text, array $vars, int $now): string {
		// Legacy %date%/%time% tokens from pre-Joplin templates (kept working so
		// already-seeded templates don't render the token literally).
		$text = strtr($text, ['%date%' => date('Y-m-d', $now), '%time%' => date('H:i', $now), '%me%' => '', '%place%' => '']);

		// {{#custom_datetime}}<moment format>{{/custom_datetime}}
		$text = preg_replace_callback('/\{\{#custom_datetime\}\}(.*?)\{\{\/custom_datetime\}\}/s',
			static fn ($m) => date(self::momentToPhp($m[1]), $now), $text) ?? $text;

		$builtins = [
			'date' => date('Y-m-d', $now),
			'time' => date('H:i', $now),
			'bowm' => date('Y-m-d', strtotime('monday this week', $now) ?: $now),
			'bows' => date('Y-m-d', strtotime('sunday last week', $now) ?: $now),
			'eowm' => date('Y-m-d', strtotime('sunday this week', $now) ?: $now),
			'eows' => date('Y-m-d', strtotime('saturday this week', $now) ?: $now),
		];

		return preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', static function ($m) use ($builtins, $vars) {
			$name = $m[1];
			if (array_key_exists($name, $vars)) {
				return $vars[$name];
			}
			if (array_key_exists($name, $builtins)) {
				return $builtins[$name];
			}
			return $m[0]; // leave unknown variables literal
		}, $text) ?? $text;
	}

	/** Translate a moment.js date format to a PHP date() format (common tokens). */
	private static function momentToPhp(string $fmt): string {
		$map = [
			'YYYY' => 'Y', 'YY' => 'y', 'MMMM' => 'F', 'MMM' => 'M', 'MM' => 'm', 'M' => 'n',
			'DD' => 'd', 'D' => 'j', 'dddd' => 'l', 'ddd' => 'D', 'HH' => 'H', 'H' => 'G',
			'hh' => 'h', 'h' => 'g', 'mm' => 'i', 'ss' => 's', 'A' => 'A', 'a' => 'a', 'X' => 'U',
		];
		$tokens = ['YYYY', 'dddd', 'MMMM', 'MMM', 'ddd', 'YY', 'MM', 'DD', 'HH', 'hh', 'mm', 'ss', 'M', 'D', 'H', 'h', 'A', 'a', 'X'];
		$out = '';
		$len = strlen($fmt);
		for ($i = 0; $i < $len;) {
			$matched = false;
			foreach ($tokens as $t) {
				if (substr($fmt, $i, strlen($t)) === $t) {
					$out .= $map[$t];
					$i += strlen($t);
					$matched = true;
					break;
				}
			}
			if (!$matched) {
				$ch = $fmt[$i];
				$out .= ctype_alpha($ch) ? '\\' . $ch : $ch; // escape literal letters
				$i++;
			}
		}
		return $out;
	}
}
