@extends('layouts.app')
@section('title', __('Reset test data'))
@section('content')
@php $total = array_sum($counts); @endphp
<form method="POST" action="{{ route('reset.run') }}" class="mx-auto max-w-2xl space-y-4" x-data="{ busy: false }" @submit="busy = true">
    @csrf
    <div class="rounded-2xl bg-rose-50 p-4 text-sm text-rose-900 ring-1 ring-rose-300">
        <p class="text-base font-bold">⚠ {{ __('Start fresh for production') }}</p>
        <p class="mt-1">{{ __('Use this once, after testing, to remove every test record and begin entering the real business data.') }}</p>
        <ul class="mt-2 list-disc space-y-1 ps-5">
            <li>{{ __('A safety backup is taken first (type "Before data reset"). You can restore it from the Backups page if you reset by mistake.') }}</li>
            <li>{{ __('Order and invoice numbers start again from 1.') }}</li>
            <li>{{ __('Only the Super Admin can see and use this page.') }}</li>
        </ul>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="card p-4">
            <p class="mb-2 font-semibold text-rose-700">{{ __('Will be deleted') }} ({{ number_format($total) }} {{ __('records') }})</p>
            <p class="mb-2 text-xs text-slate-500">{{ __('Customers, orders, invoices, payments, expenses, vendors, workers, work entries, salaries, production, deliveries, assets, investments, inventory, month closes, notifications, activity log and all uploaded photos / receipts.') }}</p>
            <dl class="max-h-56 divide-y overflow-y-auto text-sm">
                @foreach ($counts as $t => $n)@if ($n)<div class="flex justify-between py-1"><dt>{{ str_replace('_', ' ', $t) }}</dt><dd class="tabular-nums">{{ number_format($n) }}</dd></div>@endif @endforeach
                @if (! $total)<p class="py-2 text-slate-500">{{ __('Nothing to delete — the system is already empty.') }}</p>@endif
            </dl>
        </div>
        <div class="card p-4">
            <p class="mb-2 font-semibold text-emerald-700">{{ __('Will be kept') }}</p>
            <ul class="list-disc space-y-1 ps-5 text-sm text-slate-700">
                <li>{{ __('Users, roles and permissions (including your login)') }}</li>
                <li>{{ __('Settings, logos and notification choices') }}</li>
                <li>{{ __('Garments & rate card, expense categories, custom fields, currencies') }}</li>
                <li>{{ __('Existing backups') }}</li>
            </ul>
        </div>
    </div>

    <div class="card space-y-4 p-4 md:p-6">
        @if ($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <div><label class="label" for="confirm">{{ __('Type RESET to confirm') }}</label><input id="confirm" name="confirm" class="input font-mono" autocomplete="off" required></div>
        <div><label class="label" for="pw">{{ __('Your password') }}</label><input id="pw" type="password" name="password" class="input" autocomplete="current-password" required></div>
        <div class="flex flex-wrap gap-2">
            <button class="btn btn-danger" :disabled="busy"><span x-text="busy ? '{{ __('Resetting… do not close this page') }}' : '{{ __('Delete all test data') }}'"></span></button>
            <a href="{{ route('dashboard') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        </div>
    </div>
</form>
@endsection
