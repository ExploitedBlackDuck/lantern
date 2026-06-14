<?php

declare(strict_types=1);

namespace OCA\Lantern\Settings;

use OCA\Lantern\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;

/**
 * Admin page where the configured repositories (and optional allowed base
 * directory) are managed. The form posts back to the standard app-config
 * mechanism; for v1 the template renders the current JSON for editing.
 */
class AdminSettings implements ISettings {

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function getForm(): TemplateResponse {
		$params = [
			'repos' => $this->appConfig->getValueString(Application::APP_ID, 'repos', '[]'),
			'allowed_base' => $this->appConfig->getValueString(Application::APP_ID, 'allowed_base', ''),
			'git_path' => $this->appConfig->getValueString(Application::APP_ID, 'git_path', ''),
		];
		return new TemplateResponse(Application::APP_ID, 'admin', $params);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
