@extends('layouts.app')
@section('title', __('Expense'))
@section('content')
@php
    $isImg = $e->receipt_path && preg_match('/\.(jpe?g|png|webp|gif)$/i', $e->receipt_path);
    $url = $e->receipt_path ? Storage::url($e->receipt_path) : null;
    $row = fn ($l, $v) => $v === null || $v === '' ? '' : "<div><dt class=\"text-xs text-slate-400\">".e($l)."</dt><dd class=\"text-sm\">".e($v)."</dd></div>";
@endphp
<div class="mx-auto max-w-3xl space-y-4">
    <div class="card p-4 md:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <span class="badge {{ badge_class($e->type) }}">{{ label($e->type) }}</span>
                <p class="mt-1 text-lg font-bold">{{ $e->title ?: ($e->category?->name ?? __('Expense')) }}</p>
                <p class="text-sm text-slate-500">{{ fmt_date($e->date) }} · {{ $e->payment_mode === 'partial' ? __('Partly on credit') : label($e->payment_mode) }}</p>
            </div>
            <div class="text-end">
                <p class="text-2xl font-bold tabular-nums">{{ money($e->base_amount) }}</p>
                @if ($e->currency && ! $e->currency->is_base)<p class="text-xs text-slate-500">{{ number_format($e->amount, 2) }} {{ $e->currency->code }} @ {{ (float) $e->exchange_rate }}</p>@endif
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3">
            {!! $row(__('Category'), $e->category?->name) !!}
            @if ($e->order)<div><dt class="text-xs text-slate-400">{{ __('Order') }}</dt><dd class="text-sm"><a class="text-brand-700 underline" href="{{ route('orders.show', $e->order) }}">{{ $e->order->order_no }}</a> · {{ $e->order->customer?->name }}</dd></div>@endif
            @if ($e->sharedOrders->isNotEmpty())<div class="col-span-2"><dt class="text-xs text-slate-400">{{ __('Shared by orders') }}</dt><dd class="mt-1 space-y-0.5 text-sm">
                @foreach ($e->sharedOrders->sortBy('order_no') as $so)<p class="flex justify-between gap-3"><span><a class="text-brand-700 underline" href="{{ route('orders.show', $so) }}">{{ $so->order_no }}</a> · {{ $so->customer?->name }}</span><span class="tabular-nums">{{ money($so->pivot->base_amount) }}</span></p>@endforeach
            </dd></div>@endif
            @if ($e->asset)<div><dt class="text-xs text-slate-400">{{ __('Asset') }}</dt><dd class="text-sm"><a class="text-brand-700 underline" href="{{ route('assets.show', $e->asset) }}">{{ $e->asset->name }}</a></dd></div>@endif
            {!! $row(__('Vendor'), $e->vendor?->name) !!}
            {!! $row(__('Paid to'), $e->payee) !!}
            @if ($e->payment_mode === 'partial')
                {!! $row(__('Paid now').' ('.label($e->paid_via).')', money($e->paidNowBase())) !!}
                {!! $row(__('On credit (we owe)'), money($e->creditAmount())) !!}
            @endif
            @if ($e->due_date){!! $row(__('Bill due date'), fmt_date($e->due_date)) !!}@endif
            {!! $row(__('Quantity'), $e->qty !== null ? (float) $e->qty : null) !!}
            {!! $row(__('Rate'), $e->rate !== null ? number_format($e->rate, 2) : null) !!}
            @foreach ($custom as $cf)
                @php $v = $e->custom[$cf->key] ?? null; @endphp
                @if ($v)
                    <div><dt class="text-xs text-slate-400">{{ $cf->label }}</dt>
                        <dd class="text-sm">@if ($cf->type === 'image')<a href="{{ Storage::url($v) }}" data-lightbox><img src="{{ Storage::url($v) }}" alt="" class="mt-1 h-20 rounded-lg object-cover ring-1 ring-slate-200"></a>@else{{ $v }}@endif</dd></div>
                @endif
            @endforeach
        </dl>
        @if ($bill)
            <div class="mt-4 rounded-xl p-3 ring-1 {{ $bill['status'] === 'paid' ? 'bg-emerald-50 ring-emerald-200' : ($bill['status'] === 'partial' ? 'bg-amber-50 ring-amber-200' : 'bg-rose-50 ring-rose-200') }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold">{{ __('Vendor bill') }} <span class="badge {{ badge_class($bill['status']) }}">{{ \App\Support\VendorBills::label($bill['status']) }}</span></p>
                    @if ($e->vendor)<a href="{{ route('vendors.show', $e->vendor) }}" class="text-sm text-brand-700 underline">{{ __('Vendor ledger') }}</a>@endif
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                    <div><dt class="text-xs text-slate-500">{{ __('Bill (credit part)') }}</dt><dd class="tabular-nums">{{ money($bill['credit']) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">{{ __('Paid to the vendor') }}</dt><dd class="tabular-nums">{{ money($bill['paid']) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">{{ __('Still to pay') }}</dt><dd class="font-semibold tabular-nums">{{ money($bill['left']) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">{{ __('Due date') }}</dt><dd>{{ fmt_date($bill['due']) }}</dd></div>
                </dl>
                <p class="mt-1 text-xs text-slate-500">{{ __('Vendor payments clear the oldest bills first.') }}</p>
            </div>
        @endif
        @if ($e->notes)<p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">{{ $e->notes }}</p>@endif

        <div class="mt-4 flex flex-wrap gap-2">
            @can('expenses.edit')<a href="{{ route('expenses.edit', $e) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endcan
            <a href="{{ route('expenses.index') }}" class="btn btn-ghost btn-sm">← {{ __('Expenses') }}</a>
        </div>
    </div>

    <div class="card p-4 md:p-5">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="font-semibold">{{ __('Payment receipt') }}</h2>
            @if ($url)<div class="flex gap-2">
                <a href="{{ $url }}" target="_blank" class="btn btn-ghost btn-sm">{{ __('Open') }}</a>
                <a href="{{ $url }}" download class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />{{ __('Download') }}</a>
            </div>@endif
        </div>
        @if ($isImg)
            <a href="{{ $url }}" data-lightbox class="block"><img src="{{ $url }}" alt="{{ __('Payment receipt') }}" class="mx-auto max-h-[34rem] w-auto max-w-full rounded-xl object-contain ring-1 ring-slate-200"></a>
        @elseif ($url)
            <a href="{{ $url }}" target="_blank" class="block rounded-xl bg-slate-50 p-6 text-center font-medium text-brand-700 underline">📄 {{ basename($e->receipt_path) }}</a>
        @else
            <p class="rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-500">{{ __('No receipt uploaded.') }}</p>
        @endif
        @if ($e->ocr_text)
            <details class="mt-3 text-xs text-slate-500"><summary class="cursor-pointer font-medium">{{ __('Text read from the receipt') }}</summary><pre class="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3">{{ $e->ocr_text }}</pre></details>
        @endif
    </div>
</div>
@endsection
