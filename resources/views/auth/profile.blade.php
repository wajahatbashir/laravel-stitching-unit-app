@extends('layouts.app')
@section('title', __('My profile'))
@section('content')
@php
    $u = auth()->user();
    $start = $errors->hasAny(['current_password', 'password', 'code']) ? 'security' : ($errors->hasAny(['name', 'phone', 'avatar']) ? 'profile' : (in_array($tab, ['profile', 'security', 'preferences', 'activity']) ? $tab : 'profile'));
    $tabs = ['profile' => [__('Profile'), 'user'], 'security' => [__('Security'), 'shield'], 'preferences' => [__('Preferences'), 'cog'], 'activity' => [__('My activity'), 'doc']];
    $verb = ['created' => __('Added'), 'updated' => __('Edited'), 'deleted' => __('Deleted')];
@endphp
<div class="mx-auto max-w-4xl space-y-4" x-data="{ tab: '{{ $start }}' }">

    {{-- identity card --}}
    <div class="relative overflow-hidden rounded-2xl bg-[#0a0a0a] p-5 text-white shadow-sm md:p-6">
        <div class="pointer-events-none absolute -end-10 -top-10 h-44 w-44 rounded-full border border-gold-500/30"></div>
        <div class="pointer-events-none absolute -end-4 -top-4 h-28 w-28 rounded-full border border-gold-500/20"></div>
        <div class="relative flex flex-wrap items-center gap-4">
            <x-avatar :user="$u" class="h-20 w-20 text-2xl md:h-24 md:w-24" />
            <div class="min-w-0">
                <p class="truncate text-xl font-bold md:text-2xl">{{ $u->name }}</p>
                <p class="truncate text-sm text-white/70">{{ $u->email }}@if ($u->phone) · {{ $u->phone }}@endif</p>
                <span class="badge mt-2 bg-gold-500/20 text-gold-400">{{ $u->roleLabel() }}</span>
            </div>
        </div>
        <dl class="relative mt-5 grid grid-cols-2 gap-3 border-t border-white/10 pt-4 text-sm md:grid-cols-3">
            <div><dt class="text-xs text-white/50">{{ __('Member since') }}</dt><dd>{{ fmt_date($u->created_at) }}</dd></div>
            <div><dt class="text-xs text-white/50">{{ __('Last sign-in') }}</dt><dd>{{ $previousLogin ? \Illuminate\Support\Carbon::parse($previousLogin)->format('d M Y, H:i') : __('This is your first sign-in') }}</dd></div>
            <div class="col-span-2 md:col-span-1"><dt class="text-xs text-white/50">{{ __('Language') }}</dt><dd>{{ $u->locale === 'ur' ? 'اردو' : 'English' }}</dd></div>
        </dl>
    </div>

    {{-- tabs --}}
    <div class="flex gap-1 overflow-x-auto rounded-2xl bg-slate-100 p-1" role="tablist">
        @foreach ($tabs as $k => [$label, $icon])
            <button type="button" role="tab" @click="tab = '{{ $k }}'; history.replaceState(null, '', '?tab={{ $k }}')" :aria-selected="tab === '{{ $k }}'"
                    class="seg shrink-0 grow px-4 text-sm font-semibold" :class="tab === '{{ $k }}' && 'seg-on'"><x-icon name="{{ $icon }}" class="h-4 w-4" />{{ $label }}</button>
        @endforeach
    </div>

    {{-- PROFILE --}}
    <form x-show="tab === 'profile'" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="card space-y-5 p-4 md:p-6"
          x-data="{ preview: null, remove: false, pick(e) { const f = e.target.files[0]; if (this.preview) URL.revokeObjectURL(this.preview); this.preview = f ? URL.createObjectURL(f) : null; this.remove = false; } }">
        @csrf @method('PUT')
        <div class="flex flex-wrap items-center gap-4">
            <div class="relative">
                <img x-show="preview" x-cloak :src="preview" alt="" class="h-24 w-24 rounded-full object-cover ring-2 ring-gold-400/70">
                <div x-show="!preview" :class="remove && 'opacity-40'"><x-avatar :user="$u" class="h-24 w-24 text-3xl" /></div>
            </div>
            <div class="space-y-2">
                <input type="file" name="avatar" id="avatar" accept="image/png,image/jpeg,image/webp" class="hidden" @change="pick($event)">
                <label for="avatar" class="btn btn-ghost btn-sm cursor-pointer"><x-icon name="plus" class="h-4 w-4" />{{ __('Choose photo') }}</label>
                @if ($u->avatar)
                    <label class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="remove_avatar" value="1" x-model="remove" class="h-4 w-4 rounded border-slate-300">{{ __('Remove current photo') }}</label>
                @endif
                <p class="text-xs text-slate-500">{{ __('PNG, JPG or WebP. It is cropped to a square.') }}</p>
                @error('avatar')<p class="text-xs font-medium text-rose-600">{{ $message }}</p>@enderror
            </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
            <div><label class="label" for="name">{{ __('Name') }}</label><input id="name" name="name" class="input" value="{{ old('name', $u->name) }}" required>@error('name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="phone">{{ __('Phone / WhatsApp') }}</label><input id="phone" name="phone" type="tel" class="input" value="{{ old('phone', $u->phone) }}">@error('phone')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="label">{{ __('Email (login)') }}</label><input class="input opacity-70" value="{{ $u->email }}" disabled></div>
            <div><label class="label">{{ __('Role') }}</label><input class="input opacity-70" value="{{ $u->roleLabel() }}" disabled></div>
        </div>
        <button class="btn btn-primary">{{ __('Save changes') }}</button>
    </form>

    {{-- SECURITY --}}
    <form x-show="tab === 'security'" x-cloak method="POST" action="{{ route('profile.password') }}" class="card space-y-4 p-4 md:p-6" x-data="{ show: false }">
        @csrf @method('PUT')
        <div class="flex items-center justify-between"><h2 class="font-semibold">{{ __('Change password') }}</h2>
            <label class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" x-model="show" class="h-4 w-4 rounded border-slate-300">{{ __('Show passwords') }}</label></div>
        <div class="grid gap-4 md:grid-cols-2">
            <div class="md:col-span-2"><label class="label" for="cp">{{ __('Current password') }}</label><input id="cp" name="current_password" :type="show ? 'text' : 'password'" class="input" autocomplete="current-password" required>@error('current_password')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="np">{{ __('New password') }}</label><input id="np" name="password" :type="show ? 'text' : 'password'" class="input" autocomplete="new-password" minlength="8" required>@error('password')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="npc">{{ __('Confirm new password') }}</label><input id="npc" name="password_confirmation" :type="show ? 'text' : 'password'" class="input" autocomplete="new-password" required></div>
        </div>
        <p class="text-xs text-slate-500">{{ __('Use at least 8 characters. Change the default password right after the first login.') }}</p>
        <button class="btn btn-primary">{{ __('Update password') }}</button>
    </form>

    {{-- TWO-FACTOR (inside the Security tab) --}}
    <div x-show="tab === 'security'" x-cloak class="card space-y-4 p-4 md:p-6" id="two-factor">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div><h2 class="font-semibold">{{ __('Two-factor sign-in') }}</h2>
                <p class="text-xs text-slate-500">{{ __('A 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…) is asked after your password.') }}</p></div>
            <span class="badge {{ $u->hasTwoFactor() ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }}">{{ $u->hasTwoFactor() ? __('On') : __('Off') }}</span>
        </div>
        @error('code')<p class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{{ $message }}</p>@enderror

        @if ($recoveryCodes)
            <div class="rounded-xl bg-amber-50 p-4 ring-1 ring-amber-300" x-data="{ copied: false }">
                <p class="font-semibold text-amber-900">{{ __('Save these recovery codes now') }}</p>
                <p class="text-xs text-amber-800">{{ __('Each code works once if you lose your phone. They are shown only this one time — keep them somewhere safe (not on the same phone).') }}</p>
                <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4" id="rc">@foreach ($recoveryCodes as $c)<span class="rounded-lg bg-white px-2 py-1.5 text-center text-slate-900 ring-1 ring-amber-200">{{ $c }}</span>@endforeach</div>
                <button type="button" class="btn btn-ghost btn-sm mt-3" @click="navigator.clipboard.writeText($root.querySelector('#rc').innerText.trim().split(/\s+/).join('\n')); copied = true"><span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy codes') }}'"></span></button>
            </div>
        @endif

        @if ($u->hasTwoFactor())
            <p class="text-sm text-slate-600">{{ __('Recovery codes left') }}: <strong>{{ $u->recoveryCodesLeft() }}</strong></p>
            <div class="grid gap-4 md:grid-cols-2">
                <form method="POST" action="{{ route('two-factor.recovery') }}" class="space-y-2 rounded-xl p-4 ring-1 ring-slate-200">@csrf
                    <p class="text-sm font-semibold">{{ __('New recovery codes') }}</p>
                    <input type="password" name="password" class="input" placeholder="{{ __('Your password') }}" required autocomplete="current-password">
                    <button class="btn btn-ghost btn-sm">{{ __('Create new codes') }}</button>
                </form>
                <form method="POST" action="{{ route('two-factor.disable') }}" class="space-y-2 rounded-xl p-4 ring-1 ring-rose-200">@csrf
                    <p class="text-sm font-semibold text-rose-700">{{ __('Turn two-factor off') }}</p>
                    <input type="password" name="password" class="input" placeholder="{{ __('Your password') }}" required autocomplete="current-password">
                    <input name="code" class="input" placeholder="{{ __('Current 6-digit code or a recovery code') }}" required autocomplete="one-time-code">
                    <button class="btn btn-danger btn-sm">{{ __('Turn off') }}</button>
                </form>
            </div>
        @elseif ($twoFactorQr)
            <div class="grid gap-4 md:grid-cols-[13rem_1fr]">
                <div class="mx-auto rounded-xl bg-white p-2 ring-1 ring-slate-200 [&>svg]:h-auto [&>svg]:w-full" style="width: 13rem">{!! $twoFactorQr !!}</div>
                <div class="space-y-3">
                    <ol class="list-decimal space-y-1 ps-5 text-sm text-slate-600">
                        <li>{{ __('Open your authenticator app and scan this QR code.') }}</li>
                        <li>{{ __('Can’t scan? Type this key into the app instead:') }} <code class="break-all rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-900">{{ trim(chunk_split($twoFactorSecret, 4, ' ')) }}</code></li>
                        <li>{{ __('Enter the 6-digit code the app shows to finish.') }}</li>
                    </ol>
                    <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex flex-wrap items-end gap-2">@csrf
                        <div><label class="label" for="tf-code">{{ __('Code from the app') }}</label><input id="tf-code" name="code" class="input !w-44 text-center text-lg font-bold tracking-widest" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required></div>
                        <button class="btn btn-primary">{{ __('Confirm and turn on') }}</button>
                    </form>
                    <form method="POST" action="{{ route('two-factor.cancel') }}">@csrf<button class="text-xs text-slate-500 underline">{{ __('Cancel setup') }}</button></form>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('two-factor.enable') }}">@csrf
                <button class="btn btn-primary">{{ __('Set up two-factor sign-in') }}</button>
            </form>
            @if (biz('require_2fa_admins') === '1' && $u->hasAnyRole(['Admin', 'Super Admin']))<p class="text-xs font-semibold text-rose-600">{{ __('Required for admins on this system.') }}</p>@endif
        @endif
    </div>

    {{-- PREFERENCES --}}
    <div x-show="tab === 'preferences'" x-cloak class="card space-y-6 p-4 md:p-6">
        <form method="POST" action="{{ route('profile.preferences') }}">
            @csrf @method('PUT')
            <h2 class="mb-3 font-semibold">{{ __('Language') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (['en' => 'English', 'ur' => 'اردو'] as $code => $name)
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl p-4 ring-1 ring-slate-200 has-[:checked]:bg-brand-100 has-[:checked]:ring-2 has-[:checked]:ring-gold-500">
                        <input type="radio" name="locale" value="{{ $code }}" class="h-4 w-4" @checked(old('locale', $u->locale) === $code)>
                        <span class="font-semibold">{{ $name }}</span>
                    </label>
                @endforeach
            </div>
            <button class="btn btn-primary mt-4">{{ __('Save language') }}</button>
        </form>
        <div>
            <h2 class="mb-1 font-semibold">{{ __('Appearance') }}</h2>
            <p class="mb-3 text-xs text-slate-500">{{ __('Remembered on this device only.') }}</p>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (['light' => [__('Light'), 'sun'], 'dark' => [__('Dark'), 'moon'], 'auto' => [__('Match my device'), 'screen']] as $m => [$l, $ic])
                    <button type="button" @click="$store.theme.set('{{ $m }}')" class="flex items-center gap-3 rounded-xl p-4 text-start ring-1 ring-slate-200"
                            :class="$store.theme.mode === '{{ $m }}' && 'bg-brand-100 !ring-2 !ring-gold-500'"><x-icon name="{{ $ic }}" class="h-5 w-5" /><span class="font-semibold">{{ $l }}</span></button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ACTIVITY --}}
    <div x-show="tab === 'activity'" x-cloak class="card overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">{{ __('My recent activity') }}</h2><p class="text-xs text-slate-500">{{ __('The last changes you made in the system.') }}</p></div>
        @forelse ($activity as $a)
            <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 text-sm last:border-0">
                <span class="badge {{ $a->action === 'deleted' ? 'bg-rose-100 text-rose-800' : ($a->action === 'created' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800') }}">{{ $verb[$a->action] ?? $a->action }}</span>
                <span class="min-w-0 flex-1 truncate">{{ $a->model }} #{{ $a->model_id }}</span>
                <span class="shrink-0 text-xs text-slate-400" title="{{ $a->created_at->format('d M Y H:i') }}">{{ $a->created_at->diffForHumans() }}</span>
            </div>
        @empty
            <div class="flex flex-col items-center px-6 py-12 text-center text-slate-500"><x-icon name="doc" class="mb-2 h-8 w-8 text-slate-300" />{{ __('No activity yet.') }}</div>
        @endforelse
    </div>
</div>
@endsection
