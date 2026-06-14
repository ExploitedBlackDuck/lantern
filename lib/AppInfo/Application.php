<?php

declare(strict_types=1);

namespace OCA\Lantern\AppInfo;

use OCA\Lantern\Provider\Forge\GitHubProvider;
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
			return new RepoProviderManager([
				$c->get(LocalGitProvider::class),
				$c->get(GitHubProvider::class),
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
