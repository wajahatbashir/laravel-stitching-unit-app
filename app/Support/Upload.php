<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploads on the public disk, shrinking oversized photos so the server (and every backup) stays small.
 * Only JPEG/PNG/WebP larger than MAX_EDGE px, or JPEG/WebP above BIG_BYTES, are re-encoded — and only if the
 * result is really smaller. Ordinary screenshots (receipts) are stored untouched so OCR quality is unchanged.
 */
class Upload
{
    public const MAX_EDGE = 2400;
    public const BIG_BYTES = 800 * 1024;
    public const QUALITY = 82;

    public static function store(UploadedFile $file, string $dir): string
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! function_exists('imagecreatefromstring')) {
            return $file->store($dir, 'public');
        }

        $orig = (string) file_get_contents($file->getRealPath());
        $out = self::shrink($orig, $mime);
        if ($out === null || strlen($out) >= strlen($orig)) {
            return $file->store($dir, 'public');
        }

        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $path = trim($dir, '/').'/'.Str::random(40).'.'.$ext;
        Storage::disk('public')->put($path, $out);

        return $path;
    }

    /** Re-encoded bytes, or null when the image is fine as it is (or cannot be read). */
    public static function shrink(string $bytes, string $mime): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info) {
            return null;
        }
        [$w, $h] = $info;
        $resize = max($w, $h) > self::MAX_EDGE;
        if (! $resize && ($mime === 'image/png' || strlen($bytes) <= self::BIG_BYTES)) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $o = (int) (@exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes))['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0; // phones store portrait photos rotated + a flag; re-encoding drops the flag
            if ($angle && ($rot = imagerotate($img, $angle, 0))) {
                $img = $rot;
            }
        }
        if ($resize) {
            $img = imagescale($img, (int) round(imagesx($img) * self::MAX_EDGE / max(imagesx($img), imagesy($img))), -1, IMG_BICUBIC) ?: $img;
        }

        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($img, null, self::QUALITY),
            'image/webp' => imagewebp($img, null, self::QUALITY),
            default => (function () use ($img) { imagealphablending($img, false); imagesavealpha($img, true); imagepng($img, null, 9); })(),
        };
        $out = ob_get_clean();
        imagedestroy($img);

        return $out ?: null;
    }
}
