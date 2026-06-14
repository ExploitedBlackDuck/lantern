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
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Model\TreeEntry;
use OCA\Lantern\Provider\Local\GitBinary;
use OCA\Lantern\Provider\Local\LocalGitProvider;
use OCA\Lantern\Provider\Local\RefValidator;

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

	$check('descriptor JSON omits path', !str_contains((string) json_encode($repo), $repoPath));

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

echo "\n========================================\n";
echo "RESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
