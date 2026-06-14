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

/**
 * A repo in DETACHED HEAD state (HEAD points straight at a commit, not a
 * branch). Exercises defaultRef()'s fallback and confirms reads still work
 * off the literal HEAD ref. Single commit, single tree entry — also covers the
 * "single-ref / minimal repo" edge.
 */
function buildDetachedHeadRepo(): string {
	$dir = sys_get_temp_dir() . '/lantern-detached-' . bin2hex(random_bytes(4));
	mkdir($dir, 0700, true);
	$run = static function (string $cmd) use ($dir): void {
		exec('cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>/dev/null');
	};
	$run('git init -q');
	$run('git symbolic-ref HEAD refs/heads/master');
	$run('git config user.email t@e.st');
	$run('git config user.name Tester');
	file_put_contents($dir . '/only.txt', "one file\n");
	$run('git add -A && git commit -qm "sole commit"');
	$run('git checkout -q --detach HEAD');
	return $dir;
}

/**
 * A repo that weaponises its OWN .git/config against the reader: it sets a
 * malicious `diff.external` driver (a script that touches $sentinel). git
 * ALWAYS honors a repo's own config (§9.6), so for the untrusted user-Files
 * case this is a real code-execution vector for the diff/show feature. The
 * GitBinary hardening (`-c diff.external=`) must neutralise it. Two commits so
 * `git show HEAD` produces a diff that would invoke the driver.
 */
function buildMaliciousDiffRepo(string $sentinel): string {
	$dir = sys_get_temp_dir() . '/lantern-evil-' . bin2hex(random_bytes(4));
	mkdir($dir, 0700, true);
	$run = static function (string $cmd) use ($dir): void {
		exec('cd ' . escapeshellarg($dir) . ' && ' . $cmd . ' 2>/dev/null');
	};
	$run('git init -q');
	$run('git symbolic-ref HEAD refs/heads/master');
	$run('git config user.email t@e.st');
	$run('git config user.name Tester');
	file_put_contents($dir . '/f.txt', "v1\n");
	$run('git add -A && git commit -qm "c1"');
	file_put_contents($dir . '/f.txt', "v2\n");
	$run('git add -A && git commit -qm "c2"');
	$payload = $dir . '/payload.sh';
	file_put_contents($payload, "#!/bin/sh\ntouch " . escapeshellarg($sentinel) . "\n");
	chmod($payload, 0755);
	// Set it in the repo's own config — the untrusted-repo threat model.
	$run('git config diff.external ' . escapeshellarg($payload));
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
