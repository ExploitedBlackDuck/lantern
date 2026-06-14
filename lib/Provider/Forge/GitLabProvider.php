<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider\Forge;

use OCA\Lantern\Exception\ForgeAuthException;
use OCA\Lantern\Exception\InvalidRefException;
use OCA\Lantern\Exception\RateLimitException;
use OCA\Lantern\Exception\RepoException;
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
use OCA\Lantern\Service\ForgeRepoStore;
use OCP\Http\Client\IClientService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Browses a GitLab repository through the REST API v4 — the third backend
 * behind the {@see IRepoProvider} seam (v2.1). The controllers and the Vue
 * frontend are unchanged: they still talk only to the interface.
 *
 * Unlike GitHub, GitLab is frequently self-hosted, so each repo carries an
 * instance base URL ({@see ForgeRepoStore::baseFor()}; default gitlab.com), and
 * projects are addressed by a possibly-nested 'group/sub/project' path that is
 * URL-encoded into the `:id` slot. GitLab's REST API also exposes blame and
 * line-numbered code search, so this backend reaches fuller parity than GitHub.
 *
 * The on-the-wire JSON→model mapping lives in pure static methods so it can be
 * unit-tested with fixtures and no network (see run-core-tests.php); the thin
 * HTTP plumbing is exercised live against the real API.
 */
final class GitLabProvider implements IRepoProvider {

	private const DEFAULT_BASE = 'https://gitlab.com';
	/** GitLab inlines file content for the files API; cap parity with GitHub. */
	public const MAX_INLINE_BLOB = 1024 * 1024;

