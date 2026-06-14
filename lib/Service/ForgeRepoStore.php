<?php

declare(strict_types=1);

namespace OCA\Lantern\Service;

use OCA\Lantern\Model\RepoDescriptor;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Per-user source of remote-forge repositories (Horizon 3 — GitHub).
 *
 * Each entry is {id, name, host, owner, repo} plus a personal access token that
 * is stored ENCRYPTED (NC's ICrypto) and never returned to the client. The
 * descriptor's path is "github:owner/repo"; {@see \OCA\Lantern\Provider\Forge\GitHubProvider}
 * resolves it and fetches the token via {@see tokenFor()}.
 */
class ForgeRepoStore {

	private const APP = 'lantern';
	private const KEY = 'forge_repos';

	public function __construct(
		private readonly IConfig $config,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Rows for the management UI — never includes the token.
	 *
	 * @return array<int, array{id: string, name: string, host: string, owner: string, repo: string}>
	 */
	public function rawListFor(string $uid): array {
		$out = [];
		foreach ($this->rows($uid) as $r) {
			$out[] = ['id' => $r['id'], 'name' => $r['name'], 'host' => $r['host'], 'owner' => $r['owner'], 'repo' => $r['repo']];
		}
		return $out;
	}

	/** @return RepoDescriptor[] */
	public function listFor(string $uid): array {
		$out = [];
		foreach ($this->rows($uid) as $r) {
			$out[] = new RepoDescriptor($r['id'], $r['name'], 'github:' . $r['owner'] . '/' . $r['repo'], 'github');
		}
		return $out;
	}

	public function getFor(string $uid, string $id): ?RepoDescriptor {
		foreach ($this->rows($uid) as $r) {
			if ($r['id'] === $id) {
				return new RepoDescriptor($r['id'], $r['name'], 'github:' . $r['owner'] . '/' . $r['repo'], 'github');
			}
		}
		return null;
	}

	/** Decrypted PAT for a stored repo, or null. */
	public function tokenFor(string $uid, string $id): ?string {
		foreach ($this->rows($uid) as $r) {
			if ($r['id'] === $id) {
				if (($r['token'] ?? '') === '') {
					return null;
				}
				try {
					return $this->crypto->decrypt($r['token']);
				} catch (\Throwable $e) {
					$this->logger->warning('lantern: could not decrypt forge token for ' . $id);
					return null;
				}
			}
		}
		return null;
	}

	/** @return array{ok: bool, reason: string, id: ?string} */
	public function addFor(string $uid, string $owner, string $repo, string $token, string $name): array {
		$owner = trim($owner);
		$repo = trim($repo);
		if (!preg_match('#^[A-Za-z0-9._-]+$#', $owner) || !preg_match('#^[A-Za-z0-9._-]+$#', $repo)) {
			return ['ok' => false, 'reason' => 'Owner and repository must be valid GitHub names.', 'id' => null];
		}
		$name = trim($name) !== '' ? trim($name) : ($owner . '/' . $repo);
		$id = 'gh' . substr(sha1('github:' . $owner . '/' . $repo), 0, 10);
		$rows = array_values(array_filter($this->rows($uid), static fn (array $r) => $r['id'] !== $id));
		$rows[] = [
			'id' => $id,
			'name' => $name,
			'host' => 'github',
			'owner' => $owner,
			'repo' => $repo,
			'token' => $token !== '' ? $this->crypto->encrypt($token) : '',
		];
		$this->save($uid, $rows);
		return ['ok' => true, 'reason' => 'Added.', 'id' => $id];
	}

	public function removeFor(string $uid, string $id): void {
		$rows = array_values(array_filter($this->rows($uid), static fn (array $r) => $r['id'] !== $id));
		$this->save($uid, $rows);
	}

	/** @return array<int, array{id:string,name:string,host:string,owner:string,repo:string,token:string}> */
	private function rows(string $uid): array {
		$decoded = json_decode($this->config->getUserValue($uid, self::APP, self::KEY, '[]'), true);
		if (!\is_array($decoded)) {
			return [];
		}
		$rows = [];
		foreach ($decoded as $r) {
			if (\is_array($r) && isset($r['id'], $r['owner'], $r['repo'])) {
				$rows[] = [
					'id' => (string) $r['id'],
					'name' => (string) ($r['name'] ?? ''),
					'host' => (string) ($r['host'] ?? 'github'),
					'owner' => (string) $r['owner'],
					'repo' => (string) $r['repo'],
					'token' => (string) ($r['token'] ?? ''),
				];
			}
		}
		return $rows;
	}

	/** @param array<int, array<string,string>> $rows */
	private function save(string $uid, array $rows): void {
		$this->config->setUserValue($uid, self::APP, self::KEY, json_encode(array_values($rows)));
	}
}
