<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale') ?? $request->user()?->locale ?? 'en';
        app()->setLocale(in_array($locale, ['en', 'ur']) ? $locale : 'en');

        return $next($request);
    }
}
