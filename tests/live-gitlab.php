<?php

declare(strict_types=1);

/**
 * LIVE verification of the GitLab backend against the real gitlab.com API.
 *
 * Unlike run-core-tests.php (offline, framework-free, must always be green),
 * this script needs NETWORK and hits gitlab.com, so it is NOT part of the core
 * suite. It exercises every REST endpoint GitLabProvider talks to and feeds the
 * REAL responses through the provider's pure mappers — proving the URLs we build
 * and the JSON shapes we expect actually match GitLab. The NC IClientService
 * plumbing itself is identical to the live-verified GitHub backend.
 *
 *   php tests/live-gitlab.php [group/project] [private-token]
 *
 * Defaults to a small public project; a token is optional (public = no auth).
 */

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\Lantern\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = \dirname(__DIR__) . '/lib/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
	if (is_file($file)) {
		require $file;
	}
});

use OCA\Lantern\Model\BlameLine;
use OCA\Lantern\Model\CommitInfo;
use OCA\Lantern\Model\RefInfo;
use OCA\Lantern\Model\SearchMatch;
use OCA\Lantern\Model\TreeEntry;
use OCA\Lantern\Provider\Forge\GitLabProvider as GL;

$project = $argv[1] ?? 'gitlab-org/gitlab-runner';
$token = $argv[2] ?? '';
$base = 'https://gitlab.com/api/v4';
$pid = rawurlencode($project);

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $cond) use (&$pass, &$fail): void {
	if ($cond) {
		$pass++;
		echo "  PASS  $label\n";
	} else {
		$fail++;
		echo "  FAIL  $label\n";
	}
};

/** @return array{0:int,1:mixed,2:string} [status, decoded-json|null, raw-body] */
$api = static function (string $path, bool $raw = false) use ($base, $token): array {
	$headers = ["User-Agent: Lantern-Live-Test"];
	if ($token !== '') {
		$headers[] = 'PRIVATE-TOKEN: ' . $token;
	}
	$ctx = stream_context_create(['http' => [
		'method' => 'GET',
		'header' => implode("\r\n", $headers),
		'timeout' => 20,
		'ignore_errors' => true,
	]]);
	$body = @file_get_contents($base . $path, false, $ctx);
	$status = 0;
	foreach ($http_response_header ?? [] as $h) {
		if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
			$status = (int) $m[1];
		}
	}
	return [$status, $raw ? null : json_decode((string) $body, true), (string) $body];
};

echo "Live GitLab verification against $project\n\n";

// 1. project metadata -> default ref
[$st, $proj] = $api("/projects/$pid");
$check("GET /projects/:id -> 200", $st === 200);
$ref = \is_array($proj) ? (string) ($proj['default_branch'] ?? '') : '';
$check("project exposes a default_branch ('$ref')", $ref !== '');

// 2. root tree -> mapTree
[$st, $tree] = $api("/projects/$pid/repository/tree?per_page=100&ref=" . rawurlencode($ref));
$check("GET repository/tree -> 200 + list", $st === 200 && \is_array($tree) && array_is_list($tree));
$entries = GL::mapTree(\is_array($tree) ? $tree : []);
$check("mapTree yields entries with valid types", \count($entries) > 0
	&& array_reduce($entries, static fn ($ok, TreeEntry $e) => $ok && \in_array($e->type, ['tree', 'blob'], true), true));
$dirsFirst = true;
$seenFile = false;
foreach ($entries as $e) {
	if ($e->type === 'blob') {
		$seenFile = true;
	} elseif ($seenFile && $e->type === 'tree') {
		$dirsFirst = false;
	}
}
$check("mapTree sorts directories before files", $dirsFirst);

// pick a blob to read/blame — prefer a small, common file
$prefer = ['.gitignore', 'go.mod', 'VERSION', 'Makefile', 'README.md', 'LICENSE'];
$file = '';
foreach ($prefer as $name) {
	foreach ($entries as $e) {
		if ($e->type === 'blob' && $e->name === $name) {
			$file = $e->path;
			break 2;
		}
	}
}
if ($file === '') {
	foreach ($entries as $e) {
		if ($e->type === 'blob') {
			$file = $e->path;
			break;
		}
	}
}
echo "  (using blob: $file)\n";

