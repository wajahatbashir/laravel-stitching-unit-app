<?php

namespace App\Services;

use Carbon\Carbon;
use thiagoalessio\TesseractOCR\TesseractOCR;

/** Reads a payment receipt / bank-app screenshot and extracts amount, date and payee (best effort). */
class ReceiptOcr
{
    public static function read(string $absolutePath, int $psm = 6): array
    {
        $text = '';
        $tmp = self::prepare($absolutePath);
        try {
            $langs = array_filter(explode('+', (string) biz('ocr_languages', 'eng')));
            $text = (new TesseractOCR($tmp ?: $absolutePath))->lang(...($langs ?: ['eng']))->psm($psm)->run();
        } catch (\Throwable $e) {
            report($e);
        } finally {
            if ($tmp) {
                @unlink($tmp);
            }
        }

        return ['text' => $text] + self::parse($text);
    }

    /**
     * Make phone screenshots easier for Tesseract: upscale small images, turn dark-mode
     * (light text on dark background) into dark-on-light, greyscale + contrast.
     * Returns a temp PNG path, or null when the image can't be processed (original is used then).
     */
    private static function prepare(string $path): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $src = @imagecreatefromstring((string) @file_get_contents($path));
        if (! $src) {
            return null;
        }
        [$w, $h] = [imagesx($src), imagesy($src)];

        // average brightness from a coarse sample → dark mode?
        $sum = 0;
        $n = 0;
        for ($y = 0; $y < $h; $y += max(1, intdiv($h, 40))) {
            for ($x = 0; $x < $w; $x += max(1, intdiv($w, 40))) {
                $c = imagecolorat($src, $x, $y);
                $sum += (($c >> 16) & 255) * 0.299 + (($c >> 8) & 255) * 0.587 + ($c & 255) * 0.114;
                $n++;
            }
        }
        $dark = $n && ($sum / $n) < 110;

        $scale = $w < 1400 ? min(3, 1400 / $w) : 1;
        if ($scale > 1) {
            $scaled = imagescale($src, (int) round($w * $scale), -1, IMG_BICUBIC);
            if ($scaled) {
                imagedestroy($src);
                $src = $scaled;
            }
        }
        imagefilter($src, IMG_FILTER_GRAYSCALE);
        if ($dark) {
            imagefilter($src, IMG_FILTER_NEGATE);
        }
        imagefilter($src, IMG_FILTER_CONTRAST, -25);

        $out = tempnam(sys_get_temp_dir(), 'rcpt').'.png';
        imagepng($src, $out);
        imagedestroy($src);

