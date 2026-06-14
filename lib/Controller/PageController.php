<?php

declare(strict_types=1);

namespace OCA\Lantern\Controller;

use OCA\Lantern\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IURLGenerator $urlGenerator,
	) {
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

		// Tell the frontend whether the current user can configure repos and
		// where, so the empty state can guide them (add a repo) vs. (ask your
		// admin) instead of rendering a dead, unexplained blank page.
		$user = $this->userSession->getUser();
		$isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());
		$settingsUrl = $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID]);

		$response = new TemplateResponse(Application::APP_ID, 'main', [
			'is_admin' => $isAdmin,
			'settings_url' => $settingsUrl,
		]);

		// Code content is rendered as text, never executed; keep the default
		// strict CSP (no inline/eval) so a hostile file can't run in the page.
		$csp = new ContentSecurityPolicy();
		$response->setContentSecurityPolicy($csp);

		return $response;
	}
}
