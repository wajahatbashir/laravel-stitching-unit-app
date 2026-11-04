@extends('layouts.app')
@section('title', __('Settings & notifications'))
@section('content')
<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="mx-auto max-w-4xl space-y-4">
    @csrf @method('PUT')
    @if ($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="card p-4 md:p-6">
        <h2 class="mb-3 font-semibold">{{ __('Business') }}</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <div><label class="label">{{ __('Business name') }}</label><input name="business_name" class="input" value="{{ old('business_name', $values['business_name']) }}" required></div>
            <div><label class="label">{{ __('Phone') }}</label><input name="business_phone" class="input" value="{{ old('business_phone', $values['business_phone']) }}"></div>
            <div class="md:col-span-2"><label class="label">{{ __('Address') }}</label><input name="business_address" class="input" value="{{ old('business_address', $values['business_address']) }}"></div>
            <div><label class="label">{{ __('Order number prefix') }}</label><input name="order_prefix" class="input" value="{{ old('order_prefix', $values['order_prefix']) }}" required></div>
            <div><label class="label">{{ __('Invoice number prefix') }}</label><input name="invoice_prefix" class="input" value="{{ old('invoice_prefix', $values['invoice_prefix']) }}" required></div>
            <div><label class="label">{{ __('Receipt OCR languages') }}</label><input name="ocr_languages" class="input" value="{{ old('ocr_languages', $values['ocr_languages']) }}">
                <p class="mt-1 text-xs text-slate-500">{{ __('Tesseract codes joined by +, e.g. eng or eng+urd') }}</p></div>
        </div>
    </div>

    <div class="card p-4 md:p-6">
        <h2 class="mb-1 font-semibold">{{ __('Branding') }}</h2>
        <p class="mb-4 text-xs text-slate-500">{{ __('PNG, JPG or WebP, max 3 MB. Transparent PNG works best. The favicon is also used as the phone app icon — use a square image, at least 512×512.') }}</p>
        <div class="grid gap-4 md:grid-cols-3">
            @php
                $slots = [
                    'login_logo' => [__('Login page logo'), '/brand/logo-wordmark.png', 'bg-brand-900'],
                    'dashboard_logo' => [__('Dashboard / sidebar logo'), '/brand/icon-black.png', 'bg-white'],
                    'favicon' => [__('Favicon & app icon'), '/icons/icon-192.png', 'bg-white'],
                ];
            @endphp
            @foreach ($slots as $k => [$l, $default, $bg])
                @php $custom = (bool) biz("brand_$k"); @endphp
                <div class="rounded-xl p-3 ring-1 ring-slate-200">
                    <p class="mb-2 text-sm font-medium">{{ $l }}</p>
                    <div class="mb-3 flex h-24 items-center justify-center rounded-lg {{ $bg }} ring-1 ring-slate-100"><img src="{{ brand_url($k, $default) }}" alt="" class="max-h-20 max-w-full object-contain"></div>
                    <input type="file" name="{{ $k }}" accept="image/png,image/jpeg,image/webp" class="input text-xs file:me-2 file:rounded-lg file:border-0 file:bg-brand-100 file:px-2 file:py-1 file:text-xs file:font-semibold">
                    @error($k)<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @if ($custom)<label class="mt-2 flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="reset_{{ $k }}" value="1" class="h-4 w-4 rounded border-slate-300">{{ __('Remove and use default') }}</label>@endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="card p-4 md:p-6">
        <h2 class="mb-1 font-semibold">{{ __('Security') }}</h2>
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="require_2fa_admins" value="1" @checked(biz('require_2fa_admins') === '1') class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand-700"><span><strong>{{ __('Require two-factor sign-in for admins') }}</strong><br><span class="text-xs text-slate-500">{{ __('Admins and the Super Admin must set up an authenticator app (Profile → Security) before they can use the system. Each person sets it up themselves; an admin who loses their phone and recovery codes can be reset from Users → Edit.') }}</span></span></label>
    </div>

    {{-- which menu items are shown --}}
    @php $hiddenMenu = \App\Support\Menu::hidden(); @endphp
    <div class="card p-4 md:p-6" x-data="{ setAll(on) { $root.querySelectorAll('.menu-cb:not(:disabled)').forEach(c => c.checked = on) } }">
        <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">{{ __('Menu') }}</h2>
            <div class="flex gap-2"><button type="button" class="btn btn-ghost btn-sm" @click="setAll(true)">{{ __('Show all') }}</button><button type="button" class="btn btn-ghost btn-sm" @click="setAll(false)">{{ __('Hide all optional') }}</button></div>
        </div>
        <p class="mb-3 text-xs text-slate-500">{{ __('Tick what you want in the side menu, the phone menu and the quick-add list. This only hides menu entries: what each person may open is still decided by Roles & permissions, and a hidden page can still be opened from a link. Dashboard and Settings are always shown.') }}</p>
        <input type="hidden" name="menu_submitted" value="1">
        <div class="grid gap-3 md:grid-cols-2">
            @foreach (\App\Support\Menu::groups() as $g => $items)
                <div class="rounded-xl p-3 ring-1 ring-slate-200">
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $g }}</p>
                    @foreach ($items as $i)
                        @php $locked = in_array($i[1], \App\Support\Menu::LOCKED, true); @endphp
                        <label class="flex min-h-9 items-center gap-2 text-sm {{ $locked ? 'text-slate-400' : 'text-slate-700' }}">
                            <input type="checkbox" name="menu[]" value="{{ $i[1] }}" class="menu-cb h-4 w-4 rounded border-slate-300 text-brand-700" @checked($locked || ! in_array($i[1], $hiddenMenu, true)) @disabled($locked)>
                            <span>{{ $i[0] }}</span>@if ($locked)<span class="text-[11px]">({{ __('always shown') }})</span>@endif
                        </label>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    <div class="card overflow-x-auto">
        <div class="border-b px-4 py-3"><h2 class="font-semibold">{{ __('Notifications') }}</h2><p class="text-xs text-slate-500">{{ __('Choose which events notify the admins, and on which channel.') }}</p></div>
        <table class="min-w-full">
            <thead class="bg-slate-50"><tr><th class="th">{{ __('Event') }}</th><th class="th text-center">{{ __('In-app') }}</th><th class="th text-center">{{ __('Email') }}</th><th class="th text-center">WhatsApp</th></tr></thead>
            <tbody class="divide-y">
                @foreach ($events as $e => $l)
                    <tr><td class="td whitespace-normal">{{ __($l) }}</td>
                        @foreach (['database', 'mail', 'whatsapp'] as $ch)
                            <td class="td text-center"><input type="checkbox" name="notify[{{ $e }}][{{ $ch }}]" value="1" @checked($matrix[$e][$ch] ?? false) class="h-5 w-5 rounded border-slate-300 text-brand-700"></td>
                        @endforeach</tr>
                @endforeach
            </tbody>
        </table>
        <label class="flex items-center gap-2 border-t px-4 py-3 text-sm"><input type="checkbox" name="low_stock_alerts" value="1" @checked($lowStock) class="h-5 w-5 rounded border-slate-300 text-brand-700">{{ __('Check stock levels when stock is used') }}</label>
        <p class="border-t bg-slate-50 px-4 py-3 text-xs text-slate-500">{{ __('Email needs SMTP settings in .env (MAIL_*). WhatsApp needs WHATSAPP_TOKEN and WHATSAPP_PHONE_ID in .env and a phone number on the user; until then messages are only logged.') }}</p>
    </div>

    @can('settings.edit')<button class="btn btn-primary">{{ __('Save settings') }}</button>@endcan
</form>
@endsection
