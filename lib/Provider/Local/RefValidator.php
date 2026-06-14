<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider\Local;

use OCA\Lantern\Exception\InvalidRefException;

/**
 * Validates git refs and in-repo paths against strict allowlists.
 *
 * GitBinary already makes shell injection impossible by avoiding the shell,
 * but argument injection (a value that begins with "-" being read as a git
 * option, or path traversal out of the tree) is a separate class of problem.
 * This validator is the second layer that closes those.
 */
final class RefValidator {

	/**
	 * Refs we accept: branch/tag names, full or short SHAs, HEAD, and the
	 * limited suffix syntax we actually use internally (^{tree}). We forbid
	 * anything starting with "-" so a ref can never be parsed as an option,
	 * and we forbid the ".." range/traversal sequence and whitespace.
	 */
	public function assertRef(string $ref): string {
		$ref = trim($ref);
		if ($ref === '' || \strlen($ref) > 255) {
			throw new InvalidRefException('Empty or oversized ref');
		}
		if (str_starts_with($ref, '-')) {
			throw new InvalidRefException('Ref may not start with "-"');
		}
		if (str_contains($ref, '..') || preg_match('/\s/', $ref) === 1) {
			throw new InvalidRefException('Ref contains a forbidden sequence');
		}
		// Allow word chars, the path-ish separators git uses for refs, and the
		// single peeling suffix ^{tree} that we append ourselves.
		if (preg_match('#^[A-Za-z0-9._/\-]+(\^\{tree\})?$#', $ref) !== 1) {
			throw new InvalidRefException('Ref contains disallowed characters');
		}
		return $ref;
	}

	/**
	 * Normalize and validate an in-repo path. Returns a clean relative path
	 * with no leading slash and no traversal. Root is represented as "".
	 */
	public function normalizePath(string $path): string {
		$path = str_replace('\\', '/', $path);
		$path = ltrim($path, '/');
		if ($path === '') {
			return '';
		}
		if (\strlen($path) > 4096) {
			throw new InvalidRefException('Path too long');
		}
		// Reject NUL and control characters outright.
		if (preg_match('/[\x00-\x1f]/', $path) === 1) {
			throw new InvalidRefException('Path contains control characters');
		}
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new InvalidRefException('Path traversal is not allowed');
			}
			$segments[] = $segment;
		}
		return implode('/', $segments);
	}
}
