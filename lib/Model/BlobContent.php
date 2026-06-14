<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** The contents and metadata of a single file blob. */
final class BlobContent implements \JsonSerializable {

	public function __construct(
		public readonly string $path,
		public readonly int $size,
		public readonly bool $binary,
		public readonly bool $truncated,
		public readonly ?string $content,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'path' => $this->path,
			'size' => $this->size,
			'binary' => $this->binary,
			'truncated' => $this->truncated,
			// content is null for binary files; clients should offer a raw download.
			'content' => $this->content,
		];
	}
}
