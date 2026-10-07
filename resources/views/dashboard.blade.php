@extends('layouts.app')
@section('title', __('Dashboard'))
@section('content')
@php $stat = fn ($label, $value, $tone = 'text-slate-900', $sub = null) => compact('label', 'value', 'tone', 'sub'); @endphp

<div class="space-y-6">
    @if (! empty($backupWarn))
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-rose-50 p-4 text-sm text-rose-900 ring-1 ring-rose-300">
            <p><strong>{{ __('Backup warning') }}:</strong> {{ $backupWarn }} {{ __('Your data is not protected.') }}</p>
            <a href="{{ route('backups.index') }}" class="btn btn-danger btn-sm">{{ __('Open backups') }}</a>
        </div>
    @endif
    @isset($me)
        <div class="card !bg-[#0a0a0a] p-5 text-white ring-1 !ring-gold-500/40">
            <p class="text-sm text-brand-200">{{ __('My balance') }} — {{ $me->name }}</p>
            <p class="mt-1 text-3xl font-bold tabular-nums">{{ money(abs($myBalance)) }}</p>
            <p class="text-sm text-brand-100">{{ $myBalance > 0 ? __('Payable to me') : ($myBalance < 0 ? __('Advance to adjust') : __('All settled')) }}</p>
            <a href="{{ route('my-ledger') }}" class="btn btn-gold btn-sm mt-3">{{ __('View my ledger') }}</a>
        </div>
    @endisset

    @isset($cb)
        <section>
            <h2 class="mb-2 text-sm font-semibold text-slate-500 uppercase">{{ __('Money position') }} · {{ __('today') }}</h2>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    [__('Cash in hand'), money($cb['cash']['balance']), $cb['cash']['balance'] < 0 ? 'text-rose-700' : 'text-slate-900', null],
                    [__('Bank'), money($cb['bank']['balance']), $cb['bank']['balance'] < 0 ? 'text-rose-700' : 'text-slate-900', null],
                    [__('Receivable'), money($recv), 'text-emerald-700', __('from customers').($custAdv > 0 ? ' · '.__('advances held').' '.money($custAdv) : '')],
                    [__('Payable'), money($payable + max(0, $vendorPay)), 'text-rose-700', __('workers').' '.money($payable).($vendorPay > 0 ? ' · '.__('vendors').' '.money($vendorPay) : '')],
                ] as [$l, $v, $t, $s])
                    <div class="stat"><p class="text-xs text-slate-500">{{ $l }}</p><p class="mt-1 whitespace-nowrap text-[0.95rem] font-bold tabular-nums sm:text-lg md:text-xl {{ $t }}">{{ $v }}</p>@if($s)<p class="text-[11px] text-slate-400">{{ $s }}</p>@endif</div>
                @endforeach
            </div>
        </section>
        @include('dashboard._period')
        @include('dashboard._stats')
    @endisset

    @isset($series)
        @include('dashboard._charts')
    @endisset

    @if (! isset($stats) && isset($expTotal))
        @include('dashboard._period')
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        @isset($orders)
            <section class="card p-4">
                <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">{{ __('Orders') }}</h2><a href="{{ route('orders.index') }}" class="text-sm text-brand-700">{{ __('All') }}</a></div>
                <div class="mb-3 grid grid-cols-2 gap-3 text-center">
                    <div class="rounded-xl bg-brand-50 p-3"><p class="text-2xl font-bold text-brand-800">{{ $orders['open'] }}</p><p class="text-xs text-slate-500">{{ __('Open orders') }}</p></div>
                    <div class="rounded-xl bg-gold-400/20 p-3"><p class="text-2xl font-bold text-brand-800">{{ number_format($orders['pieces']) }}</p><p class="text-xs text-slate-500">{{ __('Pieces in progress') }}</p></div>
                </div>
                @isset($pipeline)
                    <div class="mb-3 flex flex-wrap gap-1.5 text-[11px]" title="{{ __('Open orders by production stage') }}">
                        @foreach ($pipeline as $k => $n)
                            <a href="{{ route('production.board') }}" class="rounded-lg px-2 py-1 {{ $n ? 'bg-brand-100 font-semibold text-brand-800' : 'bg-slate-100 text-slate-400' }}">{{ $k === 'new' ? __('Not started') : \App\Support\Production::label($k) }} · {{ $n }}</a>
                        @endforeach
                    </div>
                @endisset
                @if ($orders['late']->isNotEmpty())
                    <div class="mb-3 rounded-xl bg-rose-50 p-3 ring-1 ring-rose-200">
                        <p class="mb-1 text-xs font-bold text-rose-700 uppercase">⚠ {{ __('Overdue orders') }} ({{ $orders['late']->count() }})</p>
                        @foreach ($orders['late'] as $o)
                            <a href="{{ route('orders.show', $o) }}" class="flex justify-between py-1 text-sm"><span>{{ $o->order_no }} · {{ $o->customer->name }}</span><span class="font-semibold text-rose-700">{{ (int) $o->due_date->diffInDays(now()->startOfDay()) }} {{ __('days late') }}</span></a>
                        @endforeach
                    </div>
                @endif
                @if ($orders['due']->isNotEmpty())
                    <p class="mb-1 text-xs font-semibold text-rose-600 uppercase">{{ __('Due within 7 days') }}</p>
                    @foreach ($orders['due'] as $o)
                        <a href="{{ route('orders.show', $o) }}" class="flex justify-between border-t py-2 text-sm"><span>{{ $o->order_no }} · {{ $o->customer->name }}</span><span class="text-rose-600">{{ fmt_date($o->due_date) }}</span></a>
                    @endforeach
                @endif
                <p class="mb-1 mt-3 text-xs font-semibold text-slate-400 uppercase">{{ __('Recent') }}</p>
                @foreach ($orders['recent'] as $o)
                    <a href="{{ route('orders.show', $o) }}" class="flex justify-between border-t py-2 text-sm"><span>{{ $o->order_no }} · {{ $o->customer->name }}</span><span class="badge {{ badge_class($o->status) }}">{{ label($o->status) }}</span></a>
                @endforeach
            </section>
        @endisset

        @isset($expTotal)
            <section class="card p-4">
                <div class="mb-3 flex items-center justify-between"><h2 class="font-semibold">{{ __('Expenses') }} · {{ $p->label() }}</h2><a href="{{ route('expenses.index') }}" class="text-sm text-brand-700">{{ __('All') }}</a></div>
                <p class="mb-3 text-2xl font-bold tabular-nums">{{ money($expTotal) }}</p>
                @foreach (['order', 'unit', 'labour', 'other'] as $t)
                    @php $v = (float) ($expByType[$t] ?? 0); $pct = $expTotal > 0 ? round($v / $expTotal * 100) : 0; @endphp
                    <div class="mb-2"><div class="flex justify-between text-sm"><span>{{ label($t) }}</span><span class="tabular-nums">{{ money($v) }}</span></div>
                        <div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-600" style="width: {{ $pct }}%"></div></div></div>
                @endforeach
                <p class="mb-1 mt-3 text-xs font-semibold text-slate-400 uppercase">{{ __('Recent') }}</p>
                @foreach ($recentExpenses as $e)
                    <div class="flex justify-between border-t py-2 text-sm"><span class="truncate">{{ $e->title ?: $e->category?->name }}</span><span class="tabular-nums">{{ money($e->base_amount) }}</span></div>
                @endforeach
            </section>
        @endisset

        @if (isset($low) && $low->isNotEmpty())
            <section class="card p-4">
                <h2 class="mb-2 font-semibold text-rose-700">{{ __('Low stock') }}</h2>
                @foreach ($low as $i)<div class="flex justify-between border-t py-2 text-sm"><span>{{ $i->name }}</span><span>{{ $i->stock() }} {{ $i->unit }}</span></div>@endforeach
            </section>
        @endif
    </div>
</div>
@endsection
