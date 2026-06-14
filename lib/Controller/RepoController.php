<?php

declare(strict_types=1);

namespace OCA\Lantern\Controller;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Exception\InvalidRefException;
use OCA\Lantern\Exception\RepoNotFoundException;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Provider\RepoProviderManager;
use OCA\Lantern\Service\ForgeRepoStore;
use OCA\Lantern\Service\RepoRegistry;
use OCA\Lantern\Service\UserRepoStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Read-only JSON API. Every endpoint resolves the repo through the registry,
 * picks its provider, and returns plain data objects. All ref/path validation
 * happens inside the provider, so this layer stays thin.
 */
class RepoController extends Controller {

	/** Cap on raw blob reads (download / image preview), larger than the inline cap. */
	private const MAX_RAW_BYTES = 25 * 1024 * 1024; // 25 MiB

	/**
	 * Extensions we are willing to serve INLINE as images. Raster only — note
	 * SVG is deliberately excluded (it can carry script and would render in the
	 * browser); SVGs fall through to an attachment download instead.
	 */
	private const INLINE_IMAGE_MIME = [
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'bmp' => 'image/bmp',
		'ico' => 'image/x-icon',
		'avif' => 'image/avif',
	];

	public function __construct(
		IRequest $request,
		private readonly RepoRegistry $registry,
		private readonly RepoProviderManager $providers,
		private readonly UserRepoStore $userStore,
		private readonly ForgeRepoStore $forgeStore,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function listRepos(): JSONResponse {
		// Admin server-side repos (H1, filtered by per-repo group restrictions)
		// + the user's own Files repos (H2) + the user's remote-forge repos (H3).
		[$isAdmin, $groups] = $this->userContext();
		$repos = array_values(array_filter(
			$this->registry->all(),
			static fn ($r) => $r->visibleTo($groups, $isAdmin),
		));
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid !== null) {
			$repos = array_merge($repos, $this->userStore->listFor($uid), $this->forgeStore->listFor($uid));
		}
		return new JSONResponse(['repos' => $repos]);
	}