	public function __construct(
		private readonly IClientService $clientService,
		private readonly ForgeRepoStore $store,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getKey(): string {
		return 'gitlab';
	}

	public function defaultRef(RepoDescriptor $repo): string {
		$data = $this->get($repo, '/projects/' . $this->projectId($repo));
		$branch = \is_array($data) ? ($data['default_branch'] ?? '') : '';
		return \is_string($branch) && $branch !== '' ? $branch : 'main';
	}

	public function listTree(RepoDescriptor $repo, string $ref, string $path): array {
		$ref = $this->assertRef($ref);
		$path = $this->normalizePath($path);
		$url = '/projects/' . $this->projectId($repo) . '/repository/tree?per_page=100&ref=' . rawurlencode($ref);
		if ($path !== '') {
			$url .= '&path=' . rawurlencode($path);
		}
		$data = $this->get($repo, $url);
		if (!\is_array($data) || !array_is_list($data)) {
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
		$url = '/projects/' . $this->projectId($repo) . '/repository/files/' . rawurlencode($path) . '?ref=' . rawurlencode($ref);
		$data = $this->get($repo, $url);
		if (!\is_array($data) || !isset($data['content'])) {
			throw new RepoNotFoundException('No such file at this ref');
		}
		return self::mapBlob($data);
	}

	public function listCommits(RepoDescriptor $repo, string $ref, ?string $path, int $limit, int $offset = 0): array {
		$ref = $this->assertRef($ref);
		$limit = max(1, min($limit, 201));
		$perPage = min($limit, 100);
		$page = self::pageFor($offset, $perPage);
		$url = '/projects/' . $this->projectId($repo) . '/repository/commits?ref_name=' . rawurlencode($ref)
			. '&per_page=' . $perPage . '&page=' . $page;
		if ($path !== null && $path !== '') {
			$url .= '&path=' . rawurlencode($this->normalizePath($path));
		}
		$data = $this->get($repo, $url);
		return \is_array($data) ? self::mapCommits($data) : [];
	}

	public function listRefs(RepoDescriptor $repo): array {
		$pid = $this->projectId($repo);
		$default = $this->defaultRef($repo);
		$branches = $this->get($repo, '/projects/' . $pid . '/repository/branches?per_page=100');
		$tags = $this->get($repo, '/projects/' . $pid . '/repository/tags?per_page=100');
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
		$url = $this->apiRoot($repo) . '/projects/' . $this->projectId($repo)
			. '/repository/files/' . rawurlencode($path) . '/raw?ref=' . rawurlencode($ref);
		$body = $this->request($repo, $url, true);
		return new RawBlob($path, \strlen($body), $body, \strlen($body) >= max(1, $maxBytes));
	}

	public function search(RepoDescriptor $repo, string $ref, string $query, int $limit): array {
		$query = trim($query);
		if ($query === '') {
			return [];
		}
		// GitLab's project blob-search API requires authentication even for
		// public projects (it 401s anonymously). When no token is configured,
		// degrade gracefully to "no results" rather than surfacing an auth error
		// on an otherwise-anonymous browse — every other read works without one.
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null || ($this->store->tokenFor($uid, $repo->id) ?? '') === '') {
			return [];
		}
		$limit = max(1, min($limit, 100));
		// GitLab project search with the blobs scope returns file-level hits that
		// DO carry a line number (startline) and a snippet — better than GitHub.
		$url = '/projects/' . $this->projectId($repo) . '/search?scope=blobs&per_page=' . $limit
			. '&ref=' . rawurlencode($this->assertRef($ref)) . '&search=' . rawurlencode($query);
		$data = $this->get($repo, $url);
		return \is_array($data) && array_is_list($data) ? self::mapSearch($data) : [];
	}

	public function getCommitDiff(RepoDescriptor $repo, string $ref): string {
		$ref = $this->assertRef($ref);
		$url = '/projects/' . $this->projectId($repo) . '/repository/commits/' . rawurlencode($ref) . '/diff';
		$data = $this->get($repo, $url);
		return self::assembleDiff(\is_array($data) ? $data : []);
	}

	public function blame(RepoDescriptor $repo, string $ref, string $path): array {
		$ref = $this->assertRef($ref);
		$path = $this->normalizePath($path);
		if ($path === '') {
			throw new RepoNotFoundException('No file path given');
		}
		$url = '/projects/' . $this->projectId($repo) . '/repository/files/' . rawurlencode($path)
			. '/blame?ref=' . rawurlencode($ref);
		$data = $this->get($repo, $url);
		return \is_array($data) ? self::mapBlame($data) : [];
	}

	// --- pure mappers (unit-tested with fixtures, no network) ---

	/** @param array<int, array<string,mixed>> $json @return TreeEntry[] */
	public static function mapTree(array $json): array {
		$entries = [];
		foreach ($json as $row) {
			$type = ($row['type'] ?? '') === 'tree' ? TreeEntry::TYPE_TREE : TreeEntry::TYPE_BLOB;
			$entries[] = new TreeEntry(
				(string) ($row['name'] ?? ''),
				(string) ($row['path'] ?? ''),
				$type,
				(string) ($row['mode'] ?? ''),
				(string) ($row['id'] ?? ''),
				// The tree endpoint does not report blob sizes.
				null,
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
		$path = (string) ($row['file_path'] ?? ($row['path'] ?? ''));
		$size = (int) ($row['size'] ?? 0);
		if ($size > self::MAX_INLINE_BLOB || ($row['encoding'] ?? '') !== 'base64' || !isset($row['content'])) {
			return new BlobContent($path, $size, false, true, null);
		}
		$raw = base64_decode(str_replace("\n", '', (string) $row['content']), true);
		if ($raw === false) {
			return new BlobContent($path, $size, false, true, null);
		}
		// GitLab reports `size` as the base64 length for some endpoints; trust the
		// decoded length for display so a small file is never mis-flagged.
		$len = \strlen($raw);
		$binary = str_contains(substr($raw, 0, 8192), "\0");
		return new BlobContent($path, $size > 0 ? $size : $len, $binary, false, $binary ? null : $raw);
	}

	/** @param array<int, array<string,mixed>> $json @return CommitInfo[] */
	public static function mapCommits(array $json): array {
		$out = [];
		foreach ($json as $row) {
			$message = (string) ($row['title'] ?? ($row['message'] ?? ''));
			$subject = strtok($message, "\n");
			$out[] = new CommitInfo(
				(string) ($row['id'] ?? ''),
				(string) ($row['author_name'] ?? ''),
				(string) ($row['author_email'] ?? ''),
				(string) ($row['authored_date'] ?? ($row['created_at'] ?? '')),
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
				$isDefault = (bool) ($b['default'] ?? false) || $name === $default;
				$out[] = new RefInfo($name, RefInfo::TYPE_BRANCH, $isDefault);
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
			if ($path === '') {
				continue;
			}
			$line = (int) ($it['startline'] ?? 0);
			$text = trim((string) ($it['data'] ?? ''));
			// Snippets can span several lines; surface the first non-empty one.
			$first = strtok($text, "\n");
			$text = $first === false ? '' : substr(trim($first), 0, 200);
			$out[] = new SearchMatch($path, $line, $text);
		}
		return $out;
	}

	/**
	 * Assemble GitLab's per-file diff objects into a single unified-diff string
	 * the frontend can render the same way as GitHub's ready-made .diff.
	 *
	 * @param array<int, array<string,mixed>> $files
	 */
	public static function assembleDiff(array $files): string {
		$out = '';
		foreach ($files as $f) {
			if (!\is_array($f)) {
				continue;
			}
			$old = (string) ($f['old_path'] ?? '');
			$new = (string) ($f['new_path'] ?? $old);
			$out .= 'diff --git a/' . $old . ' b/' . $new . "\n";
			if (($f['new_file'] ?? false) === true) {
				$out .= "new file\n";
			} elseif (($f['deleted_file'] ?? false) === true) {
				$out .= "deleted file\n";
			} elseif (($f['renamed_file'] ?? false) === true) {
				$out .= 'rename from ' . $old . "\nrename to " . $new . "\n";
			}
			$diff = (string) ($f['diff'] ?? '');
			$out .= $diff;
			if ($diff !== '' && !str_ends_with($diff, "\n")) {
				$out .= "\n";
			}
		}
		return $out;
	}

	/**
	 * Map GitLab's blame groups (each a commit + the contiguous lines it last
	 * touched) into a flat per-line list. GitLab returns the groups in file
	 * order with no explicit line numbers, so we count lines as we go.
	 *
	 * @param array<int, array<string,mixed>> $groups
	 * @return BlameLine[]
	 */
	public static function mapBlame(array $groups): array {
		$out = [];
		$lineNo = 0;
		foreach ($groups as $g) {
			if (!\is_array($g)) {
				continue;
			}
			$commit = \is_array($g['commit'] ?? null) ? $g['commit'] : [];
			$sha = (string) ($commit['id'] ?? '');
			$author = (string) ($commit['author_name'] ?? '');
			$date = (string) ($commit['authored_date'] ?? ($commit['committed_date'] ?? ''));
			$lines = \is_array($g['lines'] ?? null) ? $g['lines'] : [];
			foreach ($lines as $_) {
				$out[] = new BlameLine(++$lineNo, $sha, $author, $date);
			}
		}
		return $out;
	}

	/**
	 * Convert an offset+page-size into GitLab's 1-based `page` number. Pure so
	 * the pagination edge cases are unit-tested without a network round-trip.
	 */
	public static function pageFor(int $offset, int $perPage): int {
		return (int) floor(max(0, $offset) / max(1, $perPage)) + 1;
	}

	/**
	 * Map a GitLab REST status (and its rate-limit headers) to a typed exception,
	 * or return cleanly on 2xx. Pure and static so every branch is unit-tested.
	 * GitLab signals rate limiting with a 429 (and `RateLimit-*` / `Retry-After`
	 * headers, no `x-` prefix); 401/403 are auth failures.
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
		$remaining = $get('ratelimit-remaining') ?? $get('x-ratelimit-remaining');
		$retryAfter = $get('retry-after');

		$rateLimited = $status === 429
			|| ($status === 403 && (($remaining !== null && (int) $remaining === 0) || $retryAfter !== null));
		if ($rateLimited) {
			throw new RateLimitException(self::rateLimitMessage($retryAfter, $get('ratelimit-reset') ?? $get('x-ratelimit-reset')));
		}
		if ($status === 401) {
			throw new ForgeAuthException('GitLab rejected the token (401) — it may be invalid or expired.');
		}
		if ($status === 403) {
			throw new ForgeAuthException('GitLab forbade the request (403) — the token may lack the required scope.');
		}
		if ($status === 404) {
			throw new RepoNotFoundException('GitLab: not found');
		}
		if ($status >= 400) {
			throw new RepoException('GitLab API error ' . $status);
		}
	}

	private static function rateLimitMessage(?string $retryAfter, ?string $reset): string {
		if ($retryAfter !== null && is_numeric($retryAfter)) {
			return 'GitLab rate limit reached — try again in ' . (int) $retryAfter . 's.';
		}
		if ($reset !== null && is_numeric($reset)) {
			return 'GitLab rate limit reached — resets at ' . gmdate('H:i', (int) $reset) . ' UTC.';
		}
		return 'GitLab rate limit reached — try again later.';
	}

	// --- HTTP plumbing (thin; exercised live) ---

	/** descriptor->path is "gitlab:group/sub/project"; encode it for the :id slot. */
	private function projectId(RepoDescriptor $repo): string {
		$p = $repo->path;
		$colon = strpos($p, ':');
		$slug = $colon === false ? $p : substr($p, $colon + 1);
		return rawurlencode($slug);
	}

	private function apiRoot(RepoDescriptor $repo): string {
		$uid = $this->userSession->getUser()?->getUID();
		$base = $uid !== null ? $this->store->baseFor($uid, $repo->id) : null;
		return rtrim($base ?? self::DEFAULT_BASE, '/') . '/api/v4';
	}

	/** @return mixed decoded JSON */
	private function get(RepoDescriptor $repo, string $url): mixed {
		$body = $this->request($repo, str_starts_with($url, 'http') ? $url : $this->apiRoot($repo) . $url, false);
		return json_decode($body, true);
	}

	private function request(RepoDescriptor $repo, string $url, bool $raw): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new RepoNotFoundException('Not signed in');
		}
		$token = $this->store->tokenFor($uid, $repo->id);
		$headers = [
			'Accept' => $raw ? 'text/plain' : 'application/json',
			'User-Agent' => 'Lantern-Nextcloud',
		];
		if ($token !== null && $token !== '') {
			$headers['PRIVATE-TOKEN'] = $token;
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
				$this->logger->warning('lantern: gitlab API ' . $status . ' for ' . $url);
			}
			self::classifyStatus($status, [
				'ratelimit-remaining' => $response->getHeader('RateLimit-Remaining'),
				'ratelimit-reset' => $response->getHeader('RateLimit-Reset'),
				'retry-after' => $response->getHeader('Retry-After'),
			], $body);
			return $body;
		} catch (RepoException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('lantern: gitlab request failed', ['exception' => $e]);
			throw new RepoException('GitLab request failed');
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
}
