<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** One matching line from an in-repo search. */
final class SearchMatch implements \JsonSerializable {

	public function __construct(
		public readonly string $path,
		public readonly int $line,
		public readonly string $text,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'path' => $this->path,
			'line' => $this->line,
			'text' => $this->text,
		];
	}
}
