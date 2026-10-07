@extends('layouts.app')
@section('title', __('Help'))
@section('content')
<div class="mx-auto max-w-4xl space-y-5" x-data="{ q: '' }">
    <div class="card p-4">
        <p class="text-sm text-slate-600">{{ __('What each part of the app is for and how to use it. Only the modules you have access to are shown.') }}</p>
        <input type="search" x-model="q" placeholder="{{ __('Search help…') }}" class="input mt-3" autocomplete="off">
    </div>

    @foreach ($groups as $group => $items)
        <div>
            <h2 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">{{ __($group) }}</h2>
            <div class="space-y-3">
                @foreach ($items as $key => $s)
                    @php $hay = strtolower(__($s['title']).' '.__($s['purpose']).' '.implode(' ', array_map('__', $s['how']))); @endphp
                    <section id="{{ $key }}" x-show="q === '' || @js($hay).includes(q.toLowerCase())" class="card scroll-mt-24 p-4 target:ring-2 target:ring-brand-400">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h3 class="font-semibold text-slate-900">{{ __($s['title']) }}</h3>
                                <p class="mt-0.5 text-sm text-slate-600">{{ __($s['purpose']) }}</p>
                            </div>
                            <a href="{{ route($s['route']) }}" class="btn btn-ghost btn-sm">{{ __('Open') }} →</a>
                        </div>
                        @if ($s['how'])
                            <ol class="mt-3 list-decimal space-y-1 ps-5 text-sm text-slate-700">@foreach ($s['how'] as $step)<li>{{ __($step) }}</li>@endforeach</ol>
                        @endif
                        @foreach ($s['tips'] as $tip)
                            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">💡 {{ __($tip) }}</p>
                        @endforeach
                    </section>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
