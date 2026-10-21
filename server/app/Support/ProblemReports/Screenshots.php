<?php

namespace App\Support\ProblemReports;

use App\Support\Files\Uploads;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The screenshots on a problem report (ADR 0096), stored on the private uploads
 * disk.
 *
 * Every image is decoded and drawn again before it is kept. The stored file is
 * pixels only: a photo of a screen taken with the phone's camera loses the
 * place it was taken and the camera it came from, and a file that only claims
 * to be an image is refused instead of being kept as one. A very large image is
 * scaled down to {@see self::LONGEST_SIDE}, which keeps every word on a
 * screenshot legible.
 */
final class Screenshots
{
    public const FOLDER = 'problem-reports';

    public const MAX = 3;

    /** Kilobytes, for the validation rule. */
    public const MAX_KB = 8192;

    /** The largest image accepted at all, either way — a guard against decompression bombs. */
    public const MAX_DIMENSION = 8000;

    private const LONGEST_SIDE = 2560;

    /**
     * @return array{path: string, name: string, mime: string, size: int, width: int, height: int}
     */
    public function store(UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('That file is not an image we can read.');
        }

        // A photo stays a JPEG; a screenshot (PNG, or WebP, which may be
        // transparent) is kept lossless, so its text stays sharp.
        $png = in_array($file->getMimeType(), ['image/png', 'image/webp'], true);
        $image = $this->fit($this->upright($image, $file));

        ob_start();

        if ($png) {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            imagejpeg($image, null, 85);
        }

        $encoded = (string) ob_get_clean();

        $width = imagesx($image);
        $height = imagesy($image);

        $extension = $png ? 'png' : 'jpg';
        $path = self::FOLDER.'/'.Str::random(40).'.'.$extension;

        Uploads::disk()->put($path, $encoded);

        return [
            'path' => $path,
            'name' => $this->name($file, $extension),
            'mime' => $png ? 'image/png' : 'image/jpeg',
            'size' => strlen($encoded),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Take back what {@see store()} wrote, when filing the report failed after it.
     *
     * @param  list<string>  $paths
     */
    public function discard(array $paths): void
    {
        if ($paths !== []) {
            Uploads::disk()->delete($paths);
        }
    }

    /**
     * A phone stores a photo as the sensor saw it and says in its metadata
     * which way is up. That metadata is about to be dropped, so turn the
     * pixels first, or a portrait photo would be kept on its side.
     */
    private function upright(GdImage $image, UploadedFile $file): GdImage
    {
        if ($file->getMimeType() !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $turned = imagerotate($image, $angle, 0);

        return $turned instanceof GdImage ? $turned : $image;
    }

    private function fit(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::LONGEST_SIDE) {
            return $image;
        }

        $scale = self::LONGEST_SIDE / $longest;
        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    /** The name the reporter's file had, tidied, with the extension it now has. */
    private function name(UploadedFile $file, string $extension): string
    {
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\pL\pN ._()-]+/u', '', $base));

        return Str::limit($base !== '' ? $base : 'Screenshot', 80, '').'.'.$extension;
    }
}
