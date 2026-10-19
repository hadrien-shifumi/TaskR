<?php

namespace Tests\Unit;

use App\Support\WebpConverter;
use PHPUnit\Framework\TestCase;

/**
 * WebpConverter — pure GD conversion of a JPG/PNG input into webp
 * variants (requested widths, aspect ratio kept, no upscaling) plus,
 * via generateIcons(), the site icon set (favicon.ico + PNG icons).
 */
class WebpConverterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/webp-converter-test-'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /** Creates a 64x40 red JPEG. */
    private function makeJpeg(): string
    {
        $path = $this->tmpDir.'/photo.jpg';
        $image = imagecreatetruecolor(64, 40);
        $red = imagecolorallocate($image, 220, 30, 30);
        imagefilledrectangle($image, 0, 0, 64, 40, $red);
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        return $path;
    }

    /** Creates a 20x20 PNG whose top-left quadrant is fully transparent. */
    private function makeTransparentPng(): string
    {
        $path = $this->tmpDir.'/sprite.png';
        $image = imagecreatetruecolor(20, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $blue = imagecolorallocate($image, 30, 30, 220);
        imagefilledrectangle($image, 0, 0, 20, 20, $blue);
        imagefilledrectangle($image, 0, 0, 10, 10, $transparent);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    public function test_converts_a_jpeg_to_webp(): void
    {
        $input = $this->makeJpeg();
        $written = (new WebpConverter)->convert($input, $this->tmpDir);

        $this->assertSame([$this->tmpDir.'/photo.webp'], $written);
        $this->assertFileExists($this->tmpDir.'/photo.webp');
        $this->assertSame('image/webp', mime_content_type($this->tmpDir.'/photo.webp'));

        $image = imagecreatefromwebp($this->tmpDir.'/photo.webp');
        $this->assertSame(64, imagesx($image));
        $this->assertSame(40, imagesy($image));
        imagedestroy($image);
    }

    public function test_converts_a_png_preserving_alpha(): void
    {
        $input = $this->makeTransparentPng();
        (new WebpConverter)->convert($input, $this->tmpDir);

        $image = imagecreatefromwebp($this->tmpDir.'/sprite.webp');
        $pixel = imagecolorsforindex($image, imagecolorat($image, 2, 2));

        $this->assertGreaterThan(100, $pixel['alpha'], 'top-left quadrant should stay transparent');
        imagedestroy($image);
    }

    public function test_generates_requested_widths_keeping_aspect_ratio(): void
    {
        $input = $this->makeJpeg();
        $written = (new WebpConverter)->convert($input, $this->tmpDir, [32, 16]);

        $this->assertSame([
            $this->tmpDir.'/photo.webp',
            $this->tmpDir.'/photo-32.webp',
            $this->tmpDir.'/photo-16.webp',
        ], $written);

        $wide = imagecreatefromwebp($this->tmpDir.'/photo-32.webp');
        $this->assertSame(32, imagesx($wide));
        $this->assertSame(20, imagesy($wide), 'aspect ratio should be kept');
        imagedestroy($wide);

        $narrow = imagecreatefromwebp($this->tmpDir.'/photo-16.webp');
        $this->assertSame(16, imagesx($narrow));
        $this->assertSame(10, imagesy($narrow));
        imagedestroy($narrow);
    }

    public function test_skips_widths_that_would_upscale(): void
    {
        $input = $this->makeJpeg(); // 64px wide
        $written = (new WebpConverter)->convert($input, $this->tmpDir, [128, 32]);

        $this->assertNotContains($this->tmpDir.'/photo-128.webp', $written);
        $this->assertFileDoesNotExist($this->tmpDir.'/photo-128.webp');
        $this->assertContains($this->tmpDir.'/photo-32.webp', $written);
    }

    public function test_generates_site_icons_from_an_image(): void
    {
        $input = $this->makeJpeg();
        $written = (new WebpConverter)->generateIcons($input, $this->tmpDir);

        $this->assertSame([
            $this->tmpDir.'/favicon.ico',
            $this->tmpDir.'/favicon-32x32.png',
            $this->tmpDir.'/apple-touch-icon.png',
            $this->tmpDir.'/android-chrome-192x192.png',
            $this->tmpDir.'/android-chrome-512x512.png',
        ], $written);

        $ico = file_get_contents($this->tmpDir.'/favicon.ico');
        $this->assertNotFalse($ico);
        // ICONDIR header: reserved=0, type=1 (icon), count>=1.
        $this->assertSame("\x00\x00\x01\x00", substr($ico, 0, 4));
        $this->assertGreaterThan(0, unpack('v', substr($ico, 4, 2))[1]);

        foreach ([
            'favicon-32x32.png' => 32,
            'apple-touch-icon.png' => 180,
            'android-chrome-192x192.png' => 192,
            'android-chrome-512x512.png' => 512,
        ] as $file => $size) {
            $image = imagecreatefrompng($this->tmpDir.'/'.$file);
            $this->assertSame($size, imagesx($image), $file.' should be square');
            $this->assertSame($size, imagesy($image), $file.' should be square');
            imagedestroy($image);
        }
    }

    public function test_rejects_a_missing_input_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        (new WebpConverter)->convert($this->tmpDir.'/missing.jpg', $this->tmpDir);
    }

    public function test_rejects_an_unsupported_format(): void
    {
        $path = $this->tmpDir.'/not-an-image.txt';
        file_put_contents($path, 'definitely not an image');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a supported image');

        (new WebpConverter)->convert($path, $this->tmpDir);
    }
}
