@extends('layouts.app')
@section('title', $a->name)
@section('content')
<div class="space-y-4">
    <div class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><p class="text-xl font-bold">{{ $a->name }} <span class="badge {{ badge_class($a->status) }}">{{ label($a->status) }}</span></p>
                <p class="text-sm text-slate-500">{{ label($a->type) }} @if ($a->asset_no) · #{{ $a->asset_no }} @endif @if ($a->brand) · {{ $a->brand }} {{ $a->model }} @endif</p></div>
            <div class="text-end"><p class="text-xs text-slate-500">{{ __('Cost') }}</p><p class="text-xl font-bold tabular-nums">{{ money($a->cost) }}</p></div>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
            <div><dt class="text-xs text-slate-400">{{ __('Purchased') }}</dt><dd>{{ fmt_date($a->purchase_date) }}</dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Vendor') }}</dt><dd>{{ $a->vendor?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Serial no.') }}</dt><dd>{{ $a->serial_no ?: '—' }}</dd></div>
            <div><dt class="text-xs text-slate-400">{{ __('Location') }}</dt><dd>{{ $a->location ?: '—' }}</dd></div>
        </dl>
        @if ($a->notes)<p class="mt-3 text-sm text-slate-600">{{ $a->notes }}</p>@endif
        @can('assets.edit')<a href="{{ route('assets.edit', $a) }}" class="btn btn-ghost btn-sm mt-3"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endcan
        @can('expenses.create')<a href="{{ route('expenses.create', ['type' => 'unit', 'asset_id' => $a->id]) }}" class="btn btn-primary btn-sm mt-3"><x-icon name="plus" class="h-4 w-4" />{{ __('Add repair / expense') }}</a>@endcan
    </div>
    @if ($a->attachments->isNotEmpty())
        <div class="card p-4"><div class="flex flex-wrap gap-3">@foreach ($a->attachments as $f)
            <a href="{{ Storage::url($f->path) }}" target="_blank">@if (str_starts_with((string) $f->mime, 'image/'))<img src="{{ Storage::url($f->path) }}" class="h-28 w-28 rounded-lg object-cover ring-1 ring-slate-200" alt="">@else<span class="badge bg-slate-100">{{ $f->name }}</span>@endif</a>
        @endforeach</div></div>
    @endif
    <div class="card overflow-hidden"><div class="border-b px-4 py-3 font-semibold">{{ __('Repairs & related expenses') }} — {{ money($expenses->sum('base_amount')) }}</div>
        @forelse ($expenses as $e)<div class="flex justify-between border-b px-4 py-2.5 text-sm last:border-0"><span>{{ fmt_date($e->date) }} · {{ $e->title ?: $e->category?->name }}</span><span class="tabular-nums">{{ money($e->base_amount) }}</span></div>
        @empty<p class="p-4 text-sm text-slate-500">{{ __('Nothing recorded.') }}</p>@endforelse</div>
</div>
@endsection
