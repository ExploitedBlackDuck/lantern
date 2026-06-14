<?php

declare(strict_types=1);

namespace OCA\Lantern\Search;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Provider\RepoProviderManager;
use OCA\Lantern\Service\RepoRegistry;
use OCA\Lantern\Service\UserRepoStore;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Surfaces in-repo code matches in Nextcloud's global (unified) search bar
 * (Horizon 4). Scoped to the user's visible LOCAL-backed repos (admin repos
 * they may see + their own Files repos); remote-forge repos are skipped to keep
 * search fast and network-free.
 */
class LanternSearchProvider implements IProvider {

	/** Bound the work: a global search must stay snappy. */
	private const MAX_REPOS = 15;
	private const MATCHES_PER_REPO = 5;

	public function __construct(
		private readonly RepoRegistry $registry,
		private readonly UserRepoStore $userStore,
		private readonly RepoProviderManager $providers,
		private readonly IGroupManager $groupManager,
		private readonly IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Lantern';
	}

	public function getOrder(string $route, array $routeParameters): int {
		return 55;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		$entries = [];
		if ($term !== '') {
			$isAdmin = $this->groupManager->isAdmin($user->getUID());
			$groups = $this->groupManager->getUserGroupIds($user);

			$repos = array_filter(
				$this->registry->all(),
				static fn (RepoDescriptor $r) => $r->visibleTo($groups, $isAdmin),
			);
			$repos = array_merge(array_values($repos), $this->userStore->listFor($user->getUID()));

			foreach (array_slice($repos, 0, self::MAX_REPOS) as $repo) {
				try {
					$provider = $this->providers->forRepo($repo);
					$ref = $provider->defaultRef($repo);
					foreach ($provider->search($repo, $ref, $term, self::MATCHES_PER_REPO) as $m) {
						$link = $this->url->linkToRoute('lantern.page.index')
							. '?repo=' . rawurlencode($repo->id)
							. '&ref=' . rawurlencode($ref)
							. '&blob=' . rawurlencode($m->path)
							. ($m->line > 0 ? '#L' . $m->line : '');
						$entries[] = new SearchResultEntry(
							'',
							$repo->name . ' / ' . $m->path . ($m->line > 0 ? ':' . $m->line : ''),
							$m->text,
							$link,
							'icon-search',
						);
					}
				} catch (\Throwable $e) {
					// Skip a repo that can't be searched rather than fail the lot.
					continue;
				}
			}
		}
		return SearchResult::complete($this->getName(), $entries);
	}
}
