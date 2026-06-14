<?php

declare(strict_types=1);

namespace OCA\Lantern\Exception;

/**
 * A remote forge rejected the request's credentials — a missing, invalid, or
 * expired token (401), or a token lacking the required scope (403). Kept
 * distinct from RepoNotFoundException so the user is told to fix their token
 * rather than chasing a phantom "not found".
 */
class ForgeAuthException extends RepoException {
}
