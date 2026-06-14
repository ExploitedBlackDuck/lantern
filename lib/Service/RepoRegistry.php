<?php

declare(strict_types=1);

namespace OCA\Lantern\Service;

use OCA\Lantern\Exception\RepoNotFoundException;
use OCA\Lantern\Model\RepoDescriptor;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Source of truth for which repositories Lantern is allowed to browse.
 *
 * v1 stores a small JSON document in the app config (admin-managed). Each
 * entry is {id, name, path}. The registry refuses any repo whose resolved
 * path is not a real git repository, and — if an allowlisted base directory
 * is configured — refuses anything outside it. The on-disk path never leaves
 * the server; clients only ever see ids and names.
 */
class RepoRegistry {

	private const CONFIG_REPOS = 'repos';
	private const CONFIG_BASE = 'allowed_base';

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}

	/** @return RepoDescriptor[] */
	public function all(): array {
		$raw = $this->appConfig->getValueString('lantern', self::CONFIG_REPOS, '[]');
		$decoded = json_decode($raw, true);
		if (!\is_array($decoded)) {
			return [];
		}

		$base = $this->allowedBase();
		$repos = [];
		foreach ($decoded as $row) {
			if (!\is_array($row) || !isset($row['id'], $row['name'], $row['path'])) {
				continue;
			}
			$real = realpath((string) $row['path']);
			if ($real === false || !$this->isGitRepo($real)) {
				$this->logger->warning('lantern: skipping non-repo path for ' . $row['id']);
				continue;
			}
			if ($base !== null && !str_starts_with($real . '/', $base . '/')) {
				$this->logger->warning('lantern: repo outside allowed base: ' . $row['id']);
				continue;
			}
			$groups = [];
			if (isset($row['groups']) && \is_array($row['groups'])) {
				$groups = array_values(array_filter(array_map('strval', $row['groups']), static fn (string $g) => $g !== ''));
			}
			$repos[] = new RepoDescriptor((string) $row['id'], (string) $row['name'], $real, 'local', $groups);
		}
		return $repos;
	}

	public function get(string $id): RepoDescriptor {
		foreach ($this->all() as $repo) {
			if ($repo->id === $id) {
				return $repo;
			}
		}
		throw new RepoNotFoundException('Unknown repository: ' . $id);
	}

	/**
	 * Check a candidate repository path against the same rules all() enforces,
	 * for the admin "Test path" affordance. When $allowedBaseRaw is null the
	 * saved base is used; pass a string (possibly empty) to test against a base
	 * the admin is currently editing but has not saved yet.
	 *
	 * @return array{ok: bool, reason: string, resolved: ?string}
	 */
	public function validatePath(string $path, ?string $allowedBaseRaw = null): array {
		$path = trim($path);
		if ($path === '') {
			return ['ok' => false, 'reason' => 'Path is empty.', 'resolved' => null];
		}
		$real = realpath($path);
		if ($real === false) {
			return ['ok' => false, 'reason' => 'Path does not exist or is not readable by the web server.', 'resolved' => null];
		}
		if (!$this->isGitRepo($real)) {
			return ['ok' => false, 'reason' => 'Not a git repository (expected a .git directory, a gitlink file, or a bare repo with HEAD + objects).', 'resolved' => $real];
		}
		$base = $allowedBaseRaw === null ? $this->allowedBase() : $this->normalizeBase($allowedBaseRaw);
		if ($base !== null && !str_starts_with($real . '/', $base . '/')) {
			return ['ok' => false, 'reason' => 'Path is outside the allowed base directory.', 'resolved' => $real];
		}
		return ['ok' => true, 'reason' => 'Valid git repository.', 'resolved' => $real];
	}

	private function allowedBase(): ?string {
		return $this->normalizeBase($this->appConfig->getValueString('lantern', self::CONFIG_BASE, ''));
	}

	private function normalizeBase(string $base): ?string {
		$base = trim($base);
		if ($base === '') {
			return null;
		}
		$real = realpath($base);
		return $real === false ? null : rtrim($real, '/');
	}

	private function isGitRepo(string $path): bool {
		// A standard work tree has a .git directory. Linked worktrees and
		// submodules instead have a .git FILE (a gitlink). A bare repo has
		// HEAD + objects at the top level. Accept all three.
		return is_dir($path . '/.git')
			|| is_file($path . '/.git')
			|| (is_file($path . '/HEAD') && is_dir($path . '/objects'));
	}
}
