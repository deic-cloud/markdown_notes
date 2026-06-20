<?php

declare(strict_types=1);

namespace OCA\MarkdownNotes\Controller;

use OCA\MarkdownNotes\Service\JoplinStore;
use OCA\MarkdownNotes\Service\JoplinSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A minimal WebDAV target for Joplin sync, mounted at
 *   …/index.php/apps/markdown_notes/joplin/<path>
 *
 * Joplin's WebDAV driver uses GET/HEAD/PUT/DELETE/MKCOL/MOVE and PROPFIND
 * (Depth 0 to stat, Depth 1 to list), requesting d:getlastmodified and
 * d:resourcetype, and expects 200/201/207/404. It authenticates with HTTP
 * Basic auth (a Nextcloud app password) — handled by NC's auth middleware, so
 * each request arrives with the session user set.
 *
 * Phase 2a: a correct "dumb" target backed by JoplinStore (verbatim blobs). The
 * note-tree translation layer is layered on top next.
 */
class WebDavController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private JoplinStore $store,
		private JoplinSyncService $sync,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dav(string $path = ''): Response {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			return $this->raw('', Http::STATUS_UNAUTHORIZED);
		}
		$method = $this->request->getMethod();
		$path = $this->store->norm($path);
		$now = $this->nowMs();

		$isItem = $this->sync->isItemPath($path);

		switch ($method) {
			case 'PROPFIND':
				return $this->propfind($uid, $path);
			case 'GET':
			case 'HEAD':
				if ($isItem) {
					$item = $this->sync->getItem($uid, $this->sync->jidFromPath($path));
					if ($item === null) {
						return $this->raw('', Http::STATUS_NOT_FOUND);
					}
					$resp = $this->raw($method === 'HEAD' ? '' : $item, Http::STATUS_OK);
					$resp->addHeader('Content-Type', 'text/plain; charset=utf-8');
					return $resp;
				}
				return $this->get($uid, $path, $method === 'HEAD');
			case 'PUT':
				if ($isItem) {
					$this->sync->putItem($uid, $this->sync->jidFromPath($path), $this->body());
					return $this->raw('', Http::STATUS_CREATED);
				}
				$this->store->put($uid, $path, $this->body(), $now);
				return $this->raw('', Http::STATUS_CREATED);
			case 'DELETE':
				if ($isItem) {
					$this->sync->deleteItem($uid, $this->sync->jidFromPath($path));
					return $this->raw('', Http::STATUS_NO_CONTENT);
				}
				$this->store->delete($uid, $path);
				return $this->raw('', Http::STATUS_NO_CONTENT);
			case 'MKCOL':
				$this->store->mkcol($uid, $path, $now);
				return $this->raw('', Http::STATUS_CREATED);
			case 'MOVE':
				$dest = $this->destinationPath();
				if ($dest === null) {
					return $this->raw('', Http::STATUS_BAD_REQUEST);
				}
				$this->store->move($uid, $path, $dest, $now);
				return $this->raw('', Http::STATUS_CREATED);
			default:
				return $this->raw('', Http::STATUS_METHOD_NOT_ALLOWED);
		}
	}

	private function get(string $uid, string $path, bool $headOnly): Response {
		$st = $this->store->stat($uid, $path);
		if ($st === null || $st['is_dir']) {
			return $this->raw('', Http::STATUS_NOT_FOUND);
		}
		$content = $headOnly ? '' : (string)$this->store->getContent($uid, $path);
		$resp = $this->raw($content, Http::STATUS_OK);
		$resp->addHeader('Content-Type', 'application/octet-stream');
		$resp->addHeader('Last-Modified', gmdate('D, d M Y H:i:s', (int)floor($st['updated_ms'] / 1000)) . ' GMT');
		return $resp;
	}

	private function propfind(string $uid, string $path): Response {
		$depth = $this->request->getHeader('Depth');
		$depth = ($depth === '' ? '1' : $depth);

		// Stat of a single item file (jid.md) is synthesized from the index.
		if ($this->sync->isItemPath($path)) {
			$item = $this->sync->getItem($uid, $this->sync->jidFromPath($path));
			if ($item === null) {
				return $this->multiStatusResponse('');
			}
			return $this->multiStatusResponse($this->responseXml(
				['path' => $path, 'is_dir' => false, 'size' => strlen($item), 'updated_ms' => 0]));
		}

		$self = $this->store->stat($uid, $path);
		// The root always exists (even with no opaque files yet).
		if ($self === null && $path !== '') {
			return $this->multiStatusResponse('');
		}
		if ($self === null) {
			$self = ['path' => '', 'is_dir' => true, 'size' => 0, 'updated_ms' => 0];
		}
		$entries = [$self];
		if ($depth !== '0' && $self['is_dir']) {
			foreach ($this->store->children($uid, $path) as $c) {
				$entries[] = $c;
			}
			// At the root, also list the synthesized Joplin item files.
			if ($path === '') {
				foreach ($this->sync->enumerate($uid) as $it) {
					$entries[] = ['path' => $it['path'], 'is_dir' => false, 'size' => $it['size'], 'updated_ms' => $it['updated_ms']];
				}
			}
		}
		$responses = '';
		foreach ($entries as $e) {
			$responses .= $this->responseXml($e);
		}
		return $this->multiStatusResponse($responses);
	}

	private function responseXml(array $e): string {
		$href = $this->baseHref() . $this->encodePath((string)$e['path']);
		$isDir = !empty($e['is_dir']);
		if ($isDir && substr($href, -1) !== '/') {
			$href .= '/';
		}
		$lastMod = gmdate('D, d M Y H:i:s', (int)floor(((int)$e['updated_ms']) / 1000)) . ' GMT';
		$rtype = $isDir ? '<d:resourcetype><d:collection/></d:resourcetype>' : '<d:resourcetype/>';
		$size = $isDir ? '' : '<d:getcontentlength>' . (int)$e['size'] . '</d:getcontentlength>';
		return '<d:response>'
			. '<d:href>' . $this->xml($href) . '</d:href>'
			. '<d:propstat><d:prop>'
			. '<d:getlastmodified>' . $this->xml($lastMod) . '</d:getlastmodified>'
			. $rtype . $size
			. '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
			. '</d:response>';
	}

	private function multistatus(array $responses): string {
		return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<d:multistatus xmlns:d="DAV:">' . implode('', $responses) . '</d:multistatus>';
	}

	private function multiStatusResponse(string $responsesXml): Response {
		$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
			. '<d:multistatus xmlns:d="DAV:">' . $responsesXml . '</d:multistatus>';
		$resp = $this->raw($xml, 207);
		$resp->addHeader('Content-Type', 'application/xml; charset=utf-8');
		return $resp;
	}

	// ── helpers ──────────────────────────────────────────────────────────────

	private function raw(string $body, int $status): DataDisplayResponse {
		return new DataDisplayResponse($body, $status, ['Content-Type' => 'application/octet-stream']);
	}

	private function body(): string {
		$b = file_get_contents('php://input');
		return $b === false ? '' : $b;
	}

	/** Base href (path part of the endpoint root, with trailing slash). */
	private function baseHref(): string {
		$uri = $this->request->getRequestUri(); // e.g. /index.php/apps/markdown_notes/joplin/foo
		$pos = strpos($uri, '/joplin');
		$base = $pos !== false ? substr($uri, 0, $pos + strlen('/joplin')) : $uri;
		return rtrim($base, '/') . '/';
	}

	private function encodePath(string $path): string {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	private function destinationPath(): ?string {
		$dest = $this->request->getHeader('Destination');
		if ($dest === '') {
			return null;
		}
		$p = parse_url($dest, PHP_URL_PATH) ?: $dest;
		$p = rawurldecode($p);
		$marker = '/joplin/';
		$pos = strpos($p, $marker);
		return $pos !== false ? $this->store->norm(substr($p, $pos + strlen($marker))) : null;
	}

	private function xml(string $s): string {
		return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	private function nowMs(): int {
		return (int)round(microtime(true) * 1000);
	}
}
