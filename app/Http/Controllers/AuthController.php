<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $cred = $r->validate(['email' => 'required|email', 'password' => 'required']);
        $user = User::where('email', $cred['email'])->first();

        if ($user && $user->is_active && Hash::check($cred['password'], $user->password)) {
            if ($user->hasTwoFactor()) {
                // password OK → ask for the authenticator code before really signing in
                $r->session()->put('two_factor', ['id' => $user->id, 'remember' => $r->boolean('remember'), 'at' => now()->timestamp]);

                return redirect()->route('two-factor.challenge');
            }

            return $this->finishLogin($r, $user, $r->boolean('remember'));
        }

        return back()->withErrors(['email' => __('Invalid credentials or inactive account.')])->onlyInput('email');
    }

    private function finishLogin(Request $r, User $user, bool $remember)
    {
        Auth::login($user, $remember);
        $r->session()->regenerate();
        $r->session()->forget('two_factor');
        session(['previous_login' => $user->last_login_at]); // shown on the profile as "last sign-in"
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        if ($user->locale) {
            session(['locale' => $user->locale]);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function twoFactorChallenge(Request $r)
    {
        abort_unless($this->pendingTwoFactor($r), 404);

        return view('auth.two-factor');
    }

    public function twoFactorVerify(Request $r)
    {
        $pending = $this->pendingTwoFactor($r);
        if (! $pending) {
            return redirect()->route('login')->withErrors(['email' => __('Your sign-in took too long. Please start again.')]);
        }
        $r->validate(['code' => 'required|string|max:32']);
        $user = User::find($pending['id']);
        $code = trim($r->input('code'));
        $ok = $user && (preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $code) ? TwoFactor::verify($user, $code) : TwoFactor::useRecoveryCode($user, $code));
        if (! $ok) {
            return back()->withErrors(['code' => __('That code is not valid. Try the current 6-digit code, or a recovery code.')]);
        }

        return $this->finishLogin($r, $user, $pending['remember']);
    }

    /** The half-signed-in state: valid for 10 minutes after the password was accepted. */
    private function pendingTwoFactor(Request $r): ?array
    {
        $p = $r->session()->get('two_factor');

        return $p && now()->timestamp - $p['at'] < 600 ? $p : null;
    }

    // ---- two-factor setup (Profile → Security) ----------------------------------------------
    public function twoFactorEnable(Request $r)
    {
        abort_if($r->user()->hasTwoFactor(), 404);
        $r->session()->put('two_factor_setup', TwoFactor::newSecret());

        return redirect()->route('profile', ['tab' => 'security']);
    }

    public function twoFactorConfirm(Request $r)
    {
        $secret = $r->session()->get('two_factor_setup');
        abort_unless($secret, 404);
        $r->validate(['code' => 'required|string|max:12']);
        $ts = TwoFactor::checkCode($secret, $r->input('code'));
        if ($ts === false) {
            return redirect()->route('profile', ['tab' => 'security'])->withErrors(['code' => __('That code is not right. Check the time on your phone and try the next code.')]);
        }
        [$plain, $hashed] = TwoFactor::makeRecoveryCodes();
        $r->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => json_encode($hashed),
            'two_factor_confirmed_at' => now(), 'two_factor_last_ts' => $ts])->saveQuietly();
        $r->session()->forget('two_factor_setup');

        return redirect()->route('profile', ['tab' => 'security'])->with('recovery_codes', $plain)->with('success', __('Two-factor sign-in is on.'));
    }

    public function twoFactorCancel(Request $r)
    {
        $r->session()->forget('two_factor_setup');

        return redirect()->route('profile', ['tab' => 'security']);
    }

    public function twoFactorDisable(Request $r)
    {
        $u = $r->user();
        $r->validate(['password' => 'required|current_password', 'code' => 'required|string|max:32']);
        $code = trim($r->input('code'));
        $ok = preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $code) ? TwoFactor::verify($u, $code) : TwoFactor::useRecoveryCode($u, $code);
        if (! $ok) {
            return redirect()->route('profile', ['tab' => 'security'])->withErrors(['code' => __('That code is not valid.')]);
        }
        TwoFactor::clear($u);

        return redirect()->route('profile', ['tab' => 'security'])->with('success', __('Two-factor sign-in is off.'));
    }

    public function twoFactorRecoveryCodes(Request $r)
    {
        $r->validate(['password' => 'required|current_password']);
        abort_unless($r->user()->hasTwoFactor(), 404);
        [$plain, $hashed] = TwoFactor::makeRecoveryCodes();
        $r->user()->forceFill(['two_factor_recovery_codes' => json_encode($hashed)])->saveQuietly();

        return redirect()->route('profile', ['tab' => 'security'])->with('recovery_codes', $plain)->with('success', __('New recovery codes created. The old ones no longer work.'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function profile(Request $r)
    {
        $u = $r->user();

        return view('auth.profile', [
            'activity' => AuditLog::where('user_id', $u->id)->latest('id')->limit(25)->get(),
            'previousLogin' => session('previous_login'),
            'tab' => $r->query('tab', 'profile'),
            // two-factor setup state: a pending secret shows its QR code until the first code is confirmed
            'twoFactorQr' => ($s = session('two_factor_setup')) ? TwoFactor::qrSvg($u, $s) : null,
            'twoFactorSecret' => session('two_factor_setup'),
            'recoveryCodes' => session('recovery_codes'),
        ]);
    }

    /** Name, phone and profile photo. */
    public function updateProfile(Request $r)
    {
        $u = $r->user();
        $d = $r->validate([
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:5120',
        ]);
        $u->fill(['name' => $d['name'], 'phone' => $d['phone'] ?? null]);

        if ($r->hasFile('avatar')) {
            $src = @imagecreatefromstring((string) file_get_contents($r->file('avatar')->getRealPath()));
            if ($src) {
                $path = Brand::square($src, 256, 'avatars/'.$u->id.'-'.uniqid().'.png');
                imagedestroy($src);
                $this->dropAvatar($u);
                $u->avatar = $path;
            }
        } elseif ($r->boolean('remove_avatar')) {
            $this->dropAvatar($u);
            $u->avatar = null;
        }
        $u->save();

        return redirect()->route('profile', ['tab' => 'profile'])->with('success', __('Profile updated.'));
    }

    public function updatePassword(Request $r)
    {
        $d = $r->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $r->user()->update(['password' => $d['password']]);

        return redirect()->route('profile', ['tab' => 'security'])->with('success', __('Password changed.'));
    }

    public function updatePreferences(Request $r)
    {
        $d = $r->validate(['locale' => 'required|in:en,ur']);
        $r->user()->update(['locale' => $d['locale']]);
        session(['locale' => $d['locale']]);

        return redirect()->route('profile', ['tab' => 'preferences'])->with('success', __('Preferences saved.'));
    }

    private function dropAvatar($u): void
    {
        if ($u->avatar) {
            Storage::disk('public')->delete($u->avatar);
        }
    }

    public function locale(Request $r, string $loc)
    {
        abort_unless(in_array($loc, ['en', 'ur']), 404);
        session(['locale' => $loc]);
        if ($u = $r->user()) {
            $u->update(['locale' => $loc]);
        }

        return back();
    }

    /** Fresh CSRF token for the offline sync queue. */
    public function csrf(Request $r)
    {
        return response()->json(['token' => csrf_token()]);
    }
}
