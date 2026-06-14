<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider\Local;

use OCA\Lantern\Exception\RepoException;
use Psr\Log\LoggerInterface;

/**
 * Thin, security-hardened wrapper around the git binary.
 *
 * Every invocation is run via proc_open() with an ARGUMENT ARRAY, which on
 * POSIX systems bypasses /bin/sh entirely. No user-supplied value is ever
 * interpolated into a shell string, so command injection is structurally
 * impossible here regardless of what a ref or path contains. Callers are still
 * expected to validate refs/paths (see RefValidator) as defense in depth.
 *
 * This class deliberately has NO dependency on the Nextcloud framework so it
 * can be unit-tested in isolation against a real on-disk repository.
 */
final class GitBinary {

	/** Hard ceiling on captured stdout (bytes) to protect server memory. */
	public const DEFAULT_MAX_BYTES = 8 * 1024 * 1024; // 8 MiB

	/** Wall-clock timeout for a single git invocation (seconds). */
	public const DEFAULT_TIMEOUT = 20;

	public function __construct(
		private readonly string $gitPath = 'git',
		private readonly ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * Run `git -C <repoPath> --no-pager <args...>`.
	 *
	 * @param string   $repoPath absolute path to a repository (work tree or bare)
	 * @param string[] $args     git arguments, each a discrete array element
	 * @param int|null $maxBytes cap on stdout; null uses DEFAULT_MAX_BYTES
	 *
	 * @return GitResult captured stdout/stderr/exit code
	 *
	 * @throws RepoException if the process cannot be started or times out
	 */
	public function run(string $repoPath, array $args, ?int $maxBytes = null): GitResult {
		$maxBytes = $maxBytes ?? self::DEFAULT_MAX_BYTES;

		// -c safe.directory=<repoPath> marks this (admin-configured, trusted)
		// repository as safe for THIS invocation. Without it, git's
		// "dubious ownership" guard refuses to operate on a repo owned by a
		// different user than the web-server user (very common server-side:
		// a deploy user owns the repo, www-data only reads it). The usual fix
		// is global safe.directory config, but we disable global config above,
		// so we must set it per call. The value comes from the registry's
		// realpath()'d path, never from request input.
		$command = array_merge(
			[$this->gitPath, '-C', $repoPath, '-c', 'safe.directory=' . $repoPath, '--no-pager'],
			array_values($args),
		);

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		// A clean, predictable environment. We do NOT inherit the caller's env
		// (avoids leaking GIT_* overrides) and we disable system/global config.
		// NOTE: git ALWAYS honors the repository's own .git/config — that cannot
		// be turned off and we rely on it to read the repo. Our safety against
		// config-driven code execution (core.fsmonitor, aliases, hooks, diff
		// drivers) comes from COMMAND SELECTION, not from this env: the plumbing
		// we run (cat-file, ls-tree, log --format, symbolic-ref) does not invoke
		// any of those. See PROJECT_BIBLE §9.6 and the v3 diff caveat.
		$env = [
			'GIT_CONFIG_NOSYSTEM' => '1',
			'GIT_CONFIG_GLOBAL' => '/dev/null',
			'GIT_TERMINAL_PROMPT' => '0',
			// Point HOME at a path that does not exist so no stray ~/.gitconfig
			// or credential store is ever consulted (belt-and-suspenders atop
			// GIT_CONFIG_GLOBAL).
			'HOME' => '/nonexistent',
			'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
			'LC_ALL' => 'C',
		];

		$process = @proc_open($command, $descriptors, $pipes, $repoPath, $env);
		if (!\is_resource($process)) {
			throw new RepoException('Unable to start git process');
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$truncated = false;
		$deadline = microtime(true) + self::DEFAULT_TIMEOUT;

		while (true) {
			$read = [];
			if (!feof($pipes[1])) {
				$read[] = $pipes[1];
			}
			if (!feof($pipes[2])) {
				$read[] = $pipes[2];
			}
			if ($read === []) {
				break;
			}

			$write = null;
			$except = null;
			$tv = max(0, (int) ceil($deadline - microtime(true)));
			$ready = @stream_select($read, $write, $except, $tv);

			if ($ready === false) {
				break; // interrupted; fall through to cleanup
			}

			if (microtime(true) > $deadline) {
				proc_terminate($process, 9);
				$this->closePipes($pipes);
				proc_close($process);
				throw new RepoException('git invocation timed out');
			}

			foreach ($read as $stream) {
				$chunk = fread($stream, 65536);
				if ($chunk === false || $chunk === '') {
					continue;
				}
				if ($stream === $pipes[1]) {
					if (\strlen($stdout) < $maxBytes) {
						$stdout .= substr($chunk, 0, $maxBytes - \strlen($stdout));
						if (\strlen($stdout) >= $maxBytes) {
							$truncated = true;
						}
					}
				} else {
					// Keep stderr bounded too.
					if (\strlen($stderr) < 65536) {
						$stderr .= $chunk;
					}
				}
			}
		}

		$this->closePipes($pipes);
		$exitCode = proc_close($process);

		$this->logger?->debug('lantern: git ' . implode(' ', $args)
			. ' (exit=' . $exitCode . ', bytes=' . \strlen($stdout) . ')');

		return new GitResult($stdout, trim($stderr), $exitCode, $truncated);
	}

	/** @param array<int, resource> $pipes */
	private function closePipes(array $pipes): void {
		foreach ([1, 2] as $i) {
			if (isset($pipes[$i]) && \is_resource($pipes[$i])) {
				fclose($pipes[$i]);
			}
		}
	}
}
