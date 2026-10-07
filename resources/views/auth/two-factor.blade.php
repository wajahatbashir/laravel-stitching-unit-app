<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ur' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a0a0a">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" href="{{ brand_url('favicon_32', '/icons/icon-192.png') }}">
    <title>{{ __('Sign-in code') }} · {{ biz('business_name', 'Lumiere Premium') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-dvh items-center justify-center bg-[#0a0a0a] p-4">
<div class="w-full max-w-sm">
    <div class="mb-4 text-center">
        <img src="{{ brand_url('login_logo', '/brand/logo-wordmark.png') }}" class="mx-auto w-60 max-w-full" alt="">
    </div>
    <form method="POST" action="{{ route('two-factor.verify') }}" data-allow-enter class="card space-y-4 p-6" x-data="{ recovery: false }">
        @csrf
        <div>
            <h1 class="text-lg font-bold text-slate-900">{{ __('Enter your sign-in code') }}</h1>
            <p class="mt-1 text-sm text-slate-500" x-show="!recovery">{{ __('Open your authenticator app and type the 6-digit code for this account.') }}</p>
            <p class="mt-1 text-sm text-slate-500" x-show="recovery" x-cloak>{{ __('Type one of your saved recovery codes (each works once).') }}</p>
        </div>
        @if ($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
        <div>
            <input name="code" class="input text-center text-2xl font-bold tracking-[.3em]" :class="recovery && '!text-base !tracking-normal'" inputmode="text" autocomplete="one-time-code" autofocus required
                   :placeholder="recovery ? 'xxxxx-xxxxx' : '000 000'">
        </div>
        <button class="btn btn-primary w-full">{{ __('Verify and sign in') }}</button>
        <div class="flex items-center justify-between text-sm">
            <button type="button" class="font-medium text-brand-700 underline" @click="recovery = !recovery" x-text="recovery ? '{{ __('Use my authenticator app') }}' : '{{ __('Use a recovery code') }}'"></button>
            <a href="{{ route('login') }}" class="text-slate-500 underline">{{ __('Back to sign in') }}</a>
        </div>
    </form>
</div>
</body>
</html>
