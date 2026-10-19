<?php

namespace App\Support;

use GdImage;

/**
 * Converts JPG/PNG inputs to optimized webp variants with pure GD, and
 * generates the site icon set (favicon.ico + PNG icons) from a source
 * image. Used by the `images:webp` artisan command.
 */
class WebpConverter
{
    public function __construct(private readonly int $quality = 82) {}

    /**
     * Convert the input to webp at its original size, then one variant
     * per requested width (aspect ratio kept, widths larger than the
     * source are skipped — no upscaling).
     *
     * @param  array<int, int>  $widths
     * @return array<int, string> the written file paths
     */
    public function convert(string $inputPath, string $outputDir, array $widths = []): array
    {
        $image = $this->load($inputPath);
        $name = pathinfo($inputPath, PATHINFO_FILENAME);
        $this->ensureDir($outputDir);

        $written = [$this->writeWebp($image, $outputDir.'/'.$name.'.webp')];

        $sourceWidth = imagesx($image);
        foreach ($widths as $width) {
            if ($width >= $sourceWidth) {
                continue;
            }

            $resized = $this->resize($image, $width, (int) round($width * imagesy($image) / $sourceWidth));
            $written[] = $this->writeWebp($resized, sprintf('%s/%s-%d.webp', $outputDir, $name, $width));
            imagedestroy($resized);
        }

        imagedestroy($image);

        return $written;
    }

    /**
     * Generate the site icon set from the input: favicon.ico (16/32/48
     * embedded), favicon-32x32.png, apple-touch-icon.png (180),
     * android-chrome-192x192.png and android-chrome-512x512.png. The
     * source is center-cropped to a square; upscaling is allowed here —
     * a small logo is still a valid source for large icons.
     *
     * @return array<int, string> the written file paths
     */
    public function generateIcons(string $inputPath, string $outputDir): array
    {
        $image = $this->load($inputPath);
        $square = $this->centerCrop($image);
        imagedestroy($image);
        $this->ensureDir($outputDir);

        $written = [];

        foreach ([
            'favicon-32x32.png' => 32,
            'apple-touch-icon.png' => 180,
            'android-chrome-192x192.png' => 192,
            'android-chrome-512x512.png' => 512,
        ] as $file => $size) {
            $icon = $this->resize($square, $size, $size);
            imagepng($icon, $outputDir.'/'.$file, 9);
            imagedestroy($icon);
            $written[] = $outputDir.'/'.$file;
        }

        $frames = [];
        foreach ([16, 32, 48] as $size) {
            $icon = $this->resize($square, $size, $size);
            ob_start();
            imagepng($icon, null, 9);
            $frames[] = ['size' => $size, 'data' => (string) ob_get_clean()];
            imagedestroy($icon);
        }
        imagedestroy($square);

        file_put_contents($outputDir.'/favicon.ico', $this->buildIco($frames));
        array_unshift($written, $outputDir.'/favicon.ico');

        return $written;
    }

    /**
     * @throws \InvalidArgumentException when the file is missing or GD
     *                                   cannot decode it (JPG/PNG supported).
     */
    private function load(string $inputPath): GdImage
    {
        if (! is_file($inputPath)) {
            throw new \InvalidArgumentException("Input file not found: {$inputPath}");
        }

        $image = @imagecreatefromstring((string) file_get_contents($inputPath));

        if ($image === false) {
            throw new \InvalidArgumentException("Input file is not a supported image: {$inputPath}");
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function writeWebp(GdImage $image, string $targetPath): string
    {
        if (! imagewebp($image, $targetPath, $this->quality)) {
            throw new \RuntimeException("Failed to write webp file: {$targetPath}");
        }

        return $targetPath;
    }

    /**
     * High-quality resample (imagecopyresampled) into a new truecolor
     * image with an alpha channel. imagescale() with its bicubic modes
     * returns false on PHP 8.5, so this is the reliable path.
     */
    private function resize(GdImage $image, int $width, int $height): GdImage
    {
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $resized;
    }

    /**
     * Center-crop the image to a square sized min(width, height).
     */
    private function centerCrop(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);

        $square = imagecreatetruecolor($side, $side);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagecopy(
            $square,
            $image,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            $side,
            $side,
        );

        return $square;
    }

    /**
     * Pack PNG frames into a .ico binary (PNG-embedded entries, valid
     * since Windows Vista and understood by every modern browser).
     *
     * @param  array<int, array{size: int, data: string}>  $frames
     */
    private function buildIco(array $frames): string
    {
        $count = count($frames);
        $ico = pack('vvv', 0, 1, $count);
        $offset = 6 + 16 * $count;

        foreach ($frames as $frame) {
            // Width/height bytes: 0 encodes 256; here sizes are <= 48.
            $ico .= pack(
                'CCCCvvVvV',
                $frame['size'],
                $frame['size'],
                0,
                0,
                1,
                32,
                strlen($frame['data']),
                0,
                $offset,
            );
            $offset += strlen($frame['data']);
        }

        foreach ($frames as $frame) {
            $ico .= $frame['data'];
        }

        return $ico;
    }

    private function ensureDir(string $outputDir): void
    {
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }
    }
}
