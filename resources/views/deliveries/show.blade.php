@extends('layouts.app')
@section('title', $d->challan_no)
@section('actions')
    <a href="{{ route('deliveries.pdf', $d->id) }}" class="btn btn-primary btn-sm"><x-icon name="download" class="h-4 w-4" />{{ __('Challan PDF') }}</a>
    @can('deliveries.edit')<a href="{{ route('deliveries.edit', $d) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endcan
@endsection
@section('content')
<div class="mx-auto max-w-2xl space-y-4">
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><p class="text-xl font-bold">{{ $d->challan_no }}</p><p class="text-sm text-slate-500">{{ fmt_date($d->date) }}</p></div>
            <div class="text-end"><p class="text-3xl font-bold tabular-nums">{{ number_format($d->qty) }}</p><p class="text-xs text-slate-500">{{ __('pieces in this delivery') }}</p></div>
        </div>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-3">
            <div><dt class="text-xs text-slate-400">{{ __('Client') }}</dt><dd>{{ $d->order->customer->name }}</dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Order') }}</dt><dd><a class="text-brand-700 underline" href="{{ route('orders.show', $d->order) }}">{{ $d->order->order_no }}</a></dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Collection / Brand') }}</dt><dd>{{ $d->order->collection_name ?: '—' }}</dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Vehicle / rider') }}</dt><dd>{{ $d->vehicle ?: '—' }}</dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Received by') }}</dt><dd>{{ $d->received_by ?: '—' }}</dd></div>
        </dl>
        @if ($d->notes)<p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">{{ $d->notes }}</p>@endif
    </div>
    <div class="card p-5">
        <p class="mb-2 font-semibold">{{ __('Order progress') }}</p>
        <p class="text-sm">{{ __('Delivered so far') }}: <strong class="tabular-nums">{{ number_format($sum['delivered']) }}</strong> / {{ number_format($sum['qty']) }} {{ __('pcs') }} — {{ __('balance') }} <strong class="tabular-nums">{{ number_format(max(0, $sum['qty'] - $sum['delivered'])) }}</strong></p>
        @if ($sum['stages']['packing'] && $sum['delivered'] > $sum['stages']['packing'])<p class="mt-2 text-xs text-amber-700">⚠ {{ __('More pieces delivered than recorded as packed.') }}</p>@endif
    </div>
</div>
@endsection
