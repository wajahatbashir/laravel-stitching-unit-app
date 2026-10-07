@if ($model && $model->hasTwoFactor())
    <label class="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <input type="checkbox" name="reset_two_factor" value="1" class="mt-0.5 h-5 w-5 rounded border-slate-300">
        <span><strong>{{ __('Reset two-factor sign-in') }}</strong><br><span class="text-xs">{{ __('Use this if the person lost their phone and recovery codes. Their authenticator is removed; they set it up again after signing in.') }}</span></span>
    </label>
@endif
