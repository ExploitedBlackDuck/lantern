<?php

declare(strict_types=1);

namespace OCA\Lantern\Service;

use OCA\Lantern\Model\RepoDescriptor;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Per-user source of remote-forge repositories (Horizon 3 — GitHub, and v2.1
 * GitLab).
 *
 * Each entry is {id, name, kind, host, slug} plus a personal access token that
 * is stored ENCRYPTED (NC's ICrypto) and never returned to the client.
 *
 *  - kind: which forge ('github' | 'gitlab') — also the IRepoProvider key, so
 *    the descriptor's provider is set straight from it.
 *  - host: the instance base URL ('' = the provider's default — api.github.com
 *    for GitHub, gitlab.com for GitLab). This is what lets GitLab point at a
 *    self-hosted instance.
 *  - slug: 'owner/repo' for GitHub, or a (possibly nested) 'group/sub/project'
 *    path for GitLab.
 *
 * The descriptor's path is "<kind>:<slug>"; the matching provider strips the
 * prefix to recover the slug and calls {@see tokenFor()} / {@see baseFor()}.
 *
 * Backward compatibility: pre-2.1 rows stored {host:'github', owner, repo} with
 * no `kind`/`slug`. {@see rows()} normalises those on read, so existing GitHub
 * entries keep working without a migration.
 */
class ForgeRepoStore {

	private const APP = 'lantern';
	private const KEY = 'forge_repos';

	/** Forge kinds we recognise, mapped to their id prefix. */
	private const KINDS = ['github' => 'gh', 'gitlab' => 'gl'];

	public function __construct(
		private readonly IConfig $config,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Rows for the management UI — never includes the token.
	 *
	 * @return array<int, array{id: string, name: string, kind: string, host: string, slug: string}>
	 */
	public function rawListFor(string $uid): array {
		$out = [];
		foreach ($this->rows($uid) as $r) {
			$out[] = ['id' => $r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'host' => $r['host'], 'slug' => $r['slug']];
		}
		return $out;
	}

	/** @return RepoDescriptor[] */
	public function listFor(string $uid): array {
		$out = [];
		foreach ($this->rows($uid) as $r) {
			$out[] = $this->toDescriptor($r);
		}
		return $out;
	}

	public function getFor(string $uid, string $id): ?RepoDescriptor {
		foreach ($this->rows($uid) as $r) {
			if ($r['id'] === $id) {
				return $this->toDescriptor($r);
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

	/**
	 * The configured instance base URL for a stored repo, or null when the
	 * provider default should be used. Never returns a trailing slash.
	 */
	public function baseFor(string $uid, string $id): ?string {
		foreach ($this->rows($uid) as $r) {
			if ($r['id'] === $id) {
				return $r['host'] !== '' ? $r['host'] : null;
			}
		}
		return null;
	}

	/** @return array{ok: bool, reason: string, id: ?string} */
	public function addFor(string $uid, string $kind, string $slug, string $host, string $token, string $name): array {
		$kind = trim($kind) !== '' ? trim($kind) : 'github';
		if (!isset(self::KINDS[$kind])) {
			return ['ok' => false, 'reason' => 'Unknown forge type.', 'id' => null];
		}
		$slug = trim($slug, " \t\n\r\0\x0B/");
		$segments = $slug === '' ? [] : explode('/', $slug);
		foreach ($segments as $seg) {
			if (!preg_match('#^[A-Za-z0-9._-]+$#', $seg)) {
				return ['ok' => false, 'reason' => 'The project path contains invalid characters.', 'id' => null];
			}
		}
		// GitHub is exactly owner/repo; GitLab allows nested groups (>= 2 parts).
		$minParts = 2;
		if (\count($segments) < $minParts || ($kind === 'github' && \count($segments) !== 2)) {
			$expected = $kind === 'github' ? 'owner/repository' : 'group/.../project';
			return ['ok' => false, 'reason' => "Enter the project as $expected.", 'id' => null];
		}
		$host = $this->normalizeHost($host);
		if ($host === false) {
			return ['ok' => false, 'reason' => 'The instance URL must be a valid http(s) URL.', 'id' => null];
		}
		$name = trim($name) !== '' ? trim($name) : $slug;
		$id = self::KINDS[$kind] . substr(sha1($kind . ':' . $host . ':' . $slug), 0, 10);
		$rows = array_values(array_filter($this->rows($uid), static fn (array $r) => $r['id'] !== $id));
		$rows[] = [
			'id' => $id,
			'name' => $name,
			'kind' => $kind,
			'host' => $host,
			'slug' => $slug,
			'token' => $token !== '' ? $this->crypto->encrypt($token) : '',
		];
		$this->save($uid, $rows);
		return ['ok' => true, 'reason' => 'Added.', 'id' => $id];
	}

	public function removeFor(string $uid, string $id): void {
		$rows = array_values(array_filter($this->rows($uid), static fn (array $r) => $r['id'] !== $id));
		$this->save($uid, $rows);
	}

	/** @param array{id:string,name:string,kind:string,host:string,slug:string} $r */
	private function toDescriptor(array $r): RepoDescriptor {
		return new RepoDescriptor($r['id'], $r['name'], $r['kind'] . ':' . $r['slug'], $r['kind']);
	}

	/**
	 * Validate + normalise an instance base URL. Returns the canonical form (no
	 * trailing slash), '' for "use the provider default", or false if invalid.
	 *
	 * @return string|false
	 */
	private function normalizeHost(string $host): string|false {
		$host = trim($host);
		if ($host === '') {
			return '';
		}
		if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $host)) {
			return false;
		}
		return rtrim($host, '/');
	}

	/** @return array<int, array{id:string,name:string,kind:string,host:string,slug:string,token:string}> */
	private function rows(string $uid): array {
		$decoded = json_decode($this->config->getUserValue($uid, self::APP, self::KEY, '[]'), true);
		if (!\is_array($decoded)) {
			return [];
		}
		$rows = [];
		foreach ($decoded as $r) {
			if (!\is_array($r) || !isset($r['id'])) {
				continue;
			}
			$normalized = $this->normalizeRow($r);
			if ($normalized !== null) {
				$rows[] = $normalized;
			}
		}
		return $rows;
	}

	/**
	 * Normalise a stored row to the current schema, accepting both the new
	 * {kind, host, slug} shape and the legacy {host:'github', owner, repo} one.
	 *
	 * @param array<string,mixed> $r
	 * @return array{id:string,name:string,kind:string,host:string,slug:string,token:string}|null
	 */
	private function normalizeRow(array $r): ?array {
		$slug = isset($r['slug']) ? (string) $r['slug'] : '';
		$kind = isset($r['kind']) ? (string) $r['kind'] : '';
		$host = isset($r['host']) ? (string) $r['host'] : '';

		if ($slug === '' && isset($r['owner'], $r['repo'])) {
			// Legacy GitHub row: {host:'github', owner, repo}. The old `host`
			// value was the literal string 'github', not a URL — drop it.
			$slug = (string) $r['owner'] . '/' . (string) $r['repo'];
			$kind = $kind !== '' && isset(self::KINDS[$kind]) ? $kind : 'github';
			$host = '';
		}
		if ($kind === '' || !isset(self::KINDS[$kind])) {
			$kind = 'github';
		}
		// A 'github' literal left over from a legacy row is not a real URL.
		if ($host === 'github' || $host === 'gitlab') {
			$host = '';
		}
		if ($slug === '') {
			return null;
		}
		return [
			'id' => (string) $r['id'],
			'name' => (string) ($r['name'] ?? ''),
			'kind' => $kind,
			'host' => $host,
			'slug' => $slug,
			'token' => (string) ($r['token'] ?? ''),
		];
	}

	/** @param array<int, array<string,string>> $rows */
	private function save(string $uid, array $rows): void {
		$this->config->setUserValue($uid, self::APP, self::KEY, json_encode(array_values($rows)));
	}
}