	/** @return array{0: bool, 1: string[]} [isAdmin, userGroupIds] */
	private function userContext(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return [false, []];
		}
		return [
			$this->groupManager->isAdmin($user->getUID()),
			$this->groupManager->getUserGroupIds($user),
		];
	}

	/**
	 * Resolve a repo id from the admin registry first, then the current user's
	 * Files repos. Throws RepoNotFoundException if neither has it.
	 */
	private function resolveRepo(string $repoId): RepoDescriptor {
		try {
			$repo = $this->registry->get($repoId);
			// Enforce per-repo group restrictions on direct access too — a user
			// must not reach a restricted repo by guessing its id. 404, not 403,
			// so we don't reveal that a hidden repo exists.
			[$isAdmin, $groups] = $this->userContext();
			if (!$repo->visibleTo($groups, $isAdmin)) {
				throw new RepoNotFoundException('Not visible to this user');
			}
			return $repo;
		} catch (RepoNotFoundException $e) {
			$uid = $this->userSession->getUser()?->getUID();
			if ($uid !== null) {
				$mine = $this->userStore->getFor($uid, $repoId);
				if ($mine !== null) {
					return $mine;
				}
				$forge = $this->forgeStore->getFor($uid, $repoId);
				if ($forge !== null) {
					return $forge;
				}
			}
			throw $e;
		}
	}

	#[NoAdminRequired]
	public function tree(string $repoId, string $ref = '', string $path = ''): JSONResponse {
		return $this->guard(function () use ($repoId, $ref, $path): JSONResponse {
			$repo = $this->resolveRepo($repoId);
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
			$repo = $this->resolveRepo($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			return new JSONResponse([
				'ref' => $ref,
				'blob' => $provider->getBlob($repo, $ref, $path),
			]);
		});
	}

	#[NoAdminRequired]
	public function commits(string $repoId, string $ref = '', string $path = '', int $limit = 50, int $offset = 0): JSONResponse {
		return $this->guard(function () use ($repoId, $ref, $path, $limit, $offset): JSONResponse {
			$repo = $this->resolveRepo($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			$limit = max(1, min($limit, 200));
			$offset = max(0, $offset);
			// Fetch one extra to detect whether a further page exists.
			$rows = $provider->listCommits($repo, $ref, $path !== '' ? $path : null, $limit + 1, $offset);
			$hasMore = \count($rows) > $limit;
			if ($hasMore) {
				$rows = \array_slice($rows, 0, $limit);
			}
			return new JSONResponse([
				'ref' => $ref,
				'commits' => $rows,
				'offset' => $offset,
				'hasMore' => $hasMore,
			]);
		});
	}

	#[NoAdminRequired]
	public function refs(string $repoId): JSONResponse {
		return $this->guard(function () use ($repoId): JSONResponse {
			$repo = $this->resolveRepo($repoId);
			$provider = $this->providers->forRepo($repo);
			return new JSONResponse(['refs' => $provider->listRefs($repo)]);
		});
	}

	#[NoAdminRequired]
	public function search(string $repoId, string $q = '', string $ref = '', int $limit = 100): JSONResponse {
		return $this->guard(function () use ($repoId, $q, $ref, $limit): JSONResponse {
			$repo = $this->resolveRepo($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			return new JSONResponse([
				'ref' => $ref,
				'matches' => $provider->search($repo, $ref, $q, $limit),
			]);
		});
	}

	#[NoAdminRequired]
	public function diff(string $repoId, string $ref): JSONResponse {
		return $this->guard(function () use ($repoId, $ref): JSONResponse {
			$repo = $this->resolveRepo($repoId);
			$provider = $this->providers->forRepo($repo);
			return new JSONResponse(['ref' => $ref, 'diff' => $provider->getCommitDiff($repo, $ref)]);
		});
	}

	#[NoAdminRequired]
	public function blame(string $repoId, string $path, string $ref = ''): JSONResponse {
		return $this->guard(function () use ($repoId, $ref, $path): JSONResponse {
			$repo = $this->resolveRepo($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			return new JSONResponse(['ref' => $ref, 'blame' => $provider->blame($repo, $ref, $path)]);
		});
	}

	/**
	 * Serve a blob's raw bytes for download, or inline preview of raster images.
	 * Returns a non-JSON response, so it has its own error handling rather than
	 * going through guard(). Hardened: nosniff + strict CSP, and only raster
	 * images are served inline (everything else, including SVG, downloads).
	 */
	#[NoAdminRequired]
	public function raw(string $repoId, string $path, string $ref = '', int $download = 0): DataDisplayResponse {
		try {
			$repo = $this->resolveRepo($repoId);
			$provider = $this->providers->forRepo($repo);
			$ref = $ref !== '' ? $ref : $provider->defaultRef($repo);
			$raw = $provider->getBlobRaw($repo, $ref, $path, self::MAX_RAW_BYTES);

			$ext = strtolower(pathinfo($raw->path, PATHINFO_EXTENSION));
			$inlineImage = $download === 0 && isset(self::INLINE_IMAGE_MIME[$ext]);
			$mime = $inlineImage ? self::INLINE_IMAGE_MIME[$ext] : 'application/octet-stream';
			$filename = str_replace(['"', "\r", "\n"], '', basename($raw->path));

			$resp = new DataDisplayResponse($raw->content, Http::STATUS_OK, ['Content-Type' => $mime]);
			$resp->addHeader('Content-Disposition', ($inlineImage ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
			$resp->addHeader('X-Content-Type-Options', 'nosniff');
			// Strictest possible CSP so even a coerced content-type cannot execute.
			$resp->setContentSecurityPolicy(new ContentSecurityPolicy());
			return $resp;
		} catch (InvalidRefException $e) {
			$this->logger->debug('lantern: raw bad request: ' . $e->getMessage());
			return new DataDisplayResponse('Invalid ref or path', Http::STATUS_BAD_REQUEST, ['Content-Type' => 'text/plain']);
		} catch (RepoNotFoundException $e) {
			$this->logger->debug('lantern: raw not found: ' . $e->getMessage());
			return new DataDisplayResponse('Not found', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain']);
		} catch (\Throwable $e) {
			$this->logger->error('lantern: unexpected raw error', ['exception' => $e]);
			return new DataDisplayResponse('Internal error', Http::STATUS_INTERNAL_SERVER_ERROR, ['Content-Type' => 'text/plain']);
		}
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
