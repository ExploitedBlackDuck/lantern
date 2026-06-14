<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider\Local;

use OCA\Lantern\Exception\InvalidRefException;
use OCA\Lantern\Exception\RepoNotFoundException;
use OCA\Lantern\Model\BlameLine;
use OCA\Lantern\Model\BlobContent;
use OCA\Lantern\Model\CommitInfo;
use OCA\Lantern\Model\RawBlob;
use OCA\Lantern\Model\RefInfo;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Model\SearchMatch;
use OCA\Lantern\Model\TreeEntry;
use OCA\Lantern\Provider\IRepoProvider;

/**
 * Read-only browser over a git repository that lives on the server's disk.
 *
 * All git access goes through {@see GitBinary} (no shell) and every ref/path
 * is run through {@see RefValidator} first. This class is framework-free so it
 * can be exercised directly in tests against a real repository.
 */
final class LocalGitProvider implements IRepoProvider {

	/** Files larger than this are returned as metadata only (no inline content). */
	public const MAX_INLINE_BLOB = 2 * 1024 * 1024; // 2 MiB

	public function __construct(
		private readonly GitBinary $git,
		private readonly RefValidator $validator,
	) {
	}

	public function getKey(): string {
		return 'local';
	}

	public function defaultRef(RepoDescriptor $repo): string {
		// Prefer the symbolic HEAD; fall back to a literal HEAD if detached or bare.
		$res = $this->git->run($repo->path, ['symbolic-ref', '--short', '-q', 'HEAD']);
		$name = trim($res->stdout);
		return ($res->ok() && $name !== '') ? $name : 'HEAD';
	}

	public function listTree(RepoDescriptor $repo, string $ref, string $path): array {
		$ref = $this->validator->assertRef($ref);
		$path = $this->validator->normalizePath($path);

		// An unborn HEAD (freshly initialized repo with no commits) has no root
		// tree to list. Treat the root of such a repo as legitimately empty
		// rather than surfacing a confusing "not found".
		if ($path === '' && $this->isEmpty($repo, $ref)) {
			return [];
		}

		$this->assertType($repo, $ref, $path, TreeEntry::TYPE_TREE);

		// Trailing slash makes ls-tree list the CONTENTS of the directory.
		$args = ['ls-tree', '--long', '-z', $ref];
		if ($path !== '') {
			$args[] = '--';
			$args[] = $path . '/';
		}
		$res = $this->git->run($repo->path, $args);
		if (!$res->ok()) {
			throw new RepoNotFoundException('Could not list tree: ' . $res->stderr);
		}

		$entries = [];
		foreach (explode("\0", $res->stdout) as $record) {
			if ($record === '') {
				continue;
			}
			[$meta, $name] = array_pad(explode("\t", $record, 2), 2, '');
			// meta = "<mode> <type> <oid> <size>" (size is "-" for trees)
			$parts = preg_split('/\s+/', trim($meta));
			if ($parts === false || \count($parts) < 4) {
				continue;
			}
			[$mode, $type, $oid, $sizeRaw] = $parts;
			$size = ($sizeRaw === '-' || $sizeRaw === '') ? null : (int) $sizeRaw;
			// ls-tree always reports the full repo-relative path in the name
			// field; derive the leaf name ourselves (OS-independent).
			$entryPath = $name;
			$slash = strrpos($name, '/');
			$leaf = $slash === false ? $name : substr($name, $slash + 1);
			$entries[] = new TreeEntry($leaf, $entryPath, $type, $mode, $oid, $size);
		}

		usort($entries, static function (TreeEntry $a, TreeEntry $b): int {
			$ad = $a->type === TreeEntry::TYPE_TREE ? 0 : 1;
			$bd = $b->type === TreeEntry::TYPE_TREE ? 0 : 1;
			return $ad === $bd ? strcasecmp($a->name, $b->name) : ($ad <=> $bd);
		});

		return $entries;
	}

	public function getBlob(RepoDescriptor $repo, string $ref, string $path): BlobContent {
		$ref = $this->validator->assertRef($ref);
		$path = $this->validator->normalizePath($path);
		if ($path === '') {
			throw new RepoNotFoundException('No file path given');
		}

		$this->assertType($repo, $ref, $path, TreeEntry::TYPE_BLOB);

		$spec = $ref . ':' . $path;

		$sizeRes = $this->git->run($repo->path, ['cat-file', '-s', $spec]);
		if (!$sizeRes->ok()) {
			throw new RepoNotFoundException('Blob not found: ' . $sizeRes->stderr);
		}
		$size = (int) trim($sizeRes->stdout);

		if ($size > self::MAX_INLINE_BLOB) {
			return new BlobContent($path, $size, false, true, null);
		}

		$contentRes = $this->git->run(
			$repo->path,
			['cat-file', '-p', $spec],
			self::MAX_INLINE_BLOB + 1,
		);
		if (!$contentRes->ok()) {
			throw new RepoNotFoundException('Could not read blob: ' . $contentRes->stderr);
		}

		$raw = $contentRes->stdout;
		$binary = $this->looksBinary($raw);

		return new BlobContent(
			$path,
			$size,
			$binary,
			$contentRes->truncated,
			$binary ? null : $raw,
		);
	}

