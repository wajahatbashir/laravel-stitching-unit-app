@extends('layouts.app')
@section('title', $inv->number)
@section('content')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('invoices.pdf', $inv->id) }}" class="btn btn-primary btn-sm"><x-icon name="download" class="h-4 w-4" />PDF</a>
        @can('invoices.edit')<a href="{{ route('invoices.edit', $inv) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit') }}</a>@endcan
        @if ($inv->balance() > 0)@can('customer_payments.create')<a href="{{ route('customer-payments.create', ['customer_id' => $inv->customer_id, 'invoice_id' => $inv->id, 'amount' => $inv->balance()]) }}" class="btn btn-gold btn-sm">{{ __('Record payment') }}</a>@endcan @endif
    </div>
    <div class="card p-5">
        <div class="flex justify-between gap-3">
            <div><p class="text-xl font-bold text-brand-800">{{ biz('business_name') }}</p><p class="text-sm text-slate-500">{{ biz('business_phone') }}<br>{{ biz('business_address') }}</p></div>
            <div class="text-end"><p class="text-lg font-bold">{{ $inv->number }}</p><p class="text-sm text-slate-500">{{ fmt_date($inv->date) }}</p><span class="badge {{ badge_class($inv->status()) }}">{{ label($inv->status()) }}</span></div>
        </div>
        <p class="mt-4 text-xs text-slate-400 uppercase">{{ __('Bill to') }}</p><p class="font-semibold">{{ $inv->customer->name }}</p>
        @if ($inv->order)<p class="text-sm text-slate-500">{{ __('Order') }}: {{ $inv->order->order_no }}</p>@endif
        <table class="mt-4 w-full text-sm"><thead><tr class="border-b text-xs text-slate-500 uppercase"><th class="py-2 text-start">{{ __('Description') }}</th><th class="text-end">{{ __('Qty') }}</th><th class="text-end">{{ __('Rate') }}</th><th class="text-end">{{ __('Amount') }}</th></tr></thead>
            <tbody>@foreach ($inv->items as $i)<tr class="border-b"><td class="py-2">{{ $i->description }}</td><td class="text-end tabular-nums">{{ (float) $i->qty }}</td><td class="text-end tabular-nums">{{ number_format($i->rate, 2) }}</td><td class="text-end tabular-nums">{{ number_format($i->amount, 2) }}</td></tr>@endforeach</tbody></table>
        <div class="mt-3 ms-auto w-full max-w-xs space-y-1 text-sm">
            <div class="flex justify-between"><span>{{ __('Subtotal') }}</span><span class="tabular-nums">{{ money($inv->subtotal) }}</span></div>
            @if ($inv->discount > 0)<div class="flex justify-between"><span>{{ __('Discount') }}</span><span class="tabular-nums">−{{ money($inv->discount) }}</span></div>@endif
            @if ($inv->tax > 0)<div class="flex justify-between"><span>{{ __('Tax') }}</span><span class="tabular-nums">{{ money($inv->tax) }}</span></div>@endif
            <div class="flex justify-between border-t pt-1 text-base font-bold"><span>{{ __('Total') }}</span><span class="tabular-nums">{{ money($inv->total) }}</span></div>
            <div class="flex justify-between text-emerald-700"><span>{{ __('Paid') }}</span><span class="tabular-nums">{{ money($inv->paid()) }}</span></div>
            <div class="flex justify-between font-semibold text-rose-700"><span>{{ __('Balance') }}</span><span class="tabular-nums">{{ money($inv->balance()) }}</span></div>
        </div>
        @if ($inv->notes)<p class="mt-4 text-sm text-slate-600">{{ $inv->notes }}</p>@endif
    </div>

    {{-- payment & advance history --}}
    <div class="card overflow-hidden">
        <div class="border-b px-4 py-3 font-semibold">{{ __('Payments & advances applied') }}</div>
        @foreach ($payments as $p)
            <div class="flex items-center justify-between gap-3 border-b px-4 py-2.5 text-sm">
                <span>{{ fmt_date($p->date) }} · {{ __('Payment') }} ({{ label($p->mode) }})@if ($p->reference) · {{ $p->reference }}@endif</span>
                <span class="tabular-nums text-emerald-700">{{ money($p->amount) }}</span>
            </div>
        @endforeach
        @foreach ($allocations as $a)
            <div class="flex items-center justify-between gap-3 border-b px-4 py-2.5 text-sm">
                <span>{{ fmt_date($a->date) }} · <span class="badge bg-amber-100 text-amber-800">{{ __('Advance applied') }}</span>
                    <span class="text-xs text-slate-400">{{ __('from payment of') }} {{ fmt_date($a->payment?->date) }}</span></span>
                <span class="flex items-center gap-2">
                    <span class="tabular-nums text-emerald-700">{{ money($a->amount) }}</span>
                    @can('invoices.edit')
                        <form method="POST" action="{{ route('invoices.allocations.destroy', [$inv->id, $a->id]) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Remove this advance from the invoice? The money goes back to the customer advance balance.')) }})">@csrf @method('DELETE')
                            <button class="text-xs font-medium text-rose-600">{{ __('Remove') }}</button></form>
                    @endcan
                </span>
            </div>
        @endforeach
        @if ($payments->isEmpty() && $allocations->isEmpty())<p class="p-4 text-sm text-slate-500">{{ __('Nothing received yet.') }}</p>@endif

        @can('invoices.edit')
            @if ($advance > 0 && $inv->balance() > 0)
                <form method="POST" action="{{ route('invoices.apply-advance', $inv->id) }}" class="border-t bg-gold-400/10 p-4">
                    @csrf
                    <p class="text-sm"><strong>{{ $inv->customer->name }}</strong> {{ __('has an unused advance of') }} <strong class="tabular-nums">{{ money($advance) }}</strong>.</p>
                    <div class="mt-3 flex flex-wrap items-end gap-2">
                        <div><label class="label">{{ __('Amount to apply') }}</label>
                            <input type="number" step="0.01" min="0.01" max="{{ min($advance, $inv->balance()) }}" name="amount" value="{{ min($advance, $inv->balance()) }}" class="input !w-44 font-bold" required></div>
                        <button class="btn btn-gold">{{ __('Apply advance') }}</button>
                    </div>
                    @error('amount')<p class="mt-2 text-sm font-medium text-rose-600">{{ $message }}</p>@enderror
                </form>
            @elseif ($advance > 0)
                <p class="border-t p-4 text-xs text-slate-500">{{ __('Customer advance available') }}: {{ money($advance) }}</p>
            @endif
        @endcan
    </div>
</div>
@endsection