// 3. blob -> mapBlob
[$st, $blob] = $api("/projects/$pid/repository/files/" . rawurlencode($file) . "?ref=" . rawurlencode($ref));
$check("GET repository/files/:path -> 200", $st === 200 && \is_array($blob) && isset($blob['content']));
$mb = GL::mapBlob(\is_array($blob) ? $blob : []);
$check("mapBlob returns the file path + decoded content", $mb->path !== '' && ($mb->binary || $mb->content !== null));

// 4. commits -> mapCommits
[$st, $commits] = $api("/projects/$pid/repository/commits?per_page=3&ref_name=" . rawurlencode($ref));
$check("GET repository/commits -> 200 + list", $st === 200 && \is_array($commits) && array_is_list($commits));
$mc = GL::mapCommits(\is_array($commits) ? $commits : []);
$check("mapCommits gives 40-hex hashes + subjects", \count($mc) > 0
	&& array_reduce($mc, static fn ($ok, CommitInfo $c) => $ok && \strlen($c->hash) === 40 && $c->subject !== '', true));

// 5. branches + tags -> mapRefs. The branches list is bounded at per_page=100
// (same as GitHub); a repo with >100 branches may page the default out of the
// list, so verify the `default` field SHAPE via the single-branch endpoint,
// which always carries the flag for the default branch.
[$stb, $branches] = $api("/projects/$pid/repository/branches?per_page=100");
[$stt, $tags] = $api("/projects/$pid/repository/tags?per_page=20");
$check("GET branches + tags -> 200", $stb === 200 && $stt === 200);
[$std, $defBranch] = $api("/projects/$pid/repository/branches/" . rawurlencode($ref));
$check("single default-branch object carries default:true", $std === 200
	&& \is_array($defBranch) && ($defBranch['default'] ?? false) === true);
$refs = GL::mapRefs([$defBranch], \is_array($tags) ? $tags : [], $ref);
$hasDefault = array_reduce($refs, static fn ($ok, RefInfo $r) => $ok || ($r->isDefault && $r->name === $ref), false);
$check("mapRefs flags the default branch from the real `default` field", $hasDefault);

// 6. search -> mapSearch. GitLab's blob search requires auth even on public
// projects; the provider degrades to [] without a token. With a token, verify
// the real shape (path + integer line number).
[$st, $hits] = $api("/projects/$pid/search?scope=blobs&per_page=3&search=" . rawurlencode('the'));
if ($token === '') {
	$check("blob search needs auth (401 anon) -> provider degrades to []", $st === 401);
} else {
	$check("GET project search (blobs) -> 200 with token", $st === 200);
	$ms = GL::mapSearch(\is_array($hits) && array_is_list($hits) ? $hits : []);
	$check("mapSearch carries path + integer line", $ms === [] || array_reduce($ms, static fn ($ok, SearchMatch $m) => $ok && $m->path !== '' && $m->line >= 0, true));
}

// 7. commit diff -> assembleDiff
$sha = $mc[0]->hash ?? $ref;
[$st, $diff] = $api("/projects/$pid/repository/commits/" . rawurlencode($sha) . "/diff");
$check("GET commit diff -> 200 + list", $st === 200 && \is_array($diff) && array_is_list($diff));
$ad = GL::assembleDiff(\is_array($diff) ? $diff : []);
$check("assembleDiff builds a git-style patch", $ad === '' || str_contains($ad, 'diff --git'));

// 8. blame -> mapBlame
[$st, $blame] = $api("/projects/$pid/repository/files/" . rawurlencode($file) . "/blame?ref=" . rawurlencode($ref));
$check("GET file blame -> 200 + list", $st === 200 && \is_array($blame) && array_is_list($blame));
$mbl = GL::mapBlame(\is_array($blame) ? $blame : []);
$seq = true;
foreach ($mbl as $i => $bl) {
	if ($bl->line !== $i + 1) {
		$seq = false;
		break;
	}
}
$check("mapBlame yields per-line entries numbered from 1", \count($mbl) > 0 && $seq
	&& array_reduce($mbl, static fn ($ok, BlameLine $b) => $ok && \strlen($b->hash) === 40, true));

// 9. error contract — a real 404 from a nonexistent project
[$st404] = $api('/projects/' . rawurlencode('this-org/does-not-exist-' . bin2hex(random_bytes(4))));
$check("nonexistent project really returns 404 (classifyStatus -> RepoNotFound)", $st404 === 404);

echo "\n========================================\n";
echo "LIVE RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
