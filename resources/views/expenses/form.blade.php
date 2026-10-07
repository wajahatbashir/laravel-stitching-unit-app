@extends('layouts.app')
@section('title', $title)
@section('content')
@php
    $F = collect($fields)->keyBy('name');
    $m = $model;
    $initType = old('type', $m?->type ?? $extra['preType'] ?? 'order');
    $state = [
        'type' => $initType,
        'category' => (string) old('category_id', $m?->category_id),
        'qty' => old('qty', $m?->qty),
        'rate' => old('rate', $m?->rate),
        'amount' => old('amount', $m?->amount),
        'date' => old('date', $m?->date?->format('Y-m-d') ?? now()->toDateString()),
        'payee' => old('payee', $m?->payee),
        'ocr_text' => old('ocr_text', ''),
        'cats' => $extra['categories'],
        'reading' => false,
        'preview' => null,
        'fileName' => '',
        'removeReceipt' => false,
        'note' => '',
    ];
    $types = ['order' => __('Order'), 'unit' => __('Unit'), 'labour' => __('Labour'), 'other' => __('Other')];
@endphp
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="mx-auto max-w-3xl"
      x-data="expenseForm({{ \Illuminate\Support\Js::from($state) }})"
      @if ($offline) data-offline data-after="{{ route('expenses.index', [], false) }}" data-label="{{ __('Expense') }}" @endif>
    @csrf
    @if ($m) @method('PUT') @endif
    @if ($offline)<input type="hidden" name="uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">@endif
    <input type="hidden" name="ocr_text" :value="ocr_text">
    <input type="hidden" name="type" :value="type">

    @if ($errors->any())
        <div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    {{-- Expense type --}}
    <div class="mb-4 grid grid-cols-4 gap-2">
        @foreach ($types as $k => $l)
            <button type="button" @click="type = '{{ $k }}'; category = ''"
                    :class="type === '{{ $k }}' ? 'bg-brand-700 text-white shadow' : 'bg-white text-slate-700 ring-1 ring-slate-300'"
                    class="min-h-12 rounded-xl px-1 text-sm font-semibold transition">{{ $l }}</button>
        @endforeach
    </div>

    <div class="card space-y-4 p-4 md:p-6">
        {{-- Receipt + OCR --}}
        <div class="rounded-xl border-2 border-dashed border-brand-200 bg-brand-50/50 p-3">
            <label class="label" for="f_receipt">{{ __('Payment receipt (bank app screenshot / photo)') }}</label>
            {{-- no "capture" attribute: lets you pick a screenshot from the gallery OR take a photo --}}
            <input id="f_receipt" type="file" name="receipt" x-ref="receipt" accept="image/*,application/pdf" @change="readReceipt($event)"
                   class="input file:me-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-800">
            <p x-show="reading" class="mt-2 text-sm text-brand-700">⏳ {{ __('Reading receipt…') }}</p>
            <p x-show="note" x-text="note" class="mt-2 text-sm text-emerald-700"></p>

            {{-- preview of the file just chosen --}}
            <div x-show="preview || fileName" x-cloak class="mt-3">
                <p class="mb-1 flex items-center justify-between text-xs font-medium text-slate-500">
                    <span>{{ __('New receipt') }}: <span x-text="fileName" class="font-normal"></span></span>
                    <button type="button" class="font-semibold text-rose-600" @click="clearNew()">✕ {{ __('Clear') }}</button>
                </p>
                <a :href="preview" data-lightbox x-show="preview" class="block"><img :src="preview" alt="" class="mx-auto max-h-80 w-auto max-w-full rounded-xl object-contain ring-1 ring-slate-200"></a>
                <div x-show="!preview" class="rounded-xl bg-white p-4 text-center text-sm text-slate-600 ring-1 ring-slate-200">📄 <span x-text="fileName"></span></div>
            </div>

            {{-- receipt already saved (edit form) --}}
            @if ($m?->receipt_path)
                @php $isImg = preg_match('/\.(jpe?g|png|webp|gif)$/i', $m->receipt_path); @endphp
                <div class="mt-3" x-show="!preview && !fileName" x-cloak>
                    <p class="mb-1 flex items-center justify-between text-xs font-medium text-slate-500">
                        <span>{{ __('Saved receipt') }}</span>
                        <button type="button" class="font-semibold" :class="removeReceipt ? 'text-emerald-700' : 'text-rose-600'" @click="removeReceipt = !removeReceipt" x-text="removeReceipt ? '↺ {{ __('Undo remove') }}' : '✕ {{ __('Remove receipt') }}'"></button>
                    </p>
                    <div :class="removeReceipt && 'opacity-40'">
                        @if ($isImg)
                            <a href="{{ Storage::url($m->receipt_path) }}" data-lightbox><img src="{{ Storage::url($m->receipt_path) }}" alt="" class="mx-auto max-h-80 w-auto max-w-full rounded-xl object-contain ring-1 ring-slate-200"></a>
                        @else
                            <a href="{{ Storage::url($m->receipt_path) }}" target="_blank" class="block rounded-xl bg-white p-4 text-center text-sm font-medium text-brand-700 underline ring-1 ring-slate-200">📄 {{ basename($m->receipt_path) }}</a>
                        @endif
                    </div>
                    <p x-show="removeReceipt" class="mt-1 text-center text-xs font-semibold text-rose-600">{{ __('Will be removed when you save') }}</p>
                    <input type="hidden" name="remove_receipt" value="1" :disabled="!removeReceipt">
                </div>
            @endif
            @error('receipt')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="label">{{ __('Category') }}</label>
                <select name="category_id" class="input" x-model="category">
                    <option value="">—</option>
                    <template x-for="c in cats.filter(c => c.type === type)" :key="c.id"><option :value="c.id" x-text="c.name" :selected="String(c.id) === String(category)"></option></template>
                </select>
            </div>

            <div x-show="type === 'order'">
                <label class="label">{{ __('Order') }} <span class="text-rose-600">*</span></label>
                <select name="order_id" class="input" :disabled="type !== 'order'">
                    <option value="">— {{ __('Select') }} —</option>
                    @foreach ($F['order_id']['options'] as $k => $l)<option value="{{ $k }}" @selected((string) old('order_id', $m?->order_id ?? request('order_id')) === (string) $k)>{{ $l }}</option>@endforeach
                </select>
                @error('order_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div x-show="type === 'unit'">
                <label class="label">{{ __('Related asset (machine / furniture)') }}</label>
                <select name="asset_id" class="input" :disabled="type !== 'unit'">
                    <option value="">—</option>
                    @foreach ($F['asset_id']['options'] as $k => $l)<option value="{{ $k }}" @selected((string) old('asset_id', $m?->asset_id) === (string) $k)>{{ $l }}</option>@endforeach
                </select>
            </div>

            <div class="md:col-span-2"><x-field :f="$F['title']" :model="$m" /></div>

            <div><label class="label">{{ __('Quantity') }}</label><input type="number" step="0.01" inputmode="decimal" name="qty" class="input" x-model="qty" @input="calc()"></div>
            <div><label class="label">{{ __('Rate') }}</label><input type="number" step="0.01" inputmode="decimal" name="rate" class="input" x-model="rate" @input="calc()"></div>
            <div><label class="label">{{ __('Amount') }} <span class="text-rose-600">*</span></label>
                <input type="number" step="0.01" inputmode="decimal" name="amount" class="input text-lg font-bold" x-model="amount">
                @error('amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            <x-field :f="$F['currency_id']" :model="$m" />

            <div><label class="label">{{ __('Date') }} <span class="text-rose-600">*</span></label><input type="date" name="date" class="input" x-model="date" required></div>
            <x-field :f="$F['payment_mode']" :model="$m" />
            <div><label class="label">{{ __('Paid to') }}</label><input name="payee" class="input" x-model="payee"></div>
            <x-field :f="$F['vendor_id']" :model="$m" />
            <x-field :f="$F['due_date']" :model="$m" />

            {{-- Admin-defined custom fields (unit expenses) --}}
            @foreach ($extra['custom'] as $cf)
                @php $cv = old("custom.{$cf->key}", $m?->custom[$cf->key] ?? ''); @endphp
                <div x-show="type === 'unit'" @if ($cf->type === 'textarea') class="md:col-span-2" @endif>
                    <label class="label">{{ $cf->label }}@if($cf->required)<span class="text-rose-600"> *</span>@endif</label>
                    @if ($cf->type === 'image')
                        <input type="file" name="custom[{{ $cf->key }}]" accept="image/*" class="input file:me-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-800">
                        @if ($cv)<a href="{{ Storage::url($cv) }}" target="_blank"><img src="{{ Storage::url($cv) }}" class="mt-2 h-20 rounded-lg ring-1 ring-slate-200" alt=""></a>@endif
                    @elseif ($cf->type === 'select')
                        <select name="custom[{{ $cf->key }}]" class="input"><option value="">—</option>@foreach ($cf->optionList() as $o)<option @selected($cv === $o)>{{ $o }}</option>@endforeach</select>
                    @elseif ($cf->type === 'textarea')
                        <textarea name="custom[{{ $cf->key }}]" rows="3" class="input">{{ $cv }}</textarea>
                    @else
                        <input type="{{ $cf->type === 'number' ? 'number' : ($cf->type === 'date' ? 'date' : 'text') }}" step="any" name="custom[{{ $cf->key }}]" value="{{ $cv }}" class="input">
                    @endif
                    @error("custom.{$cf->key}")<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
            @endforeach

            <div class="md:col-span-2"><x-field :f="$F['notes']" :model="$m" /></div>
        </div>
    </div>

    <div class="mt-4">@include('crud._period_override', ['model' => $m])</div>

    <div class="sticky bottom-20 z-10 mt-4 flex gap-2 md:static">
        <button type="submit" class="btn btn-primary flex-1 md:flex-none">{{ __('Save') }}</button>
        <a href="{{ route('expenses.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </div>
</form>

@push('scripts')
<script>
function expenseForm(init) {
    return {
        ...init,
        clearNew() {
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.preview = null; this.fileName = ''; this.note = '';
            this.$refs.receipt.value = '';
        },
        calc() { if (this.qty && this.rate) this.amount = (parseFloat(this.qty) * parseFloat(this.rate)).toFixed(2); },
        // downscale big camera photos before OCR (faster upload, better OCR speed)
        shrink(file, max = 1800) {
            return new Promise((res) => {
                const img = new Image();
                img.onload = () => {
                    const s = Math.min(1, max / Math.max(img.width, img.height));
                    const c = document.createElement('canvas');
                    c.width = img.width * s; c.height = img.height * s;
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    c.toBlob((b) => res(b || file), 'image/jpeg', 0.85);
                };
                img.onerror = () => res(file);
                img.src = URL.createObjectURL(file);
            });
        },
        async readReceipt(e) {
            const f = e.target.files[0];
            this.note = '';
            // live preview of the chosen receipt
            if (this.preview) URL.revokeObjectURL(this.preview);
            this.preview = f && f.type.startsWith('image/') ? URL.createObjectURL(f) : null;
            this.fileName = f ? f.name : '';
            this.removeReceipt = false;
            if (!f || !f.type.startsWith('image/')) return;
            if (!navigator.onLine) { this.note = '{{ __('Offline: the receipt will be read automatically after sync — make sure the amount is filled or it will be read for you.') }}'; return; }
            this.reading = true;
            try {
                const fd = new FormData();
                fd.append('receipt', await this.shrink(f), 'receipt.jpg');
                fd.append('_token', document.querySelector('meta[name=csrf-token]').content);
                const r = await fetch('{{ route('expenses.ocr') }}', { method: 'POST', body: fd, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!r.ok) throw new Error();
                const j = await r.json();
                if (j.amount) this.amount = j.amount;
                if (j.date) this.date = j.date;
                if (j.payee && !this.payee) this.payee = j.payee;
                // description = purpose of payment, notes = transaction id (only into empty fields)
                const fill = (n, v) => {
                    const el = this.$root.querySelector(`[name=${n}]`);
                    if (el && v && !el.value.trim()) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); }
                };
                fill('title', j.title);
                fill('notes', j.reference ? 'Txn ID: ' + j.reference : '');
                this.ocr_text = j.text || '';
                this.note = (j.amount || j.date || j.payee) ? '{{ __('Receipt read — please check the values below.') }}' : '{{ __('Could not read this receipt — please enter the values.') }}';
            } catch (_) {
                this.note = '{{ __('Could not read this receipt — please enter the values.') }}';
            } finally { this.reading = false; }
        },
    };
}
</script>
@endpush
@endsection
