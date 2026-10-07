@extends('layouts.app')
@section('title', $v->name)
@section('actions')
    @can('vendor_payments.create')<a href="{{ route('vendor-payments.create', ['vendor_id' => $v->id, 'amount' => max(0, $payable)]) }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('Pay vendor') }}</a>@endcan
    <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />Excel</a>
    <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />PDF</a>
    @can('vendors.edit')<a href="{{ route('vendors.edit', $v) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endcan
@endsection
@section('content')
<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="stat {{ $payable > 0 ? 'ring-2 !ring-rose-300' : '' }}"><p class="text-xs text-slate-500">{{ $payable < 0 ? __('Paid ahead') : __('We owe') }}</p>
            <p class="mt-1 whitespace-nowrap text-lg font-bold tabular-nums {{ $payable > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ money(abs($payable)) }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Bought on credit') }}</p><p class="mt-1 whitespace-nowrap text-lg font-bold tabular-nums">{{ money($v->billed()) }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Paid to vendor') }}</p><p class="mt-1 whitespace-nowrap text-lg font-bold tabular-nums">{{ money($v->paid()) }}</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('All purchases (incl. paid on the spot)') }}</p><p class="mt-1 whitespace-nowrap text-lg font-bold tabular-nums">{{ money($spent) }}</p></div>
    </div>
    <p class="text-sm text-slate-500">{{ $v->phone }} @if ($v->contact_person) · {{ $v->contact_person }}@endif</p>

    @if ($open)
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">{{ __('Unpaid bills') }}</h2><p class="text-xs text-slate-500">{{ __('Payments are applied to the oldest bills first.') }}</p></div>
            <div class="grid grid-cols-2 gap-px bg-slate-200 text-center text-xs sm:grid-cols-4">
                @foreach ([__('Not yet due') => $aging['current'], '1–30 '.__('days late') => $aging['d30'], '31–60 '.__('days late') => $aging['d60'], '60+ '.__('days late') => $aging['d60p']] as $l => $amt)
                    <div class="bg-white p-3"><p class="text-slate-500">{{ $l }}</p><p class="mt-0.5 text-base font-bold tabular-nums {{ $amt > 0 && ! str_starts_with($l, __('Not')) ? 'text-rose-700' : '' }}">{{ money($amt) }}</p></div>
                @endforeach
            </div>
            @foreach ($open as $b)
                @php $late = $b['due']->lt(now()->startOfDay()); @endphp
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-2.5 text-sm">
                    <span class="min-w-0 truncate">{{ fmt_date($b['expense']->date) }} · {{ $b['expense']->title ?: label($b['expense']->type) }}
                        <span class="text-xs {{ $late ? 'font-semibold text-rose-600' : 'text-slate-400' }}">· {{ __('due') }} {{ fmt_date($b['due']) }}{{ $late ? ' ('.(int) $b['due']->diffInDays(now()->startOfDay()).' '.__('days late').')' : '' }}</span></span>
                    <span class="shrink-0 font-semibold tabular-nums">{{ money($b['left']) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3 font-semibold">{{ __('Ledger') }}</div>
        @forelse ($lines as $l)
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-2.5 text-sm last:border-0">
                <div class="min-w-0"><p class="truncate">{{ $l['desc'] }}</p><p class="text-xs text-slate-400">{{ fmt_date($l['date']) }}</p></div>
                <div class="shrink-0 text-end"><p class="font-semibold tabular-nums {{ $l['bill'] ? 'text-rose-700' : 'text-emerald-700' }}">{{ $l['bill'] ? '+'.money($l['bill']) : '−'.money($l['paid']) }}</p><p class="text-xs text-slate-400 tabular-nums">{{ money($l['balance']) }}</p></div>
            </div>
        @empty
            <p class="p-6 text-center text-sm text-slate-500">{{ __('No credit purchases yet. Choose “On credit” as the payment method when adding an expense for this vendor.') }}</p>
        @endforelse
    </div>
</div>
@endsection
