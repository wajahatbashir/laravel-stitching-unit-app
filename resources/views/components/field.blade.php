@props(['f', 'model' => null])
@php
    $n = $f['name'];
    $type = $f['type'];
    $cur = $model?->{$n} ?? null;
    if ($cur instanceof \DateTimeInterface) {
        $cur = $type === 'month' ? $cur->format('Y-m') : $cur->format('Y-m-d');
    }
    // priority: old input → explicit value → model → query string (prefill from links) → default
    $val = old($n, $f['value'] ?? $cur ?? request()->query($n) ?? $f['default'] ?? '');
    $span = ($f['span'] ?? 1) == 2 ? 'md:col-span-2' : '';
    $err = $errors->first($n);
    $req = ! empty($f['required']);
@endphp
@if ($type === 'hidden')
    <input type="hidden" name="{{ $n }}" value="{{ $val }}">
@elseif ($type === 'checkbox')
    <label class="flex min-h-11 items-center gap-3 {{ $span }}">
        <input type="hidden" name="{{ $n }}" value="0">
        <input type="checkbox" name="{{ $n }}" value="1" class="h-5 w-5 rounded border-slate-300 text-brand-700 focus:ring-brand-500" @checked((bool) $val)>
        <span class="text-sm font-medium text-slate-700">{{ $f['label'] }}</span>
    </label>
@else
    <div class="{{ $span }}" {{ $attributes }}>
        <label class="label" for="f_{{ $n }}">{{ $f['label'] }}@if($req)<span class="text-rose-600"> *</span>@endif</label>
        @if ($type === 'select')
            <select id="f_{{ $n }}" name="{{ $n }}" class="input" @required($req)>
                <option value="">{{ $req ? '— '.__('Select').' —' : '—' }}</option>
                @foreach ($f['options'] as $k => $l)
                    <option value="{{ $k }}" @selected((string) $val === (string) $k)>{{ $l }}</option>
                @endforeach
            </select>
        @elseif ($type === 'textarea')
            <textarea id="f_{{ $n }}" name="{{ $n }}" rows="3" class="input">{{ $val }}</textarea>
        @elseif ($type === 'file')
            <input id="f_{{ $n }}" type="file" name="{{ $n }}" accept="{{ $f['accept'] ?? 'image/*,application/pdf' }}" class="input file:me-3 file:rounded-lg file:border-0 file:bg-brand-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-800">
            @if ($n === 'receipt' && $model?->receipt_path)<a class="mt-1 block text-xs text-brand-700 underline" target="_blank" href="{{ Storage::url($model->receipt_path) }}">{{ __('Current file') }}</a>@endif
        @elseif ($type === 'files')
            @include('crud._files', ['model' => $model])
        @else
            <input id="f_{{ $n }}" type="{{ $type }}" name="{{ $n }}" value="{{ $type === 'password' ? '' : $val }}" class="input"
                   @if (isset($f['step'])) step="{{ $f['step'] }}" @endif
                   @if ($type === 'number') inputmode="decimal" @endif
                   @if ($type === 'password') autocomplete="new-password" @endif
                   @required($req)>
        @endif
        @if (! empty($f['help']))<p class="mt-1 text-xs text-slate-500">{{ $f['help'] }}</p>@endif
        @if ($err)<p class="mt-1 text-xs font-medium text-rose-600">{{ $err }}</p>@endif
    </div>
@endif
