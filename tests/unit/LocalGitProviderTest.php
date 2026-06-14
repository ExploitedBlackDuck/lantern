<?php

declare(strict_types=1);

namespace OCA\Lantern\Tests\Unit;

use OCA\Lantern\Exception\RepoException;
use OCA\Lantern\Model\RepoDescriptor;
use OCA\Lantern\Provider\Local\GitBinary;
use OCA\Lantern\Provider\Local\LocalGitProvider;
use OCA\Lantern\Provider\Local\RefValidator;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__) . '/fixtures.php';

/**
 * PHPUnit wrapper over the framework-free git core. Mirrors the assertions in
 * tests/run-core-tests.php so the same coverage runs in CI once
 * `composer require --dev phpunit/phpunit` is installed.
 */
final class LocalGitProviderTest extends TestCase {

	private string $repoPath = '';
	private LocalGitProvider $provider;
	private RepoDescriptor $repo;

	protected function setUp(): void {
		$this->repoPath = buildFixtureRepo();
		$this->provider = new LocalGitProvider(new GitBinary('git', null), new RefValidator());
		$this->repo = new RepoDescriptor('test', 'Test Repo', $this->repoPath, 'local');
	}

	protected function tearDown(): void {
		destroyRepo($this->repoPath);
	}

	public function testListsRootDirectoriesFirst(): void {
		$names = array_map(static fn ($e) => $e->name, $this->provider->listTree($this->repo, 'master', ''));
		self::assertSame(['assets', 'docs', 'src', 'README.md'], $names);
	}

	public function testReadsTextBlob(): void {
		$blob = $this->provider->getBlob($this->repo, 'master', 'README.md');
		self::assertFalse($blob->binary);
		self::assertStringContainsString('# Lantern Test', (string) $blob->content);
	}

	public function testSuppressesBinaryContent(): void {
		$blob = $this->provider->getBlob($this->repo, 'master', 'assets/logo.bin');
		self::assertTrue($blob->binary);
		self::assertNull($blob->content);
	}

	public function testCommitHistoryNewestFirst(): void {
		$commits = $this->provider->listCommits($this->repo, 'master', null, 50);
		self::assertCount(3, $commits);
		self::assertSame('expand README', $commits[0]->subject);
	}

	/** @dataProvider maliciousRefs */
	public function testRejectsMaliciousRefs(string $ref): void {
		$this->expectException(RepoException::class);
		$this->provider->listTree($this->repo, $ref, '');
	}

	public static function maliciousRefs(): array {
		return [['--upload-pack=evil'], ['-x'], ['a..b'], ["main\nrm"], ['../etc']];
	}

	/** @dataProvider traversalPaths */
	public function testRejectsPathTraversal(string $path): void {
		$this->expectException(RepoException::class);
		$this->provider->getBlob($this->repo, 'master', $path);
	}

	public static function traversalPaths(): array {
		return [['../../../etc/passwd'], ['..'], ['src/../../escape']];
	}

	public function testDescriptorJsonOmitsPath(): void {
		self::assertStringNotContainsString($this->repoPath, (string) json_encode($this->repo));
	}
}
