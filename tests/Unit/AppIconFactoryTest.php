<?php

namespace Tests\Unit;

use App\Support\AppIconFactory;
use GdImage;
use PHPUnit\Framework\TestCase;

/**
 * AppIconFactory — pure GD drawing of the Taskr app icon from the geometry
 * of the brand SVG (rounded #026FD7 square, two round-capped chevrons), plus
 * the committed PWA icon set. No raster source needed: the mark is redrawn
 * deterministically at any size.
 */
class AppIconFactoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/app-icon-factory-test-'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Samples the pixel at a viewBox point, scaled to the drawn size.
     *
     * @return array{int, int, int, int}
     */
    private function pixelAt(GdImage $image, int $size, float $x, float $y): array
    {
        $scale = $size / 64;
        $color = imagecolorsforindex($image, imagecolorat($image, (int) round($x * $scale), (int) round($y * $scale)));

        return [$color['red'], $color['green'], $color['blue'], $color['alpha']];
    }

    public function test_draws_the_app_icon_with_rounded_background_and_chevrons(): void
    {
        $icon = (new AppIconFactory)->draw(512);

        $this->assertSame(512, imagesx($icon));
        $this->assertSame(512, imagesy($icon));

        // Outside the rounded corner: fully transparent.
        $this->assertSame([0, 0, 0, 127], $this->pixelAt($icon, 512, 0.5, 0.5));
        // Background inside the square, above the chevrons.
        $this->assertSame([2, 111, 215, 0], $this->pixelAt($icon, 512, 32, 8));
        // Mid-stroke of each chevron.
        $this->assertSame([251, 252, 253, 0], $this->pixelAt($icon, 512, 26.7, 23.05));
        $this->assertSame([144, 186, 241, 0], $this->pixelAt($icon, 512, 26.7, 40.95));

        imagedestroy($icon);
    }

    public function test_draws_the_maskable_variant_with_full_bleed_background(): void
    {
        $icon = (new AppIconFactory)->draw(512, fullBleed: true);

        // Edge-to-edge background: corners and borders are painted.
        $this->assertSame([2, 111, 215, 0], $this->pixelAt($icon, 512, 0.5, 0.5));
        $this->assertSame([2, 111, 215, 0], $this->pixelAt($icon, 512, 32, 0.5));
        $this->assertSame([2, 111, 215, 0], $this->pixelAt($icon, 512, 0.5, 32));
        // The mark stays inside the 80% safe zone: same chevron strokes.
        $this->assertSame([251, 252, 253, 0], $this->pixelAt($icon, 512, 26.7, 23.05));
        $this->assertSame([144, 186, 241, 0], $this->pixelAt($icon, 512, 26.7, 40.95));

        imagedestroy($icon);
    }

    public function test_writes_the_app_icon_set(): void
    {
        $written = (new AppIconFactory)->writeIcons($this->tmpDir);

        $this->assertSame([
            $this->tmpDir.'/apple-touch-icon.png',
            $this->tmpDir.'/android-chrome-192x192.png',
            $this->tmpDir.'/android-chrome-512x512.png',
            $this->tmpDir.'/android-chrome-512x512-maskable.png',
        ], $written);

        foreach ([
            'apple-touch-icon.png' => 180,
            'android-chrome-192x192.png' => 192,
            'android-chrome-512x512.png' => 512,
            'android-chrome-512x512-maskable.png' => 512,
        ] as $file => $size) {
            $image = imagecreatefrompng($this->tmpDir.'/'.$file);
            $this->assertSame($size, imagesx($image), $file.' should be square');
            $this->assertSame($size, imagesy($image), $file.' should be square');
            imagedestroy($image);
        }
    }
}
