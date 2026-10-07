<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Admin-uploaded branding: login logo, dashboard logo and favicon (+ generated PWA icon sizes). */
class Brand
{
    public const SLOTS = [
        'login_logo' => 'Login page logo',
        'dashboard_logo' => 'Dashboard / sidebar logo',
        'favicon' => 'Favicon & app icon',
    ];

    /** Store an upload for a slot; the favicon also gets 32/180/192/512 px square variants. */
    public static function store(string $slot, UploadedFile $file): void
    {
        self::remove($slot);
        $path = $file->store('brand', 'public');
        Setting::put("brand_$slot", $path);

        if ($slot === 'favicon') {
            $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
            if ($src) {
                foreach ([32, 180, 192, 512] as $size) {
                    Setting::put("brand_favicon_$size", self::square($src, $size, "brand/favicon-$size-".uniqid().'.png'));
                }
                imagedestroy($src);
            }
        }
        Setting::put('brand_version', (string) time());
    }

    public static function remove(string $slot): void
    {
        $keys = $slot === 'favicon' ? ['favicon', 'favicon_32', 'favicon_180', 'favicon_192', 'favicon_512'] : [$slot];
        foreach ($keys as $k) {
            if ($p = Setting::get("brand_$k")) {
                Storage::disk('public')->delete($p);
            }
            Setting::put("brand_$k", '');
        }
        Setting::put('brand_version', (string) time());
    }

    /** Centre-crop to a square and save as PNG, keeping transparency. */
    public static function square($src, int $size, string $path): string
    {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagepng($dst);
        Storage::disk('public')->put($path, ob_get_clean());
        imagedestroy($dst);

        return $path;
    }
}
