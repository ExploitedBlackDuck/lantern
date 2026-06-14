<?php

declare(strict_types=1);

/**
 * Self-contained test runner for Lantern's framework-free git core.
 *
 * Requires only PHP + git — no composer, no Nextcloud. It builds its own
 * fixture repositories, runs every assertion, cleans up, and exits nonzero on
 * any failure. This is what makes the coverage claim in PROJECT_BIBLE §13
 * reproducible from the shipped artifact:
 *
 *     php tests/run-core-tests.php
 *
 * A PHPUnit wrapper (tests/unit/LocalGitProviderTest.php) reuses the same
 * fixtures for CI once `composer require --dev phpunit/phpunit` is available.
 */

require __DIR__ . '/fixtures.php';
require __DIR__ . '/registry-stubs.php';

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\Lantern\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$rel = str_replace('\\', '/', substr($class, \strlen($prefix)));
	$file = \dirname(__DIR__) . '/lib/' . $rel . '.php';
	if (is_file($file)) {
		require $file;
	}
});

use OCA\Lantern\Exception\RepoException;
use OCA\Lantern\Model\RefInfo;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Model\SearchMatch;
use OCA\Lantern\Model\TreeEntry;
use OCA\Lantern\Provider\Local\GitBinary;
use OCA\Lantern\Provider\Local\LocalGitProvider;
use OCA\Lantern\Provider\Local\RefValidator;
use OCA\Lantern\Service\RepoRegistry;

/** In-memory IAppConfig for the RepoRegistry test (see registry-stubs.php). */
final class FakeAppConfig implements \OCP\IAppConfig {
	/** @param array<string,string> $values */
	public function __construct(private array $values) {
	}
	public function getValueString(string $app, string $key, string $default = ''): string {
		return $this->values[$key] ?? $default;
	}
}

/** No-op logger satisfying the (stubbed) PSR LoggerInterface. */
final class NullLogger implements \Psr\Log\LoggerInterface {
	public function warning($message, array $context = []): void {
	}
}

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
$throws = static function (callable $fn): bool {
	try {
		$fn();
		return false;
	} catch (RepoException $e) {
		return true;
	}
};

$git = new GitBinary('git', null);
$validator = new RefValidator();
$provider = new LocalGitProvider($git, $validator);

$repoPath = buildFixtureRepo();
$emptyPath = buildEmptyRepo();
$repo = new RepoDescriptor('test', 'Test Repo', $repoPath, 'local');
$empty = new RepoDescriptor('empty', 'Empty Repo', $emptyPath, 'local');

