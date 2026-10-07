@extends('layouts.app')
@section('title', __('Production board'))
@section('actions')
    @can('production.create')
        <a href="{{ route('production-logs.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('Log production') }}</a>
        <a href="{{ route('production-rejects.create') }}" class="btn btn-ghost btn-sm">{{ __('Reject / rework') }}</a>
    @endcan
@endsection
@section('content')
@php
    $short = ['cutting' => __('Cut'), 'stitching' => __('Stitched'), 'finishing' => __('Finished'), 'quality' => __('QC'), 'packing' => __('Packed')];
@endphp
<div class="space-y-4">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <select name="customer_id" class="input !w-auto !py-2 text-sm" onchange="this.form.submit()">
            <option value="">{{ __('All clients') }}</option>
            @foreach ($customers as $id => $n)<option value="{{ $id }}" @selected((string) request('customer_id') === (string) $id)>{{ $n }}</option>@endforeach
        </select>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="all" value="1" class="h-4 w-4 rounded border-slate-300" @checked(request('all')) onchange="this.form.submit()">{{ __('Show delivered orders') }}</label>
        <span class="ms-auto text-sm text-slate-500">{{ $total }} {{ __('orders') }} @if ($late)· <strong class="text-rose-600">{{ $late }} {{ __('late') }}</strong>@endif</span>
    </form>

    <div class="-mx-4 overflow-x-auto px-4 pb-3 md:mx-0 md:px-0">
        <div class="flex min-w-max gap-3">
            @foreach ($columns as $key => $label)
                <section class="w-[16.5rem] shrink-0">
                    <header class="mb-2 flex items-center justify-between rounded-xl bg-slate-100 px-3 py-2">
                        <h2 class="text-sm font-semibold text-slate-800">{{ $label }}</h2>
                        <span class="badge bg-white text-slate-700">{{ count($cards[$key]) }}</span>
                    </header>
                    <div class="space-y-2">
                        @forelse ($cards[$key] as $c)
                            @php $o = $c['order']; $s = $c['s']; @endphp
                            <div class="card p-3 {{ $c['late'] ? 'ring-2 !ring-rose-400' : '' }}">
                                <div class="flex items-start justify-between gap-2">
                                    <a href="{{ route('orders.show', $o) }}" class="font-semibold text-brand-800 hover:underline">{{ $o->order_no }}</a>
                                    @if ($c['days'] !== null)
                                        <span class="badge shrink-0 {{ $c['late'] ? 'bg-rose-100 text-rose-800' : ($c['days'] <= 3 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600') }}">
                                            {{ $c['late'] ? abs($c['days']).' '.__('days late') : ($c['days'] === 0 ? __('Due today') : fmt_date($o->due_date)) }}</span>
                                    @endif
                                </div>
                                <p class="truncate text-xs text-slate-500">{{ $o->customer->name }}@if ($o->collection_name) · {{ $o->collection_name }}@endif</p>
                                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-2 rounded-full" style="width: {{ $s['percent'] }}%; background: var(--viz-rev, #b8893a)"></div></div>
                                <p class="mt-1 flex justify-between text-[11px] text-slate-500"><span>{{ $s['percent'] }}%</span><span class="tabular-nums">{{ number_format($o->qty) }} {{ __('pcs') }}</span></p>
                                <div class="mt-2 flex flex-wrap gap-1 text-[11px]">
                                    @foreach ($s['stages'] as $k => $n)@if ($n > 0)<span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-slate-700">{{ $short[$k] }} {{ number_format($n) }}</span>@endif @endforeach
                                    @if ($s['delivered'])<span class="rounded-md bg-emerald-100 px-1.5 py-0.5 text-emerald-800">{{ __('Delivered') }} {{ number_format($s['delivered']) }}</span>@endif
                                    @if ($s['rejected'])<span class="rounded-md bg-rose-100 px-1.5 py-0.5 text-rose-800">{{ __('Rejected') }} {{ $s['rejected'] }}</span>@endif
                                    @if ($s['rework'])<span class="rounded-md bg-amber-100 px-1.5 py-0.5 text-amber-800">{{ __('Rework') }} {{ $s['rework'] }}</span>@endif
                                </div>
                                @can('production.create')
                                    <a href="{{ route('production-logs.create', ['order_id' => $o->id, 'stage' => $s['current'] ?? 'cutting']) }}" class="mt-2 inline-block text-xs font-semibold text-brand-700 underline">+ {{ __('Log pieces') }}</a>
                                @endcan
                            </div>
                        @empty
                            <p class="rounded-xl border border-dashed border-slate-300 p-4 text-center text-xs text-slate-400">{{ __('No orders') }}</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</div>
@endsection
