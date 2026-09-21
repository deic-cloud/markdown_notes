<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Trusted timestamps for notes (RFC 3161).
 *
 * What is stamped is NOT the note file but a small manifest holding the note's
 * own SHA-256 plus the SHA-256 of every file in the notes tree the note links
 * to. A note says "the spectrum is in attachments/cell-07.csv"; a token over the
 * note bytes alone would say nothing about the spectrum. The manifest is plain
 * text, is kept next to the token, and is what verification compares against.
 *
 * Both files live in a VISIBLE `timestamps/` folder at the root of the top-level
 * notebook, like `attachments/`: the token has to be handed to other people and
 * has to travel with the notebook when it is shared, published or deposited.
 *
 * The token is requested with `-cert`, so the response carries the authority's
 * certificate and verifies offline against our CA alone.
 *
 * See /home/claude/code/ELN_TIMESTAMPING.md for the design and the reasoning.
 */
class TimestampService {
	/** Folder holding the tokens, kept out of the notebook tree. */
	public const DIR = 'timestamps';

	private const MANIFEST_HEADER = 'ScienceData note timestamp manifest v1';

	public function __construct(
		private NotesService    $notes,
		private IClientService  $clients,
		private IConfig         $config,
		private ITempManager    $temp,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Settings come from the config file. There is no second place.
	 *
	 * Trust settings belong in a file someone edits, reviews, saves and can put
	 * back, and Nextcloud itself keeps every setting of that kind in config.php
	 * rather than in the database: trusted_domains, trusted_proxies,
	 * allow_local_remote_servers. Two ways to configure one thing is two places
	 * to look when it behaves unexpectedly, and one of them will be wrong.
	 * The whole feature is one block:
	 *
	 *   'timestamp_authority' => [
	 *       'url'    => 'https://sciencedata.dk/tsa/',
	 *       'ca'     => '',          // empty: use my_ca_certificate
	 *       'policy' => '',
	 *       'pins'   => [
	 *           ['fingerprint' => '4EBC…', 'from' => '2026-09-20', 'until' => '', 'note' => '…'],
	 *       ],
	 *   ],
	 */
	private function setting(string $name): string {
		$block = $this->systemBlock();
		return isset($block[$name]) && is_string($block[$name]) ? trim($block[$name]) : '';
	}

	/**
	 * The deployment's timestamping block. Named for what it is, not for this
	 * app: the authority is a plain RFC 3161 service that knows nothing about
	 * Nextcloud, and which authorities a node trusts is a property of the node.
	 * The PDF signer is the next thing that will want to read the same list.
	 *
	 * @return array<string, mixed>
	 */
	private function systemBlock(): array {
		$block = $this->config->getSystemValue('timestamp_authority', []);
		return is_array($block) ? $block : [];
	}

	/** The timestamp authority this node talks to; '' hides the feature. */
	public function tsaUrl(): string {
		return $this->setting('url');
	}

	public function isConfigured(): bool {
		return $this->tsaUrl() !== '';
	}

	/**
	 * The authorities whose tokens this server accepts, each with the window it
	 * is accepted for. Chain validation says a token came from someone our CA
	 * vouched for; this says it came from the authority we actually run, during
	 * a period we actually trust.
	 *
	 * That distinction is the whole revocation story for timestamps. This CA
	 * publishes no revocation list, deliberately: a list is signed by the CA, so
	 * it is worthless in the one case that matters, a stolen CA key. Removing an
	 * entry here is the same gesture as deleting a user's stored certificate, and
	 * it does not depend on the compromised key. The dates are what keep it
	 * humane: retire an authority without them and every genuine token it ever
	 * issued dies with it; with them, only the window after a breach is cut out.
	 *
	 * Empty list = accept any token that chains to the CA, which is where a
	 * server starts and is a reasonable place to stay until there is something
	 * to distrust.
	 *
	 * @return list<array{fingerprint: string, from: string, until: string, note: string}>
	 */
	public function pins(): array {
		$block = $this->systemBlock();
		return isset($block['pins']) && is_array($block['pins']) ? $this->cleanPins($block['pins']) : [];
	}

	/**
	 * @param array<mixed> $decoded
	 * @return list<array{fingerprint: string, from: string, until: string, note: string}>
	 */
	private function cleanPins(array $decoded): array {
		$out = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry) || !isset($entry['fingerprint'])) {
				continue;
			}
			$out[] = [
				'fingerprint' => self::normalizeFingerprint((string)$entry['fingerprint']),
				'from'        => trim((string)($entry['from'] ?? '')),
				'until'       => trim((string)($entry['until'] ?? '')),
				'note'        => trim((string)($entry['note'] ?? '')),
			];
		}
		return $out;
	}

	/** Colons, case and whitespace differ between tools; the digest does not. */
	public static function normalizeFingerprint(string $fp): string {
		return strtoupper((string)preg_replace('/[^0-9A-Fa-f]/', '', $fp));
	}

	/**
	 * CA used to verify tokens. Defaults to the CA this cluster already issues
	 * its certificates from — every node sets `my_ca_certificate`.
	 */
	private function caFile(): string {
		$ca = $this->setting('ca');
		if ($ca === '') {
			$ca = $this->config->getSystemValueString('my_ca_certificate', '');
		}
		return ($ca !== '' && is_readable($ca)) ? $ca : '';
	}

	// ── Reading ──────────────────────────────────────────────────────────────

	/**
	 * Every timestamp of one note, newest first, each with its verification.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listFor(string $uid, string $noteRel): array {
		$dir = $this->stampFolder($uid, $noteRel, false);
		if ($dir === null) {
			return [];
		}
		$prefix = $this->slug($noteRel) . '-';
		$out = [];
		foreach ($dir->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (!($node instanceof File) || !str_starts_with($name, $prefix) || !str_ends_with($name, '.tsr')) {
				continue;
			}
			$base = substr($name, 0, -4);
			$manifest = $dir->nodeExists($base . '.manifest') ? $dir->get($base . '.manifest') : null;
			$out[] = $this->describe($uid, $noteRel, $node, $manifest instanceof File ? $manifest : null);
		}
		usort($out, static fn ($a, $b) => strcmp((string)$b['name'], (string)$a['name']));
		return $out;
	}

	// ── Stamping ─────────────────────────────────────────────────────────────

	/**
	 * Timestamp a note and its linked files. Returns the new record, described
	 * exactly as listFor() describes the existing ones.
	 *
	 * @throws NotesException when the authority is not configured or refuses
	 */
	public function stamp(string $uid, string $noteRel): array {
		$url = $this->tsaUrl();
		if ($url === '') {
			throw new NotesException('No timestamp authority is configured on this server.');
		}
		$noteRel = trim($noteRel, '/');
		$manifest = $this->buildManifest($uid, $noteRel);

		$manFile = $this->temp->getTemporaryFile('.manifest');
		file_put_contents($manFile, $manifest);
		$query = $this->run(['openssl', 'ts', '-query', '-data', $manFile, '-sha256', '-cert',
			...$this->policyArgs()], true);
		if ($query['out'] === '') {
			throw new NotesException('Could not build the timestamp request: ' . $query['err']);
		}
		$response = $this->ask($url, $query['out']);

		$respFile = $this->temp->getTemporaryFile('.tsr');
		file_put_contents($respFile, $response);
		$reply = $this->run(['openssl', 'ts', '-reply', '-in', $respFile, '-text']);
		if (stripos($reply['out'], 'granted') === false) {
			// The authority answered, but refused. Its own words are the useful part.
			$reason = trim(preg_replace('/\s+/', ' ', $reply['out'] . ' ' . $reply['err']) ?? '');
			throw new NotesException('The timestamp authority refused the request: '
				. ($reason !== '' ? $reason : 'no reason given'));
		}

		$dir = $this->stampFolder($uid, $noteRel, true);
		if ($dir === null) {
			throw new NotesException('Could not create the timestamps folder.');
		}
		$base = $this->slug($noteRel) . '-' . gmdate('Ymd\THis\Z');
		$dir->newFile($base . '.manifest', $manifest);
		$tsr = $dir->newFile($base . '.tsr', $response);
		$this->logger->info('markdown_notes: timestamped ' . $noteRel . ' for ' . $uid . ' as ' . $base,
			['app' => 'markdown_notes']);
		$manNode = $dir->get($base . '.manifest');
		return $this->describe($uid, $noteRel, $tsr, $manNode instanceof File ? $manNode : null);
	}

	/** POST the DER request to the authority and return the DER response. */
	private function ask(string $url, string $query): string {
		$options = [
			'body'    => $query,
			'headers' => ['Content-Type' => 'application/timestamp-query',
				'Accept' => 'application/timestamp-reply'],
			'timeout' => 20,
			// The authority normally sits beside us on the cluster's own network,
			// which Nextcloud's client refuses by default. Not a way in for anyone:
			// the URL is app configuration, set by an administrator, never by a user.
			'nextcloud' => ['allow_local_address' => true],
		];
		// Our own authority presents a certificate from our own CA, which is not
		// in the system trust store on every node.
		$ca = $this->caFile();
		if ($ca !== '' && str_starts_with($url, 'https://')) {
			$options['verify'] = $ca;
		}
		try {
			$response = $this->clients->newClient()->post($url, $options);
		} catch (\Throwable $e) {
			throw new NotesException('The timestamp authority could not be reached: ' . $e->getMessage());
		}
		$body = (string)$response->getBody();
		if ($body === '') {
			throw new NotesException('The timestamp authority returned an empty response.');
		}
		return $body;
	}

	// ── The manifest ─────────────────────────────────────────────────────────

	/**
	 * The bytes that get stamped: one line per file, sorted, plus a readable
	 * header. Deliberately plain text — someone verifying this in ten years
	 * should not need our code to see what was covered.
	 */
	public function buildManifest(string $uid, string $noteRel): string {
		$noteRel = trim($noteRel, '/');
		$note = $this->notes->getNote($uid, $noteRel); // throws if it is not a note
		$files = array_values(array_unique(array_merge([$noteRel],
			$this->notes->attachmentsOfNote($uid, $noteRel))));
		sort($files, SORT_STRING);
		$lines = [
			self::MANIFEST_HEADER,
			'note: ' . $noteRel,
			'title: ' . str_replace(["\r", "\n"], ' ', (string)$note['title']),
			'user: ' . $uid,
			'built: ' . gmdate('c'),
			'',
		];
		foreach ($files as $rel) {
			$hash = $this->hashOf($uid, $rel);
			if ($hash === null) {
				throw new NotesException('Cannot timestamp: ' . $rel . ' could not be read.');
			}
			$lines[] = 'sha256 ' . $hash . '  ' . $rel;
		}
		return implode("\n", $lines) . "\n";
	}

	/**
	 * Compare a stored manifest against the files as they are now.
	 *
	 * A changed note is NOT an error: it is the normal state of a notebook still
	 * being written. The caller says so in those words.
	 *
	 * @return array{matches: bool, changed: list<string>, missing: list<string>, files: int}
	 */
	private function checkManifest(string $uid, string $manifest): array {
		$changed = [];
		$missing = [];
		$count = 0;
		foreach (explode("\n", $manifest) as $line) {
			if (!str_starts_with($line, 'sha256 ')) {
				continue;
			}
			$rest = substr($line, 7);
			$sep = strpos($rest, '  ');
			if ($sep === false) {
				continue;
			}
			$count++;
			$want = substr($rest, 0, $sep);
			$rel  = substr($rest, $sep + 2);
			$have = $this->hashOf($uid, $rel);
			if ($have === null) {
				$missing[] = $rel;
			} elseif (!hash_equals($want, $have)) {
				$changed[] = $rel;
			}
		}
		return ['matches' => $changed === [] && $missing === [], 'changed' => $changed,
			'missing' => $missing, 'files' => $count];
	}

	private function hashOf(string $uid, string $rel): ?string {
		try {
			$node = $this->notes->getNotesFolder($uid)->get(trim($rel, '/'));
			if (!($node instanceof File)) {
				return null;
			}
			$content = $node->getContent();
			return hash('sha256', $content);
		} catch (\Throwable) {
			return null;
		}
	}

	// ── Describing and verifying one stamp ───────────────────────────────────

	/**
	 * One stamp as the UI needs it: when the authority says it was stamped,
	 * whether the token verifies against our CA, and whether the files still
	 * match what was stamped.
	 *
	 * @return array<string,mixed>
	 */
	private function describe(string $uid, string $noteRel, File $tsr, ?File $manifest): array {
		$name = $tsr->getName();
		$record = [
			'name'     => substr($name, 0, -4),
			'path'     => $this->relOf($uid, $tsr),
			'created'  => $tsr->getMTime(),
			'time'     => '',
			'authority' => '',
			'verified' => 'unknown',
			'detail'   => '',
			'matches'  => null,
			'changed'  => [],
			'missing'  => [],
			'files'    => 0,
		];
		$respFile = $this->temp->getTemporaryFile('.tsr');
		file_put_contents($respFile, $tsr->getContent());
		$reply = $this->run(['openssl', 'ts', '-reply', '-in', $respFile, '-text']);
		$record['time'] = $this->field($reply['out'], 'Time stamp');
		$record['authority'] = $this->field($reply['out'], 'TSA');

		if ($manifest === null) {
			$record['verified'] = 'no-manifest';
			$record['detail'] = 'The manifest that was stamped is missing, so this token cannot be checked.';
			return $record;
		}
		$content = $manifest->getContent();
		$check = $this->checkManifest($uid, $content);
		$record['matches'] = $check['matches'];
		$record['changed'] = $check['changed'];
		$record['missing'] = $check['missing'];
		$record['files']   = $check['files'];

		$ca = $this->caFile();
		if ($ca === '') {
			$record['verified'] = 'no-ca';
			$record['detail'] = 'This server has no CA file to check the token against.';
			return $record;
		}
		$manFile = $this->temp->getTemporaryFile('.manifest');
		file_put_contents($manFile, $content);
		$argv = ['openssl', 'ts', '-verify', '-data', $manFile, '-in', $respFile, '-CAfile', $ca];
		$verify = $this->run($argv);
		$stamped = $record['time'] !== '' ? strtotime((string)$record['time']) : false;
		if ($this->verified($verify)) {
			$pin = $this->pinVerdict($this->signerCertificates($respFile), $stamped === false ? time() : $stamped);
			if ($pin['state'] !== '') {
				$record['verified'] = $pin['state'];
				$record['detail'] = $pin['detail'];
				return $record;
			}
			$record['verified'] = 'ok';
			return $record;
		}
		// An authority certificate does not live forever, and openssl checks the
		// chain as of NOW — so the day it expires, every token ever issued under it
		// stops verifying, though nothing about them has changed. Measured, not
		// assumed. Check again as of the moment the token itself claims, which is
		// what the evidence is about, and say plainly that the certificate has since
		// expired. A token forged with a stolen key could claim a time inside the
		// certificate's life either way, so this concedes nothing that was not
		// already conceded by having no revocation list.
		$expired = stripos($verify['err'] . $verify['out'], 'certificate has expired') !== false;
		if ($expired && $stamped !== false) {
			$again = $this->run(array_merge($argv, ['-attime', (string)$stamped]));
			if ($this->verified($again)) {
				$pin = $this->pinVerdict($this->signerCertificates($respFile), $stamped);
				if ($pin['state'] !== '') {
					$record['verified'] = $pin['state'];
					$record['detail'] = $pin['detail'];
					return $record;
				}
				$record['verified'] = 'ok-expired';
				$record['detail'] = 'The token verifies as of the time it carries. '
					. 'The certificate of the authority that issued it has expired since.';
				return $record;
			}
		}
		$record['verified'] = 'failed';
		$record['detail'] = $this->reason($verify['err'] . "\n" . $verify['out']);
		return $record;
	}

	/**
	 * The certificates carried inside a token, as fingerprint => subject. We ask
	 * for them with `-cert` when stamping precisely so this is possible offline.
	 *
	 * @return array<string, string>
	 */
	public function signerCertificates(string $responseFile): array {
		$tokenFile = $this->temp->getTemporaryFile('.tk');
		$this->run(['openssl', 'ts', '-reply', '-in', $responseFile, '-token_out', '-out', $tokenFile]);
		if (!is_file($tokenFile) || filesize($tokenFile) === 0) {
			return [];
		}
		$pem = $this->run(['openssl', 'pkcs7', '-inform', 'DER', '-in', $tokenFile, '-print_certs']);
		$out = [];
		if (!preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem['out'], $m)) {
			return $out;
		}
		foreach ($m[0] as $certPem) {
			$fp = openssl_x509_fingerprint($certPem, 'sha256');
			if ($fp === false) {
				continue;
			}
			$info = openssl_x509_parse($certPem);
			$subject = '';
			if (is_array($info) && isset($info['subject']) && is_array($info['subject'])) {
				$parts = [];
				foreach ($info['subject'] as $k => $v) {
					$parts[] = $k . '=' . (is_array($v) ? implode('+', $v) : $v);
				}
				$subject = implode(', ', $parts);
			}
			$out[self::normalizeFingerprint($fp)] = $subject;
		}
		return $out;
	}

	/**
	 * Is this token from an authority we accept, at the time it carries?
	 *
	 * @param array<string, string> $certificates fingerprint => subject
	 * @return array{state: string, detail: string}
	 */
	private function pinVerdict(array $certificates, int $stampedAt): array {
		$pins = $this->pins();
		if ($pins === []) {
			return ['state' => '', 'detail' => ''];
		}
		$known = null;
		foreach ($pins as $pin) {
			if ($pin['fingerprint'] === '' || !isset($certificates[$pin['fingerprint']])) {
				continue;
			}
			$from  = $pin['from'] !== '' ? strtotime($pin['from'] . ' 00:00:00 UTC') : false;
			$until = $pin['until'] !== '' ? strtotime($pin['until'] . ' 23:59:59 UTC') : false;
			if (($from === false || $stampedAt >= $from) && ($until === false || $stampedAt <= $until)) {
				return ['state' => '', 'detail' => ''];
			}
			// Right authority, wrong time: keep looking, another entry may cover it.
			$known = $pin;
		}
		if ($known !== null) {
			return ['state' => 'outside-window', 'detail' => 'This authority is accepted'
				. ($known['from'] !== '' ? ' from ' . $known['from'] : '')
				. ($known['until'] !== '' ? ' until ' . $known['until'] : '')
				. ', and this token is dated outside that.'];
		}
		return ['state' => 'not-pinned', 'detail' => 'The token is signed by an authority this server does '
			. 'not accept. Its certificate: ' . (implode('; ', $certificates) ?: 'none found') . '.'];
	}

	/** @param array{out: string, err: string, code: int} $result */
	private function verified(array $result): bool {
		return stripos($result['out'] . $result['err'], 'verification: ok') !== false;
	}

	/**
	 * The readable half of an openssl failure. Its error lines look like
	 * `40A7…:error:17800067:time stamp routines:ts_check_imprints:message imprint
	 * mismatch:../crypto/ts/ts_rsp_verify.c:512:` — the fifth field is the only
	 * part worth showing anyone.
	 */
	private function reason(string $text): string {
		foreach (explode("\n", $text) as $line) {
			$line = trim($line);
			if (!str_contains($line, ':error:')) {
				continue;
			}
			$parts = explode(':', $line);
			if (isset($parts[5]) && trim($parts[5]) !== '') {
				return trim($parts[5]);
			}
		}
		foreach (explode("\n", $text) as $line) {
			$line = trim($line);
			if ($line !== '' && !str_starts_with($line, 'Using configuration')) {
				return $line;
			}
		}
		return 'openssl gave no reason';
	}

	/** Pull "Field: value" out of openssl's text dump. */
	private function field(string $text, string $label): string {
		foreach (explode("\n", $text) as $line) {
			$line = trim($line);
			if (stripos($line, $label . ':') === 0) {
				return trim(substr($line, strlen($label) + 1));
			}
		}
		return '';
	}

	// ── Plumbing ─────────────────────────────────────────────────────────────

	/** `timestamps/` at the root of the note's top-level notebook. */
	private function stampFolder(string $uid, string $noteRel, bool $create): ?Folder {
		$rel = $this->stampDirFor($noteRel);
		$root = $this->notes->getNotesFolder($uid);
		try {
			if ($root->nodeExists($rel)) {
				$node = $root->get($rel);
				return $node instanceof Folder ? $node : null;
			}
			if (!$create) {
				return null;
			}
			return $root->newFolder($rel);
		} catch (\Throwable $e) {
			$this->logger->warning('markdown_notes: timestamps folder ' . $rel . ' for ' . $uid . ': '
				. $e->getMessage(), ['app' => 'markdown_notes']);
			return null;
		}
	}

	/** Mirrors NotesService::attachDirFor — per notebook, not per user. */
	public function stampDirFor(string $noteRel): string {
		$dir = trim(dirname('/' . trim($noteRel, '/')), '/');
		return $dir === '' || $dir === '.' ? self::DIR : explode('/', $dir)[0] . '/' . self::DIR;
	}

	/** A file name that is unique per note inside one notebook and still readable. */
	private function slug(string $noteRel): string {
		$rel = trim($noteRel, '/');
		if (str_ends_with($rel, '.md')) {
			$rel = substr($rel, 0, -3);
		}
		$slug = (string)preg_replace('/[^\p{L}\p{N}._-]+/u', '_', str_replace('/', '__', $rel));
		return trim($slug, '_') ?: 'note';
	}

	private function relOf(string $uid, Node $node): string {
		$root = rtrim($this->notes->getNotesFolder($uid)->getPath(), '/');
		return ltrim(substr($node->getPath(), strlen($root)), '/');
	}

	/** @return list<string> */
	private function policyArgs(): array {
		$policy = $this->setting('policy');
		return $policy === '' ? [] : ['-policy', $policy];
	}

	/**
	 * Run openssl. $binary keeps the raw stdout bytes (a DER request), otherwise
	 * the caller gets text.
	 *
	 * @param list<string> $argv
	 * @return array{out: string, err: string, code: int}
	 */
	private function run(array $argv, bool $binary = false): array {
		$cmd = implode(' ', array_map('escapeshellarg', $argv));
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$pipes = [];
		$proc = proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			return ['out' => '', 'err' => 'could not run openssl', 'code' => -1];
		}
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		if (!$binary) {
			$out = (string)$out;
		}
		return ['out' => $out, 'err' => $err, 'code' => $code];
	}
}
