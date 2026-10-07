@if (! empty($extra['garments']))
<div class="mt-6">
    <h3 class="font-semibold text-slate-800">{{ __('Personal rate card (per piece)') }}</h3>
    <p class="mb-3 text-xs text-slate-500">{{ __('Leave blank to use the default rate from Garments & rate card.') }}</p>
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($extra['garments'] as $g)
            <div>
                <label class="label">{{ $g->name }} <span class="text-xs text-slate-400">({{ __('default') }} {{ number_format($g->default_rate, 2) }})</span></label>
                <input type="number" step="0.01" inputmode="decimal" name="rates[{{ $g->id }}]" value="{{ old("rates.{$g->id}", $extra['rates'][$g->id] ?? '') }}" class="input">
            </div>
        @endforeach
    </div>
</div>
@endif