	public function listCommits(RepoDescriptor $repo, string $ref, ?string $path, int $limit, int $offset = 0): array {
		$ref = $this->validator->assertRef($ref);
		$limit = max(1, min($limit, 201));
		$offset = max(0, $offset);

		$fieldSep = "\x1f"; // unit separator
		$args = [
			'log',
			'--max-count=' . $limit,
			'--skip=' . $offset,
			'-z',
			'--format=%H' . $fieldSep . '%an' . $fieldSep . '%ae' . $fieldSep . '%aI' . $fieldSep . '%s',
			$ref,
		];
		if ($path !== null && $path !== '') {
			$path = $this->validator->normalizePath($path);
			$args[] = '--';
			$args[] = $path;
		}

		$res = $this->git->run($repo->path, $args);
		if (!$res->ok()) {
			throw new RepoNotFoundException('Could not read history: ' . $res->stderr);
		}

		$commits = [];
		foreach (explode("\0", $res->stdout) as $record) {
			$record = ltrim($record, "\n");
			if ($record === '') {
				continue;
			}
			$f = explode($fieldSep, $record);
			if (\count($f) < 5) {
				continue;
			}
			$commits[] = new CommitInfo($f[0], $f[1], $f[2], $f[3], $f[4]);
		}
		return $commits;
	}

	public function listRefs(RepoDescriptor $repo): array {
		$default = $this->defaultRef($repo);
		$res = $this->git->run($repo->path, [
			'for-each-ref',
			'--format=%(refname)',
			'refs/heads',
			'refs/tags',
		]);
		if (!$res->ok()) {
			return [];
		}

		$branches = [];
		$tags = [];
		foreach (explode("\n", $res->stdout) as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			if (str_starts_with($line, 'refs/heads/')) {
				$name = substr($line, 11);
				$branches[] = new RefInfo($name, RefInfo::TYPE_BRANCH, $name === $default);
			} elseif (str_starts_with($line, 'refs/tags/')) {
				$tags[] = new RefInfo(substr($line, 10), RefInfo::TYPE_TAG, false);
			}
		}
		usort($branches, static fn (RefInfo $a, RefInfo $b): int => strcasecmp($a->name, $b->name));
		usort($tags, static fn (RefInfo $a, RefInfo $b): int => strcasecmp($a->name, $b->name));
		return array_merge($branches, $tags);
	}

	public function getBlobRaw(RepoDescriptor $repo, string $ref, string $path, int $maxBytes): RawBlob {
		$ref = $this->validator->assertRef($ref);
		$path = $this->validator->normalizePath($path);
		if ($path === '') {
			throw new RepoNotFoundException('No file path given');
		}
		$this->assertType($repo, $ref, $path, TreeEntry::TYPE_BLOB);

		$spec = $ref . ':' . $path;
		$sizeRes = $this->git->run($repo->path, ['cat-file', '-s', $spec]);
		if (!$sizeRes->ok()) {
			throw new RepoNotFoundException('Blob not found: ' . $sizeRes->stderr);
		}
		$size = (int) trim($sizeRes->stdout);

		$contentRes = $this->git->run($repo->path, ['cat-file', '-p', $spec], max(1, $maxBytes));
		if (!$contentRes->ok()) {
			throw new RepoNotFoundException('Could not read blob: ' . $contentRes->stderr);
		}
		return new RawBlob($path, $size, $contentRes->stdout, $contentRes->truncated);
	}

	public function search(RepoDescriptor $repo, string $ref, string $query, int $limit): array {
		$ref = $this->validator->assertRef($ref);
		$query = trim($query);
		if ($query === '') {
			return [];
		}
		if (\strlen($query) > 256) {
			throw new InvalidRefException('Search query too long');
		}
		if (preg_match('/[\x00-\x1f]/', $query) === 1) {
			throw new InvalidRefException('Search query contains control characters');
		}
		$limit = max(1, min($limit, 200));

		// -I skips binary files; -n adds line numbers; -F is a FIXED string (no
		// regex, so no catastrophic-backtracking DoS); -e <query> ensures a
		// query beginning with "-" is treated as data, not an option. The ref is
		// the last arg so we search committed content (works for bare repos too).
		$res = $this->git->run($repo->path, [
			'grep', '-I', '-n', '--no-color', '-F', '-e', $query, $ref,
		]);
		// git grep exits 1 when there are simply no matches — that is not an error.
		if (!$res->ok() && $res->exitCode !== 1) {
			throw new RepoNotFoundException('Search failed: ' . $res->stderr);
		}

		$matches = [];
		$prefix = $ref . ':';
		foreach (explode("\n", $res->stdout) as $line) {
			if (\count($matches) >= $limit) {
				break;
			}
			if ($line === '') {
				continue;
			}
			// Output is "<ref>:<path>:<lineno>:<text>"; the ref has no colon
			// (RefValidator forbids it), so strip that prefix first, then split
			// the remainder into path / line / text.
			if (str_starts_with($line, $prefix)) {
				$line = substr($line, \strlen($prefix));
			}
			$parts = explode(':', $line, 3);
			if (\count($parts) < 3 || !ctype_digit($parts[1])) {
				continue;
			}
			$text = $parts[2];
			if (\strlen($text) > 400) {
				$text = substr($text, 0, 400);
			}
			$matches[] = new SearchMatch($parts[0], (int) $parts[1], $text);
		}
		return $matches;
	}

