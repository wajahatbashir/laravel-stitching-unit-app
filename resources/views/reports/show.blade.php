@extends('layouts.app')
@section('title', $rep['title'])
@section('content')
@php
    $money = $rep['moneyCols'] ?? [];
    $hasFilters = ! empty($rep['filters']) || ! empty($rep['date']) || ! empty($rep['dateTo']);
    $fmt = fn ($v, $i) => in_array($i, $money) && is_numeric($v) ? money($v) : $v;
@endphp
<div class="mb-4 flex flex-wrap items-center gap-2">
    <a href="{{ route('reports.index') }}" class="btn btn-ghost btn-sm">← {{ __('Reports') }}</a>
    <div class="ms-auto flex gap-2">
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />PDF</a>
    </div>
</div>

@if ($hasFilters)
<form method="GET" class="card mb-4 p-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($rep['filters'] as $f)
            <div><label class="label">{{ $f['label'] }}</label>
                @if (isset($f['options']))
                    <select name="{{ $f['name'] }}" class="input"><option value="">{{ __('All') }}</option>@foreach ($f['options'] as $k => $l)<option value="{{ $k }}" @selected((string) ($values[$f['name']] ?? '') === (string) $k)>{{ $l }}</option>@endforeach</select>
                @else<input name="{{ $f['name'] }}" value="{{ $values[$f['name']] ?? '' }}" class="input">@endif
            </div>
        @endforeach
        @if (! empty($rep['date']))
            <div><label class="label">{{ __('From') }}</label><input type="date" name="date_from" value="{{ $values['date_from'] ?? '' }}" class="input"></div>
            <div><label class="label">{{ __('To') }}</label><input type="date" name="date_to" value="{{ $values['date_to'] ?? '' }}" class="input"></div>
        @elseif (! empty($rep['dateTo']))
            <div><label class="label">{{ __('As of date') }}</label><input type="date" name="date_to" value="{{ $values['date_to'] ?? '' }}" class="input"></div>
        @endif
    </div>
    <div class="mt-3 flex gap-2"><button class="btn btn-primary btn-sm">{{ __('Apply') }}</button><a href="{{ url()->current() }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a></div>
</form>
@endif

@if (! empty($rep['note']))<div class="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 ring-1 ring-amber-200">{{ $rep['note'] }}</div>@endif

@if (! empty($rep['totals']))
    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ($rep['totals'] as $l => $v)
            <div class="stat"><p class="text-xs text-slate-500">{{ $l }}</p><p class="mt-1 text-lg font-bold tabular-nums">{{ is_numeric($v) && abs($v) >= 1000 || is_float($v) ? money($v) : $v }}</p></div>
        @endforeach
    </div>
@endif

<div class="card overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50"><tr>@foreach ($rep['head'] as $i => $h)<th class="th {{ in_array($i, $money) ? 'text-end' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rep['rows'] as $row)
                <tr class="hover:bg-slate-50">@foreach ($row as $i => $c)<td class="td {{ in_array($i, $money) ? 'text-end tabular-nums' : '' }}">{{ $fmt($c, $i) }}</td>@endforeach</tr>
            @empty<tr><td class="p-8 text-center text-slate-500" colspan="{{ count($rep['head']) }}">{{ __('No data for these filters.') }}</td></tr>@endforelse
        </tbody>
    </table>
</div>
@endsection
