{{-- Production progress card on the order page. Needs $o and $prod (App\Support\Production::summary). --}}
@php
    $u = auth()->user();
    $logs = \App\Models\ProductionLog::withoutGlobalScopes()->with('worker')->where('order_id', $o->id)->latest('date')->latest('id')->limit(6)->get();
    $deliveries = \App\Models\Delivery::where('order_id', $o->id)->orderBy('date')->orderBy('id')->get();
    $cap = $prod['qty'] ?: 1;
@endphp
<div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
        <div><h2 class="font-semibold">{{ __('Production') }}</h2>
            <p class="text-xs text-slate-500">{{ number_format($prod['qty']) }} {{ __('pieces ordered') }} · {{ $prod['percent'] }}% {{ __('through') }}</p></div>
        <div class="flex flex-wrap gap-2">
            @can('production.create')
                <a href="{{ route('production-logs.create', ['order_id' => $o->id, 'stage' => $prod['current'] ?? 'cutting']) }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('Log pieces') }}</a>
                <a href="{{ route('production-rejects.create', ['order_id' => $o->id]) }}" class="btn btn-ghost btn-sm">{{ __('Reject / rework') }}</a>
            @endcan
            @can('deliveries.create')<a href="{{ route('deliveries.create', ['order_id' => $o->id]) }}" class="btn btn-gold btn-sm">{{ __('New delivery') }}</a>@endcan
        </div>
    </div>
    <div class="space-y-3 p-4">
        @foreach (\App\Support\Production::STAGES as $k => $label)
            @php $n = $prod['stages'][$k]; $p = min(100, round($n / $cap * 100)); @endphp
            <div>
                <div class="flex justify-between text-sm"><span>{{ __($label) }}</span><span class="tabular-nums"><strong>{{ number_format($n) }}</strong> <span class="text-slate-400">/ {{ number_format($prod['qty']) }}</span></span></div>
                <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-2.5 rounded-full" style="width: {{ $p }}%; background: var(--viz-rev, #b8893a)"></div></div>
            </div>
        @endforeach
        <div>
            <div class="flex justify-between text-sm"><span>{{ __('Delivered') }}</span><span class="tabular-nums"><strong>{{ number_format($prod['delivered']) }}</strong> <span class="text-slate-400">/ {{ number_format($prod['qty']) }}</span></span></div>
            <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-2.5 rounded-full bg-emerald-600" style="width: {{ min(100, round($prod['delivered'] / $cap * 100)) }}%"></div></div>
        </div>
        @if ($prod['rejected'] || $prod['rework'])
            <p class="flex flex-wrap gap-2 text-xs">
                @if ($prod['rejected'])<span class="badge bg-rose-100 text-rose-800">{{ __('Rejected') }}: {{ $prod['rejected'] }} {{ __('pcs') }}</span>@endif
                @if ($prod['rework'])<span class="badge bg-amber-100 text-amber-800">{{ __('Rework') }}: {{ $prod['rework'] }} {{ __('pcs') }}</span>@endif
                <a href="{{ route('production-rejects.index', ['order_id' => $o->id]) }}" class="text-brand-700 underline">{{ __('details') }}</a>
            </p>
        @endif
    </div>

    @if ($deliveries->isNotEmpty())
        <div class="border-t border-slate-200">
            <p class="px-4 pt-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ __('Deliveries') }}</p>
            @foreach ($deliveries as $d)
                <a href="{{ route('deliveries.show', $d) }}" class="flex items-center justify-between gap-2 px-4 py-2 text-sm hover:bg-slate-50"><span>{{ $d->challan_no }} · {{ fmt_date($d->date) }}</span><span class="tabular-nums">{{ number_format($d->qty) }} {{ __('pcs') }}</span></a>
            @endforeach
        </div>
    @endif
    @if ($logs->isNotEmpty())
        <div class="border-t border-slate-200 pb-1">
            <p class="px-4 pt-3 text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ __('Recent entries') }}</p>
            @foreach ($logs as $l)
                <div class="flex items-center justify-between gap-2 px-4 py-1.5 text-sm"><span class="truncate">{{ fmt_date($l->date) }} · {{ \App\Support\Production::label($l->stage) }}@if ($l->worker) · {{ $l->worker->name }}@endif</span><span class="tabular-nums">+{{ number_format($l->qty) }}</span></div>
            @endforeach
        </div>
    @endif
</div>
