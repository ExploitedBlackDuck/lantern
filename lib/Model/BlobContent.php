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
		/** True when this blob is a Git LFS pointer standing in for a large object. */
		public readonly bool $lfs = false,
		/** The LFS object's sha256 oid (null unless $lfs). */
		public readonly ?string $lfsOid = null,
		/** The real (out-of-band) size the LFS pointer declares, in bytes (null unless $lfs). */
		public readonly ?int $lfsSize = null,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'path' => $this->path,
			'size' => $this->size,
			'binary' => $this->binary,
			'truncated' => $this->truncated,
			// content is null for binary blobs and LFS pointers; clients should
			// offer a raw download (LFS pointers download the pointer text itself).
			'content' => $this->content,
			'lfs' => $this->lfs,
			'lfsOid' => $this->lfsOid,
			'lfsSize' => $this->lfsSize,
		];
	}
}
