<?php

declare(strict_types=1);

namespace OCA\Lantern\Exception;

/** A configured repository, or a path/ref within one, does not exist. */
class RepoNotFoundException extends RepoException {
}
