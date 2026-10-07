{{-- Period picker: This week / This month / This year / Custom, with ‹ › to move between periods --}}
@php
    $isCustom = $p->key === 'custom';
    $names = ['week' => __('This week'), 'month' => __('This month'), 'year' => __('This year')];
    $today = now()->toDateString();
    $presets = [
        __('Today') => [$today, $today],
        __('Last 7 days') => [now()->subDays(6)->toDateString(), $today],
        __('Last 30 days') => [now()->subDays(29)->toDateString(), $today],
        __('Last 90 days') => [now()->subDays(89)->toDateString(), $today],
        __('Last 12 months') => [now()->subMonthsNoOverflow(12)->addDay()->toDateString(), $today],
    ];
@endphp
<div class="card p-3 md:p-4" x-data="{ custom: {{ $isCustom ? 'true' : 'false' }} }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid w-full grid-cols-4 gap-1 rounded-xl bg-slate-100 p-1 text-sm font-semibold sm:w-auto" role="tablist" aria-label="{{ __('Period') }}">
            @foreach ($names as $k => $l)
                <a href="{{ $p->url(['period' => $k]) }}" role="tab" aria-selected="{{ $p->key === $k ? 'true' : 'false' }}" class="seg whitespace-nowrap px-2 sm:px-3 {{ $p->key === $k ? 'seg-on' : '' }}"><span class="sm:hidden">{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Str::after($l, ' ')) }}</span><span class="hidden sm:inline">{{ $l }}</span></a>
            @endforeach
            <button type="button" role="tab" @click="custom = !custom" class="seg whitespace-nowrap px-2 sm:px-3 {{ $isCustom ? 'seg-on' : '' }}">{{ __('Custom') }}<x-icon name="chevron" class="h-3.5 w-3.5 transition-transform" x-bind:class="custom && 'rotate-180'" /></button>
        </div>

        <div class="flex items-center gap-1.5">
            @unless ($isCustom)
                <a href="{{ $p->url(['period' => $p->key, 'offset' => $p->offset - 1]) }}" class="btn btn-ghost btn-sm !px-2.5" aria-label="{{ __('Previous period') }}"><x-icon name="chevron" class="h-4 w-4 rotate-90 rtl:-rotate-90" /></a>
            @endunless
            <span class="min-w-[9rem] text-center text-sm font-semibold text-slate-900 tabular-nums">{{ $p->label() }}</span>
            @unless ($isCustom)
                @if ($p->offset < 0)
                    <a href="{{ $p->url(['period' => $p->key, 'offset' => $p->offset + 1]) }}" class="btn btn-ghost btn-sm !px-2.5" aria-label="{{ __('Next period') }}"><x-icon name="chevron" class="h-4 w-4 -rotate-90 rtl:rotate-90" /></a>
                @else
                    <span class="btn btn-ghost btn-sm pointer-events-none !px-2.5 opacity-30" aria-hidden="true"><x-icon name="chevron" class="h-4 w-4 -rotate-90 rtl:rotate-90" /></span>
                @endif
            @endunless
        </div>
    </div>

    <form x-show="custom" x-cloak method="GET" action="{{ route('dashboard') }}" class="mt-3 border-t border-slate-200 pt-3">
        <input type="hidden" name="period" value="custom">
        <div class="flex flex-wrap items-end gap-3">
            <div><label class="label">{{ __('From') }}</label><input type="date" name="from" value="{{ $isCustom ? $p->from->toDateString() : now()->startOfMonth()->toDateString() }}" class="input !w-44" required></div>
            <div><label class="label">{{ __('To') }}</label><input type="date" name="to" value="{{ $isCustom ? $p->to->toDateString() : $today }}" class="input !w-44" required></div>
            <button class="btn btn-primary">{{ __('Apply') }}</button>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($presets as $l => [$a, $b])
                <a href="{{ $p->url(['period' => 'custom', 'from' => $a, 'to' => $b]) }}" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-brand-100">{{ $l }}</a>
            @endforeach
        </div>
    </form>
</div>
