<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/**
 * Raw bytes of a blob, for download / image preview. Unlike {@see BlobContent}
 * this carries the actual bytes verbatim (no binary suppression) and is NOT
 * JsonSerializable — it is streamed straight to the client by the controller.
 */
final class RawBlob {

	public function __construct(
		public readonly string $path,
		public readonly int $size,
		public readonly string $content,
		public readonly bool $truncated,
	) {
	}
}