try {
	$ref = $provider->defaultRef($repo);
	$check('default ref resolves (master)', $ref === 'master');

	$root = $provider->listTree($repo, $ref, '');
	$names = array_map(static fn (TreeEntry $e) => $e->name, $root);
	$check('root has 4 entries', \count($root) === 4);
	$check('dirs sorted before files', $names === ['assets', 'docs', 'src', 'README.md']);
	$readme = array_values(array_filter($root, static fn (TreeEntry $e) => $e->name === 'README.md'))[0] ?? null;
	$check('README.md is a blob, size 42', $readme && $readme->type === 'blob' && $readme->size === 42);

	$src = $provider->listTree($repo, $ref, 'src');
	$srcNames = array_map(static fn (TreeEntry $e) => $e->name, $src);
	$check('subdir lists leaf names', $srcNames === ['index.js', 'style.css']);
	$check('subdir entry path is fully-qualified', $src[0]->path === 'src/index.js');

	$blob = $provider->getBlob($repo, $ref, 'README.md');
	$check('text blob not binary', $blob->binary === false);
	$check('text blob content present', str_contains((string) $blob->content, '# Lantern Test'));

	$bin = $provider->getBlob($repo, $ref, 'assets/logo.bin');
	$check('binary blob flagged', $bin->binary === true);
	$check('binary content suppressed', $bin->content === null);

	$commits = $provider->listCommits($repo, $ref, null, 50);
	$check('three commits', \count($commits) === 3);
	$check('newest commit first', $commits[0]->subject === 'expand README');
	$check('short hash length 7', \strlen($commits[0]->shortHash()) === 7);

	$pc = $provider->listCommits($repo, $ref, 'src/index.js', 50);
	$check('path-scoped history (2 commits)', \count($pc) === 2);

	// --- pagination (offset) ---
	$page1 = $provider->listCommits($repo, $ref, null, 2, 0);
	$page2 = $provider->listCommits($repo, $ref, null, 2, 2);
	$check('commits page 1 has 2', \count($page1) === 2);
	$check('commits page 2 has the 3rd (offset)', \count($page2) === 1 && $page2[0]->subject === 'initial commit');
	$check('pages do not overlap', $page1[0]->hash !== $page2[0]->hash);

	// --- diff + blame (H4) ---
	$diff = $provider->getCommitDiff($repo, $ref);
	$check('commit diff is a unified patch', str_contains($diff, 'diff --git') && str_contains($diff, '+extra line'));
	$blame = $provider->blame($repo, $ref, 'README.md');
	$check('blame returns one entry per line', \count($blame) === 4);
	$check('blame carries author + full hash', $blame[0]->author === 'Tester' && \strlen($blame[0]->hash) === 40);
	$check('blame serializes a 7-char short hash', \strlen($blame[0]->jsonSerialize()['hash']) === 7);
	$check('blame last line from newest commit', $blame[3]->author === 'Tester');

	// --- group restrictions (RepoDescriptor visibility) ---
	$restricted = new RepoDescriptor('r', 'R', '/x', 'local', ['devs']);
	$check('restricted repo hidden from outsider', $restricted->visibleTo(['other'], false) === false);
	$check('restricted repo visible to member', $restricted->visibleTo(['devs'], false) === true);
	$check('restricted repo visible to admin', $restricted->visibleTo([], true) === true);
	$open = new RepoDescriptor('o', 'O', '/x', 'local', []);
	$check('unrestricted repo visible to all', $open->visibleTo([], false) === true);

	$check('descriptor JSON omits path', !str_contains((string) json_encode($repo), $repoPath));

	// --- refs (branch/tag picker) ---
	$refs = $provider->listRefs($repo);
	$refNames = array_map(static fn (RefInfo $r) => $r->name, $refs);
	$check('listRefs finds dev, master, v1.0', $refNames === ['dev', 'master', 'v1.0']);
	$branches = array_values(array_filter($refs, static fn (RefInfo $r) => $r->type === RefInfo::TYPE_BRANCH));
	$tags = array_values(array_filter($refs, static fn (RefInfo $r) => $r->type === RefInfo::TYPE_TAG));
	$check('listRefs: two branches, one tag', \count($branches) === 2 && \count($tags) === 1);
	$default = array_values(array_filter($refs, static fn (RefInfo $r) => $r->isDefault));
	$check('listRefs flags master as default', \count($default) === 1 && $default[0]->name === 'master');

	// --- raw blob (download / image preview) ---
	$rawText = $provider->getBlobRaw($repo, $ref, 'README.md', 1024 * 1024);
	$check('raw blob returns verbatim bytes', str_contains($rawText->content, '# Lantern Test'));
	$rawBin = $provider->getBlobRaw($repo, $ref, 'assets/logo.bin', 1024 * 1024);
	$check('raw blob returns binary bytes verbatim', \strlen($rawBin->content) === 300 && str_contains($rawBin->content, "\0"));
	$check('raw blob rejects a directory', $throws(static fn () => $provider->getBlobRaw($repo, $ref, 'src', 1024)));

	// --- in-repo search (git grep) ---
	$hits = $provider->search($repo, $ref, 'console.log', 50);
	$check('search finds console.log in src/index.js', \count($hits) === 1 && $hits[0]->path === 'src/index.js');
	$check('search match carries a line number', $hits[0]->line === 1);
	$noHits = $provider->search($repo, $ref, 'zzz-not-present-anywhere', 50);
	$check('search with no matches returns [] (exit 1 not error)', $noHits === []);
	$check('search rejects control chars in query', $throws(static fn () => $provider->search($repo, $ref, "a\x00b", 50)));
	$multi = $provider->search($repo, $ref, 'Lantern', 50);
	$check('search matches multiple files', \count($multi) >= 1 && array_filter($multi, static fn (SearchMatch $m) => $m->path === 'README.md'));

	// --- security / edge cases ---
	foreach (['--upload-pack=evil', '-x', 'a..b', "main\nrm", '../etc'] as $bad) {
		$check('reject bad ref ' . json_encode($bad), $throws(static fn () => $provider->listTree($repo, $bad, '')));
	}
	foreach (['../../../etc/passwd', '..', 'src/../../escape'] as $bad) {
		$check('reject traversal ' . json_encode($bad), $throws(static fn () => $provider->getBlob($repo, $ref, $bad)));
	}
	$check('getBlob on a dir throws', $throws(static fn () => $provider->getBlob($repo, $ref, 'src')));
	$check('listTree on a file throws', $throws(static fn () => $provider->listTree($repo, $ref, 'README.md')));
	$check('missing blob throws', $throws(static fn () => $provider->getBlob($repo, $ref, 'no/such.txt')));

	// --- empty-repo edge case (previously untested) ---
	$emptyRoot = $provider->listTree($empty, $provider->defaultRef($empty), '');
	$check('empty repo root lists [] (no crash)', $emptyRoot === []);
} finally {
	destroyRepo($repoPath);
	destroyRepo($emptyPath);
}

