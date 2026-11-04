@extends('layouts.app')
@section('title', __($title))
@section('actions')
    <div class="relative" x-data="{ o: false }" @click.outside="o = false" @keydown.escape.window="o = false">
        <button type="button" class="btn btn-ghost btn-sm" @click="o = !o"><x-icon name="download" class="h-4 w-4" />{{ __('Export') }}<x-icon name="chevron" class="h-3.5 w-3.5" /></button>
        <div x-show="o" x-cloak x-transition.opacity class="absolute end-0 z-20 mt-2 w-40 rounded-2xl bg-white p-1.5 shadow-2xl ring-1 ring-slate-200">
            <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="nav-link !py-2">Excel (.xlsx)</a>
            <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="nav-link !py-2">PDF</a>
        </div>
    </div>
    @includeIf($route.'._actions')
    @if ($canCreate)<a href="{{ route($route.'.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('Add new') }}</a>@endif
@endsection
@section('content')
@php
    $hasFilters = $hasSearch || $hasDate || count($filters);
    $applied = collect(request()->query())->except(['page', 'export'])->filter(fn ($v) => $v !== null && $v !== '')->count();
    $cell = function ($row, $col) {
        $o = $col[2] ?? [];
        $t = cell_text($row, $col);
        if (! empty($o['url']) && ($u = $o['url']($row))) {
            $img = preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $u);   // images preview in a lightbox, PDFs open in a new tab
            return '<a href="'.e($u).'" '.($img ? 'data-lightbox' : 'target="_blank"').' class="font-medium text-brand-700 underline">'.e($t).'</a>';
        }
        if (! empty($o['badge']) && $t !== '—') {
            return '<span class="badge '.badge_class(! empty($o['badge_key']) ? ($o['badge_key'])($row) : (is_string($col[1]) ? data_get($row, $col[1]) : ($col[1])($row))).'">'.e($t).'</span>';
        }
        return e($t);
    };
@endphp

<div class="mb-4 flex flex-wrap items-center gap-2">
    @if ($hasFilters)
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('filter-panel').classList.toggle('hidden')"><x-icon name="filter" class="h-4 w-4" />{{ __('Filters') }}@if($applied) <span class="badge bg-brand-100 text-brand-800">{{ $applied }}</span>@endif</button>
    @endif
    @if ($applied)<a href="{{ url()->current() }}" class="text-sm font-medium text-brand-700 underline">{{ __('Clear filters') }}</a>@endif
    @if ($sum !== null)
        <span class="ms-auto inline-flex items-center gap-2 rounded-xl bg-brand-100 px-3 py-1.5 text-sm text-brand-900">{{ __('Total (filtered)') }}: <strong class="tabular-nums">{{ $sumMoney ? money($sum) : number_format($sum) }}</strong></span>
    @endif
</div>
@if ($hasFilters)
<form method="GET" id="filter-panel" class="{{ $applied ? '' : 'hidden' }} card mb-4 p-4">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if ($hasSearch)
            <div><label class="label">{{ __('Search') }}</label><input name="q" value="{{ request('q') }}" class="input" placeholder="{{ __('Search') }}…"></div>
        @endif
        @foreach ($filters as $f)
            <div>
                <label class="label">{{ $f['label'] }}</label>
                @if ($f['type'] === 'select')
                    <select name="{{ $f['name'] }}" class="input"><option value="">{{ __('All') }}</option>
                        @foreach ($f['options'] as $k => $l)<option value="{{ $k }}" @selected((string) request($f['name']) === (string) $k)>{{ $l }}</option>@endforeach
                    </select>
                @else
                    <input name="{{ $f['name'] }}" value="{{ request($f['name']) }}" class="input">
                @endif
            </div>
        @endforeach
        @if ($hasDate)
            <div><label class="label">{{ __('From') }}</label><input type="date" name="date_from" value="{{ request('date_from') }}" class="input"></div>
            <div><label class="label">{{ __('To') }}</label><input type="date" name="date_to" value="{{ request('date_to') }}" class="input"></div>
        @endif
    </div>
    <div class="mt-3 flex gap-2">
        <button class="btn btn-primary btn-sm">{{ __('Apply') }}</button>
        <a href="{{ url()->current() }}" class="btn btn-ghost btn-sm">{{ __('Reset') }}</a>
    </div>
</form>
@endif


@if ($rows->isEmpty())
    <div class="card flex flex-col items-center px-6 py-14 text-center">
        <span class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-brand-100 text-brand-700"><x-icon name="{{ $applied ? 'search' : 'doc' }}" class="h-8 w-8" /></span>
        <p class="text-base font-semibold text-slate-900">{{ $applied ? __('No records match your filters') : __('Nothing here yet') }}</p>
        <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $applied ? __('Try a different search or clear the filters.') : __('Records you add will appear here.') }}</p>
        <div class="mt-5 flex flex-wrap justify-center gap-2">
            @if ($applied)<a href="{{ url()->current() }}" class="btn btn-ghost btn-sm">{{ __('Clear filters') }}</a>@endif
            @if ($canCreate && ! $applied)<a href="{{ route($route.'.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('Add the first one') }}</a>@endif
        </div>
    </div>
