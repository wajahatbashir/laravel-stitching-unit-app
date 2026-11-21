@extends('layouts.app')
@section('title', $title)
@section('content')
@php
    $F = collect($fields)->keyBy('name');
    $m = $model;
    $state = [
        'worker' => (string) old('worker_id', $m?->worker_id ?? request('worker_id')),
        'garment' => (string) old('garment_type_id', $m?->garment_type_id),
        'qty' => old('qty', $m?->qty),
        'rate' => old('rate', $m?->rate),
        'rateMap' => $extra['rateMap'],
        // new entries: one line per order (the same worker did pieces on several orders)
        'lines' => old('lines', [['order_id' => (string) request('order_id'), 'garment_type_id' => '', 'qty' => '', 'rate' => '']]),
    ];
    $orderOpts = $F['order_id']['options'];
@endphp
<form method="POST" action="{{ $action }}" class="mx-auto max-w-3xl" x-data="workForm({{ \Illuminate\Support\Js::from($state) }})"
      @if ($offline) data-offline data-after="{{ route('work-entries.index', [], false) }}" data-again="{{ route('work-entries.create', [], false) }}" data-label="{{ __('Work entry') }}" @endif>
    @csrf
    @if ($m) @method('PUT') @endif
    @if ($offline)<input type="hidden" name="uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endif
    @if ($errors->any())<div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    @if ($m && ! empty($extra['pay']) && $extra['pay']['status'] !== 'unpaid')
        <div class="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">⚠ {{ __('Payment status') }}: <strong>{{ \App\Support\WorkPayStatus::label($extra['pay']['status']) }}</strong> — {{ __('paid') }} {{ money($extra['pay']['paid']) }} {{ __('of') }} {{ money($extra['pay']['amount']) }}. {{ __('Changing or deleting this entry changes the worker balance.') }}</div>
    @endif
    @if ($m?->isClosed())
        <div class="mb-4 rounded-xl bg-slate-100 p-3 text-sm text-slate-700 ring-1 ring-slate-300">🔒 {{ __('This entry is closed (locked). You can change it because you are a Super Admin.') }}@if ($m->closed_at) <span class="text-xs text-slate-500">({{ __('closed') }} {{ $m->closed_at->format('d M Y, H:i') }}@if ($m->closer) {{ __('by') }} {{ $m->closer->name }}@endif)</span>@endif</div>
    @endif

    <div class="card p-4 md:p-6">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="label">{{ __('Worker') }} <span class="text-rose-600">*</span></label>
                <select name="worker_id" class="input" x-model="worker" @change="pick()" required>
                    <option value="">— {{ __('Select') }} —</option>
                    @foreach ($F['worker_id']['options'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </select>
                @error('worker_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <x-field :f="$F['date']" :model="$m" />
            @if ($m)
            <x-field :f="$F['order_id']" :model="$m" />
            <div>
                <label class="label">{{ __('Garment') }} <span class="text-rose-600">*</span></label>
                <select name="garment_type_id" class="input" x-model="garment" @change="pick()" required>
                    <option value="">— {{ __('Select') }} —</option>
                    @foreach ($F['garment_type_id']['options'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                </select>
                @error('garment_type_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div><label class="label">{{ __('Pieces') }} <span class="text-rose-600">*</span></label>
                <input type="number" name="qty" min="1" inputmode="numeric" class="input text-lg font-bold" x-model="qty" required>
                @error('qty')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <div><label class="label">{{ __('Rate per piece') }}</label>
                <input type="number" name="rate" step="0.01" inputmode="decimal" class="input" x-model="rate">
                <p class="mt-1 text-xs text-slate-500">{{ __('Auto-filled from the rate card; change if needed') }}</p></div>
            <div class="rounded-xl bg-brand-50 p-4 text-center md:col-span-2">
                <p class="text-xs text-slate-500">{{ __('Amount earned') }}</p>
                <p class="text-3xl font-bold text-brand-800 tabular-nums" x-text="total()"></p>
            </div>
            @else
            {{-- New entry: one line per order, same worker and date --}}
            <div class="md:col-span-2">
                <div class="mb-2 flex items-center justify-between">
                    <p class="label !mb-0">{{ __('Orders worked on') }}</p>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addLine()">＋ {{ __('Add another order') }}</button>
                </div>
                <div class="space-y-3">
                    <template x-for="(l, i) in lines" :key="i">
                        <div class="rounded-xl bg-slate-50 p-3 ring-1 ring-slate-200">
                            <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-500">
                                <span x-text="'{{ __('Order') }} ' + (i + 1)"></span>
                                <button type="button" x-show="lines.length > 1" class="text-rose-600" @click="removeLine(i)">✕ {{ __('Remove') }}</button>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <div>
                                    <label class="label">{{ __('Order') }}</label>
                                    <select :name="'lines[' + i + '][order_id]'" class="input" x-model="l.order_id">
                                        <option value="">—</option>
                                        @foreach ($orderOpts as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="label">{{ __('Garment') }} <span class="text-rose-600">*</span></label>
                                    <select :name="'lines[' + i + '][garment_type_id]'" class="input" x-model="l.garment_type_id" @change="pick1(l)" required>
                                        <option value="">— {{ __('Select') }} —</option>
                                        @foreach ($F['garment_type_id']['options'] as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="label">{{ __('Pieces') }} <span class="text-rose-600">*</span></label>
                                    <input type="number" :name="'lines[' + i + '][qty]'" min="1" inputmode="numeric" class="input text-lg font-bold" x-model="l.qty" required>
                                </div>
                                <div>
                                    <label class="label">{{ __('Rate per piece') }}</label>
                                    <input type="number" :name="'lines[' + i + '][rate]'" step="0.01" inputmode="decimal" class="input" x-model="l.rate">
                                </div>
                            </div>
                            <p class="mt-2 text-end text-sm text-slate-600">{{ __('Amount') }}: <strong class="tabular-nums" x-text="fmt((parseFloat(l.qty) || 0) * (parseFloat(l.rate) || 0))"></strong></p>
                        </div>
                    </template>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ __('Rates fill in from the rate card; change if needed. Each order is saved as its own work entry.') }}</p>
            </div>
            <div class="rounded-xl bg-brand-50 p-4 text-center md:col-span-2">
                <p class="text-xs text-slate-500">{{ __('Amount earned') }} · <span x-text="totalPieces()"></span> {{ __('pieces') }} · <span x-text="lines.length"></span> {{ __('order(s)') }}</p>
                <p class="text-3xl font-bold text-brand-800 tabular-nums" x-text="grand()"></p>
            </div>
            @endif
            <div class="md:col-span-2"><x-field :f="$F['notes']" :model="$m" /></div>
            @isset($F['status'])<div class="md:col-span-2"><x-field :f="$F['status']" :model="$m" /></div>@endisset
        </div>
    </div>

    <div class="mt-4">@include('crud._period_override', ['model' => $m])</div>

    <div class="sticky bottom-20 z-10 mt-4 flex flex-wrap gap-2 md:static">
        <button type="submit" class="btn btn-primary flex-1 md:flex-none">{{ __('Save') }}</button>
        @if (! $m)<button type="submit" name="again" value="1" class="btn btn-gold flex-1 md:flex-none">{{ __('Save & add another') }}</button>@endif
        <a href="{{ route('work-entries.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>
@push('scripts')
<script>
function workForm(init) {
    return {
        ...init,
        // single-entry (edit) form
        pick() {
            const r = this.rateMap?.[this.worker]?.[this.garment];
            if (r !== undefined) this.rate = r;
            // new-entry form: refresh every line that has a garment when the worker changes
            (this.lines || []).forEach(l => this.pick1(l));
        },
        pick1(l) {
            const r = this.rateMap?.[this.worker]?.[l.garment_type_id];
            if (r !== undefined && l.garment_type_id) l.rate = r;
        },
        addLine() { this.lines.push({ order_id: '', garment_type_id: '', qty: '', rate: '' }); },
        removeLine(i) { this.lines.splice(i, 1); },
        fmt(v) { return new Intl.NumberFormat(undefined, { minimumFractionDigits: 2 }).format(v); },
        totalPieces() { return this.lines.reduce((t, l) => t + (parseInt(l.qty) || 0), 0); },
        grand() { return this.fmt(this.lines.reduce((t, l) => t + (parseFloat(l.qty) || 0) * (parseFloat(l.rate) || 0), 0)); },
        total() { return new Intl.NumberFormat(undefined, { minimumFractionDigits: 2 }).format((parseFloat(this.qty) || 0) * (parseFloat(this.rate) || 0)); },
    };
}
</script>
@endpush
@endsection
