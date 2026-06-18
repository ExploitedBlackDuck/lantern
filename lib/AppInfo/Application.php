<?php

declare(strict_types=1);

namespace OCA\Lantern\AppInfo;

use OCA\Lantern\Provider\Cache\CachingRepoProvider;
use OCA\Lantern\Provider\Forge\GitHubProvider;
use OCA\Lantern\Provider\Forge\GitLabProvider;
use OCA\Lantern\Provider\IRepoProvider;
use OCA\Lantern\Provider\Local\GitBinary;
use OCA\Lantern\Provider\Local\LocalGitProvider;
use OCA\Lantern\Provider\Local\RefValidator;
use OCA\Lantern\Provider\RepoProviderManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {

	public const APP_ID = 'lantern';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Git binary path is admin-configurable (containers often don't have
		// `git` on a predictable PATH). Defaults to "git" on PATH when unset.
		$context->registerService(GitBinary::class, static function (ContainerInterface $c): GitBinary {
			$configured = $c->get(IAppConfig::class)->getValueString(self::APP_ID, 'git_path', '');
			$gitPath = $configured !== '' ? $configured : 'git';
			return new GitBinary($gitPath, $c->get(LoggerInterface::class));
		});

		$context->registerService(RefValidator::class, static function (): RefValidator {
			return new RefValidator();
		});

		// The set of providers the manager dispatches across. The remote-forge
		// (GitHub) provider slots in here behind the same IRepoProvider seam —
		// the controllers and the entire Vue frontend are unchanged.
		$context->registerService(RepoProviderManager::class, static function (ContainerInterface $c): RepoProviderManager {
			// Wrap each provider in a short-TTL cache so repeated tree/blob/commit/
			// forge reads don't re-fork git or re-hit forge rate limits. The cache
			// prefix is namespaced per user, because forge/user-Files repo IDs are
			// per-user and the distributed cache is shared (no cross-user leakage).
			// createDistributed falls back to a no-op cache when no memcache is
			// configured, so this is transparently safe without Redis/APCu.
			$uid = $c->get(IUserSession::class)->getUser()?->getUID() ?? 'anon';
			$cache = $c->get(ICacheFactory::class)->createDistributed('lantern/' . $uid . '/');
			$wrap = static fn (IRepoProvider $p): IRepoProvider => new CachingRepoProvider($p, $cache);
			return new RepoProviderManager([
				$wrap($c->get(LocalGitProvider::class)),
				$wrap($c->get(GitHubProvider::class)),
				$wrap($c->get(GitLabProvider::class)),
			]);
		});

		// Alias so type-hinting IRepoProvider yields the local one by default.
		$context->registerServiceAlias(IRepoProvider::class, LocalGitProvider::class);

		// Warn admins (under setup checks) when git isn't reachable — the stock
		// Nextcloud Docker image ships without it.
		$context->registerSetupCheck(\OCA\Lantern\SetupCheck\GitAvailable::class);

		// Native integration (Horizon 4): code matches in unified search, and a
		// recent-commits dashboard widget.
		$context->registerSearchProvider(\OCA\Lantern\Search\LanternSearchProvider::class);
		$context->registerDashboardWidget(\OCA\Lantern\Dashboard\RecentCommitsWidget::class);
	}

	public function boot(IBootContext $context): void {
		// Nothing to eager-load in v1.
	}
}