@else
    <div @if (! empty($bulk)) x-data="{ n: 0,
        upd() { this.n = new Set([...document.querySelectorAll('.bulk-cb:checked')].map(c => c.value)).size },
        all(e) { document.querySelectorAll('.bulk-cb').forEach(c => c.checked = e.target.checked); this.upd() },
        clear() { document.querySelectorAll('.bulk-cb, .bulk-all').forEach(c => c.checked = false); this.upd() } }" @endif>
    @if (! empty($bulk))
        {{-- grid actions: tick rows, then use the buttons --}}
        <form id="bulkForm" method="POST" action="{{ $bulk['route'] }}" x-show="n > 0" x-cloak class="sticky top-2 z-20 mb-3 flex flex-wrap items-center gap-2 rounded-xl bg-white p-3 shadow ring-1 ring-brand-300">
            @csrf
            <span class="text-sm font-semibold"><span x-text="n"></span> {{ __('selected') }}</span>
            @foreach ($bulk['actions'] as $a)
                <button type="submit" name="action" value="{{ $a['value'] }}" class="btn {{ $a['value'] === 'lock' ? 'btn-primary' : 'btn-ghost' }} btn-sm" onclick="return confirm('{{ e($a['confirm']) }}')">{{ $a['value'] === 'lock' ? '🔒 ' : '' }}{{ $a['label'] }}</button>
            @endforeach
            <button type="button" class="btn btn-ghost btn-sm" @click="clear()">{{ __('Clear selection') }}</button>
        </form>
    @endif
    {{-- Mobile cards --}}
    <div class="space-y-3 md:hidden">
        @foreach ($rows as $row)
            <div class="card p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        @if (! empty($bulk))<input type="checkbox" form="bulkForm" name="ids[]" value="{{ $row->id }}" class="bulk-cb mt-1 h-5 w-5 shrink-0 rounded border-slate-300" @change="upd()" aria-label="{{ __('Select') }}">@endif
                        <div class="min-w-0 font-semibold text-slate-900">{!! $cell($row, $columns[0]) !!}</div>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        @if ($canShow)<a href="{{ route($route.'.show', $row) }}" class="btn btn-ghost btn-sm"><x-icon name="eye" class="h-4 w-4" /></a>@endif
                        @if ($canEdit && ! in_array($row->id, $frozenIds))<a href="{{ route($route.'.edit', $row) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" /></a>@endif
                    </div>
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
                    @foreach (array_slice($columns, 1) as $col)
                        <div class="min-w-0"><dt class="text-xs text-slate-400">{{ $col[0] }}</dt><dd class="truncate text-slate-700">{!! $cell($row, $col) !!}</dd></div>
                    @endforeach
                </dl>
                @if (in_array($row->id, $frozenIds))
                    <p class="mt-3 text-end text-xs text-amber-700">🔒 {{ __('Locked — only a Super Admin can change it') }}</p>
                @elseif ($canDelete && in_array($row->id, $lockedIds))
                    <p class="mt-3 text-end text-xs text-amber-700">🔒 {{ __('Closed month') }}</p>
                @elseif ($canDelete)
                    <form method="POST" action="{{ route($route.'.destroy', $row) }}" class="mt-3 text-end" onsubmit="return confirm('{{ in_array($row->id, $warnIds) ? __('This entry is already covered by payments. Deleting it changes the balance of the worker. Delete anyway?') : __('Delete this record?') }}')">@csrf @method('DELETE')
                        <button class="text-xs font-medium text-rose-600">{{ __('Delete') }}</button></form>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Desktop table --}}
    <div class="card hidden max-h-[70vh] overflow-auto md:block">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="sticky top-0 z-10 bg-slate-50 shadow-[0_1px_0_var(--color-slate-200)]"><tr>
                @if (! empty($bulk))<th class="th w-10"><input type="checkbox" class="bulk-all h-4 w-4 rounded border-slate-300" @change="all($event)" aria-label="{{ __('Select all') }}"></th>@endif
                @foreach ($columns as $col)<th class="th {{ ! empty(($col[2] ?? [])['money']) ? 'text-end' : '' }}">{{ $col[0] }}</th>@endforeach
                <th class="th text-end">{{ __('Actions') }}</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    <tr class="odd:bg-transparent even:bg-slate-50/50 hover:bg-brand-50">
                        @if (! empty($bulk))<td class="td w-10"><input type="checkbox" form="bulkForm" name="ids[]" value="{{ $row->id }}" class="bulk-cb h-4 w-4 rounded border-slate-300" @change="upd()" aria-label="{{ __('Select') }}"></td>@endif
                        @foreach ($columns as $col)
                            <td class="td {{ ! empty(($col[2] ?? [])['money']) ? 'text-end tabular-nums' : '' }}">{!! $cell($row, $col) !!}</td>
                        @endforeach
                        <td class="td text-end">
                            <div class="inline-flex gap-1">
                                @if ($canShow)<a href="{{ route($route.'.show', $row) }}" class="btn btn-ghost btn-sm" title="{{ __('View') }}"><x-icon name="eye" class="h-4 w-4" /></a>@endif
                                @if ($canEdit && ! in_array($row->id, $frozenIds))<a href="{{ route($route.'.edit', $row) }}" class="btn btn-ghost btn-sm" title="{{ __('Edit') }}"><x-icon name="pencil" class="h-4 w-4" /></a>@endif
                                @if (in_array($row->id, $frozenIds))<span class="btn btn-ghost btn-sm pointer-events-none opacity-70" title="{{ __('Locked — only a Super Admin can change it') }}">🔒</span>
                                @elseif ($canDelete && in_array($row->id, $lockedIds))<span class="btn btn-ghost btn-sm pointer-events-none opacity-70" title="{{ __('Closed month') }}">🔒</span>
                                @elseif ($canDelete)
                                    <form method="POST" action="{{ route($route.'.destroy', $row) }}" onsubmit="return confirm('{{ in_array($row->id, $warnIds) ? __('This entry is already covered by payments. Deleting it changes the balance of the worker. Delete anyway?') : __('Delete this record?') }}')">@csrf @method('DELETE')
                                        <button class="btn btn-danger btn-sm" title="{{ __('Delete') }}"><x-icon name="trash" class="h-4 w-4" /></button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
    </div>
@endif
@endsection
