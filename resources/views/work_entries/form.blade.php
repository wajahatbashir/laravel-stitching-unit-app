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
    ];
@endphp
<form method="POST" action="{{ $action }}" class="mx-auto max-w-3xl" x-data="workForm({{ \Illuminate\Support\Js::from($state) }})"
      @if ($offline) data-offline data-after="{{ route('work-entries.index', [], false) }}" data-again="{{ route('work-entries.create', [], false) }}" data-label="{{ __('Work entry') }}" @endif>
    @csrf
    @if ($m) @method('PUT') @endif
    @if ($offline)<input type="hidden" name="uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endif
    @if ($errors->any())<div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

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
            <div class="md:col-span-2"><x-field :f="$F['notes']" :model="$m" /></div>
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
        pick() {
            const r = this.rateMap?.[this.worker]?.[this.garment];
            if (r !== undefined) this.rate = r;
        },
        total() { return new Intl.NumberFormat(undefined, { minimumFractionDigits: 2 }).format((parseFloat(this.qty) || 0) * (parseFloat(this.rate) || 0)); },
    };
}
</script>
@endpush
@endsection
