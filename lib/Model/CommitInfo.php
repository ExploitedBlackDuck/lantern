<?php

declare(strict_types=1);

namespace OCA\Lantern\Model;

/** Summary of a single commit. */
final class CommitInfo implements \JsonSerializable {

	public function __construct(
		public readonly string $hash,
		public readonly string $authorName,
		public readonly string $authorEmail,
		public readonly string $date,
		public readonly string $subject,
	) {
	}

	public function shortHash(): string {
		return substr($this->hash, 0, 7);
	}

	public function jsonSerialize(): array {
		return [
			'hash' => $this->hash,
			'shortHash' => $this->shortHash(),
			'authorName' => $this->authorName,
			'authorEmail' => $this->authorEmail,
			'date' => $this->date,
			'subject' => $this->subject,
		];
	}
}
