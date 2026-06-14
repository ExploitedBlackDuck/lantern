<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** One entry (file or directory) inside a tree listing. */
final class TreeEntry implements \JsonSerializable {

	public const TYPE_BLOB = 'blob';
	public const TYPE_TREE = 'tree';
	public const TYPE_COMMIT = 'commit'; // submodule

	public function __construct(
		public readonly string $name,
		public readonly string $path,
		public readonly string $type,
		public readonly string $mode,
		public readonly string $oid,
		public readonly ?int $size,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'path' => $this->path,
			'type' => $this->type,
			'mode' => $this->mode,
			'oid' => $this->oid,
			'size' => $this->size,
		];
	}
}
