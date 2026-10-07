{{-- Closed-month notice + override reason (shown when saving was refused, or when editing a record in a closed month) --}}
@php
    $locked = isset($model) && $model && $model->exists && method_exists($model, 'isPeriodLocked') && $model->isPeriodLocked();
    $show = $errors->has('period') || $errors->has('override_reason') || $locked;
@endphp
@if ($show)
    <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-300 md:col-span-2">
        <p class="flex items-center gap-2 font-semibold">🔒 {{ __('Closed month') }}</p>
        @error('period')<p class="mt-1 font-medium text-rose-700">{{ $message }}</p>@enderror
        @if ($locked && ! $errors->has('period'))<p class="mt-1">{{ __('This record belongs to a month that has been closed.') }}</p>@endif
        @can('period.override')
            <label class="label mt-3" for="override_reason">{{ __('Reason for changing a closed month') }}</label>
            <textarea id="override_reason" name="override_reason" rows="2" class="input" placeholder="{{ __('e.g. supplier bill arrived late; amount corrected') }}">{{ old('override_reason') }}</textarea>
            <p class="mt-1 text-xs">{{ __('The change and your reason are written to the audit log. Leave empty if you do not want to change a closed month.') }}</p>
            @error('override_reason')<p class="mt-1 text-xs font-medium text-rose-700">{{ $message }}</p>@enderror
        @else
            <p class="mt-1">{{ __('Ask an admin to approve the change or re-open the month.') }}</p>
        @endcan
    </div>
@endif
