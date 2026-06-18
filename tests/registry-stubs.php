<?php

declare(strict_types=1);

/**
 * Minimal interface stubs so RepoRegistry — which is framework-COUPLED (it
 * type-hints OCP\IAppConfig and Psr\Log\LoggerInterface) — can be unit-tested
 * by the framework-free runner with no Nextcloud install.
 *
 * Each declaration is guarded so that under the real PHPUnit-on-Nextcloud path
 * (where the genuine interfaces exist) these are skipped and the real ones win.
 * Only the methods RepoRegistry actually calls are declared.
 */

namespace OCP {
	if (!interface_exists(IAppConfig::class)) {
		interface IAppConfig {
			public function getValueString(string $app, string $key, string $default = ''): string;
		}
	}
}

namespace OCP {
	if (!interface_exists(ICache::class)) {
		// Only the two methods CachingRepoProvider actually calls.
		interface ICache {
			public function get($key);
			public function set($key, $value, $ttl = 0): bool;
		}
	}
}

namespace Psr\Log {
	if (!interface_exists(LoggerInterface::class)) {
		interface LoggerInterface {
			public function warning($message, array $context = []): void;
		}
	}
}
