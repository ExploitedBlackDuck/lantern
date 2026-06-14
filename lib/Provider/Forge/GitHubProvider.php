<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider\Forge;

use OCA\Lantern\Exception\ForgeAuthException;
use OCA\Lantern\Exception\InvalidRefException;
use OCA\Lantern\Exception\RateLimitException;
use OCA\Lantern\Exception\RepoException;
use OCA\Lantern\Exception\RepoNotFoundException;
use OCA\Lantern\Model\BlobContent;
use OCA\Lantern\Model\CommitInfo;
use OCA\Lantern\Model\RawBlob;
use OCA\Lantern\Model\RefInfo;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Model\SearchMatch;
use OCA\Lantern\Model\TreeEntry;
use OCA\Lantern\Provider\IRepoProvider;
use OCA\Lantern\Service\ForgeRepoStore;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Browses a GitHub repository through the REST API — the second backend behind
 * the {@see IRepoProvider} seam (Horizon 3). The controllers and the Vue
 * frontend are unchanged: they still talk only to the interface.
 *
 * Per-repo credentials (owner/repo + a personal access token) come from the
 * per-user {@see ForgeRepoStore}, keyed by the descriptor id + the current
 * user. The on-the-wire JSON→model mapping is kept in pure static methods so it
 * can be unit-tested with fixtures and no network (see run-core-tests.php); the
 * thin HTTP plumbing is exercised live against the real API.
 */
final class GitHubProvider implements IRepoProvider {

	private const API = 'https://api.github.com';
	/** GitHub's contents API only inlines files up to ~1 MiB. */
	public const MAX_INLINE_BLOB = 1024 * 1024;

