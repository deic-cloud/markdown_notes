<?php

declare(strict_types=1);

/**
 * remote.php service "notes": the Joplin sync endpoint at
 *
 *     https://<host>/remote.php/notes/[<path>]
 *
 * — the address the old service used, so existing Joplin setups keep working.
 * Declared in appinfo/info.xml (<remote><notes>appinfo/notes.php</notes></remote>);
 * Nextcloud registers it as app config core/remote_notes on install or upgrade.
 * remote.php has booted Nextcloud and loaded this app before including us; it
 * does not log the user in, so accept HTTP Basic auth (an app/device password)
 * here the way index.php does, then dispatch to the same WebDavController that
 * serves /index.php/apps/markdown_notes/joplin.
 */

use OCA\MarkdownNotes\Controller\WebDavController;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;

$request     = \OCP\Server::get(IRequest::class);
$userSession = \OCP\Server::get(IUserSession::class);

if (!$userSession->isLoggedIn()) {
	$loggedIn = \OC_User::handleApacheAuth();
	if (!$loggedIn && method_exists($userSession, 'tryTokenLogin')) {
		$loggedIn = $userSession->tryTokenLogin($request);
	}
	if (!$loggedIn && method_exists($userSession, 'tryBasicAuthLogin')) {
		$loggedIn = $userSession->tryBasicAuthLogin($request, \OCP\Server::get(IThrottler::class));
	}
	if (!$loggedIn) {
		header('WWW-Authenticate: Basic realm="Notes"');
		http_response_code(401);
		exit;
	}
}

// /notes/<path> → <path>
$pathInfo = (string)($request->getPathInfo() ?: '');
$sub = preg_replace('#^/notes/?#', '', $pathInfo) ?? '';

$container = \OC::$server->getRegisteredAppContainer('markdown_notes');
\OC\AppFramework\App::main(WebDavController::class, 'dav', $container, [
	'path'   => rawurldecode($sub),
	'_route' => 'markdown_notes.webDav.dav',
]);
