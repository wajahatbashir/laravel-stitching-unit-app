@extends('layouts.app')
@section('title', $own ? __('My ledger') : $w->name)
@section('content')
@php
    $u = auth()->user();
    $label = ['paid' => __('Paid in full'), 'partial' => __('Partially paid'), 'pending' => __('Pending')][$status];
    $cls = ['paid' => 'bg-emerald-100 text-emerald-800', 'partial' => 'bg-amber-100 text-amber-800', 'pending' => 'bg-rose-100 text-rose-800'][$status];
    $presets = [
        __('This week') => [now()->startOfWeek()->toDateString(), now()->toDateString()],
        __('Last 7 days') => [now()->subDays(6)->toDateString(), now()->toDateString()],
        __('Last 14 days') => [now()->subDays(13)->toDateString(), now()->toDateString()],
        __('This month') => [now()->startOfMonth()->toDateString(), now()->toDateString()],
        __('All time') => [null, null],
    ];
@endphp

<div class="space-y-4">
    <div class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div>
            <p class="text-lg font-bold">{{ $w->name }}</p>
            <p class="text-sm text-slate-500">{{ label($w->type) }} · {{ label($w->pay_cycle) }} @if ($w->phone) · {{ $w->phone }} @endif @if ($w->type === 'salaried') · {{ __('Salary') }} {{ money($w->monthly_salary) }} @endif</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if (! $own && $u->can('workers.edit'))<a href="{{ route('workers.edit', $w) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endif
            <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />Excel</a>
            <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />{{ __('Ledger PDF') }}</a>
        </div>
        <div class="w-full border-t pt-3"><p class="mb-2 text-xs font-semibold text-slate-500">{{ __('Payslip for the period below') }}</p>
            @include('documents._share', ['kind' => 'payslip', 'id' => $w->id, 'from' => $from, 'to' => $to, 'phone' => $w->phone,
                'text' => __('Payslip for :n — :l: :a. :b', ['n' => $w->name, 'l' => $closing < 0 ? __('Advance to adjust') : __('Balance to pay'), 'a' => money(abs($closing)), 'b' => biz('business_name')])])
        </div>
    </div>

    {{-- period filter --}}
    <form method="GET" class="card p-4">
        <div class="mb-3 flex flex-wrap gap-2">
            @foreach ($presets as $l => [$f, $t])
                <a href="{{ url()->current() }}?{{ http_build_query(array_filter(['date_from' => $f, 'date_to' => $t])) }}"
                   class="btn btn-ghost btn-sm {{ ($from ?? null) == $f && ($to ?? null) == $t ? '!bg-brand-100 !text-brand-800' : '' }}">{{ $l }}</a>
            @endforeach
        </div>
        <div class="grid grid-cols-2 items-end gap-3 md:grid-cols-4">
            <div><label class="label">{{ __('From') }}</label><input type="date" name="date_from" value="{{ $from }}" class="input"></div>
            <div><label class="label">{{ __('To') }}</label><input type="date" name="date_to" value="{{ $to }}" class="input"></div>
            <button class="btn btn-primary">{{ __('Apply') }}</button>
        </div>
    </form>

    {{-- summary --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Brought forward') }}</p><p class="mt-1 text-lg font-bold tabular-nums">{{ money($opening) }}</p><p class="text-[11px] text-slate-400">{{ __('balance before this period') }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Previous due') }}</p><p class="mt-1 text-lg font-bold tabular-nums text-amber-700">{{ money($prevDue) }}</p><p class="text-[11px] text-slate-400">{{ $w->opening_date && $w->openingDue() != 0 ? __('entered, as of :d', ['d' => fmt_date($w->opening_date)]) : __('owed from before the app') }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ $w->type === 'salaried' ? __('Salary & earnings') : __('Earned') }}</p><p class="mt-1 text-lg font-bold tabular-nums text-emerald-700">{{ money($earned) }}</p><p class="text-[11px] text-slate-400">{{ __('work / salary, net of deductions') }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Paid') }}</p><p class="mt-1 text-lg font-bold tabular-nums text-brand-700">{{ money($payments) }}</p><p class="text-[11px] text-slate-400">{{ __('wages / salary paid') }}</p></div>
        <div class="stat col-span-2 lg:col-span-1"><p class="text-xs text-slate-500">{{ __('Advances given') }}</p><p class="mt-1 text-lg font-bold tabular-nums text-amber-700">{{ money($advances) }}</p><p class="text-[11px] text-slate-400">{{ __('adjusted against what is owed') }}</p></div>
    </div>
    <div class="stat {{ $closing > 0 ? 'ring-2 ring-rose-300' : ($closing < 0 ? 'ring-2 ring-amber-300' : '') }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-xs text-slate-500">{{ $closing < 0 ? __('Advance still to adjust') : __('Pending — balance to pay') }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums {{ $closing > 0 ? 'text-rose-700' : ($closing < 0 ? 'text-amber-700' : 'text-emerald-700') }}">{{ money(abs($closing)) }}</p>
            </div>
            <span class="badge {{ $cls }}">{{ $label }}</span>
        </div>
        <p class="mt-2 text-xs text-slate-500">{{ __('Brought forward') }} {{ money($opening) }} + {{ __('Previous due') }} {{ money($prevDue) }} + {{ __('Earned') }} {{ money($earned) }} − {{ __('Paid') }} {{ money($payments) }} − {{ __('Advances') }} {{ money($advances) }} = <strong>{{ money($closing) }}</strong></p>
    </div>

    {{-- pay now --}}
    @if (! $own && $u->can('worker_payments.create'))
        <form method="POST" action="{{ route('worker-payments.store') }}" enctype="multipart/form-data" class="card p-4" data-offline data-after="{{ request()->getRequestUri() }}" data-label="{{ __('Payment') }} — {{ $w->name }}">
            @csrf
            <input type="hidden" name="uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="worker_id" value="{{ $w->id }}">
            <input type="hidden" name="period_from" value="{{ $from }}">
            <input type="hidden" name="period_to" value="{{ $to }}">
            <input type="hidden" name="back_to_worker" value="{{ $w->id }}">
            <p class="mb-3 font-semibold">{{ __('Record payment') }}</p>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div><label class="label">{{ __('Amount') }}</label><input type="number" step="0.01" name="amount" value="{{ $closing > 0 ? $closing : '' }}" class="input font-bold" required></div>
                <div><label class="label">{{ __('Date') }}</label><input type="date" name="date" value="{{ now()->toDateString() }}" class="input" required></div>
                <div><label class="label">{{ __('Type') }}</label><select name="type" class="input"><option value="payment">{{ __('Payment') }}</option><option value="advance">{{ __('Advance') }}</option></select></div>
                <div><label class="label">{{ __('Via') }}</label><select name="mode" class="input"><option value="cash">{{ __('Cash') }}</option><option value="bank">{{ __('Bank') }}</option></select></div>
                <div class="col-span-2 flex items-end md:col-span-1"><button class="btn btn-primary w-full">{{ __('Pay') }}</button></div>
            </div>
            <div class="mt-3"><label class="label" for="quick_slip">{{ __('Payment receipt (bank app screenshot / photo)') }}</label>
                <input id="quick_slip" type="file" name="receipt" accept="image/*,application/pdf" class="input file:me-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-800"></div>
            <p class="mt-2 text-xs text-slate-500">{{ __('Pay less than the balance for a partial payment — the remainder stays on the ledger and carries to the next payment.') }}</p>
        </form>
    @endif

    {{-- ledger --}}
    <div class="card overflow-hidden">
        <div class="border-b px-4 py-3 font-semibold">{{ __('Ledger') }}</div>
        @if ($lines->isEmpty())
            <p class="p-6 text-center text-slate-500">{{ __('No entries in this period.') }}</p>
        @else
            <div class="divide-y md:hidden">
                @foreach ($lines as $l)
                    <div class="p-3">
                        <div class="flex justify-between text-xs text-slate-400"><span>{{ fmt_date($l['date']) }}</span><span>{{ __('Balance') }} {{ money($l['balance']) }}</span></div>
                        <div class="mt-0.5 flex items-start justify-between gap-2 text-sm"><span class="min-w-0">{{ $l['desc'] }}@if (! empty($l['receipt'])) <a href="{{ Storage::url($l['receipt']) }}" target="_blank" class="text-xs text-brand-700 underline">📎 {{ __('Slip') }}</a>@endif</span>
                            <span class="shrink-0 font-semibold tabular-nums {{ $l['earned'] ? 'text-emerald-700' : 'text-brand-700' }}">{{ $l['earned'] > 0 ? '+'.money($l['earned']) : ($l['earned'] < 0 ? '−'.money(abs($l['earned'])) : '−'.money($l['paid'])) }}</span></div>
                    </div>
                @endforeach
            </div>
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full divide-y">
                    <thead class="bg-slate-50"><tr><th class="th">{{ __('Date') }}</th><th class="th">{{ __('Details') }}</th><th class="th text-end">{{ __('Earned') }}</th><th class="th text-end">{{ __('Paid') }}</th><th class="th text-end">{{ __('Balance') }}</th></tr></thead>
                    <tbody class="divide-y">
                        @foreach ($lines as $l)
                            <tr><td class="td">{{ fmt_date($l['date']) }}</td><td class="td whitespace-normal">{{ $l['desc'] }}@if (! empty($l['receipt'])) <a href="{{ Storage::url($l['receipt']) }}" target="_blank" class="ms-1 text-xs text-brand-700 underline">📎 {{ __('Slip') }}</a>@endif</td>
                                <td class="td text-end tabular-nums text-emerald-700">{{ $l['earned'] ? money($l['earned']) : '' }}</td>
                                <td class="td text-end tabular-nums text-brand-700">{{ $l['paid'] ? money($l['paid']) : '' }}</td>
                                <td class="td text-end font-medium tabular-nums">{{ money($l['balance']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
