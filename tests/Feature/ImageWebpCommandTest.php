<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * images:webp artisan command — converts a JPG/PNG input to webp,
 * writes the requested width variants to the output dir, and with
 * --icon writes the site icon set instead.
 */
class ImageWebpCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/image-webp-command-test-'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    /** Creates a 64x40 green JPEG. */
    private function makeJpeg(): string
    {
        $path = $this->tmpDir.'/hero.jpg';
        $image = imagecreatetruecolor(64, 40);
        $green = imagecolorallocate($image, 30, 200, 60);
        imagefilledrectangle($image, 0, 0, 64, 40, $green);
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        return $path;
    }

    public function test_converts_the_input_and_writes_width_variants(): void
    {
        $input = $this->makeJpeg();

        $this->artisan('images:webp', [
            'input' => $input,
            '--output-dir' => $this->tmpDir,
            '--sizes' => '32',
        ])
            ->expectsOutputToContain('hero.webp')
            ->assertSuccessful();

        $this->assertFileExists($this->tmpDir.'/hero.webp');
        $this->assertFileExists($this->tmpDir.'/hero-32.webp');
        $this->assertSame('image/webp', mime_content_type($this->tmpDir.'/hero.webp'));
    }

    public function test_icon_flag_generates_the_site_icon_set(): void
    {
        $input = $this->makeJpeg();

        $this->artisan('images:webp', [
            'input' => $input,
            '--output-dir' => $this->tmpDir,
            '--icon' => true,
        ])->assertSuccessful();

        $this->assertFileExists($this->tmpDir.'/favicon.ico');
        $this->assertFileExists($this->tmpDir.'/favicon-32x32.png');
        $this->assertFileExists($this->tmpDir.'/apple-touch-icon.png');
        $this->assertFileExists($this->tmpDir.'/android-chrome-192x192.png');
        $this->assertFileExists($this->tmpDir.'/android-chrome-512x512.png');
    }

    public function test_fails_with_a_clear_error_for_a_missing_input(): void
    {
        $this->artisan('images:webp', [
            'input' => $this->tmpDir.'/missing.jpg',
            '--output-dir' => $this->tmpDir,
        ])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }
}
