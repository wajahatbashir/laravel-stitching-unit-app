<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Authenticator-app (TOTP, RFC 6238) sign-in codes plus one-time recovery codes. */
class TwoFactor
{
    public static function engine(): Google2FA
    {
        return new Google2FA;
    }

    public static function newSecret(): string
    {
        return self::engine()->generateSecretKey(32);
    }

    /** SVG QR code for authenticator apps. */
    public static function qrSvg(User $u, string $secret): string
    {
        $url = self::engine()->getQRCodeUrl(biz('business_name', 'Lumiere Premium'), $u->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(210, 1), new SvgImageBackEnd)))->writeString($url);

        return trim(preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }

    /** Check a 6-digit code against a secret (±1 time step); a code can only be used once. Returns the new "last used" step or false. */
    public static function checkCode(string $secret, string $code, ?int $lastTs = null)
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return self::engine()->verifyKeyNewer($secret, $code, $lastTs, 1);
    }

    /** Verify a sign-in code for a user (and remember the step so the same code cannot be replayed). */
    public static function verify(User $u, string $code): bool
    {
        $ts = self::checkCode((string) $u->two_factor_secret, $code, $u->two_factor_last_ts);
        if ($ts === false) {
            return false;
        }
        $u->forceFill(['two_factor_last_ts' => $ts])->saveQuietly();

        return true;
    }

    /** @return array{0: array<int,string>, 1: array<int,string>} [plain codes to show once, hashes to store] */
    public static function makeRecoveryCodes(int $n = 8): array
    {
        $plain = collect(range(1, $n))->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))->all();

        return [$plain, array_map(fn ($c) => Hash::make($c), $plain)];
    }

    /** Use (and burn) a recovery code. */
    public static function useRecoveryCode(User $u, string $code): bool
    {
        $code = strtolower(trim($code));
        $hashes = json_decode((string) $u->two_factor_recovery_codes, true) ?: [];
        foreach ($hashes as $i => $h) {
            if (Hash::check($code, $h)) {
                unset($hashes[$i]);
                $u->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->saveQuietly();

                return true;
            }
        }

        return false;
    }

    public static function clear(User $u): void
    {
        $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_ts' => null])->saveQuietly();
    }
}
