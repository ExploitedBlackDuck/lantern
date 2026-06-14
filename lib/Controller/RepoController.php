<?php

declare(strict_types=1);

namespace OCA\Lantern\Controller;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Exception\InvalidRefException;
use OCA\Lantern\Exception\RepoNotFoundException;
use OCA\Lantern\Provider\RepoProviderManager;
use OCA\Lantern\Service\RepoRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read-only JSON API. Every endpoint resolves the repo through the registry,
 * picks its provider, and returns plain data objects. All ref/path validation
 * happens inside the provider, so this layer stays thin.
 */
class RepoController extends Controller {

	public function __construct(
		IRequest $request,
		private readonly RepoRegistry $registry,
		private readonly RepoProviderManager $providers,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function listRepos(): JSONResponse {
		return new JSONResponse(['repos' => $this->registry->all()]);
	}

	#[NoAdminRequired]
	public function tree(string $repoId, string $ref = '', string $path = ''): JSONResponse {
		return $this->guard(function () use ($repoId, $ref, $path): JSONResponse {
			$repo = $this->registry->get($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			return new JSONResponse([
				'ref' => $ref,
				'path' => $path,
				'entries' => $provider->listTree($repo, $ref, $path),
			]);
		});
	}

	#[NoAdminRequired]
	public function blob(string $repoId, string $path, string $ref = ''): JSONResponse {
		return $this->guard(function () use ($repoId, $ref, $path): JSONResponse {
			$repo = $this->registry->get($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			return new JSONResponse([
				'ref' => $ref,
				'blob' => $provider->getBlob($repo, $ref, $path),
			]);
		});
	}

	#[NoAdminRequired]
	public function commits(string $repoId, string $ref = '', string $path = '', int $limit = 50): JSONResponse {
		return $this->guard(function () use ($repoId, $ref, $path, $limit): JSONResponse {
			$repo = $this->registry->get($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			return new JSONResponse([
				'ref' => $ref,
				'commits' => $provider->listCommits($repo, $ref, $path !== '' ? $path : null, $limit),
			]);
		});
	}

	/**
	 * Translate domain exceptions into clean HTTP statuses and keep internal
	 * error detail out of the response body.
	 *
	 * @param callable():JSONResponse $fn
	 */
	private function guard(callable $fn): JSONResponse {
		try {
			return $fn();
		} catch (InvalidRefException $e) {
			// Log server-side (the message carries useful git detail) but return
			// a generic body so nothing internal leaks to the client.
			$this->logger->debug('lantern: bad request: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Invalid ref or path'], Http::STATUS_BAD_REQUEST);
		} catch (RepoNotFoundException $e) {
			$this->logger->debug('lantern: not found: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			$this->logger->error('lantern: unexpected error', ['exception' => $e]);
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
