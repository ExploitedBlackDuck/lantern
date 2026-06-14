<?php

declare(strict_types=1);

namespace OCA\Lantern\Controller;

use OCA\Lantern\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {

	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Render the app shell. The Vue bundle takes over from here and talks to
	 * the JSON API in RepoController.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'lantern-main');
		Util::addStyle(Application::APP_ID, 'main');

		$response = new TemplateResponse(Application::APP_ID, 'main');

		// Code content is rendered as text, never executed; keep the default
		// strict CSP (no inline/eval) so a hostile file can't run in the page.
		$csp = new ContentSecurityPolicy();
		$response->setContentSecurityPolicy($csp);

		return $response;
	}
}