// --- RepoRegistry (framework-coupled; tested via stubbed IAppConfig) ---
$initBareRepo = static function (string $dir): void {
	mkdir($dir, 0700, true);
	exec('cd ' . escapeshellarg($dir) . ' && git init -q 2>/dev/null');
};
$rand = bin2hex(random_bytes(4));
$baseRoot = sys_get_temp_dir() . '/lantern-regbase-' . $rand;
$inside = $baseRoot . '/inside';
$evilSibling = $baseRoot . '-evil';      // shares the base string but is NOT inside it
$outside = sys_get_temp_dir() . '/lantern-regout-' . $rand;
$notRepo = sys_get_temp_dir() . '/lantern-regplain-' . $rand;

$initBareRepo($inside);       // valid, inside base
$initBareRepo($evilSibling);  // valid repo, but false-prefix sibling of base
$initBareRepo($outside);      // valid repo, outside base
mkdir($notRepo, 0700, true);  // exists but is not a git repo

try {
	$reposJson = json_encode([
		['id' => 'inside', 'name' => 'Inside', 'path' => $inside],
		['id' => 'evil', 'name' => 'Evil', 'path' => $evilSibling],
		['id' => 'outside', 'name' => 'Outside', 'path' => $outside],
		['id' => 'plain', 'name' => 'Plain', 'path' => $notRepo],
		['id' => 'inside', 'name' => 'Dup', 'path' => $inside], // duplicate id
	]);
	$cfg = new FakeAppConfig(['repos' => $reposJson, 'allowed_base' => $baseRoot]);
	$registry = new RepoRegistry($cfg, new NullLogger());

	$all = $registry->all();
	$ids = array_map(static fn (RepoDescriptor $r) => $r->id, $all);
	$check('registry includes the in-base repo', \in_array('inside', $ids, true));
	$check('registry excludes the non-repo path', !\in_array('plain', $ids, true));
	$check('registry excludes the repo outside base', !\in_array('outside', $ids, true));
	$check('registry rejects false-prefix sibling (base-evil)', !\in_array('evil', $ids, true));

	$check('registry get() resolves a known id', $registry->get('inside')->id === 'inside');
	$check('registry get() throws on unknown id', $throws(static fn () => $registry->get('nope')));

	// validatePath: saved base used when override is null.
	$vIn = $registry->validatePath($inside);
	$check('validatePath ok for in-base repo', $vIn['ok'] === true);
	$vEvil = $registry->validatePath($evilSibling);
	$check('validatePath rejects false-prefix sibling', $vEvil['ok'] === false);
	// Explicit empty base override disables containment.
	$vOut = $registry->validatePath($outside, '');
	$check('validatePath ok for real repo with no base', $vOut['ok'] === true);
	$vPlain = $registry->validatePath($notRepo, '');
	$check('validatePath rejects a non-repo dir', $vPlain['ok'] === false);
	$vMissing = $registry->validatePath('/no/such/path/' . $rand, '');
	$check('validatePath rejects a missing path', $vMissing['ok'] === false);
} finally {
	destroyRepo($baseRoot);
	destroyRepo($evilSibling);
	destroyRepo($outside);
	destroyRepo($notRepo);
}

