<?php

declare(strict_types=1);

namespace OCA\Lantern\Controller;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Service\UserRepoStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Manage the current user's own Files-backed repositories (Horizon 2).
 * Every route is per-user (#[NoAdminRequired]); POSTs are CSRF-protected and
 * @nextcloud/axios supplies the token.
 */
class UserRepoController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly UserRepoStore $store,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function listMine(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['repos' => []]);
		}
		return new JSONResponse(['repos' => $this->store->rawListFor($uid)]);
	}

	#[NoAdminRequired]
	public function validateMine(string $path = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['ok' => false, 'reason' => 'Not signed in.']);
		}
		return new JSONResponse($this->store->validateFor($uid, $path));
	}

	#[NoAdminRequired]
	public function addMine(string $path = '', string $name = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}
		$result = $this->store->addFor($uid, $name, $path);
		if (!$result['ok']) {
			return new JSONResponse(['error' => $result['reason']], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['status' => 'ok', 'id' => $result['id']]);
	}

	#[NoAdminRequired]
	public function removeMine(string $id = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid !== null && $id !== '') {
			$this->store->removeFor($uid, $id);
		}
		return new JSONResponse(['status' => 'ok']);
	}

	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}
}
