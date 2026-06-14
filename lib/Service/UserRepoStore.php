<?php

declare(strict_types=1);

namespace OCA\Lantern\Service;

use OCA\Lantern\Model\RepoDescriptor;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Per-user source of repositories that live inside a user's own Nextcloud
 * Files (Horizon 2). Unlike {@see RepoRegistry} (admin-configured, server-side
 * paths), these are chosen by the user and resolved through the Files API to a
 * local filesystem path.
 *
 * Trust note (§9.6a): a user-writable repo's `.git/config` is attacker-
 * controlled, so reads MUST go through the hardened {@see \OCA\Lantern\Provider\Local\GitBinary}
 * (which disables fsmonitor/hooks/attributes per call). We additionally confine
 * every resolved path to the user's own Files directory and refuse non-local
 * storage (git needs a real local path).
 */
class UserRepoStore {

	private const APP = 'lantern';
	private const KEY = 'user_repos';

	public function __construct(
		private readonly IConfig $config,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Stored rows for the management UI: {id, name, path (relative), valid}.
	 *
	 * @return array<int, array{id: string, name: string, path: string, valid: bool}>
	 */
	public function rawListFor(string $uid): array {
		$out = [];
		foreach ($this->rows($uid) as $row) {
			[$ok] = $this->resolveLocal($uid, $row['path']);
			$out[] = [
				'id' => $row['id'],
				'name' => $row['name'],
				'path' => $row['path'],
				'valid' => $ok,
			];
		}
		return $out;
	}

	/**
	 * Resolved, browsable descriptors for the current user (provider 'local',
	 * since once resolved the read path is identical — the hardened GitBinary
	 * makes that safe). Invalid/missing entries are skipped.
	 *
	 * @return RepoDescriptor[]
	 */
	public function listFor(string $uid): array {
		$out = [];
		foreach ($this->rows($uid) as $row) {
			[$ok, , $real] = $this->resolveLocal($uid, $row['path']);
			if ($ok && $real !== null) {
				$out[] = new RepoDescriptor($row['id'], $row['name'], $real, 'local');
			}
		}
		return $out;
	}

	public function getFor(string $uid, string $id): ?RepoDescriptor {
		foreach ($this->rows($uid) as $row) {
			if ($row['id'] === $id) {
				[$ok, , $real] = $this->resolveLocal($uid, $row['path']);
				return ($ok && $real !== null)
					? new RepoDescriptor($row['id'], $row['name'], $real, 'local')
					: null;
			}
		}
		return null;
	}

	/** @return array{ok: bool, reason: string} */
	public function validateFor(string $uid, string $filesPath): array {
		[$ok, $reason] = $this->resolveLocal($uid, $filesPath);
		return ['ok' => $ok, 'reason' => $reason];
	}

	/** @return array{ok: bool, reason: string, id: ?string} */
	public function addFor(string $uid, string $name, string $filesPath): array {
		$filesPath = trim($filesPath);
		$name = trim($name);
		if ($name === '') {
			$name = $filesPath === '' ? 'Repository' : basename(rtrim($filesPath, '/'));
		}
		[$ok, $reason] = $this->resolveLocal($uid, $filesPath);
		if (!$ok) {
			return ['ok' => false, 'reason' => $reason, 'id' => null];
		}
		$rows = $this->rows($uid);
		$id = 'f' . substr(sha1($filesPath), 0, 10);
		// De-dupe by id (same path) — update the name rather than add twice.
		$rows = array_values(array_filter($rows, static fn (array $r) => $r['id'] !== $id));
		$rows[] = ['id' => $id, 'name' => $name, 'path' => $filesPath];
		$this->save($uid, $rows);
		return ['ok' => true, 'reason' => $reason, 'id' => $id];
	}

	public function removeFor(string $uid, string $id): void {
		$rows = array_values(array_filter($this->rows($uid), static fn (array $r) => $r['id'] !== $id));
		$this->save($uid, $rows);
	}

	/**
	 * Resolve a relative Files path to a confined, local, git-repo path.
	 *
	 * @return array{0: bool, 1: string, 2: ?string} [ok, reason, realLocalPath]
	 */
	private function resolveLocal(string $uid, string $filesPath): array {
		$filesPath = trim($filesPath);
		if ($filesPath === '') {
			return [false, 'Path is empty.', null];
		}
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
			$node = $userFolder->get($filesPath);
		} catch (NotFoundException $e) {
			return [false, 'No such folder in your Files.', null];
		} catch (\Throwable $e) {
			$this->logger->debug('lantern: user repo resolve failed: ' . $e->getMessage());
			return [false, 'Could not open that path.', null];
		}
		if (!$node instanceof Folder) {
			return [false, 'That path is a file, not a folder.', null];
		}
		$storage = $node->getStorage();
		if (!$storage->isLocal()) {
			return [false, 'This folder is on non-local storage, which Lantern cannot browse.', null];
		}
		$local = $storage->getLocalFile($node->getInternalPath());
		if (!\is_string($local) || $local === '') {
			return [false, 'Could not resolve a local path for that folder.', null];
		}
		$real = realpath($local);
		if ($real === false) {
			return [false, 'Folder is not readable.', null];
		}
		// Confine to the user's own Files directory (defends against a symlink
		// that points elsewhere on disk).
		$rootLocalRaw = $userFolder->getStorage()->getLocalFile($userFolder->getInternalPath());
		$rootReal = \is_string($rootLocalRaw) ? realpath($rootLocalRaw) : false;
		if ($rootReal !== false && !str_starts_with($real . '/', rtrim($rootReal, '/') . '/')) {
			return [false, 'Folder is outside your Files.', null];
		}
		if (!$this->isGitRepo($real)) {
			return [false, 'Not a git repository (no .git directory).', $real];
		}
		return [true, 'Valid git repository.', $real];
	}

	/** @return array<int, array{id: string, name: string, path: string}> */
	private function rows(string $uid): array {
		$raw = $this->config->getUserValue($uid, self::APP, self::KEY, '[]');
		$decoded = json_decode($raw, true);
		if (!\is_array($decoded)) {
			return [];
		}
		$rows = [];
		foreach ($decoded as $row) {
			if (\is_array($row) && isset($row['id'], $row['name'], $row['path'])) {
				$rows[] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'path' => (string) $row['path']];
			}
		}
		return $rows;
	}

	/** @param array<int, array{id: string, name: string, path: string}> $rows */
	private function save(string $uid, array $rows): void {
		$this->config->setUserValue($uid, self::APP, self::KEY, json_encode(array_values($rows)));
	}

	/** Same acceptance as RepoRegistry: work tree (.git dir/file) or bare repo. */
	private function isGitRepo(string $path): bool {
		return is_dir($path . '/.git')
			|| is_file($path . '/.git')
			|| (is_file($path . '/HEAD') && is_dir($path . '/objects'));
	}
}
