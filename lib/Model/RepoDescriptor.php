<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** Identifies one repository that Lantern can browse. */
final class RepoDescriptor implements \JsonSerializable {

	/**
	 * @param string[] $groups group ids allowed to see this repo; empty = all
	 *                          users. NOT serialized to clients.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $path,
		public readonly string $provider = 'local',
		public readonly array $groups = [],
	) {
	}

	/** True if a user in $userGroups (admin bypasses) may see this repo. */
	public function visibleTo(array $userGroups, bool $isAdmin): bool {
		if ($isAdmin || $this->groups === []) {
			return true;
		}
		return array_intersect($this->groups, $userGroups) !== [];
	}

	public function jsonSerialize(): array {
		// Note: the on-disk path and group restrictions are NOT serialized.
		return [
			'id' => $this->id,
			'name' => $this->name,
			'provider' => $this->provider,
		];
	}
}
