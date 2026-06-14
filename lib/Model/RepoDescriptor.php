<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** Identifies one repository that Lantern can browse. */
final class RepoDescriptor implements \JsonSerializable {

	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $path,
		public readonly string $provider = 'local',
	) {
	}

	public function jsonSerialize(): array {
		// Note: the on-disk path is intentionally NOT serialized to clients.
		return [
			'id' => $this->id,
			'name' => $this->name,
			'provider' => $this->provider,
		];
	}
}
