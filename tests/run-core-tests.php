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

use OCA\Lantern\Exception\ForgeAuthException;
use OCA\Lantern\Exception\RateLimitException;
use OCA\Lantern\Exception\RepoException;
use OCA\Lantern\Exception\RepoNotFoundException;
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
/** Return the class of the exception a callable throws, or '' if it doesn't. */
$throwsClass = static function (callable $fn): string {
	try {
		$fn();
		return '';
	} catch (\Throwable $e) {
		return $e::class;
	}
};

$git = new GitBinary('git', null);
$validator = new RefValidator();
$provider = new LocalGitProvider($git, $validator);

$repoPath = buildFixtureRepo();
$emptyPath = buildEmptyRepo();
$detachedPath = buildDetachedHeadRepo();
$repo = new RepoDescriptor('test', 'Test Repo', $repoPath, 'local');
$empty = new RepoDescriptor('empty', 'Empty Repo', $emptyPath, 'local');
$detached = new RepoDescriptor('detached', 'Detached Repo', $detachedPath, 'local');

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
	// commit-range diff (Tier 2): base..head shows cumulative changes
	$rdCommits = $provider->listCommits($repo, $ref, null, 50);
	$rangeDiff = $provider->getRangeDiff($repo, $rdCommits[2]->hash, $rdCommits[0]->hash);
	$check('range diff base..head shows cumulative changes across commits',
		str_contains($rangeDiff, 'diff --git') && str_contains($rangeDiff, '+extra line') && str_contains($rangeDiff, 'hi v2'));
	$check('range diff rejects an injected range string as a ref',
		$throws(static fn () => $provider->getRangeDiff($repo, 'a..b', $rdCommits[0]->hash)));

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

	// --- empty / no-commit repo edge cases (§0 hardening) ---
	$emptyRoot = $provider->listTree($empty, $provider->defaultRef($empty), '');
	$check('empty repo root lists [] (no crash)', $emptyRoot === []);
	// HEAD is symbolic (refs/heads/master) even with no commits, so defaultRef
	// still resolves a name rather than falling back.
	$check('empty repo defaultRef resolves symbolic name', $provider->defaultRef($empty) === 'master');
	// No commits yet -> no refs to enumerate; must be [] not an error.
	$check('empty repo listRefs is [] (no refs yet)', $provider->listRefs($empty) === []);
	// History of an unborn ref is a genuine not-found, not an empty list.
	$check('empty repo history throws (unborn ref)',
		$throws(static fn () => $provider->listCommits($empty, 'master', null, 10)));
	$check('empty repo getBlob throws', $throws(static fn () => $provider->getBlob($empty, 'master', 'x')));

	// --- detached HEAD / single-ref repo edge cases (§0 hardening) ---
	$check('detached HEAD defaultRef falls back to HEAD', $provider->defaultRef($detached) === 'HEAD');
	$detTree = $provider->listTree($detached, 'HEAD', '');
	$check('detached HEAD lists its tree off literal HEAD',
		\count($detTree) === 1 && $detTree[0]->name === 'only.txt');
	$detBlob = $provider->getBlob($detached, 'HEAD', 'only.txt');
	$check('detached HEAD reads a blob', str_contains((string) $detBlob->content, 'one file'));
	$detCommits = $provider->listCommits($detached, 'HEAD', null, 10);
	$check('detached HEAD reads history', \count($detCommits) === 1 && $detCommits[0]->subject === 'sole commit');
	// A detached repo has the one branch its commit sits on; no tags.
	$detRefs = $provider->listRefs($detached);
	$check('detached/minimal repo lists its single branch',
		\count($detRefs) === 1 && $detRefs[0]->name === 'master' && $detRefs[0]->type === RefInfo::TYPE_BRANCH);

	// --- trust boundary: repo-controlled diff.external must NOT execute (§9.6) ---
	$sentinel = sys_get_temp_dir() . '/lantern-pwned-' . bin2hex(random_bytes(4));
	$evilPath = buildMaliciousDiffRepo($sentinel);
	$evil = new RepoDescriptor('evil', 'Evil', $evilPath, 'local');
	try {
		// Sanity: prove the vector is REAL. `git diff` honors a repo's own
		// diff.external by default, so left unguarded it runs the payload and
		// drops the sentinel. Without this the negative test could pass vacuously.
		// (Note: `git show`/`log` ignore diff.external unless --ext-diff is given,
		// which is why the provider's show-based diff is already safe — asserted
		// separately below. `git diff` is used here purely to exercise the flag.)
		@unlink($sentinel);
		exec('cd ' . escapeshellarg($evilPath) . ' && git --no-pager diff HEAD~1 HEAD >/dev/null 2>&1');
		$firesUnguarded = file_exists($sentinel);
		@unlink($sentinel);
		$check('SANITY: malicious diff.external fires when git is unguarded', $firesUnguarded === true);

		// GitBinary's HARDENING_FLAGS (`-c diff.external=`) must neutralise it on
		// the very command that would otherwise honor it — this guards any future
		// feature that adds a `git diff` call (§4 standing rule).
		$git->run($evilPath, ['diff', 'HEAD~1', 'HEAD']);
		$check('GitBinary hardening blocks repo diff.external (no RCE)', !file_exists($sentinel));
		@unlink($sentinel);

		// And the provider's real diff path (git show) produces a valid diff and
		// never invokes the driver — safe by command selection AND by the flag.
		$diff = $provider->getCommitDiff($evil, 'HEAD');
		$check('getCommitDiff yields a diff without invoking diff.external',
			!file_exists($sentinel) && str_contains($diff, 'diff --git'));
		@unlink($sentinel);

		// Range diff uses `git diff`, which DOES honor diff.external — so this is
		// the real test that --no-ext-diff neutralises it while still producing
		// a valid diff (Tier 2).
		$evilCommits = $provider->listCommits($evil, 'HEAD', null, 5);
		$rd = $provider->getRangeDiff($evil, $evilCommits[1]->hash, $evilCommits[0]->hash);
		$check('getRangeDiff (git diff) neutralises diff.external (no RCE) + still diffs',
			!file_exists($sentinel) && str_contains($rd, 'diff --git'));
		@unlink($sentinel);
	} finally {
		destroyRepo($evilPath);
		@unlink($sentinel);
	}
} finally {
	destroyRepo($repoPath);
	destroyRepo($emptyPath);
	destroyRepo($detachedPath);
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

// --- GitHubProvider error contract (classifyStatus) — the §0 hardening gap ---
$GH = \OCA\Lantern\Provider\Forge\GitHubProvider::class;
$classify = static fn (int $s, array $h = []) => $throwsClass(static fn () => $GH::classifyStatus($s, $h));
$check('classify 200 does not throw', $classify(200) === '');
$check('classify 204 does not throw', $classify(204) === '');
$check('classify 401 -> ForgeAuthException', $classify(401) === ForgeAuthException::class);
$check('classify 403 forbidden (no rate hdrs) -> ForgeAuthException', $classify(403) === ForgeAuthException::class);
$check('classify 403 + remaining:0 -> RateLimitException',
	$classify(403, ['X-RateLimit-Remaining' => '0']) === RateLimitException::class);
$check('classify 403 + Retry-After -> RateLimitException',
	$classify(403, ['Retry-After' => '60']) === RateLimitException::class);
$check('classify 403 + remaining:57 -> ForgeAuthException (not rate limit)',
	$classify(403, ['X-RateLimit-Remaining' => '57']) === ForgeAuthException::class);
$check('classify 429 -> RateLimitException', $classify(429) === RateLimitException::class);
$check('classify 404 -> RepoNotFoundException', $classify(404) === RepoNotFoundException::class);
// The core regression this hardening fixes: a 5xx (or any generic error) must
// NOT masquerade as "not found" the way pre-2.0.1 code mapped everything to it.
$check('classify 500 -> RepoException (not RepoNotFound)',
	$classify(500) === RepoException::class);
$check('classify 502 -> RepoException', $classify(502) === RepoException::class);
// header lookup is case-insensitive; empty header values are treated as absent.
$check('classify rate-limit header is case-insensitive',
	$classify(403, ['x-ratelimit-remaining' => '0']) === RateLimitException::class);
$check('classify empty remaining header is not a rate limit',
	$classify(403, ['X-RateLimit-Remaining' => '']) === ForgeAuthException::class);
// The rate-limit message is actionable (carries the reset clock), not opaque.
$rlMsg = '';
try {
	$GH::classifyStatus(429, ['X-RateLimit-Reset' => '1700000000']);
} catch (RateLimitException $e) {
	$rlMsg = $e->getMessage();
}
$check('rate-limit message names the reset time', str_contains($rlMsg, 'resets at') && str_contains($rlMsg, 'UTC'));

// --- GitHubProvider pagination math (pageFor) — pagination edge cases ---
$check('pageFor offset 0 -> page 1', $GH::pageFor(0, 100) === 1);
$check('pageFor sub-page offset stays page 1', $GH::pageFor(50, 100) === 1);
$check('pageFor offset == perPage -> page 2', $GH::pageFor(100, 100) === 2);
$check('pageFor large offset -> correct page', $GH::pageFor(250, 100) === 3);
$check('pageFor negative offset clamps to page 1', $GH::pageFor(-5, 100) === 1);
$check('pageFor zero perPage does not divide-by-zero', $GH::pageFor(10, 0) === 11);

// --- GitHubProvider malformed / empty API responses (mappers stay total) ---
$check('GH mapTree of [] is []', $GH::mapTree([]) === []);
$check('GH mapCommits of [] is []', $GH::mapCommits([]) === []);
$check('GH mapRefs of empties is []', $GH::mapRefs([], [], 'main') === []);
$check('GH mapSearch of [] is []', $GH::mapSearch([]) === []);
$badCommits = $GH::mapCommits([['sha' => 'x'], ['commit' => 'not-an-array'], []]);
$check('GH mapCommits tolerates missing/garbage commit blocks',
	\count($badCommits) === 3 && $badCommits[0]->subject === '' && $badCommits[1]->authorName === '');
$badTree = $GH::mapTree([['type' => 'file'], ['name' => 'only-name']]);
$check('GH mapTree tolerates rows missing fields', \count($badTree) === 2);
$noContent = $GH::mapBlob(['path' => 'p', 'size' => 10, 'encoding' => 'base64']);
$check('GH mapBlob with no content field -> truncated, no crash',
	$noContent->truncated === true && $noContent->content === null);
$badB64 = $GH::mapBlob(['path' => 'p', 'size' => 4, 'encoding' => 'base64', 'content' => '!!!not base64!!!']);
$check('GH mapBlob with undecodable base64 -> truncated', $badB64->truncated === true);

// --- GitLabProvider pure mappers (fixture JSON, no network) ---
$GL = \OCA\Lantern\Provider\Forge\GitLabProvider::class;

$glTree = $GL::mapTree([
	['id' => 'a1', 'name' => 'main.py', 'type' => 'blob', 'path' => 'main.py', 'mode' => '100644'],
	['id' => 'b2', 'name' => 'lib', 'type' => 'tree', 'path' => 'lib', 'mode' => '040000'],
	['id' => 'c3', 'name' => 'README.md', 'type' => 'blob', 'path' => 'README.md', 'mode' => '100644'],
]);
$glNames = array_map(static fn (TreeEntry $e) => $e->name, $glTree);
$check('GL mapTree sorts dirs first', $glNames === ['lib', 'main.py', 'README.md']);
$check('GL mapTree carries oid + null size (tree endpoint has no sizes)',
	$glTree[0]->oid === 'b2' && $glTree[0]->size === null && $glTree[1]->size === null);

$glBlob = $GL::mapBlob(['file_path' => 'a.txt', 'size' => 5, 'encoding' => 'base64', 'content' => base64_encode('hello')]);
$check('GL mapBlob decodes base64', $glBlob->content === 'hello' && $glBlob->binary === false && $glBlob->path === 'a.txt');
$glBlobBin = $GL::mapBlob(['file_path' => 'b.bin', 'size' => 4, 'encoding' => 'base64', 'content' => base64_encode("a\x00b\xff")]);
$check('GL mapBlob flags binary + suppresses', $glBlobBin->binary === true && $glBlobBin->content === null);
$glBlobBig = $GL::mapBlob(['file_path' => 'big', 'size' => 5_000_000, 'encoding' => 'base64', 'content' => 'AAAA']);
$check('GL mapBlob marks >1MiB truncated', $glBlobBig->truncated === true && $glBlobBig->content === null);
$glBlobNoSize = $GL::mapBlob(['file_path' => 'c.txt', 'size' => 0, 'encoding' => 'base64', 'content' => base64_encode('hi')]);
$check('GL mapBlob falls back to decoded length when size is 0', $glBlobNoSize->size === 2 && $glBlobNoSize->content === 'hi');

$glCommits = $GL::mapCommits([
	['id' => 'deadbeef', 'title' => 'Fix bug', 'message' => "Fix bug\n\nbody", 'author_name' => 'Ann', 'author_email' => 'a@x', 'authored_date' => '2026-01-01T00:00:00Z'],
]);
$check('GL mapCommits maps id/title/author', $glCommits[0]->subject === 'Fix bug' && $glCommits[0]->authorName === 'Ann' && $glCommits[0]->shortHash() === 'deadbee');

$glRefs = $GL::mapRefs(
	[['name' => 'main', 'default' => true], ['name' => 'dev', 'default' => false]],
	[['name' => 'v2.0']],
	'main',
);
$check('GL mapRefs branches+tags', \count($glRefs) === 3 && $glRefs[2]->type === 'tag');
$check('GL mapRefs flags the default branch (from the default flag)', $glRefs[0]->name === 'main' && $glRefs[0]->isDefault === true);

$glSearch = $GL::mapSearch([
	['path' => 'src/x.js', 'startline' => 12, 'data' => "  console.log('x')\nnext line"],
	['startline' => 3, 'data' => 'no path -> dropped'],
]);
$check('GL mapSearch carries path + REAL line number + first snippet line',
	\count($glSearch) === 1 && $glSearch[0]->path === 'src/x.js' && $glSearch[0]->line === 12 && str_contains($glSearch[0]->text, 'console.log'));

$glDiff = $GL::assembleDiff([
	['old_path' => 'a.txt', 'new_path' => 'a.txt', 'diff' => "@@ -1 +1 @@\n-old\n+new"],
	['old_path' => 'gone.txt', 'new_path' => 'gone.txt', 'deleted_file' => true, 'diff' => "@@ -1 +0 @@\n-bye\n"],
]);
$check('GL assembleDiff builds a unified patch with git headers',
	str_contains($glDiff, 'diff --git a/a.txt b/a.txt') && str_contains($glDiff, '+new') && str_contains($glDiff, 'deleted file'));
$check('GL assembleDiff of [] is empty string', $GL::assembleDiff([]) === '');

$glBlame = $GL::mapBlame([
	['commit' => ['id' => str_repeat('a', 40), 'author_name' => 'Ann', 'authored_date' => '2026-01-01T00:00:00Z'], 'lines' => ['l1', 'l2']],
	['commit' => ['id' => str_repeat('b', 40), 'author_name' => 'Bob', 'authored_date' => '2026-02-02T00:00:00Z'], 'lines' => ['l3']],
]);
$check('GL mapBlame flattens groups into per-line entries with running line numbers',
	\count($glBlame) === 3 && $glBlame[0]->line === 1 && $glBlame[1]->line === 2 && $glBlame[2]->line === 3);
$check('GL mapBlame carries author + full hash, serializes short hash',
	$glBlame[0]->author === 'Ann' && \strlen($glBlame[0]->hash) === 40 && \strlen($glBlame[2]->jsonSerialize()['hash']) === 7 && $glBlame[2]->author === 'Bob');

// GitLab error contract — same shape as GitHub but RateLimit-* headers (no x-).
$glClassify = static fn (int $s, array $h = []) => $throwsClass(static fn () => $GL::classifyStatus($s, $h));
$check('GL classify 200 does not throw', $glClassify(200) === '');
$check('GL classify 401 -> ForgeAuthException', $glClassify(401) === ForgeAuthException::class);
$check('GL classify 429 -> RateLimitException', $glClassify(429) === RateLimitException::class);
$check('GL classify 403 + RateLimit-Remaining:0 -> RateLimitException',
	$glClassify(403, ['RateLimit-Remaining' => '0']) === RateLimitException::class);
$check('GL classify 403 + Retry-After -> RateLimitException',
	$glClassify(403, ['Retry-After' => '30']) === RateLimitException::class);
$check('GL classify 403 forbidden (no rate hdrs) -> ForgeAuthException', $glClassify(403) === ForgeAuthException::class);
$check('GL classify 404 -> RepoNotFoundException', $glClassify(404) === RepoNotFoundException::class);
$check('GL classify 500 -> RepoException (not RepoNotFound)', $glClassify(500) === RepoException::class);
$check('GL pageFor offset 0 -> page 1', $GL::pageFor(0, 100) === 1);
$check('GL pageFor offset 150 perPage 50 -> page 4', $GL::pageFor(150, 50) === 4);

// Malformed/empty responses stay total.
$check('GL mapTree of [] is []', $GL::mapTree([]) === []);
$check('GL mapCommits of [] is []', $GL::mapCommits([]) === []);
$check('GL mapBlame tolerates missing commit/lines', \count($GL::mapBlame([['x' => 1], ['commit' => 'nope']])) === 0);
$glBadCommit = $GL::mapCommits([['id' => 'x'], []]);
$check('GL mapCommits tolerates missing fields', \count($glBadCommit) === 2 && $glBadCommit[0]->subject === '');

echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
