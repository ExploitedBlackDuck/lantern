<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider;

use OCA\Lantern\Model\BlameLine;
use OCA\Lantern\Model\BlobContent;
use OCA\Lantern\Model\CommitInfo;
use OCA\Lantern\Model\RawBlob;
use OCA\Lantern\Model\RefInfo;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Model\SearchMatch;
use OCA\Lantern\Model\TreeEntry;

/**
 * Contract every repository backend implements.
 *
 * This is the seam that lets Lantern grow a second backend (a remote forge
 * client talking to GitHub/GitLab) WITHOUT touching the controllers or the
 * Vue frontend: both talk only to this interface. v1 ships a single
 * implementation, {@see \OCA\Lantern\Provider\Local\LocalGitProvider}.
 *
 * Implementations must treat every $ref and $path as untrusted input.
 */
interface IRepoProvider {

	/** Stable provider key, e.g. "local" or "github". */
	public function getKey(): string;

	/**
	 * The ref this provider treats as the default view (e.g. the HEAD branch).
	 */
	public function defaultRef(RepoDescriptor $repo): string;

	/**
	 * List the immediate children of a tree (directory).
	 *
	 * @param string $path "" for the repository root.
	 *
	 * @return TreeEntry[] sorted directories-first, then alphabetically
	 */
	public function listTree(RepoDescriptor $repo, string $ref, string $path): array;

	/**
	 * Fetch a single file blob. Binary blobs return content === null.
	 */
	public function getBlob(RepoDescriptor $repo, string $ref, string $path): BlobContent;

	/**
	 * List recent commits, optionally constrained to a path.
	 *
	 * Pagination is offset-based and part of the contract (baked in before the
	 * second — remote-forge — implementer, per the roadmap, so it is not a later
	 * breaking change): callers ask for $limit commits starting at $offset, and
	 * detect "more available" by requesting one extra and seeing it come back.
	 *
	 * @param string|null $path   null = whole repo
	 * @param int         $offset commits to skip from the newest
	 *
	 * @return CommitInfo[]
	 */
	public function listCommits(RepoDescriptor $repo, string $ref, ?string $path, int $limit, int $offset = 0): array;

	/**
	 * List the repository's branches and tags (for the ref picker).
	 *
	 * @return RefInfo[] branches first (default branch flagged), then tags
	 */
	public function listRefs(RepoDescriptor $repo): array;

	/**
	 * Fetch a blob's raw bytes (for download / image preview). Unlike getBlob,
	 * binary content is returned verbatim. $maxBytes caps the read.
	 */
	public function getBlobRaw(RepoDescriptor $repo, string $ref, string $path, int $maxBytes): RawBlob;

	/**
	 * Search file contents at a ref for a fixed (non-regex) string.
	 *
	 * @return SearchMatch[]
	 */
	public function search(RepoDescriptor $repo, string $ref, string $query, int $limit): array;

	/**
	 * Unified diff (patch text) introduced by a single commit.
	 *
	 * Implementations MUST NOT let a repo-controlled external diff driver run
	 * (§9.6): generate the diff with `diff.external` / textconv / attributes
	 * filters disabled, or via an API.
	 */
	public function getCommitDiff(RepoDescriptor $repo, string $ref): string;

	/**
	 * Unified diff (patch text) between two commits/refs ($baseRef..$headRef).
	 *
	 * Same hard constraint as getCommitDiff: a repo-controlled external diff
	 * driver MUST NOT run (§9.6) — disable `diff.external` / textconv /
	 * attributes filters, or go via an API.
	 */
	public function getRangeDiff(RepoDescriptor $repo, string $baseRef, string $headRef): string;

	/**
	 * Per-line authorship for a file at a ref. May return [] if the backend
	 * does not support blame (e.g. a forge with no blame REST endpoint).
	 *
	 * @return BlameLine[]
	 */
	public function blame(RepoDescriptor $repo, string $ref, string $path): array;
}