// --- GitHubProvider pure mappers (fixture JSON, no network) ---
$ghTree = \OCA\Lantern\Provider\Forge\GitHubProvider::mapTree([
	['name' => 'main.py', 'path' => 'main.py', 'type' => 'file', 'size' => 12, 'sha' => 'aaa'],
	['name' => 'lib', 'path' => 'lib', 'type' => 'dir', 'sha' => 'bbb'],
	['name' => 'README.md', 'path' => 'README.md', 'type' => 'file', 'size' => 30, 'sha' => 'ccc'],
]);
$ghNames = array_map(static fn (TreeEntry $e) => $e->name, $ghTree);
$check('GH mapTree sorts dirs first', $ghNames === ['lib', 'main.py', 'README.md']);
$check('GH mapTree dir size is null', $ghTree[0]->type === 'tree' && $ghTree[0]->size === null);
$check('GH mapTree file size mapped', $ghTree[1]->size === 12);

$ghBlobText = \OCA\Lantern\Provider\Forge\GitHubProvider::mapBlob([
	'path' => 'a.txt', 'size' => 5, 'encoding' => 'base64', 'content' => base64_encode("hello"),
]);
$check('GH mapBlob decodes base64', $ghBlobText->content === 'hello' && $ghBlobText->binary === false);
$ghBlobBin = \OCA\Lantern\Provider\Forge\GitHubProvider::mapBlob([
	'path' => 'b.bin', 'size' => 4, 'encoding' => 'base64', 'content' => base64_encode("a\x00b\xff"),
]);
$check('GH mapBlob flags binary + suppresses', $ghBlobBin->binary === true && $ghBlobBin->content === null);
$ghBlobBig = \OCA\Lantern\Provider\Forge\GitHubProvider::mapBlob(['path' => 'big', 'size' => 5_000_000, 'encoding' => 'none']);
$check('GH mapBlob marks >1MiB truncated', $ghBlobBig->truncated === true && $ghBlobBig->content === null);

$ghCommits = \OCA\Lantern\Provider\Forge\GitHubProvider::mapCommits([
	['sha' => 'deadbeef', 'commit' => ['message' => "Fix bug\n\nlong body", 'author' => ['name' => 'Ann', 'email' => 'a@x', 'date' => '2026-01-01T00:00:00Z']]],
]);
$check('GH mapCommits takes subject line only', $ghCommits[0]->subject === 'Fix bug' && $ghCommits[0]->authorName === 'Ann');
$check('GH mapCommits shortHash', $ghCommits[0]->shortHash() === 'deadbee');

$ghRefs = \OCA\Lantern\Provider\Forge\GitHubProvider::mapRefs(
	[['name' => 'main'], ['name' => 'dev']],
	[['name' => 'v2.0']],
	'main',
);
$check('GH mapRefs branches+tags', \count($ghRefs) === 3 && $ghRefs[2]->type === 'tag');
$check('GH mapRefs default flagged', $ghRefs[0]->name === 'main' && $ghRefs[0]->isDefault === true);

$ghSearch = \OCA\Lantern\Provider\Forge\GitHubProvider::mapSearch([
	['path' => 'src/x.js', 'text_matches' => [['fragment' => "found here\nsecond"]]],
	['path' => 'y.js'],
]);
$check('GH mapSearch maps path + fragment', $ghSearch[0]->path === 'src/x.js' && str_contains($ghSearch[0]->text, 'found here'));
$check('GH mapSearch tolerates no fragment', $ghSearch[1]->path === 'y.js' && $ghSearch[1]->line === 0);

echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
