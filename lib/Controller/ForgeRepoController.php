<?php

declare(strict_types=1);

namespace OCA\Lantern\Controller;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Service\ForgeRepoStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Manage the current user's remote-forge (GitHub) repositories (Horizon 3).
 * Per-user; tokens are write-only (stored encrypted, never read back).
 */
class ForgeRepoController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly ForgeRepoStore $store,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function listMine(): JSONResponse {
		$uid = $this->uid();
		return new JSONResponse(['repos' => $uid === null ? [] : $this->store->rawListFor($uid)]);
	}

	#[NoAdminRequired]
	public function addMine(string $kind = 'github', string $slug = '', string $host = '', string $token = '', string $name = '', string $owner = '', string $repo = ''): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}
		// Back-compat: older clients sent owner+repo instead of a slug.
		if ($slug === '' && $owner !== '' && $repo !== '') {
			$slug = trim($owner) . '/' . trim($repo);
		}
		$result = $this->store->addFor($uid, $kind, $slug, $host, $token, $name);
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
