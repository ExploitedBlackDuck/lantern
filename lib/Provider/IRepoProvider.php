<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider;

use OCA\Lantern\Model\BlobContent;
use OCA\Lantern\Model\CommitInfo;
use OCA\Lantern\Model\RepoDescriptor;
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
	 * @param string|null $path null = whole repo
	 *
	 * @return CommitInfo[]
	 */
	public function listCommits(RepoDescriptor $repo, string $ref, ?string $path, int $limit): array;
}
