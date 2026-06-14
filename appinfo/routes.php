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
		['name' => 'repo#refs', 'url' => '/api/repos/{repoId}/refs', 'verb' => 'GET'],
		['name' => 'repo#raw', 'url' => '/api/repos/{repoId}/raw', 'verb' => 'GET'],
		['name' => 'repo#search', 'url' => '/api/repos/{repoId}/search', 'verb' => 'GET'],
		['name' => 'repo#searchAll', 'url' => '/api/search', 'verb' => 'GET'],
		['name' => 'repo#diff', 'url' => '/api/repos/{repoId}/diff', 'verb' => 'GET'],
		['name' => 'repo#diffRange', 'url' => '/api/repos/{repoId}/diff-range', 'verb' => 'GET'],
		['name' => 'repo#blame', 'url' => '/api/repos/{repoId}/blame', 'verb' => 'GET'],

		// Per-user Files-backed repos (Horizon 2)
		['name' => 'userRepo#listMine', 'url' => '/api/my/repos', 'verb' => 'GET'],
		['name' => 'userRepo#validateMine', 'url' => '/api/my/repos/validate', 'verb' => 'POST'],
		['name' => 'userRepo#addMine', 'url' => '/api/my/repos/add', 'verb' => 'POST'],
		['name' => 'userRepo#removeMine', 'url' => '/api/my/repos/remove', 'verb' => 'POST'],

		// Per-user remote-forge (GitHub) repos (Horizon 3)
		['name' => 'forgeRepo#listMine', 'url' => '/api/forge/repos', 'verb' => 'GET'],
		['name' => 'forgeRepo#addMine', 'url' => '/api/forge/repos/add', 'verb' => 'POST'],
		['name' => 'forgeRepo#removeMine', 'url' => '/api/forge/repos/remove', 'verb' => 'POST'],

		// Admin settings (admin-only by default; CSRF-protected)
		['name' => 'settings#save', 'url' => '/settings/save', 'verb' => 'POST'],
		['name' => 'settings#validatePath', 'url' => '/settings/validate-path', 'verb' => 'POST'],
	],
];
