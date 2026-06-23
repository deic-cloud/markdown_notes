<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'resource#show', 'url' => '/resource/{id}', 'verb' => 'GET'],
		['name' => 'resource#create', 'url' => '/resource', 'verb' => 'POST'],

		// Joplin WebDAV sync target — one catch-all per verb (path may contain
		// slashes). All dispatch to WebDavController::dav.
		['name' => 'webDav#dav', 'url' => '/joplin', 'verb' => 'PROPFIND',
			'postfix' => 'root-propfind'],
		['name' => 'webDav#dav', 'url' => '/joplin/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '.*'], 'defaults' => ['path' => ''], 'postfix' => 'get'],
		['name' => 'webDav#dav', 'url' => '/joplin/{path}', 'verb' => 'HEAD',
			'requirements' => ['path' => '.*'], 'defaults' => ['path' => ''], 'postfix' => 'head'],
		['name' => 'webDav#dav', 'url' => '/joplin/{path}', 'verb' => 'PUT',
			'requirements' => ['path' => '.*'], 'defaults' => ['path' => ''], 'postfix' => 'put'],
		['name' => 'webDav#dav', 'url' => '/joplin/{path}', 'verb' => 'DELETE',
			'requirements' => ['path' => '.*'], 'defaults' => ['path' => ''], 'postfix' => 'delete'],
		['name' => 'webDav#dav', 'url' => '/joplin/{path}', 'verb' => 'PROPFIND',
			'requirements' => ['path' => '.*'], 'defaults' => ['path' => ''], 'postfix' => 'propfind'],
		['name' => 'webDav#dav', 'url' => '/joplin/{path}', 'verb' => 'MKCOL',
			'requirements' => ['path' => '.*'], 'defaults' => ['path' => ''], 'postfix' => 'mkcol'],
		['name' => 'webDav#dav', 'url' => '/joplin/{path}', 'verb' => 'MOVE',
			'requirements' => ['path' => '.*'], 'defaults' => ['path' => ''], 'postfix' => 'move'],
	],
	'ocs' => [
		['name' => 'api#tree',           'url' => '/api/v1/tree',            'verb' => 'GET'],
		['name' => 'api#listNotes',      'url' => '/api/v1/notes',           'verb' => 'GET'],
		['name' => 'api#getNote',        'url' => '/api/v1/note',            'verb' => 'GET'],
		['name' => 'api#templates',      'url' => '/api/v1/templates',       'verb' => 'GET'],
		['name' => 'api#templateInfo',   'url' => '/api/v1/template/info',   'verb' => 'GET'],
		['name' => 'api#saveNote',       'url' => '/api/v1/note/save',       'verb' => 'POST'],
		['name' => 'api#setCompleted',   'url' => '/api/v1/note/complete',   'verb' => 'POST'],
		['name' => 'api#setDue',         'url' => '/api/v1/note/due',        'verb' => 'POST'],
		['name' => 'api#setTodo',        'url' => '/api/v1/note/todo',       'verb' => 'POST'],
		['name' => 'api#setMeta',        'url' => '/api/v1/note/meta',       'verb' => 'POST'],
		['name' => 'api#createNote',     'url' => '/api/v1/note/create',     'verb' => 'POST'],
		['name' => 'api#addTags',        'url' => '/api/v1/note/tag',        'verb' => 'POST'],
		['name' => 'api#untag',          'url' => '/api/v1/note/untag',      'verb' => 'POST'],
		['name' => 'api#deleteNote',     'url' => '/api/v1/note/delete',     'verb' => 'POST'],
		['name' => 'api#createNotebook', 'url' => '/api/v1/notebook/create', 'verb' => 'POST'],
		['name' => 'api#deleteNotebook', 'url' => '/api/v1/notebook/delete', 'verb' => 'POST'],
		['name' => 'api#rename',         'url' => '/api/v1/rename',          'verb' => 'POST'],
		['name' => 'api#gc',             'url' => '/api/v1/gc',              'verb' => 'POST'],
	],
];
