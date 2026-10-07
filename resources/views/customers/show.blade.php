@extends('layouts.app')
@section('title', $c->name)
@section('content')
<div class="space-y-4">
    <div class="card flex flex-wrap items-center justify-between gap-3 p-4">
        <div><p class="text-lg font-bold">{{ $c->name }}</p><p class="text-sm text-slate-500">{{ $c->contact_person }} @if ($c->phone) · {{ $c->phone }} @endif @if ($c->email) · {{ $c->email }} @endif</p></div>
        <div class="text-end"><p class="text-xs text-slate-500">{{ __('Outstanding receivable') }}</p><p class="text-2xl font-bold tabular-nums {{ $c->receivable() > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ money($c->receivable()) }}</p>
            @if ($c->advance() > 0)<p class="text-sm font-semibold text-amber-700">{{ __('Advance held (unused)') }}: {{ money($c->advance()) }}</p>@endif
            @can('reports.customer_ledger')<a href="{{ route('reports.show', ['key' => 'customer_ledger', 'customer_id' => $c->id]) }}" class="text-sm text-brand-700 underline">{{ __('Full ledger') }}</a>@endcan</div>
    </div>
    <div class="card p-4">
        <form method="GET" class="mb-3 flex flex-wrap items-end gap-2">
            <div><label class="label">{{ __('Statement from') }}</label><input type="date" name="date_from" value="{{ request('date_from') }}" class="input"></div>
            <div><label class="label">{{ __('To') }}</label><input type="date" name="date_to" value="{{ request('date_to') }}" class="input"></div>
            <button class="btn btn-ghost btn-sm">{{ __('Apply') }}</button>
        </form>
        @include('documents._share', ['kind' => 'statement', 'id' => $c->id, 'from' => request('date_from'), 'to' => request('date_to'), 'phone' => $c->phone, 'email' => $c->email ?? '',
            'text' => __('Account statement from :b — outstanding :a.', ['b' => biz('business_name'), 'a' => money($c->receivable())])])
    </div>
    <div class="card overflow-hidden"><div class="border-b px-4 py-3 font-semibold">{{ __('Orders') }} ({{ $orders->count() }})</div>
        @forelse ($orders as $o)<a href="{{ route('orders.show', $o) }}" class="flex items-center justify-between gap-2 border-b px-4 py-2.5 text-sm last:border-0"><span>{{ $o->order_no }} · {{ $o->collection_name }} · {{ $o->qty }} {{ __('pcs') }}</span><span class="badge {{ badge_class($o->status) }}">{{ label($o->status) }}</span></a>
        @empty<p class="p-4 text-sm text-slate-500">{{ __('No orders yet.') }}</p>@endforelse</div>
    <div class="card overflow-hidden"><div class="border-b px-4 py-3 font-semibold">{{ __('Invoices') }}</div>
        @forelse ($invoices as $i)<a href="{{ route('invoices.show', $i) }}" class="flex items-center justify-between gap-2 border-b px-4 py-2.5 text-sm last:border-0"><span>{{ $i->number }} · {{ fmt_date($i->date) }}</span><span class="tabular-nums">{{ money($i->total) }} <span class="badge {{ badge_class($i->status()) }}">{{ label($i->status()) }}</span></span></a>
        @empty<p class="p-4 text-sm text-slate-500">{{ __('No invoices yet.') }}</p>@endforelse</div>
    <div class="card overflow-hidden"><div class="border-b px-4 py-3 font-semibold">{{ __('Payments received') }}</div>
        @forelse ($payments as $p)
            <div class="border-b px-4 py-2.5 text-sm last:border-0">
                <div class="flex justify-between gap-2"><span>{{ fmt_date($p->date) }} · {{ label($p->mode) }}@if ($p->reference) · {{ $p->reference }}@endif</span><span class="font-medium tabular-nums">{{ money($p->amount) }}</span></div>
                @if ($p->invoice_id)
                    <p class="text-xs text-slate-500">{{ __('Paid against') }} <a class="text-brand-700 underline" href="{{ route('invoices.show', $p->invoice_id) }}">{{ $p->invoice?->number }}</a></p>
                @else
                    <p class="mt-0.5 text-xs">
                        <span class="badge bg-amber-100 text-amber-800">{{ __('Advance') }}</span>
                        @foreach ($p->allocations as $a)<span class="text-slate-500"> · {{ money($a->amount) }} → <a class="text-brand-700 underline" href="{{ route('invoices.show', $a->invoice_id) }}">{{ $a->invoice?->number }}</a> ({{ fmt_date($a->date) }})</span>@endforeach
                        <span class="{{ $p->unapplied() > 0 ? 'font-semibold text-emerald-700' : 'text-slate-400' }}"> · {{ __('unused') }} {{ money($p->unapplied()) }}</span>
                    </p>
                @endif
            </div>
        @empty<p class="p-4 text-sm text-slate-500">{{ __('No payments yet.') }}</p>@endforelse</div>
</div>
@endsection
