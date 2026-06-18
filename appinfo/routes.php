<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	],
	'ocs' => [
		['name' => 'api#tree',           'url' => '/api/v1/tree',            'verb' => 'GET'],
		['name' => 'api#listNotes',      'url' => '/api/v1/notes',           'verb' => 'GET'],
		['name' => 'api#getNote',        'url' => '/api/v1/note',            'verb' => 'GET'],
		['name' => 'api#templates',      'url' => '/api/v1/templates',       'verb' => 'GET'],
		['name' => 'api#saveNote',       'url' => '/api/v1/note/save',       'verb' => 'POST'],
		['name' => 'api#createNote',     'url' => '/api/v1/note/create',     'verb' => 'POST'],
		['name' => 'api#addTags',        'url' => '/api/v1/note/tag',        'verb' => 'POST'],
		['name' => 'api#untag',          'url' => '/api/v1/note/untag',      'verb' => 'POST'],
		['name' => 'api#deleteNote',     'url' => '/api/v1/note/delete',     'verb' => 'POST'],
		['name' => 'api#createNotebook', 'url' => '/api/v1/notebook/create', 'verb' => 'POST'],
		['name' => 'api#deleteNotebook', 'url' => '/api/v1/notebook/delete', 'verb' => 'POST'],
		['name' => 'api#rename',         'url' => '/api/v1/rename',          'verb' => 'POST'],
	],
];
