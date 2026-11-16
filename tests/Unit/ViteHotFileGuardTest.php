<?php

namespace Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * ensureBuiltAssetsForBrowserSuite() — the fail-fast guard wired into every
 * Browser test: a live Vite dev server (public/hot) makes the suite serve
 * unbundled assets where import.meta.env.PROD is false, silently disabling
 * build-only behaviours (service worker registration) behind opaque 5s
 * timeouts. The guard turns that into an immediate, actionable failure.
 *
 * Named without the word "Browser": Pest's ->in('Browser') scoping matches
 * test paths by substring, so a "Browser"-prefixed filename in Unit would
 * pull the guard's beforeEach into the Unit suite.
 */
class ViteHotFileGuardTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/vite-hot-file-guard-test-'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpDir.'/hot');
        @rmdir($this->tmpDir);
    }

    public function test_passes_when_no_hot_file_exists(): void
    {
        ensureBuiltAssetsForBrowserSuite($this->tmpDir.'/hot');

        $this->addToAssertionCount(1);
    }

    public function test_fails_with_an_actionable_message_when_the_hot_file_exists(): void
    {
        file_put_contents($this->tmpDir.'/hot', 'http://[::1]:5174');

        try {
            ensureBuiltAssetsForBrowserSuite($this->tmpDir.'/hot');
            $this->fail('The guard must fail when the Vite hot file exists.');
        } catch (AssertionFailedError $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString('public/hot', $message);
            $this->assertStringContainsString('dev server', $message);
            $this->assertStringContainsString('npm run build', $message);
        }
    }
}
