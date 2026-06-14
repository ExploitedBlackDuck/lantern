<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** A branch or tag, for the ref picker. */
final class RefInfo implements \JsonSerializable {

	public const TYPE_BRANCH = 'branch';
	public const TYPE_TAG = 'tag';

	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly bool $isDefault = false,
	) {
	}

	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'type' => $this->type,
			'isDefault' => $this->isDefault,
		];
	}
}