        return $out;
    }

    public static function parse(string $text): array
    {
        return [
            'amount' => self::amount($text),
            'date' => self::date($text),
            'payee' => self::payee($text),
            'title' => self::title($text),
            'reference' => self::reference($text),
        ];
    }

    private static function num(string $s): ?float
    {
        $s = str_replace([',', ' '], '', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    public static function amount(string $t): ?float
    {
        $n = '(\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)';

        // 1) explicit "Amount: Rs. 12,500"
        if (preg_match("/(?:amount|total|paid|transferred|sent)[^\d\n]{0,25}?(?:rs\.?|pkr)?\s*$n/i", $t, $m) && ($v = self::num($m[1]))) {
            return $v;
        }
        // 2) currency-prefixed/suffixed numbers → the largest
        $c = [];
        if (preg_match_all("/(?:rs\.?|pkr)\s*$n|$n\s*(?:rs\.?|pkr)\b/i", $t, $m)) {
            foreach (array_merge($m[1], $m[2]) as $x) {
                if ($x !== '' && ($v = self::num($x))) {
                    $c[] = $v;
                }
            }
        }

        return $c ? max($c) : null;
    }

    public static function date(string $t): ?string
    {
        $months = 'jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec';
        $patterns = [
            '/\b(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/' => 'ymd',
            '/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})\b/' => 'dmy',
            "/\b(\d{1,2})(?:st|nd|rd|th)?[ -]($months)[a-z]*[ ,.-]+(\d{4})\b/i" => 'dMy',
            "/\b($months)[a-z]*[ .]+(\d{1,2})(?:st|nd|rd|th)?,?[ ]+(\d{4})\b/i" => 'Mdy',
        ];
        foreach ($patterns as $re => $kind) {
            if (! preg_match($re, $t, $m)) {
                continue;
            }
            try {
                $d = match ($kind) {
                    'ymd' => Carbon::create((int) $m[1], (int) $m[2], (int) $m[3]),
                    'dmy' => Carbon::create((int) $m[3], (int) $m[2], (int) $m[1]),
                    'dMy' => Carbon::parse("{$m[1]} {$m[2]} {$m[3]}"),
                    'Mdy' => Carbon::parse("{$m[2]} {$m[1]} {$m[3]}"),
                };
                if ($d && $d->year >= 2000 && $d->lte(now()->addDay())) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private const BILLERS = 'LESCO|FESCO|GEPCO|MEPCO|IESCO|PESCO|HESCO|SEPCO|QESCO|TESCO|K-?Electric|SNGPL|SSGC|PTCL|WAPDA|WASA|Nayatel|Storm ?Fiber|Jazz|Zong|Ufone|Telenor|Wateen|Cybernet';

    /** Who was paid: bank-app "Destination Acc. Title", utility biller, "To:" labels, or the NayaPay header name. */
    public static function payee(string $t): ?string
    {
        $clean = fn (string $s) => trim(preg_replace('/\s+/', ' ', $s), " .-:|");

        // 1) NayaPay / Raast / most apps: "Destination Acc. Title  Ali Traders" (name may wrap to the next line)
        if (preg_match('/destination\s+acc(?:ount|\.)?\s*title\s*[:\-]?\s*([^\n\r]{2,60})/i', $t, $m) && self::looksLikeName($m[1])) {
            return $clean($m[1]);
        }

        // 2) utility bill payments (Faysal, HBL, …): biller name such as LESCO / SNGPL
        if (preg_match('/\b('.self::BILLERS.')\b/i', $t, $m)) {
            return strtoupper($clean($m[1])) === 'K-ELECTRIC' || stripos($m[1], 'electric') !== false ? 'K-Electric' : strtoupper($clean($m[1]));
        }

        // 3) explicit labels
        $re = '/(?:paid to|beneficiary(?: name)?|account title|account name|recipient|receiver|transfer to|sent to|pay(?:ee)?|\bto)\s*[:\-]\s*([^\n\r]{3,60})/i';
        if (preg_match($re, $t, $m) && self::looksLikeName($m[1])) {
            return $clean($m[1]);
        }

        // 4) NayaPay header: the name sits right above "Meezan-0380" / "JazzCash/Mobilink MFB-1899" / "sarakhan@nayapay"
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $t)), fn ($l) => $l !== ''));
        foreach ($lines as $i => $l) {
            if ($i > 0 && preg_match('/(?:-\s?\d{3,4}$|@nayapay)/i', $l) && self::looksLikeName($lines[$i - 1])) {
                return $clean($lines[$i - 1]);
            }
        }

        return null;
    }

    private static function looksLikeName(string $s): bool
    {
        $s = trim($s);

        return strlen($s) >= 3 && preg_match('/[A-Za-z]{3}/', $s) && ! preg_match('/^(rs\.?|pkr|\d)/i', $s)
            && ! preg_match('/transaction|amount|service|bank|channel|raast id|number/i', $s);
    }

    /** What the payment was for, when the app says so ("Purpose of Payment: Electricity"). */
    public static function title(string $t): ?string
    {
        if (preg_match('/purpose(?:\s+of\s+payment)?\s*[:\-]\s*([^\n\r]{3,60})/i', $t, $m)) {
            return trim(preg_replace('/\s+/', ' ', $m[1]), " .-:|");
        }

        return null;
    }

    public static function reference(string $t): ?string
    {
        return preg_match('/transaction\s*id\s*[:\-]?\s*([A-Za-z0-9]{5,})/i', $t, $m) ? $m[1] : null;
    }
}
