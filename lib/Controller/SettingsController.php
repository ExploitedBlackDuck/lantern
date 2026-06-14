<?php

declare(strict_types=1);

namespace OCA\Lantern\Controller;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Service\RepoRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Admin-only settings persistence.
 *
 * Replaces the earlier attempt to save through the provisioning_api OCS
 * endpoint (which required generateOcsUrl + the OCS-APIRequest header and was
 * effectively unusable from a plain index.php-routed page). This is a normal
 * authenticated app route: by omitting #[NoAdminRequired] it is admin-only,
 * and @nextcloud/axios attaches the CSRF token automatically. It also lets us
 * validate the repository JSON on the server before storing it.
 */
class SettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly RepoRegistry $registry,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Admin-only "Test path" check: report whether a candidate path is a real
	 * git repository (and, if the admin is editing one, inside the allowed
	 * base) before they commit to saving. Admin-only by omitting
	 * #[NoAdminRequired]; CSRF token carried by @nextcloud/axios.
	 *
	 * @param string $path        candidate repository path
	 * @param string $allowedBase base currently in the form (unsaved)
	 */
	public function validatePath(string $path = '', string $allowedBase = ''): JSONResponse {
		return new JSONResponse($this->registry->validatePath($path, $allowedBase));
	}

	/**
	 * Persist the repository list and optional allowed base directory.
	 *
	 * @param string $repos        JSON array of {id, name, path}
	 * @param string $allowedBase  optional base directory
	 */
	public function save(string $repos = '[]', string $allowedBase = '', string $gitPath = ''): JSONResponse {
		$decoded = json_decode($repos, true);
		if (!\is_array($decoded)) {
			return new JSONResponse(['error' => 'Repositories must be a JSON array'], Http::STATUS_BAD_REQUEST);
		}

		$gitPath = trim($gitPath);
		if ($gitPath !== '') {
			// If set, require an absolute path to an executable file — never a
			// bare name or a relative path that could resolve unexpectedly.
			if (!str_starts_with($gitPath, '/') || !is_file($gitPath) || !is_executable($gitPath)) {
				return new JSONResponse(['error' => 'Git path must be an absolute path to an executable'], Http::STATUS_BAD_REQUEST);
			}
		}

		$clean = [];
		$seen = [];
		foreach ($decoded as $row) {
			if (!\is_array($row) || !isset($row['id'], $row['name'], $row['path'])) {
				return new JSONResponse(['error' => 'Each repository needs id, name and path'], Http::STATUS_BAD_REQUEST);
			}
			$id = (string) $row['id'];
			if ($id === '' || isset($seen[$id])) {
				return new JSONResponse(['error' => 'Repository ids must be present and unique'], Http::STATUS_BAD_REQUEST);
			}
			$seen[$id] = true;
			$groups = [];
			if (isset($row['groups']) && \is_array($row['groups'])) {
				$groups = array_values(array_filter(array_map(static fn ($g) => trim((string) $g), $row['groups']), static fn (string $g) => $g !== ''));
			}
			$clean[] = [
				'id' => $id,
				'name' => (string) $row['name'],
				'path' => (string) $row['path'],
				'groups' => $groups,
			];
		}

		$this->appConfig->setValueString(Application::APP_ID, 'repos', json_encode($clean));
		$this->appConfig->setValueString(Application::APP_ID, 'allowed_base', trim($allowedBase));
		$this->appConfig->setValueString(Application::APP_ID, 'git_path', $gitPath);

		return new JSONResponse(['status' => 'ok', 'count' => \count($clean)]);
	}
}