	public function getCommitDiff(RepoDescriptor $repo, string $ref): string {
		$ref = $this->validator->assertRef($ref);
		// `show` honors diff.external/textconv/attributes — GitBinary disables
		// all of those per-call (§9.6 HARDENING_FLAGS), so a malicious repo
		// cannot turn this into code execution. --no-color for clean text.
		$res = $this->git->run($repo->path, [
			'show', '--no-color', '--format=fuller', $ref,
		]);
		if (!$res->ok()) {
			throw new RepoNotFoundException('Could not produce diff: ' . $res->stderr);
		}
		return $res->stdout;
	}

	public function blame(RepoDescriptor $repo, string $ref, string $path): array {
		$ref = $this->validator->assertRef($ref);
		$path = $this->validator->normalizePath($path);
		if ($path === '') {
			throw new RepoNotFoundException('No file path given');
		}
		$this->assertType($repo, $ref, $path, TreeEntry::TYPE_BLOB);
		$res = $this->git->run($repo->path, ['blame', '--porcelain', $ref, '--', $path]);
		if (!$res->ok()) {
			throw new RepoNotFoundException('Could not blame: ' . $res->stderr);
		}
		return $this->parsePorcelainBlame($res->stdout);
	}

	/**
	 * Parse `git blame --porcelain`. Each line group starts with
	 * "<sha> <orig> <final> [count]"; commit headers (author, author-time…)
	 * appear once per commit and are remembered for subsequent lines.
	 *
	 * @return BlameLine[]
	 */
	private function parsePorcelainBlame(string $out): array {
		$lines = [];
		$meta = []; // sha => [author, date]
		$curSha = '';
		$curAuthor = '';
		$curEpoch = 0;
		$finalLine = 0;
		foreach (explode("\n", $out) as $row) {
			if (preg_match('/^([0-9a-f]{40}) \d+ (\d+)(?: \d+)?$/', $row, $m) === 1) {
				$curSha = $m[1];
				$finalLine = (int) $m[2];
				$curAuthor = $meta[$curSha][0] ?? '';
				$curEpoch = $meta[$curSha][1] ?? 0;
			} elseif (str_starts_with($row, 'author ')) {
				$curAuthor = substr($row, 7);
				$meta[$curSha][0] = $curAuthor;
			} elseif (str_starts_with($row, 'author-time ')) {
				$curEpoch = (int) substr($row, 12);
				$meta[$curSha][1] = $curEpoch;
			} elseif (str_starts_with($row, "\t")) {
				// The actual file line; emit a blame entry for it.
				$lines[] = new BlameLine(
					$finalLine,
					$curSha,
					$curAuthor,
					$curEpoch > 0 ? gmdate('c', $curEpoch) : '',
				);
			}
		}
		return $lines;
	}

	/**
	 * Confirm that $path resolves to the expected object type at $ref, raising
	 * a not-found error otherwise. This both validates existence and prevents
	 * e.g. asking for a directory as a blob.
	 */
	private function assertType(RepoDescriptor $repo, string $ref, string $path, string $expected): void {
		$spec = $ref . ':' . $path; // "<ref>:" is the root tree
		$res = $this->git->run($repo->path, ['cat-file', '-t', $spec]);
		if (!$res->ok()) {
			throw new RepoNotFoundException('No such path at this ref');
		}
		$actual = trim($res->stdout);
		if ($actual !== $expected) {
			throw new RepoNotFoundException(
				sprintf('Expected %s but found %s', $expected, $actual),
			);
		}
	}

	/**
	 * True when the ref has no commit yet (a genuinely empty/unborn repo).
	 *
	 * Crucially, this distinguishes "accessible repo with no commits" from
	 * "git could not read this repo at all" (bad permissions, corruption, a
	 * dubious-ownership refusal that slipped past our safe.directory handling).
	 * The latter must NOT be silently reported as an empty repo — it throws so
	 * the caller surfaces a real error instead of a misleading empty tree.
	 */
	private function isEmpty(RepoDescriptor $repo, string $ref): bool {
		// First confirm git can actually read the repository at all.
		$probe = $this->git->run($repo->path, ['rev-parse', '--git-dir']);
		if (!$probe->ok()) {
			throw new RepoNotFoundException(
				'Repository is not readable (check ownership/permissions): ' . $probe->stderr,
			);
		}
		// Repo is readable; now it's empty iff the ref has no commit.
		$res = $this->git->run($repo->path, ['rev-parse', '--verify', '-q', $ref . '^{commit}']);
		return !$res->ok();
	}

	/** Heuristic binary check: a NUL byte in the first 8 KiB. */
	private function looksBinary(string $data): bool {
		$head = substr($data, 0, 8192);
		return str_contains($head, "\0");
	}
}
