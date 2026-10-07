@extends('layouts.app')
@section('title', $title)
@section('content')
@php
    $m = $model;
    $items = old('items', $extra['items']) ?: [['description' => '', 'qty' => 1, 'rate' => 0]];
    $F = collect($fields)->keyBy('name');
@endphp
<form method="POST" action="{{ $action }}" class="mx-auto max-w-4xl"
      x-data="invoiceForm({{ \Illuminate\Support\Js::from([
          'items' => array_values($items), 'orders' => $extra['orders'],
          'discount' => old('discount', $m?->discount ?? 0), 'tax' => old('tax', $m?->tax ?? 0),
          'customer' => (string) old('customer_id', $m?->customer_id ?? request('customer_id')),
          'advances' => $extra['advances'], 'paid' => $extra['paidAlready'],
          'useAdv' => (bool) old('apply_advance'), 'advAmount' => old('advance_amount', ''),
      ]) }})" @change="onChange($event)"
      @if ($offline) data-offline data-after="{{ route('invoices.index', [], false) }}" data-label="{{ __('Invoice') }}" @endif>
    @csrf
    @if ($m) @method('PUT') @endif
    @if ($offline)<input type="hidden" name="uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endif
    @if ($errors->any())<div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="card p-4 md:p-6">
        <div class="grid gap-4 md:grid-cols-2">
            <x-field :f="$F['number']" :model="$m" />
            <x-field :f="$F['date']" :model="$m" />
            <x-field :f="$F['customer_id']" :model="$m" />
            <div>
                <label class="label">{{ __('Order (optional)') }}</label>
                <select name="order_id" class="input" @change="fromOrder($event.target.value)">
                    <option value="">—</option>
                    @foreach ($F['order_id']['options'] as $k => $l)<option value="{{ $k }}" @selected((string) old('order_id', $m?->order_id ?? request('order_id')) === (string) $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <x-field :f="$F['due_date']" :model="$m" />
        </div>

        <h3 class="mb-2 mt-6 font-semibold">{{ __('Items') }}</h3>
        <div class="space-y-3">
            <template x-for="(it, i) in items" :key="i">
                <div class="grid grid-cols-12 items-end gap-2 rounded-xl bg-slate-50 p-3">
                    <div class="col-span-12 md:col-span-6"><label class="label">{{ __('Description') }}</label><input :name="`items[${i}][description]`" x-model="it.description" class="input" required></div>
                    <div class="col-span-4 md:col-span-2"><label class="label">{{ __('Qty') }}</label><input type="number" step="0.01" :name="`items[${i}][qty]`" x-model="it.qty" class="input" required></div>
                    <div class="col-span-5 md:col-span-2"><label class="label">{{ __('Rate') }}</label><input type="number" step="0.01" :name="`items[${i}][rate]`" x-model="it.rate" class="input" required></div>
                    <div class="col-span-3 flex items-center justify-between gap-1 md:col-span-2">
                        <span class="text-sm font-semibold tabular-nums" x-text="fmt(it.qty * it.rate)"></span>
                        <button type="button" class="btn btn-danger btn-sm" @click="items.splice(i, 1)" x-show="items.length > 1">✕</button>
                    </div>
                </div>
            </template>
        </div>
        <button type="button" class="btn btn-ghost btn-sm mt-3" @click="items.push({description: '', qty: 1, rate: 0})"><x-icon name="plus" class="h-4 w-4" />{{ __('Add line') }}</button>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
            <div class="space-y-4">
                <div><label class="label">{{ __('Discount') }}</label><input type="number" step="0.01" name="discount" x-model="discount" class="input"></div>
                <div><label class="label">{{ __('Tax') }}</label><input type="number" step="0.01" name="tax" x-model="tax" class="input"></div>
            </div>
            <div class="rounded-xl bg-brand-50 p-4 text-end">
                <p class="text-sm text-slate-500">{{ __('Subtotal') }}: <span class="tabular-nums" x-text="fmt(subtotal())"></span></p>
                <p class="mt-1 text-xs text-slate-500">{{ __('Total') }}</p>
                <p class="text-3xl font-bold text-brand-800 tabular-nums" x-text="fmt(total())"></p>
                <template x-if="adv() > 0">
                    <div class="mt-2 border-t border-brand-200 pt-2 text-sm">
                        <p class="text-emerald-700">{{ __('Advance deducted') }}: <span class="font-semibold tabular-nums" x-text="'− ' + fmt(adv())"></span></p>
                        <p class="font-semibold text-rose-700">{{ __('Balance due') }}: <span class="tabular-nums" x-text="fmt(Math.max(0, due() - adv()))"></span></p>
                    </div>
                </template>
            </div>
            {{-- Customer advance: only shown when the chosen customer has unused advance money --}}
            <div class="rounded-xl border border-gold-400/60 bg-gold-400/10 p-4 md:col-span-2" x-show="avail() > 0" x-cloak>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="apply_advance" value="1" x-model="useAdv" @change="toggleAdv()" class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                    <span>
                        <span class="font-semibold">{{ __('Deduct customer advance from this invoice') }}</span><br>
                        <span class="text-sm text-slate-600">{{ __('Advance available') }}: <b class="tabular-nums" x-text="fmt(avail())"></b></span>
                    </span>
                </label>
                <div class="mt-3 max-w-xs" x-show="useAdv" x-cloak>
                    <label class="label" for="advance_amount">{{ __('Advance amount to deduct') }}</label>
                    <input id="advance_amount" type="number" step="0.01" min="0" inputmode="decimal" name="advance_amount" x-model="advAmount" :max="maxApply()" :disabled="!useAdv" class="input font-bold">
                    <p class="mt-1 text-xs text-slate-500">{{ __('Up to') }} <span x-text="fmt(maxApply())"></span> — {{ __('the rest of the advance stays available for other invoices.') }}</p>
                </div>
                @error('advance_amount')<p class="mt-2 text-sm font-medium text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="md:col-span-2"><x-field :f="$F['notes']" :model="$m" /></div>
        </div>
    </div>

    <div class="mt-4">@include('crud._period_override', ['model' => $m])</div>

    <div class="sticky bottom-20 z-10 mt-4 flex gap-2 md:static">
        <button type="submit" class="btn btn-primary flex-1 md:flex-none">{{ __('Save invoice') }}</button>
        <a href="{{ route('invoices.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@push('scripts')
<script>
function invoiceForm(init) {
    return {
        ...init,
        fmt: (n) => new Intl.NumberFormat(undefined, { minimumFractionDigits: 2 }).format(n || 0),
        subtotal() { return this.items.reduce((s, i) => s + (parseFloat(i.qty) || 0) * (parseFloat(i.rate) || 0), 0); },
        total() { return this.subtotal() - (parseFloat(this.discount) || 0) + (parseFloat(this.tax) || 0); },
        // --- customer advance ---
        avail() { return parseFloat(this.advances[this.customer]) || 0; },
        due() { return Math.max(0, this.total() - (parseFloat(this.paid) || 0)); },
        maxApply() { return Math.min(this.avail(), this.due()); },
        adv() { return this.useAdv ? Math.min(parseFloat(this.advAmount) || 0, this.maxApply()) : 0; },
        toggleAdv() { if (this.useAdv && !this.advAmount) this.advAmount = this.maxApply().toFixed(2); },
        onChange(e) {
            if (e.target.name === 'customer_id') {
                this.customer = e.target.value;
                if (this.useAdv) this.advAmount = this.maxApply().toFixed(2);
            }
        },
        fromOrder(id) {
            const o = this.orders[id];
            if (!o) return;
            const desc = ['Stitching', o.collection_name, o.fabric_name, `(${o.order_no})`].filter(Boolean).join(' – ');
            const line = { description: desc, qty: o.qty, rate: o.rate };
            if (this.items.length === 1 && !this.items[0].description) this.items[0] = line; else this.items.push(line);
            const sel = document.querySelector('[name=customer_id]');
            if (sel && !sel.value) { sel.value = o.customer_id; sel.dispatchEvent(new Event('change', { bubbles: true })); }
        },
    };
}
</script>
@endpush
@endsection
