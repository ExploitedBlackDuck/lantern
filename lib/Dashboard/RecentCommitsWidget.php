<?php

declare(strict_types=1);

namespace OCA\Lantern\Dashboard;

use OCA\Lantern\AppInfo\Application;
use OCA\Lantern\Model\CommitInfo;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Provider\RepoProviderManager;
use OCA\Lantern\Service\RepoRegistry;
use OCA\Lantern\Service\UserRepoStore;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * "Recent commits" dashboard widget (Horizon 4): the newest commit from each of
 * the user's visible local-backed repos, rendered by Nextcloud's generic API
 * widget UI. Bounded for snappiness; remote-forge repos are skipped.
 */
class RecentCommitsWidget implements IAPIWidget {

	private const MAX_REPOS = 10;

	public function __construct(
		private readonly RepoRegistry $registry,
		private readonly UserRepoStore $userStore,
		private readonly RepoProviderManager $providers,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IURLGenerator $url,
		private readonly \OCP\IL10N $l,
	) {
	}

	public function getId(): string {
		return 'lantern-recent';
	}

	public function getTitle(): string {
		return $this->l->t('Lantern — recent commits');
	}

	public function getOrder(): int {
		return 30;
	}

	public function getIconClass(): string {
		return 'icon-category-organization';
	}

	public function getUrl(): ?string {
		return $this->url->linkToRoute('lantern.page.index');
	}

	public function load(): void {
		// Rendered by the generic API-widget UI; no per-widget script needed.
	}

	/**
	 * @return WidgetItem[]
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}
		$isAdmin = $this->groupManager->isAdmin($userId);
		$groups = $this->groupManager->getUserGroupIds($user);

		$repos = array_filter(
			$this->registry->all(),
			static fn (RepoDescriptor $r) => $r->visibleTo($groups, $isAdmin),
		);
		$repos = array_merge(array_values($repos), $this->userStore->listFor($userId));

		$items = [];
		foreach (array_slice($repos, 0, self::MAX_REPOS) as $repo) {
			try {
				$provider = $this->providers->forRepo($repo);
				$ref = $provider->defaultRef($repo);
				$commits = $provider->listCommits($repo, $ref, null, 1, 0);
				if ($commits === []) {
					continue;
				}
				/** @var CommitInfo $c */
				$c = $commits[0];
				$items[] = new WidgetItem(
					$repo->name . ': ' . $c->subject,
					$c->authorName . ' · ' . $c->shortHash(),
					$this->url->linkToRoute('lantern.page.index') . '?repo=' . rawurlencode($repo->id),
					'',
					$c->date,
				);
			} catch (\Throwable $e) {
				continue;
			}
		}
		// Newest first, then cap to the requested limit.
		usort($items, static fn (WidgetItem $a, WidgetItem $b) => strcmp($b->getSinceId(), $a->getSinceId()));
		return \array_slice($items, 0, max(1, $limit));
	}
}
