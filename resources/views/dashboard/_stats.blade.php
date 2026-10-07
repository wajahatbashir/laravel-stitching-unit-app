{{-- Headline numbers for the selected period, each with the change vs the previous equally long period --}}
@php
    // [key, label, good direction when it goes up]
    $tiles = [
        ['revenue', __('Revenue (invoiced)'), 'up'],
        ['received', __('Payments received'), 'up'],
        ['costs', __('Total costs'), 'down'],
        ['profit', $stats['profit'] >= 0 ? __('Net profit') : __('Net loss'), 'up'],
        ['orders', __('Orders received'), 'up'],
        ['wages', __('Wages paid'), 'none'],
    ];
@endphp
<section>
    <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3"><h2 class="text-sm font-semibold text-slate-500 uppercase">{{ __('Selected period') }} · {{ $p->label() }}</h2>@if (collect($deltas)->filter(fn ($x) => $x !== null)->isEmpty())<span class="text-[11px] text-slate-400">{{ __('No data in the previous period to compare with.') }}</span>@endif</div>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        @foreach ($tiles as [$k, $label, $good])
            @php
                $v = $stats[$k];
                $dl = $deltas[$k] ?? null;
                $tone = $k === 'profit' ? ($v >= 0 ? 'text-emerald-700' : 'text-rose-700') : 'text-slate-900';
                $better = $dl === null || $good === 'none' ? null : (($dl >= 0) === ($good === 'up'));
            @endphp
            <div class="stat">
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="mt-1 whitespace-nowrap text-[0.95rem] font-bold tabular-nums sm:text-lg md:text-xl {{ $tone }}">{{ $k === 'orders' ? number_format($v) : money($v) }}</p>
                @if ($k === 'orders')<p class="text-[11px] text-slate-400">{{ number_format($stats['pieces']) }} {{ __('pieces') }}</p>@endif
                @if ($dl !== null)
                    <p class="mt-0.5 text-[11px] font-medium {{ $better === null ? 'text-slate-500' : ($better ? 'text-emerald-700' : 'text-rose-700') }}" title="{{ __('vs') }} {{ $prevLabel }}">
                        {{ $dl > 0 ? '▲' : ($dl < 0 ? '▼' : '■') }} {{ abs($dl) }}% <span class="font-normal text-slate-400">{{ __('vs previous') }}</span>
                    </p>
                @endif
            </div>
        @endforeach
    </div>
</section>
