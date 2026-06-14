<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** One line of `git blame` output: which commit/author last touched it. */
final class BlameLine implements \JsonSerializable {

	public function __construct(
		public readonly int $line,
		public readonly string $hash,
		public readonly string $author,
		public readonly string $date,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'line' => $this->line,
			'hash' => substr($this->hash, 0, 7),
			'author' => $this->author,
			'date' => $this->date,
		];
	}
}
