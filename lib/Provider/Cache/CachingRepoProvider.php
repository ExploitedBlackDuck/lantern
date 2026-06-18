<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider\Cache;

use OCA\Lantern\Model\BlameLine;
use OCA\Lantern\Model\BlobContent;
use OCA\Lantern\Model\CommitInfo;
use OCA\Lantern\Model\RawBlob;
use OCA\Lantern\Model\RefInfo;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Model\SearchMatch;
use OCA\Lantern\Model\TreeEntry;
use OCA\Lantern\Provider\IRepoProvider;
use OCP\ICache;

/**
 * Caching decorator over any {@see IRepoProvider}.
 *
 * Every tree/blob/commit/ref/diff/blame/search read otherwise re-forks `git` or
 * re-hits a forge API. Fine for one user; a public instance with real repos is
 * slow and burns forge rate limits. This memoises the expensive reads in
 * Nextcloud's cache abstraction (ICache) with short TTLs.
 *
 * Safety: keys are namespaced PER USER by the cache prefix chosen at
 * registration (see {@see \OCA\Lantern\AppInfo\Application::register}), because
 * forge/user-Files repo IDs are per-user and the distributed cache is shared —
 * a global key could serve one user's private repo content to another.
 *
 * TTLs are short because keys are ref-based and refs move; content is not keyed
 * on an immutable commit SHA yet (a future optimisation that would permit much
 * longer-lived content caches). Errors are never cached: a failed read throws,
 * so only successful results ever reach the cache. Large blob bodies and large
 * diffs are not cached, to bound memory.
 */
final class CachingRepoProvider implements IRepoProvider {

	/** Ref-dependent reads (tree/blob/commits/diff/blame/search): refs move, keep short. */
	public const TTL_REF = 60;

	/** Branch/tag list and default ref. */
	public const TTL_REFS = 60;

	/** Do not cache blob bodies larger than this (avoid bloating the cache). */
	public const MAX_CACHED_BLOB = 256 * 1024;

	/** Do not cache diffs larger than this. */
	public const MAX_CACHED_DIFF = 512 * 1024;

	/** Classes permitted when unserialising a cached value (guards object injection). */
	private const ALLOWED = [
		TreeEntry::class, BlobContent::class, CommitInfo::class,
		RefInfo::class, SearchMatch::class, BlameLine::class,
	];

	public function __construct(
		private readonly IRepoProvider $inner,
		private readonly ICache $cache,
	) {
	}

	public function getKey(): string {
		return $this->inner->getKey();
	}

	private function key(RepoDescriptor $repo, string $method, string ...$parts): string {
		return 'v1:' . md5(implode('|', array_merge(
			[$this->inner->getKey(), $repo->provider, $repo->id, $method],
			$parts,
		)));
	}

	/**
	 * Return the cached value for $key, or compute it via $produce, cache it
	 * (when $store permits), and return it. A miss or a corrupt entry recomputes.
	 * A throwing $produce propagates and caches nothing.
	 *
	 * @param callable():mixed $produce
	 * @param null|callable(mixed):bool $store whether the produced value may be cached
	 */
	private function remember(string $key, int $ttl, callable $produce, ?callable $store = null): mixed {
		$raw = $this->cache->get($key);
		if (\is_string($raw)) {
			$val = @unserialize($raw, ['allowed_classes' => self::ALLOWED]);
			if ($val !== false) {
				return $val;
			}
		}
		$val = $produce();
		if ($store === null || $store($val)) {
			$this->cache->set($key, serialize($val), $ttl);
		}
		return $val;
	}

	public function defaultRef(RepoDescriptor $repo): string {
		return $this->remember($this->key($repo, 'defaultRef'), self::TTL_REFS,
			fn () => $this->inner->defaultRef($repo));
	}

	public function listTree(RepoDescriptor $repo, string $ref, string $path): array {
		return $this->remember($this->key($repo, 'listTree', $ref, $path), self::TTL_REF,
			fn () => $this->inner->listTree($repo, $ref, $path));
	}

	public function getBlob(RepoDescriptor $repo, string $ref, string $path): BlobContent {
		return $this->remember($this->key($repo, 'getBlob', $ref, $path), self::TTL_REF,
			fn () => $this->inner->getBlob($repo, $ref, $path),
			fn (BlobContent $b) => $b->content === null || \strlen($b->content) <= self::MAX_CACHED_BLOB);
	}

	public function listCommits(RepoDescriptor $repo, string $ref, ?string $path, int $limit, int $offset = 0): array {
		return $this->remember(
			$this->key($repo, 'listCommits', $ref, (string) $path, (string) $limit, (string) $offset),
			self::TTL_REF,
			fn () => $this->inner->listCommits($repo, $ref, $path, $limit, $offset),
		);
	}

	public function listRefs(RepoDescriptor $repo): array {
		return $this->remember($this->key($repo, 'listRefs'), self::TTL_REFS,
			fn () => $this->inner->listRefs($repo));
	}

	public function getBlobRaw(RepoDescriptor $repo, string $ref, string $path, int $maxBytes): RawBlob {
		// Raw bytes (downloads / image streams) are often large and one-shot —
		// pass straight through without caching.
		return $this->inner->getBlobRaw($repo, $ref, $path, $maxBytes);
	}

	public function search(RepoDescriptor $repo, string $ref, string $query, int $limit): array {
		return $this->remember($this->key($repo, 'search', $ref, $query, (string) $limit), self::TTL_REF,
			fn () => $this->inner->search($repo, $ref, $query, $limit));
	}

	public function getCommitDiff(RepoDescriptor $repo, string $ref): string {
		return $this->remember($this->key($repo, 'getCommitDiff', $ref), self::TTL_REF,
			fn () => $this->inner->getCommitDiff($repo, $ref),
			fn (string $d) => \strlen($d) <= self::MAX_CACHED_DIFF);
	}

	public function getRangeDiff(RepoDescriptor $repo, string $baseRef, string $headRef): string {
		return $this->remember($this->key($repo, 'getRangeDiff', $baseRef, $headRef), self::TTL_REF,
			fn () => $this->inner->getRangeDiff($repo, $baseRef, $headRef),
			fn (string $d) => \strlen($d) <= self::MAX_CACHED_DIFF);
	}

	public function blame(RepoDescriptor $repo, string $ref, string $path): array {
		return $this->remember($this->key($repo, 'blame', $ref, $path), self::TTL_REF,
			fn () => $this->inner->blame($repo, $ref, $path));
	}
}
