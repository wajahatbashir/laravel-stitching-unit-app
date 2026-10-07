@extends('layouts.app')
@section('title', __('Reports'))
@section('content')
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    @forelse ($reports as $k => $l)
        <a href="{{ route('reports.show', $k) }}" class="card flex items-center gap-3 p-4 transition hover:ring-brand-400">
            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-100 text-brand-700"><x-icon name="chart" /></span>
            <span class="font-semibold">{{ __($l) }}</span>
        </a>
    @empty<div class="card p-8 text-center text-slate-500 sm:col-span-3">{{ __('No reports are enabled for your role.') }}</div>@endforelse
</div>
@endsection