	public function __construct(
		private readonly IClientService $clientService,
		private readonly ForgeRepoStore $store,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getKey(): string {
		return 'github';
	}

	public function defaultRef(RepoDescriptor $repo): string {
		$data = $this->get($repo, '/repos/' . $this->slug($repo));
		$branch = \is_array($data) ? ($data['default_branch'] ?? '') : '';
		return \is_string($branch) && $branch !== '' ? $branch : 'main';
	}

	public function listTree(RepoDescriptor $repo, string $ref, string $path): array {
		$ref = $this->assertRef($ref);
		$path = $this->normalizePath($path);
		$url = '/repos/' . $this->slug($repo) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($ref);
		$data = $this->get($repo, $url);
		if (!\is_array($data) || !array_is_list($data)) {
			// A non-list response means the path was a file, not a directory.
			throw new RepoNotFoundException('Not a directory at this ref');
		}
		return self::mapTree($data);
	}

	public function getBlob(RepoDescriptor $repo, string $ref, string $path): BlobContent {
		$ref = $this->assertRef($ref);
		$path = $this->normalizePath($path);
		if ($path === '') {
			throw new RepoNotFoundException('No file path given');
		}
		$url = '/repos/' . $this->slug($repo) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($ref);
		$data = $this->get($repo, $url);
		if (!\is_array($data) || ($data['type'] ?? '') !== 'file') {
			throw new RepoNotFoundException('No such file at this ref');
		}
		return self::mapBlob($data);
	}

	public function listCommits(RepoDescriptor $repo, string $ref, ?string $path, int $limit, int $offset = 0): array {
		$ref = $this->assertRef($ref);
		$limit = max(1, min($limit, 201));
		$perPage = min($limit, 100);
		$page = self::pageFor($offset, $perPage);
		$url = '/repos/' . $this->slug($repo) . '/commits?sha=' . rawurlencode($ref)
			. '&per_page=' . $perPage . '&page=' . $page;
		if ($path !== null && $path !== '') {
			$url .= '&path=' . $this->encodePath($this->normalizePath($path));
		}
		$data = $this->get($repo, $url);
		return \is_array($data) ? self::mapCommits($data) : [];
	}

	public function listRefs(RepoDescriptor $repo): array {
		$default = $this->defaultRef($repo);
		$slug = $this->slug($repo);
		$branches = $this->get($repo, '/repos/' . $slug . '/branches?per_page=100');
		$tags = $this->get($repo, '/repos/' . $slug . '/tags?per_page=100');
		return self::mapRefs(
			\is_array($branches) ? $branches : [],
			\is_array($tags) ? $tags : [],
			$default,
		);
	}

	public function getBlobRaw(RepoDescriptor $repo, string $ref, string $path, int $maxBytes): RawBlob {
		$ref = $this->assertRef($ref);
		$path = $this->normalizePath($path);
		if ($path === '') {
			throw new RepoNotFoundException('No file path given');
		}
		$url = self::API . '/repos/' . $this->slug($repo) . '/contents/' . $this->encodePath($path) . '?ref=' . rawurlencode($ref);
		$body = $this->raw($repo, $url);
		return new RawBlob($path, \strlen($body), $body, \strlen($body) >= max(1, $maxBytes));
	}

	public function search(RepoDescriptor $repo, string $ref, string $query, int $limit): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}
		$limit = max(1, min($limit, 100));
		// GitHub code search is repo-scoped via a qualifier; results are file-level.
		$q = $query . ' repo:' . $this->slug($repo);
		$data = $this->get($repo, '/search/code?per_page=' . $limit . '&q=' . rawurlencode($q));
		$items = (\is_array($data) && isset($data['items']) && \is_array($data['items'])) ? $data['items'] : [];
		return self::mapSearch($items);
	}

	public function getCommitDiff(RepoDescriptor $repo, string $ref): string {
		$ref = $this->assertRef($ref);
		// The .diff media type returns a ready-made unified diff.
		$url = self::API . '/repos/' . $this->slug($repo) . '/commits/' . rawurlencode($ref);
		return $this->request($repo, $url, false, 'application/vnd.github.diff');
	}

	public function getRangeDiff(RepoDescriptor $repo, string $baseRef, string $headRef): string {
		$base = $this->assertRef($baseRef);
		$head = $this->assertRef($headRef);
		// The compare endpoint with the .diff media type returns a ready-made
		// unified diff between the two refs (GitHub uses base...head syntax).
		$url = self::API . '/repos/' . $this->slug($repo) . '/compare/'
			. rawurlencode($base) . '...' . rawurlencode($head);
		return $this->request($repo, $url, false, 'application/vnd.github.diff');
	}

	public function blame(RepoDescriptor $repo, string $ref, string $path): array {
		// GitHub exposes blame only via GraphQL; the REST seam used here has no
		// blame endpoint, so this backend reports "no blame" (the UI degrades
		// gracefully). A GraphQL-based blame is a possible follow-up.
		return [];
	}

	// --- pure mappers (unit-tested with fixtures, no network) ---

	/** @param array<int, array<string,mixed>> $json @return TreeEntry[] */
	public static function mapTree(array $json): array {
		$entries = [];
		foreach ($json as $row) {
			$type = ($row['type'] ?? '') === 'dir' ? TreeEntry::TYPE_TREE : TreeEntry::TYPE_BLOB;
			$size = $type === TreeEntry::TYPE_TREE ? null : (int) ($row['size'] ?? 0);
			$entries[] = new TreeEntry(
				(string) ($row['name'] ?? ''),
				(string) ($row['path'] ?? ''),
				$type,
				'',
				(string) ($row['sha'] ?? ''),
				$size,
			);
		}
		usort($entries, static function (TreeEntry $a, TreeEntry $b): int {
			$ad = $a->type === TreeEntry::TYPE_TREE ? 0 : 1;
			$bd = $b->type === TreeEntry::TYPE_TREE ? 0 : 1;
			return $ad === $bd ? strcasecmp($a->name, $b->name) : ($ad <=> $bd);
		});
		return $entries;
	}

	/** @param array<string,mixed> $row */
	public static function mapBlob(array $row): BlobContent {
		$path = (string) ($row['path'] ?? '');
		$size = (int) ($row['size'] ?? 0);
		if ($size > self::MAX_INLINE_BLOB || ($row['encoding'] ?? '') !== 'base64' || !isset($row['content'])) {
			// >1 MiB: GitHub returns empty content here; treat as truncated.
			return new BlobContent($path, $size, false, true, null);
		}
		$raw = base64_decode(str_replace("\n", '', (string) $row['content']), true);
		if ($raw === false) {
			return new BlobContent($path, $size, false, true, null);
		}
		$binary = str_contains(substr($raw, 0, 8192), "\0");
		return new BlobContent($path, $size, $binary, false, $binary ? null : $raw);
	}

	/** @param array<int, array<string,mixed>> $json @return CommitInfo[] */
	public static function mapCommits(array $json): array {
		$out = [];
		foreach ($json as $row) {
			$commit = \is_array($row['commit'] ?? null) ? $row['commit'] : [];
			$author = \is_array($commit['author'] ?? null) ? $commit['author'] : [];
			$message = (string) ($commit['message'] ?? '');
			$subject = strtok($message, "\n");
			$out[] = new CommitInfo(
				(string) ($row['sha'] ?? ''),
				(string) ($author['name'] ?? ''),
				(string) ($author['email'] ?? ''),
				(string) ($author['date'] ?? ''),
				$subject === false ? '' : $subject,
			);
		}
		return $out;
	}

	/**
	 * @param array<int, array<string,mixed>> $branches
	 * @param array<int, array<string,mixed>> $tags
	 * @return RefInfo[]
	 */
	public static function mapRefs(array $branches, array $tags, string $default): array {
		$out = [];
		foreach ($branches as $b) {
			$name = (string) ($b['name'] ?? '');
			if ($name !== '') {
				$out[] = new RefInfo($name, RefInfo::TYPE_BRANCH, $name === $default);
			}
		}
		foreach ($tags as $t) {
			$name = (string) ($t['name'] ?? '');
			if ($name !== '') {
				$out[] = new RefInfo($name, RefInfo::TYPE_TAG, false);
			}
		}
		return $out;
	}

	/** @param array<int, array<string,mixed>> $items @return SearchMatch[] */
	public static function mapSearch(array $items): array {
		$out = [];
		foreach ($items as $it) {
			$path = (string) ($it['path'] ?? '');
			// GitHub code search returns file-level hits; line numbers aren't
			// provided, so line is 0 and we surface the first text-match fragment
			// if the text-match media type was negotiated.
			$text = '';
			if (isset($it['text_matches'][0]['fragment'])) {
				$text = trim((string) $it['text_matches'][0]['fragment']);
				$text = substr(str_replace("\n", ' ', $text), 0, 200);
			}
			if ($path !== '') {
				$out[] = new SearchMatch($path, 0, $text);
			}
		}
		return $out;
	}

	/**
	 * Convert an offset+page-size into GitHub's 1-based `page` number. Pure so
	 * the pagination edge cases (offset 0, sub-page offsets, large offsets) are
	 * unit-tested without a network round-trip.
	 */
	public static function pageFor(int $offset, int $perPage): int {
		return (int) floor(max(0, $offset) / max(1, $perPage)) + 1;
	}

	/**
	 * Map a GitHub REST status (and its rate-limit headers) to a typed
	 * exception, or return cleanly on 2xx. This is the single source of truth
	 * for the error contract — kept pure and static so every branch (rate limit,
	 * bad token, forbidden scope, not found, generic upstream error) is unit-
	 * tested with fixtures; the live request() path simply calls it. Returning
	 * RepoNotFoundException for a 403 rate-limit (the pre-2.0.1 behaviour) is the
	 * exact "opaque failure" the roadmap §0/§2 calls out.
	 *
	 * @param array<string,string> $headers response headers (case-insensitive keys)
	 */
	public static function classifyStatus(int $status, array $headers = [], string $body = ''): void {
		if ($status >= 200 && $status < 300) {
			return;
		}
		$h = [];
		foreach ($headers as $k => $v) {
			$h[strtolower($k)] = $v;
		}
		$get = static fn (string $k): ?string => (isset($h[$k]) && $h[$k] !== '') ? $h[$k] : null;
		$remaining = $get('x-ratelimit-remaining');
		$retryAfter = $get('retry-after');

		// GitHub signals rate limiting as a 429, or a 403 with the remaining
		// quota at 0 (primary limit) or a Retry-After header (secondary limit).
		$rateLimited = $status === 429
			|| ($status === 403 && (($remaining !== null && (int) $remaining === 0) || $retryAfter !== null));
		if ($rateLimited) {
			throw new RateLimitException(self::rateLimitMessage($retryAfter, $get('x-ratelimit-reset')));
		}
		if ($status === 401) {
			throw new ForgeAuthException('GitHub rejected the token (401) — it may be invalid or expired.');
		}
		if ($status === 403) {
			throw new ForgeAuthException('GitHub forbade the request (403) — the token may lack the required scope.');
		}
		if ($status === 404) {
			throw new RepoNotFoundException('GitHub: not found');
		}
		if ($status >= 400) {
			throw new RepoException('GitHub API error ' . $status);
		}
	}

	private static function rateLimitMessage(?string $retryAfter, ?string $reset): string {
		if ($retryAfter !== null && is_numeric($retryAfter)) {
			return 'GitHub rate limit reached — try again in ' . (int) $retryAfter . 's.';
		}
		if ($reset !== null && is_numeric($reset)) {
			return 'GitHub rate limit reached — resets at ' . gmdate('H:i', (int) $reset) . ' UTC.';
		}
		return 'GitHub rate limit reached — try again later.';
	}

	// --- HTTP plumbing (thin; exercised live) ---

	private function slug(RepoDescriptor $repo): string {
		// descriptor->path is "github:owner/repo"
		$p = $repo->path;
		$colon = strpos($p, ':');
		return $colon === false ? $p : substr($p, $colon + 1);
	}

	/** @return mixed decoded JSON */
	private function get(RepoDescriptor $repo, string $url): mixed {
		$body = $this->request($repo, str_starts_with($url, 'http') ? $url : self::API . $url, false);
		$decoded = json_decode($body, true);
		return $decoded;
	}

	private function raw(RepoDescriptor $repo, string $url): string {
		return $this->request($repo, $url, true);
	}

	private function request(RepoDescriptor $repo, string $url, bool $raw, ?string $accept = null): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new RepoNotFoundException('Not signed in');
		}
		$token = $this->store->tokenFor($uid, $repo->id);
		$headers = [
			'Accept' => $accept ?? ($raw ? 'application/vnd.github.raw' : 'application/vnd.github+json'),
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent' => 'Lantern-Nextcloud',
		];
		if ($token !== null && $token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		try {
			$response = $this->clientService->newClient()->get($url, [
				'headers' => $headers,
				'timeout' => 15,
				'http_errors' => false,
			]);
			$status = $response->getStatusCode();
			$body = (string) $response->getBody();
			if ($status >= 400) {
				$this->logger->warning('lantern: github API ' . $status . ' for ' . $url);
			}
			// classifyStatus throws the right typed exception (rate limit, auth,
			// not found, generic) or returns cleanly on 2xx.
			self::classifyStatus($status, [
				'x-ratelimit-remaining' => $response->getHeader('X-RateLimit-Remaining'),
				'x-ratelimit-reset' => $response->getHeader('X-RateLimit-Reset'),
				'retry-after' => $response->getHeader('Retry-After'),
			], $body);
			return $body;
		} catch (RepoException $e) {
			// RepoNotFound / RateLimit / ForgeAuth / generic — already typed.
			throw $e;
		} catch (\Throwable $e) {
			// Transport failure (DNS, TLS, timeout): a genuine upstream error,
			// not a 404. RepoException maps to 502 in the controller guard.
			$this->logger->error('lantern: github request failed', ['exception' => $e]);
			throw new RepoException('GitHub request failed');
		}
	}

	private function assertRef(string $ref): string {
		$ref = trim($ref);
		if ($ref === '' || \strlen($ref) > 255 || preg_match('/[\x00-\x1f\s]/', $ref) === 1) {
			throw new InvalidRefException('Invalid ref');
		}
		return $ref;
	}

	private function normalizePath(string $path): string {
		$path = ltrim(str_replace('\\', '/', $path), '/');
		foreach (explode('/', $path) as $seg) {
			if ($seg === '..') {
				throw new InvalidRefException('Path traversal is not allowed');
			}
		}
		if (preg_match('/[\x00-\x1f]/', $path) === 1) {
			throw new InvalidRefException('Path contains control characters');
		}
		return $path;
	}

	private function encodePath(string $path): string {
		// Encode each segment but keep the slashes.
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}
}
