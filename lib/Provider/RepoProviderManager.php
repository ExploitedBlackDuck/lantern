<?php

declare(strict_types=1);

namespace OCA\Lantern\Provider;

use OCA\Lantern\Exception\RepoException;
use OCA\Lantern\Model\RepoDescriptor;

/**
 * Maps a repository to the provider that knows how to read it.
 *
 * This is the dispatch point the controllers use, so they never care whether
 * a repo is local or (in a future version) a remote forge. To add a backend,
 * register another {@see IRepoProvider} keyed by its getKey() value — nothing
 * upstream of here needs to change.
 */
class RepoProviderManager {

	/** @var array<string, IRepoProvider> */
	private array $providers = [];

	/**
	 * @param iterable<IRepoProvider> $providers injected by the DI container
	 */
	public function __construct(iterable $providers) {
		foreach ($providers as $provider) {
			$this->providers[$provider->getKey()] = $provider;
		}
	}

	public function forRepo(RepoDescriptor $repo): IRepoProvider {
		if (!isset($this->providers[$repo->provider])) {
			throw new RepoException('No provider registered for: ' . $repo->provider);
		}
		return $this->providers[$repo->provider];
	}
}
