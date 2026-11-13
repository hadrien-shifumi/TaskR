<?php

namespace Tests\Unit;

use App\Support\SourceUpdater;
use Illuminate\Support\Facades\Http;
use PharData;
use Tests\TestCase;

/**
 * SourceUpdater — downloads a GitHub release tarball, extracts it, and
 * copies its tracked files over an installation directory. This is the
 * file-level equivalent of `git pull` for `composer create-project`
 * installs, which have no .git history to fast-forward.
 */
class SourceUpdaterTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/taskr-source-updater-test-'.uniqid();
        mkdir($this->sandbox, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->sandbox);

        parent::tearDown();
    }

    /** Builds a .tar.gz release archive containing a single top-level folder, as GitHub produces. */
    private function fakeReleaseArchive(): string
    {
        $staging = $this->sandbox.'/staging';
        $releaseDir = $staging.'/hadrien-shifumi-TaskR-abc1234';
        mkdir($releaseDir.'/app', 0777, true);

        file_put_contents($releaseDir.'/VERSION', "0.2.0\n");
        file_put_contents($releaseDir.'/composer.json', '{"name":"hadrien-shifumi/taskr"}');
        file_put_contents($releaseDir.'/app/new-file.php', '<?php // new in 0.2.0');

        $tarPath = $this->sandbox.'/release.tar';
        $phar = new PharData($tarPath);
        $phar->buildFromDirectory($staging);
        $phar->compress(\Phar::GZ);
        unlink($tarPath);

        return $tarPath.'.gz';
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

    public function test_apply_release_downloads_extracts_and_copies_files_into_the_target_directory(): void
    {
        $archive = $this->fakeReleaseArchive();

        Http::fake([
            'example.test/release.tar.gz' => Http::response(file_get_contents($archive)),
        ]);

        $target = $this->sandbox.'/install';
        mkdir($target, 0777, true);

        $written = app(SourceUpdater::class)->applyRelease('https://example.test/release.tar.gz', $target);

        $this->assertSame(3, $written);
        $this->assertSame("0.2.0\n", file_get_contents($target.'/VERSION'));
        $this->assertSame('{"name":"hadrien-shifumi/taskr"}', file_get_contents($target.'/composer.json'));
        $this->assertSame('<?php // new in 0.2.0', file_get_contents($target.'/app/new-file.php'));
    }

    public function test_apply_release_overwrites_existing_tracked_files_without_touching_untracked_ones(): void
    {
        $archive = $this->fakeReleaseArchive();

        Http::fake([
            'example.test/release.tar.gz' => Http::response(file_get_contents($archive)),
        ]);

        $target = $this->sandbox.'/install';
        mkdir($target.'/vendor', 0777, true);
        file_put_contents($target.'/VERSION', "0.1.0\n");
        file_put_contents($target.'/.env', 'APP_KEY=base64:local-secret');
        file_put_contents($target.'/vendor/autoload.php', '<?php // untouched vendor file');

        app(SourceUpdater::class)->applyRelease('https://example.test/release.tar.gz', $target);

        $this->assertSame("0.2.0\n", file_get_contents($target.'/VERSION'));
        $this->assertSame('APP_KEY=base64:local-secret', file_get_contents($target.'/.env'));
        $this->assertSame('<?php // untouched vendor file', file_get_contents($target.'/vendor/autoload.php'));
    }

    public function test_apply_release_throws_when_the_download_fails(): void
    {
        Http::fake([
            'example.test/release.tar.gz' => Http::response('not found', 404),
        ]);

        $target = $this->sandbox.'/install';
        mkdir($target, 0777, true);

        $this->expectException(\RuntimeException::class);

        app(SourceUpdater::class)->applyRelease('https://example.test/release.tar.gz', $target);
    }

    public function test_apply_release_cleans_up_its_temporary_working_directory(): void
    {
        $archive = $this->fakeReleaseArchive();

        Http::fake([
            'example.test/release.tar.gz' => Http::response(file_get_contents($archive)),
        ]);

        $target = $this->sandbox.'/install';
        mkdir($target, 0777, true);

        $before = glob(sys_get_temp_dir().'/taskr-update-*');

        app(SourceUpdater::class)->applyRelease('https://example.test/release.tar.gz', $target);

        $after = glob(sys_get_temp_dir().'/taskr-update-*');

        $this->assertSame($before, $after, 'No leftover temp working directory should remain.');
    }
}
