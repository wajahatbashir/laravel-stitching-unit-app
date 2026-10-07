<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a0a0a">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="{{ brand_url('favicon_180', '/apple-touch-icon.png') }}"><link rel="icon" type="image/png" href="{{ brand_url('favicon_32', '/icons/icon-192.png') }}">
    <title>{{ __('Sign in') }} · {{ biz('business_name', 'Lumiere Premium') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-dvh items-center justify-center bg-[#0a0a0a] p-4">
<div class="w-full max-w-sm">
    <div class="mb-4 text-center">
        <img src="{{ brand_url('login_logo', '/brand/logo-wordmark.png') }}" class="mx-auto w-72 max-w-full" alt="{{ biz('business_name', 'Lumiere Premium') }}">
        <p class="-mt-4 text-xs tracking-[.3em] text-gold-400 uppercase">{{ __('Business management') }}</p>
    </div>
    <form method="POST" action="{{ route('login') }}" data-allow-enter class="card space-y-4 p-6">
        @csrf
        @if (request('restored'))<div class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{{ __('Backup restored. Please sign in again.') }}</div>@endif
        @if ($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
        <div><label class="label" for="email">{{ __('Email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" class="input" required autofocus autocomplete="username"></div>
        <div><label class="label" for="password">{{ __('Password') }}</label>
            <input id="password" type="password" name="password" class="input" required autocomplete="current-password"></div>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-700"> {{ __('Keep me signed in') }}</label>
        <button class="btn btn-primary w-full">{{ __('Sign in') }}</button>
    </form>
    <p class="mt-4 text-center text-xs text-brand-200"><a href="{{ route('locale', app()->getLocale() === 'ur' ? 'en' : 'ur') }}" class="underline">{{ app()->getLocale() === 'ur' ? 'English' : 'اردو' }}</a></p>
</div>
</body>
</html>
