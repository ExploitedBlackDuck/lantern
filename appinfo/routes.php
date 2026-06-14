<?php

declare(strict_types=1);

return [
	'routes' => [
		// App shell
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// JSON API (read-only)
		['name' => 'repo#listRepos', 'url' => '/api/repos', 'verb' => 'GET'],
		['name' => 'repo#tree', 'url' => '/api/repos/{repoId}/tree', 'verb' => 'GET'],
		['name' => 'repo#blob', 'url' => '/api/repos/{repoId}/blob', 'verb' => 'GET'],
		['name' => 'repo#commits', 'url' => '/api/repos/{repoId}/commits', 'verb' => 'GET'],

		// Admin settings (admin-only by default; CSRF-protected)
		['name' => 'settings#save', 'url' => '/settings/save', 'verb' => 'POST'],
	],
];
