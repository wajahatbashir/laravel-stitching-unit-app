<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * When Settings → "Require two-factor for admins" is on, Admin / Super Admin accounts without two-factor
 * can only reach their profile (to set it up), the language switch and log out.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if ($u && biz('require_2fa_admins') === '1' && ! $u->hasTwoFactor() && $u->hasAnyRole(['Admin', 'Super Admin'])
            && ! $request->routeIs('profile', 'profile.*', 'logout', 'locale', 'csrf', 'two-factor.*')) {
            return redirect()->route('profile', ['tab' => 'security'])
                ->with('success', __('For safety, admins must turn on two-factor sign-in before continuing.'));
        }

        return $next($request);
    }
}
