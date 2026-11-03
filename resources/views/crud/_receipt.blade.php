{{-- Payment receipt box (same design as the Expense form): dashed box at the top of the form with the file input,
     a preview of the file just chosen (✕ Clear) and, when editing, the saved receipt (✕ Remove receipt / ↺ Undo remove).
     Used by forms that have a `receipt` file field flagged 'custom_ui'. Param: $model --}}
<div class="mb-4 rounded-xl border-2 border-dashed border-brand-200 bg-brand-50/50 p-3"
     x-data="{ preview: null, fileName: '', removeReceipt: false,
        pick(e) { const f = e.target.files[0]; if (this.preview) URL.revokeObjectURL(this.preview);
                  this.fileName = f ? f.name : ''; this.preview = f && f.type.startsWith('image/') ? URL.createObjectURL(f) : null; },
        clearNew() { if (this.preview) URL.revokeObjectURL(this.preview); this.preview = null; this.fileName = ''; this.$refs.receipt.value = ''; } }">
    <label class="label" for="f_receipt">{{ __('Payment receipt (bank app screenshot / photo)') }}</label>
    {{-- no "capture" attribute: lets you pick a screenshot from the gallery OR take a photo --}}
    <input id="f_receipt" type="file" name="receipt" x-ref="receipt" accept="image/*,application/pdf" @change="pick($event)"
           class="input file:me-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-800">

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
    @if ($model?->receipt_path)
        @php $isImg = preg_match('/\.(jpe?g|png|webp|gif)$/i', $model->receipt_path); @endphp
        <div class="mt-3" x-show="!preview && !fileName" x-cloak>
            <p class="mb-1 flex items-center justify-between text-xs font-medium text-slate-500">
                <span>{{ __('Saved receipt') }}</span>
                <button type="button" class="font-semibold" :class="removeReceipt ? 'text-emerald-700' : 'text-rose-600'" @click="removeReceipt = !removeReceipt" x-text="removeReceipt ? '↺ {{ __('Undo remove') }}' : '✕ {{ __('Remove receipt') }}'"></button>
            </p>
            <div :class="removeReceipt && 'opacity-40'">
                @if ($isImg)
                    <a href="{{ Storage::url($model->receipt_path) }}" data-lightbox><img src="{{ Storage::url($model->receipt_path) }}" alt="" class="mx-auto max-h-80 w-auto max-w-full rounded-xl object-contain ring-1 ring-slate-200"></a>
                @else
                    <a href="{{ Storage::url($model->receipt_path) }}" target="_blank" class="block rounded-xl bg-white p-4 text-center text-sm font-medium text-brand-700 underline ring-1 ring-slate-200">📄 {{ basename($model->receipt_path) }}</a>
                @endif
            </div>
            <p x-show="removeReceipt" class="mt-1 text-center text-xs font-semibold text-rose-600">{{ __('Will be removed when you save') }}</p>
            <input type="hidden" name="remove_receipt" value="1" :disabled="!removeReceipt">
        </div>
    @endif
    @error('receipt')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
</div>
