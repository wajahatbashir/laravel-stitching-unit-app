@extends('layouts.app')
@section('title', __('Month close'))
@section('content')
<div class="space-y-4">
    @if ($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
    <div class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <strong>{{ __('How it works:') }}</strong>
        {{ __('When you close a month, its expenses, customer and worker payments, salaries, work entries, invoices and investments become read-only — nobody can add, edit or delete records dated in it. An admin can still make a single change by writing a reason (it is recorded in the audit log), or re-open the month.') }}
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">{{ __('Months') }}</h2><p class="text-xs text-slate-500">{{ __('Check the numbers, then close the month once your books for it are final.') }}</p></div>
        @foreach ($months as $m)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0" x-data="{ open: false }">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-semibold">{{ $m['date']->translatedFormat('F Y') }}
                        @if ($m['closure'])<span class="badge bg-amber-100 text-amber-800">🔒 {{ __('Closed') }}</span>
                        @elseif ($m['current'])<span class="badge bg-sky-100 text-sky-800">{{ __('In progress') }}</span>
                        @else<span class="badge bg-emerald-100 text-emerald-800">{{ __('Open') }}</span>@endif</p>
                    <p class="text-xs text-slate-500 tabular-nums">{{ __('Revenue') }} {{ money($m['revenue']) }} · {{ __('Costs') }} {{ money($m['costs']) }} · <span class="{{ $m['profit'] >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $m['profit'] >= 0 ? __('Profit') : __('Loss') }} {{ money($m['profit']) }}</span></p>
                    @if ($m['closure'])<p class="text-xs text-slate-400">{{ __('Closed') }} {{ $m['closure']->created_at->format('d M Y, H:i') }} {{ __('by') }} {{ $m['closure']->closer?->name ?? '—' }}@if ($m['closure']->note) · “{{ $m['closure']->note }}”@endif</p>@endif
                </div>
                @unless ($m['current'])
                    <div class="flex items-center gap-2">
                        @if ($m['closure'])
                            <button type="button" class="btn btn-ghost btn-sm" @click="open = !open">{{ __('Re-open…') }}</button>
                        @else
                            <button type="button" class="btn btn-primary btn-sm" @click="open = !open">{{ __('Close month') }}</button>
                        @endif
                    </div>
                    <div x-show="open" x-cloak class="w-full">
                        @if ($m['closure'])
                            <form method="POST" action="{{ route('periods.reopen', $m['closure']) }}" class="flex flex-wrap items-end gap-2 rounded-xl bg-slate-50 p-3">
                                @csrf
                                <div class="min-w-[16rem] flex-1"><label class="label">{{ __('Reason for re-opening') }}</label><input name="reason" class="input" minlength="5" required placeholder="{{ __('e.g. missing supplier bill') }}"></div>
                                <button class="btn btn-danger btn-sm">{{ __('Re-open month') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('periods.close') }}" class="flex flex-wrap items-end gap-2 rounded-xl bg-slate-50 p-3" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Close :m? Records in it will become read-only.', ['m' => $m['date']->translatedFormat('F Y')])) }})">
                                @csrf
                                <input type="hidden" name="month" value="{{ $m['key'] }}">
                                <div class="min-w-[16rem] flex-1"><label class="label">{{ __('Note (optional)') }}</label><input name="note" class="input" maxlength="200" placeholder="{{ __('e.g. reconciled with bank statement') }}"></div>
                                <button class="btn btn-primary btn-sm">{{ __('Close :m', ['m' => $m['date']->translatedFormat('M Y')]) }}</button>
                            </form>
                        @endif
                    </div>
                @endunless
            </div>
        @endforeach
    </div>

    @if ($history->isNotEmpty())
        <div class="card overflow-hidden">
            <div class="border-b border-slate-200 px-4 py-3"><h2 class="font-semibold">{{ __('History') }}</h2></div>
            @foreach ($history as $h)
                <div class="border-b border-slate-100 px-4 py-2.5 text-sm last:border-0">
                    <strong>{{ $h->month->translatedFormat('F Y') }}</strong> — {{ __('closed') }} {{ $h->created_at->format('d M Y H:i') }} {{ __('by') }} {{ $h->closer?->name ?? '—' }}
                    @if ($h->reopened_at)<span class="text-rose-700"> · {{ __('re-opened') }} {{ $h->reopened_at->format('d M Y H:i') }} {{ __('by') }} {{ $h->reopener?->name ?? '—' }}: “{{ $h->reopen_reason }}”</span>@endif
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
