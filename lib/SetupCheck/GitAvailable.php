<?php

declare(strict_types=1);

namespace OCA\Lantern\SetupCheck;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Provider\Local\GitBinary;
use OCP\IAppConfig;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Surfaces a clear admin warning when the git binary Lantern depends on is not
 * available. The stock nextcloud Docker image ships WITHOUT git, so without
 * this check every Lantern API call would fail with no obvious cause. Shows up
 * under Administration settings → Overview → Security & setup warnings.
 */
class GitAvailable implements ISetupCheck {

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly GitBinary $git,
		private readonly \OCP\IL10N $l,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l->t('Lantern: git binary availability');
	}

	public function run(): SetupResult {
		$configured = $this->appConfig->getValueString(Application::APP_ID, 'git_path', '');
		// `git version` doesn't touch any repo, so it's a safe probe. We pass an
		// existing directory as the working dir; the binary either runs or not.
		try {
			$res = $this->git->run(sys_get_temp_dir(), ['version']);
		} catch (\Throwable $e) {
			$res = null;
		}

		if ($res !== null && $res->ok()) {
			return SetupResult::success($this->l->t('git is available (%1$s).', [trim($res->stdout)]));
		}

		$where = $configured !== ''
			? $this->l->t('the configured path "%1$s"', [$configured])
			: $this->l->t('the server PATH');
		return SetupResult::error(
			$this->l->t(
				'Lantern could not run git from %1$s. The git binary must be installed and '
				. 'reachable by the web-server user. Note: the official Nextcloud Docker image '
				. 'does not include git — install it, then set an absolute git path in Lantern\'s '
				. 'admin settings if it is not on PATH.',
				[$where],
			),
		);
	}
}
