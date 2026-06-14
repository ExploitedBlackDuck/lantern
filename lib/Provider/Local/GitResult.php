<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider\Local;

/** Immutable result of a single git invocation. */
final class GitResult {

	public function __construct(
		public readonly string $stdout,
		public readonly string $stderr,
		public readonly int $exitCode,
		public readonly bool $truncated = false,
	) {
	}

	public function ok(): bool {
		return $this->exitCode === 0;
	}
}
