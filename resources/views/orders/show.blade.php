@extends('layouts.app')
@section('title', $o->order_no)
@section('content')
@php $u = auth()->user(); @endphp
<div class="space-y-4">
    <div class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xl font-bold">{{ $o->order_no }} <span class="badge {{ badge_class($o->status) }}">{{ label($o->status) }}</span></p>
                <p class="text-sm text-slate-500"><a class="text-brand-700 underline" href="{{ route('customers.show', $o->customer_id) }}">{{ $o->customer->name }}</a> · {{ fmt_date($o->date) }} @if ($o->due_date) · {{ __('Due') }} {{ fmt_date($o->due_date) }} @endif</p>
                <p class="mt-1 text-sm">{{ $o->collection_name }} @if ($o->fabric_name) · {{ $o->fabric_name }} @endif · <strong>{{ number_format($o->qty) }}</strong> {{ __('pcs') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($u->can('orders.edit'))<a href="{{ route('orders.edit', $o) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endif
                @if ($u->can('expenses.create'))<a href="{{ route('expenses.create', ['type' => 'order', 'order_id' => $o->id]) }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('Add expense') }}</a>@endif
                @if ($u->can('work_entries.create'))<a href="{{ route('work-entries.create', ['order_id' => $o->id]) }}" class="btn btn-ghost btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('Work entry') }}</a>@endif
                @if ($u->can('invoices.create'))<a href="{{ route('invoices.create', ['order_id' => $o->id]) }}" class="btn btn-gold btn-sm">{{ __('Invoice') }}</a>@endif
            </div>
        </div>
        @if ($o->notes)<p class="mt-3 text-sm text-slate-600">{{ $o->notes }}</p>@endif
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Billed amount') }}</p><p class="mt-1 text-lg font-bold tabular-nums">{{ money($billed) }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Material cost') }}</p><p class="mt-1 text-lg font-bold tabular-nums">{{ money($material) }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Labour cost') }}</p><p class="mt-1 text-lg font-bold tabular-nums">{{ money($labour) }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Cost per piece') }}</p><p class="mt-1 text-lg font-bold tabular-nums">{{ money($o->qty ? ($material + $labour) / $o->qty : 0) }}</p></div>
        <div class="stat col-span-2 lg:col-span-1"><p class="text-xs text-slate-500">{{ $profit >= 0 ? __('Profit') : __('Loss') }}</p><p class="mt-1 text-xl font-bold tabular-nums {{ $profit >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ money($profit) }}</p></div>
    </div>

    @can('production.view')@include('orders._production', ['o' => $o, 'prod' => $prod])@endcan

    <div class="card overflow-hidden">
        <div class="border-b px-4 py-3 font-semibold">{{ __('Expenses on this order') }}</div>
        @forelse ($expenses as $e)
            <div class="flex items-center justify-between gap-3 border-b px-4 py-2.5 text-sm last:border-0">
                <div class="min-w-0"><p class="truncate font-medium">{{ $e->category?->name }} @if ($e->title && $e->title !== $e->category?->name) — {{ $e->title }} @endif</p><p class="text-xs text-slate-400">{{ fmt_date($e->date) }} @if ($e->payee) · {{ $e->payee }} @endif</p></div>
                <span class="shrink-0 tabular-nums">{{ money($e->base_amount) }}</span>
            </div>
        @empty <p class="p-4 text-sm text-slate-500">{{ __('No expenses yet.') }}</p> @endforelse
    </div>

    <div class="card overflow-hidden">
        <div class="border-b px-4 py-3 font-semibold">{{ __('Stitching work on this order') }}</div>
        @forelse ($work as $w)
            <div class="flex items-center justify-between gap-3 border-b px-4 py-2.5 text-sm last:border-0">
                <div class="min-w-0"><p class="truncate font-medium">{{ $w->worker->name }} — {{ $w->garmentType->name }} × {{ $w->qty }}</p><p class="text-xs text-slate-400">{{ fmt_date($w->date) }} · {{ money($w->rate) }}/{{ __('pc') }}</p></div>
                <span class="shrink-0 tabular-nums">{{ money($w->amount) }}</span>
            </div>
        @empty <p class="p-4 text-sm text-slate-500">{{ __('No work entries yet.') }}</p> @endforelse
    </div>

    @if ($o->invoices->isNotEmpty())
        <div class="card p-4"><p class="mb-2 font-semibold">{{ __('Invoices') }}</p>
            @foreach ($o->invoices as $i)<a class="flex justify-between border-t py-2 text-sm" href="{{ route('invoices.show', $i) }}"><span>{{ $i->number }}</span><span class="badge {{ badge_class($i->status()) }}">{{ label($i->status()) }}</span></a>@endforeach</div>
    @endif
    @if ($o->attachments->isNotEmpty())
        <div class="card p-4"><p class="mb-2 font-semibold">{{ __('Attachments') }}</p>
            <div class="flex flex-wrap gap-3">@foreach ($o->attachments as $a)
                <a href="{{ Storage::url($a->path) }}" @if (str_starts_with((string) $a->mime, 'image/')) data-lightbox @else target="_blank" @endif class="block w-24 text-center text-xs">
                    @if (str_starts_with((string) $a->mime, 'image/'))<img src="{{ Storage::url($a->path) }}" class="h-24 w-24 rounded-lg object-cover ring-1 ring-slate-200" alt="">@else<div class="flex h-24 w-24 items-center justify-center rounded-lg bg-slate-100">PDF</div>@endif
                    <span class="mt-1 block truncate">{{ $a->name }}</span></a>
            @endforeach</div></div>
    @endif
</div>
@endsection
