{{-- Dashboard charts. Series colours are validated (gold + blue, light & dark); see .viz in app.css. --}}
@php
    $n = count($series);
    $max = nice_max((float) max(1, collect($series)->max(fn ($m) => max($m['revenue'], $m['costs']))));
    $ticks = [1, .75, .5, .25, 0];
    $hasData = collect($series)->contains(fn ($m) => $m['revenue'] > 0 || $m['costs'] > 0);
    $pct = fn ($v) => $max > 0 ? round($v / $max * 100, 2) : 0;
    $mixTotal = collect($mix)->sum(1);
    $mixMax = (float) (collect($mix)->max(1) ?: 1);
@endphp
<div class="grid gap-6 lg:grid-cols-2">

    {{-- 1) Revenue vs costs, last 6 months --}}
    <section class="card viz p-4" x-data="{ hover: null, table: false }">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="font-semibold text-slate-900">{{ __('Revenue vs costs') }}</h2>
                <p class="text-xs text-slate-500">{{ $p->label() }} · {{ __('per') }} {{ __($bucket) }} · {{ __('invoiced revenue against all costs') }}</p>
            </div>
            <div class="flex items-center gap-3 text-xs text-slate-600">
                <span class="flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" style="background: var(--viz-rev)"></i>{{ __('Revenue') }}</span>
                <span class="flex items-center gap-1.5"><i class="inline-block h-2.5 w-2.5 rounded-[3px]" style="background: var(--viz-cost)"></i>{{ __('Costs') }}</span>
                <button type="button" class="rounded-lg px-2 py-1 font-semibold text-brand-700 ring-1 ring-slate-200" @click="table = !table" x-text="table ? '{{ __('Chart') }}' : '{{ __('Table') }}'"></button>
            </div>
        </div>

        @if (! $hasData)
            <p class="mt-6 rounded-xl bg-slate-50 p-8 text-center text-sm text-slate-500">{{ __('No invoices or costs in this period yet.') }}</p>
        @else
            <div x-show="!table" class="mt-4" role="img" aria-label="{{ __('Revenue and costs') }}">
                <div class="flex gap-2">
                    <div class="flex h-48 w-11 shrink-0 flex-col justify-between text-end text-[11px] leading-none text-slate-500 tabular-nums">
                        @foreach ($ticks as $t)<span>{{ compact_num($max * $t) }}</span>@endforeach
                    </div>
                    <div class="relative h-48 min-w-0 flex-1">
                        {{-- hairline grid --}}
                        <div class="pointer-events-none absolute inset-0 flex flex-col justify-between">
                            @foreach ($ticks as $t)<div class="border-t border-slate-200"></div>@endforeach
                        </div>
                        {{-- columns: one hover target per month (taller than the marks) --}}
                        <div class="absolute inset-0 flex">
                            @foreach ($series as $i => $m)
                                <button type="button" class="relative flex h-full flex-1 items-end justify-center gap-[2px] focus:outline-none" @mouseenter="hover = {{ $i }}" @mouseleave="hover = null"
                                        @focus="hover = {{ $i }}" @blur="hover = null" @click="hover = hover === {{ $i }} ? null : {{ $i }}"
                                        aria-label="{{ $m['full'] }}: {{ __('Revenue') }} {{ money($m['revenue']) }}, {{ __('Costs') }} {{ money($m['costs']) }}">
                                    <span class="pointer-events-none absolute inset-x-1 inset-y-0 rounded-md transition" :class="hover === {{ $i }} && 'bg-slate-100'"></span>
                                    @foreach ([['revenue', '--viz-rev'], ['costs', '--viz-cost']] as [$k, $c])
                                        <span class="relative z-[1] flex h-full w-[22px] max-w-[40%] flex-col justify-end">
                                            @if ($i === $n - 1 && $m[$k] > 0)<span class="mb-0.5 text-center text-[10px] leading-none font-medium text-slate-700 tabular-nums">{{ compact_num($m[$k]) }}</span>@endif
                                            @if ($m[$k] > 0)<span class="block w-full rounded-t-[4px]" style="height: max(3px, {{ $pct($m[$k]) }}%); background: var({{ $c }})"></span>@endif
                                        </span>
                                    @endforeach
                                </button>
                            @endforeach
                        </div>
                        {{-- tooltips --}}
                        @foreach ($series as $i => $m)
                            <div x-show="hover === {{ $i }}" x-cloak x-transition.opacity.duration.100ms
                                 class="pointer-events-none absolute z-10 w-56 rounded-xl bg-white p-3 text-xs shadow-xl ring-1 ring-slate-200"
                                 style="top: -0.25rem; transform: translateY(-100%); @if ($i === 0) left: 0; @elseif ($i === $n - 1) right: 0; @else left: {{ round(($i + .5) / $n * 100, 2) }}%; transform: translate(-50%, -100%); @endif">
                                <p class="mb-1.5 font-semibold text-slate-900">{{ $m['full'] }}</p>
                                <p class="flex items-center justify-between gap-2"><span class="flex items-center gap-1.5 text-slate-600"><i class="inline-block h-2 w-2 rounded-[2px]" style="background: var(--viz-rev)"></i>{{ __('Revenue') }}</span><b class="whitespace-nowrap tabular-nums text-slate-900">{{ money($m['revenue']) }}</b></p>
                                <p class="flex items-center justify-between gap-2"><span class="flex items-center gap-1.5 text-slate-600"><i class="inline-block h-2 w-2 rounded-[2px]" style="background: var(--viz-cost)"></i>{{ __('Costs') }}</span><b class="whitespace-nowrap tabular-nums text-slate-900">{{ money($m['costs']) }}</b></p>
                                <p class="mt-1.5 flex items-center justify-between gap-2 border-t border-slate-200 pt-1.5"><span class="text-slate-600">{{ $m['profit'] >= 0 ? __('Profit') : __('Loss') }}</span><b class="tabular-nums {{ $m['profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ money($m['profit']) }}</b></p>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="ms-[3.25rem] mt-1.5 flex">
                    @foreach ($series as $m)<span class="flex-1 text-center text-[11px] text-slate-500">{{ $m['label'] }}</span>@endforeach
                </div>
            </div>

            {{-- table view (accessible alternative) --}}
            <div x-show="table" x-cloak class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-xs text-slate-500"><th class="py-1.5 text-start font-medium">{{ __('Month') }}</th><th class="text-end font-medium">{{ __('Revenue') }}</th><th class="text-end font-medium">{{ __('Costs') }}</th><th class="text-end font-medium">{{ __('Profit') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($series as $m)
                            <tr><td class="py-1.5">{{ $m['full'] }}</td><td class="text-end tabular-nums">{{ money($m['revenue']) }}</td><td class="text-end tabular-nums">{{ money($m['costs']) }}</td>
                                <td class="text-end tabular-nums {{ $m['profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ money($m['profit']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- 2) Where the money went this month (single series → no legend) --}}
    <section class="card viz p-4">
        <h2 class="font-semibold text-slate-900">{{ __('Where the money went') }}</h2>
        <p class="text-xs text-slate-500">{{ $p->label() }} · {{ __('total') }} <span class="tabular-nums">{{ money($mixTotal) }}</span></p>
        @if (! $mixTotal)
            <p class="mt-6 rounded-xl bg-slate-50 p-8 text-center text-sm text-slate-500">{{ __('No costs recorded in this period.') }}</p>
        @else
            <div class="mt-4 space-y-3" role="list">
                @foreach ($mix as [$label, $value])
                    <div role="listitem" class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 sm:grid-cols-[11rem_1fr_auto]" title="{{ $label }}: {{ money($value) }} ({{ round($value / $mixTotal * 100) }}%)">
                        <span class="truncate text-sm text-slate-700">{{ $label }}</span>
                        <span class="block h-4 rounded-e-[4px] bg-slate-100/70"><span class="block h-4 rounded-e-[4px]" style="width: {{ max(1.5, round($value / $mixMax * 100, 2)) }}%; background: var(--viz-cost)"></span></span>
                        <span class="min-w-[4.5rem] text-end text-xs font-medium text-slate-700 tabular-nums">{{ compact_num($value) }}</span>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-[11px] text-slate-400">{{ __('Includes piece-rate wages and salaries. Hover a bar for the exact amount.') }}</p>
        @endif
    </section>
</div>
