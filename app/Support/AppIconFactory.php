<?php

namespace App\Support;

use GdImage;

/**
 * Draws the Taskr app icon in pure GD from the geometry of the brand SVG
 * (public/assets/taskr-app-icon.svg: rounded #026FD7 square, two
 * round-capped chevrons) — no raster source needed, the mark is redrawn
 * deterministically at any size. writeIcons() produces the PWA icon set
 * committed in public/.
 */
class AppIconFactory
{
    /** Geometry is expressed in the 64x64 viewBox of the brand SVG. */
    private const VIEWBOX = 64;

    /** Supersampling factor: the vector geometry is drawn at 4x then
     * downscaled, which anti-aliases the rounded corners and round caps. */
    private const SUPERSAMPLING = 4;

    private const BACKGROUND = [2, 111, 215];      // #026FD7

    private const CHEVRON_LIGHT = [251, 252, 253]; // #FBFCFD

    private const CHEVRON_SOFT = [144, 186, 241];  // #90BAF1

    /**
     * Draw the app icon at the requested size: rounded #026FD7 square with
     * the two round-capped chevrons of the brand mark. The $fullBleed
     * variant (maskable) paints the background edge to edge and keeps the
     * mark inside the 80% safe zone.
     */
    public function draw(int $size, bool $fullBleed = false): GdImage
    {
        $drawn = $size * self::SUPERSAMPLING;
        $canvas = imagecreatetruecolor($drawn, $drawn);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        // Truecolor canvases start opaque black: paint the transparent base
        // the rounded (non-full-bleed) corners leave untouched.
        imagefilledrectangle($canvas, 0, 0, $drawn, $drawn, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        $this->paintBackground($canvas, $drawn, $fullBleed);
        $this->paintChevrons($canvas, $drawn);

        $icon = imagecreatetruecolor($size, $size);
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagecopyresampled($icon, $canvas, 0, 0, 0, 0, $size, $size, $drawn, $drawn);
        imagedestroy($canvas);

        return $icon;
    }

    /**
     * Write the committed PWA icon set: apple-touch-icon.png (180, opaque
     * square — iOS applies its own mask), android-chrome-192x192.png and
     * android-chrome-512x512.png (rounded), and the maskable
     * android-chrome-512x512-maskable.png (full-bleed background).
     *
     * @return array<int, string> the written file paths
     */
    public function writeIcons(string $outputDir): array
    {
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $written = [];

        foreach ([
            'apple-touch-icon.png' => [180, true],
            'android-chrome-192x192.png' => [192, false],
            'android-chrome-512x512.png' => [512, false],
            'android-chrome-512x512-maskable.png' => [512, true],
        ] as $file => [$size, $fullBleed]) {
            $icon = $this->draw($size, $fullBleed);
            imagepng($icon, $outputDir.'/'.$file, 9);
            imagedestroy($icon);
            $written[] = $outputDir.'/'.$file;
        }

        return $written;
    }

    private function paintBackground(GdImage $canvas, int $size, bool $fullBleed): void
    {
        $background = $this->allocate($canvas, self::BACKGROUND);

        if ($fullBleed) {
            imagefilledrectangle($canvas, 0, 0, $size, $size, $background);

            return;
        }

        $radius = (int) round(14 * $size / self::VIEWBOX);

        imagefilledrectangle($canvas, $radius, 0, $size - $radius, $size, $background);
        imagefilledrectangle($canvas, 0, $radius, $size, $size - $radius, $background);

        foreach ([
            [$radius, $radius],
            [$size - $radius, $radius],
            [$radius, $size - $radius],
            [$size - $radius, $size - $radius],
        ] as [$x, $y]) {
            imagefilledellipse($canvas, $x, $y, 2 * $radius, 2 * $radius, $background);
        }
    }

    /**
     * The two chevrons of the brand mark, round-capped (a filled circle at
     * each endpoint and at the elbow mimics the SVG round caps/joins).
     *
     * @param  array{int, int, int}  $color
     */
    private function paintChevrons(GdImage $canvas, int $size): void
    {
        $scale = $size / self::VIEWBOX;
        $thickness = max(1, (int) round(5.3 * $scale));
        imagesetthickness($canvas, $thickness);

        foreach ([
            [[21.4, 18.1], [32.0, 28.0], [42.6, 18.1], self::CHEVRON_LIGHT],
            [[21.4, 45.9], [32.0, 36.0], [42.6, 45.9], self::CHEVRON_SOFT],
        ] as [[$x1, $y1], [$x2, $y2], [$x3, $y3], $rgb]) {
            $color = $this->allocate($canvas, $rgb);

            imageline(
                $canvas,
                (int) round($x1 * $scale), (int) round($y1 * $scale),
                (int) round($x2 * $scale), (int) round($y2 * $scale),
                $color,
            );
            imageline(
                $canvas,
                (int) round($x2 * $scale), (int) round($y2 * $scale),
                (int) round($x3 * $scale), (int) round($y3 * $scale),
                $color,
            );

            foreach ([[$x1, $y1], [$x2, $y2], [$x3, $y3]] as [$x, $y]) {
                imagefilledellipse(
                    $canvas,
                    (int) round($x * $scale), (int) round($y * $scale),
                    $thickness, $thickness,
                    $color,
                );
            }
        }
    }

    /**
     * @param  array{int, int, int}  $rgb
     */
    private function allocate(GdImage $canvas, array $rgb): int
    {
        return imagecolorallocate($canvas, ...$rgb);
    }
}
