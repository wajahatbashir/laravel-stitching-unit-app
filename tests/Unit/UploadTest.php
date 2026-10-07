<?php

namespace Tests\Unit;

use App\Support\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadTest extends TestCase
{
    private function noisy(int $w, int $h, string $type = 'jpeg'): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($i = 0; $i < 4000; $i++) {
            imagefilledrectangle($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im, null, 95);

        return ob_get_clean();
    }

    public function test_big_photos_are_shrunk_small_ones_and_screenshots_are_kept(): void
    {
        Storage::fake('public');

        $big = $this->noisy(4000, 3000);
        $path = Upload::store(UploadedFile::fake()->createWithContent('cam.jpg', $big), 'uploads/t');
        $stored = Storage::disk('public')->get($path);
        [$w, $h] = getimagesizefromstring($stored);
        $this->assertSame(2400, max($w, $h));
        $this->assertLessThan(strlen($big), strlen($stored));

        $small = $this->noisy(600, 400);
        $p2 = Upload::store(UploadedFile::fake()->createWithContent('s.jpg', $small), 'uploads/t');
        $this->assertSame($small, Storage::disk('public')->get($p2)); // untouched

        $shot = $this->noisy(1080, 2300, 'png'); // typical phone screenshot: kept as is for OCR
        $p3 = Upload::store(UploadedFile::fake()->createWithContent('shot.png', $shot), 'uploads/t');
        $this->assertSame($shot, Storage::disk('public')->get($p3));

        $pdf = Upload::store(UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 test'), 'uploads/t');
        $this->assertSame('%PDF-1.4 test', Storage::disk('public')->get($pdf));
    }
}
