<?php

declare(strict_types=1);

namespace OCA\Lantern\Exception;

/**
 * A remote forge (e.g. GitHub) refused the request because a rate limit was
 * hit. Distinct from RepoNotFoundException so the UI can surface an honest
 * "rate-limited, try again later" state instead of a misleading "not found".
 */
class RateLimitException extends RepoException {
}
