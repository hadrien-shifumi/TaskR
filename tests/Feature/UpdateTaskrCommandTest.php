<?php

namespace Tests\Feature;

use App\Support\ReleaseFetcher;
use App\Support\SourceUpdater;
use Tests\TestCase;

/**
 * taskr:update — checks GitHub for a newer release and, for composer
 * create-project installs (no .git history to `git pull`), downloads and
 * applies it in place: sync files, then offer composer/npm/migrate.
 */
class UpdateTaskrCommandTest extends TestCase
{
    private string $installDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installDir = sys_get_temp_dir().'/taskr-update-cmd-test-'.uniqid();
        mkdir($this->installDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->installDir);

        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }

    private function writeVersion(string $version): void
    {
        file_put_contents($this->installDir.'/VERSION', $version."\n");
    }

    private function fakeLatestRelease(?array $release): void
    {
        $fetcher = new class($release) extends ReleaseFetcher
        {
            /** @param array{version: string, tag: string, tarballUrl: string, url: string}|null $release */
            public function __construct(private readonly ?array $release) {}

            public function latest(): ?array
            {
                return $this->release;
            }
        };

        $this->instance(ReleaseFetcher::class, $fetcher);
    }

    /** @return SourceUpdater&object{calls: array<int, array{tarballUrl: string, targetDir: string}>} */
    private function fakeUpdater(int $filesWritten = 3): SourceUpdater
    {
        $updater = new class($filesWritten) extends SourceUpdater
        {
            /** @var array<int, array{tarballUrl: string, targetDir: string}> */
            public array $calls = [];

            public function __construct(private readonly int $filesWritten) {}

            public function applyRelease(string $tarballUrl, string $targetDir): int
            {
                $this->calls[] = ['tarballUrl' => $tarballUrl, 'targetDir' => $targetDir];
                file_put_contents($targetDir.'/VERSION', "0.2.0\n");

                return $this->filesWritten;
            }
        };

        $this->instance(SourceUpdater::class, $updater);

        return $updater;
    }

    public function test_reports_up_to_date_when_the_latest_release_matches_the_installed_version(): void
    {
        $this->writeVersion('0.2.0');
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);

        $this->artisan('taskr:update', ['--path' => $this->installDir])
            ->expectsOutputToContain('already up to date')
            ->assertSuccessful();
    }

    public function test_check_option_reports_an_available_update_without_applying_it(): void
    {
        $this->writeVersion('0.1.0');
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);
        $updater = $this->fakeUpdater();

        $this->artisan('taskr:update', ['--path' => $this->installDir, '--check' => true])
            ->expectsOutputToContain('0.1.0')
            ->expectsOutputToContain('0.2.0')
            ->assertSuccessful();

        $this->assertSame([], $updater->calls, '--check must never apply the update.');
        $this->assertSame("0.1.0\n", file_get_contents($this->installDir.'/VERSION'));
    }

    public function test_declining_the_confirmation_makes_no_changes(): void
    {
        $this->writeVersion('0.1.0');
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);
        $updater = $this->fakeUpdater();

        $this->artisan('taskr:update', ['--path' => $this->installDir])
            ->expectsConfirmation('Update TaskR to v0.2.0 now?', 'no')
            ->assertSuccessful();

        $this->assertSame([], $updater->calls);
        $this->assertSame("0.1.0\n", file_get_contents($this->installDir.'/VERSION'));
    }

    public function test_confirming_applies_the_release_and_offers_post_steps(): void
    {
        $this->writeVersion('0.1.0');
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);
        $updater = $this->fakeUpdater();

        $this->artisan('taskr:update', ['--path' => $this->installDir])
            ->expectsConfirmation('Update TaskR to v0.2.0 now?', 'yes')
            ->expectsConfirmation('Run `composer install` now?', 'no')
            ->expectsConfirmation('Install npm dependencies and rebuild the frontend now?', 'no')
            ->expectsConfirmation('Run the database migrations now?', 'no')
            ->assertSuccessful();

        $this->assertCount(1, $updater->calls);
        $this->assertSame('https://example.test/release.tar.gz', $updater->calls[0]['tarballUrl']);
        $this->assertSame($this->installDir, $updater->calls[0]['targetDir']);
        $this->assertSame("0.2.0\n", file_get_contents($this->installDir.'/VERSION'));
    }

    public function test_force_option_applies_the_update_without_any_prompt(): void
    {
        $this->writeVersion('0.1.0');
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);
        $updater = $this->fakeUpdater();

        $this->artisan('taskr:update', ['--path' => $this->installDir, '--force' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertCount(1, $updater->calls);
    }

    public function test_aborts_without_force_when_not_running_interactively(): void
    {
        $this->writeVersion('0.1.0');
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);
        $updater = $this->fakeUpdater();

        $this->artisan('taskr:update', ['--path' => $this->installDir, '--no-interaction' => true])
            ->assertSuccessful();

        $this->assertSame([], $updater->calls);
    }

    public function test_aborts_when_no_version_file_is_present(): void
    {
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);

        $this->artisan('taskr:update', ['--path' => $this->installDir])
            ->expectsOutputToContain('No VERSION file found')
            ->assertFailed();
    }

    public function test_aborts_when_a_git_checkout_is_detected(): void
    {
        $this->writeVersion('0.1.0');
        mkdir($this->installDir.'/.git');
        $this->fakeLatestRelease(['version' => '0.2.0', 'tag' => 'v0.2.0', 'tarballUrl' => 'https://example.test/release.tar.gz', 'url' => 'https://example.test/releases/v0.2.0']);

        $this->artisan('taskr:update', ['--path' => $this->installDir])
            ->expectsOutputToContain('git pull')
            ->assertFailed();
    }

    public function test_aborts_when_github_is_unreachable(): void
    {
        $this->writeVersion('0.1.0');
        $this->fakeLatestRelease(null);

        $this->artisan('taskr:update', ['--path' => $this->installDir])
            ->expectsOutputToContain('Could not reach GitHub')
            ->assertFailed();
    }
}
