<?php

declare(strict_types=1);

/**
 * Builds a deterministic git repository in a temp directory for tests.
 * Returns the repo path. Caller is responsible for cleanup (see destroyRepo()).
 */
function buildFixtureRepo(): string {
	$dir = sys_get_temp_dir() . '/lantern-fixture-' . bin2hex(random_bytes(4));
	mkdir($dir, 0700, true);
	$run = static function (string $cmd) use ($dir): void {
		exec('cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>/dev/null');
	};
	$run('git init -q');
	// Pin the initial branch to master deterministically. `git init` honors
	// init.defaultBranch (often `main` on modern CI/Homebrew), which would
	// break the master-based assertions; symbolic-ref forces it portably on
	// every git version without needing `-b` (git >= 2.28).
	$run('git symbolic-ref HEAD refs/heads/master');
	$run('git config user.email t@e.st');
	$run('git config user.name Tester');
	mkdir($dir . '/src');
	mkdir($dir . '/assets');
	mkdir($dir . '/docs');
	file_put_contents($dir . '/README.md', "# Lantern Test\n\nA sample repo.\n");
	file_put_contents($dir . '/src/index.js', "console.log(\"hi\");\n");
	file_put_contents($dir . '/src/style.css', "body { color: red; }\n");
	file_put_contents($dir . '/docs/guide.md', "Some docs here.\n");
	// Deterministic binary content with guaranteed NUL bytes. (Random bytes
	// would only contain a NUL ~69% of the time at this length, making the
	// binary-detection assertion flaky across runs.)
	file_put_contents($dir . '/assets/logo.bin', str_repeat("\x00\x01\x02\xff", 75));
	$run('git add -A && git commit -qm "initial commit"');
	file_put_contents($dir . '/src/index.js', "console.log(\"hi v2\");\n");
	$run('git add -A && git commit -qm "update index.js"');
	file_put_contents($dir . '/README.md', "# Lantern Test\n\nA sample repo.\nextra line\n");
	$run('git add -A && git commit -qm "expand README"');
	// A second branch and a tag so listRefs() has something to enumerate.
	$run('git branch dev');
	$run('git tag v1.0');
	return $dir;
}

function buildEmptyRepo(): string {
	$dir = sys_get_temp_dir() . '/lantern-empty-' . bin2hex(random_bytes(4));
	mkdir($dir, 0700, true);
	exec('cd ' . escapeshellarg($dir) . ' && git init -q && git symbolic-ref HEAD refs/heads/master 2>/dev/null');
	return $dir;
}

function destroyRepo(string $dir): void {
	if ($dir !== '' && str_contains($dir, 'lantern-')) {
		exec('rm -rf ' . escapeshellarg($dir));
	}
}
