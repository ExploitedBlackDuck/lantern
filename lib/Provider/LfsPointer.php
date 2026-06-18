<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider;

/**
 * Detects Git LFS pointer files.
 *
 * A Git LFS pointer is a tiny text blob that stands in for a large binary stored
 * out-of-band. Rendering its pointer text as if it were the file's content
 * misleads the viewer (it looks like a three-line text file rather than the
 * image/archive it represents). This detector lets the providers suppress that
 * text and label the blob as an LFS object instead.
 *
 * The check is pure and total (no git, no network, no I/O) so it is unit-tested
 * directly with fixtures.
 *
 * Spec: https://github.com/git-lfs/git-lfs/blob/main/docs/spec.md
 *
 *     version https://git-lfs.github.com/spec/v1
 *     oid sha256:<64 lowercase hex>
 *     size <non-negative integer>
 */
final class LfsPointer {

	/**
	 * A genuine pointer is tiny. Anything larger is regular content that merely
	 * happens to begin with a similar line, and must not be suppressed.
	 */
	public const MAX_POINTER_BYTES = 1024;

	/**
	 * Parse $content as a Git LFS pointer.
	 *
	 * @return array{oid: string, size: int}|null the oid (sha256 hex) and the
	 *   declared real size when $content is a well-formed pointer; null otherwise.
	 */
	public static function parse(string $content): ?array {
		if ($content === '' || \strlen($content) > self::MAX_POINTER_BYTES) {
			return null;
		}
		// Per spec the version line MUST come first. Accept the current domain and
		// the legacy "hawser" one that early LFS releases wrote.
		if (!str_starts_with($content, 'version https://git-lfs.github.com/spec/v1')
			&& !str_starts_with($content, 'version https://hawser.github.com/spec/v1')) {
			return null;
		}

		$oid = null;
		$size = null;
		foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
			if (str_starts_with($line, 'oid sha256:')) {
				$candidate = substr($line, 11);
				if (preg_match('/^[0-9a-f]{64}$/', $candidate) === 1) {
					$oid = $candidate;
				}
			} elseif (str_starts_with($line, 'size ')) {
				$candidate = substr($line, 5);
				if (preg_match('/^[0-9]+$/', $candidate) === 1) {
					$size = (int) $candidate;
				}
			}
		}

		if ($oid === null || $size === null) {
			return null;
		}
		return ['oid' => $oid, 'size' => $size];
	}

	/** Convenience predicate: true when $content is a well-formed LFS pointer. */
	public static function isPointer(string $content): bool {
		return self::parse($content) !== null;
	}
}
