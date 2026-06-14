<?php

declare(strict_types=1);

namespace OCA\Lantern\Settings;

use OCA\Lantern\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {

	public function __construct(
		private readonly IURLGenerator $url,
		private readonly \OCP\IL10N $l,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Lantern');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->url->imagePath(Application::APP_ID, 'app.svg');
	}
}
