@extends('layouts.app')
@section('title', $role ? __('Edit role').' — '.$role->name : __('New role'))
@section('content')
@php
    use App\Support\Perms;
    $acts = ['view' => __('View'), 'create' => __('Add'), 'edit' => __('Edit'), 'delete' => __('Delete')];
    $has = fn ($p) => in_array($p, old('permissions', $granted));
@endphp
<form method="POST" action="{{ $role ? route('roles.update', $role) : route('roles.store') }}" class="mx-auto max-w-4xl" x-data>
    @csrf @if ($role) @method('PUT') @endif
    @if ($errors->any())<div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="card mb-4 p-4"><label class="label">{{ __('Role name') }}</label>
        <input name="name" value="{{ old('name', $role?->name) }}" class="input max-w-sm" required @if ($role && in_array($role->name, ['Admin', 'User'])) readonly @endif></div>

    <div class="card mb-4 overflow-x-auto">
        <div class="border-b px-4 py-3 font-semibold">{{ __('Module access') }}</div>
        <table class="min-w-full">
            <thead class="bg-slate-50"><tr><th class="th">{{ __('Module') }}</th>@foreach ($acts as $l)<th class="th text-center">{{ $l }}</th>@endforeach</tr></thead>
            <tbody class="divide-y">
                @foreach (Perms::MODULES as $m => $l)
                    <tr><td class="td whitespace-normal">{{ __($l) }}</td>
                        @foreach ($acts as $a => $_)<td class="td text-center"><input type="checkbox" name="permissions[]" value="{{ $m }}.{{ $a }}" @checked($has("$m.$a")) class="h-5 w-5 rounded border-slate-300 text-brand-700"></td>@endforeach</tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card mb-4 p-4">
        <p class="mb-2 font-semibold">{{ __('Reports') }}</p>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach (Perms::REPORTS as $k => $l)
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="permissions[]" value="reports.{{ $k }}" @checked($has("reports.$k")) class="h-5 w-5 rounded border-slate-300 text-brand-700">{{ __($l) }}</label>
            @endforeach
        </div>
    </div>

    <div class="card mb-4 p-4">
        <p class="mb-2 font-semibold">{{ __('Special') }}</p>
        <div class="space-y-2">
            @foreach (Perms::EXTRA as $k => $l)
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="permissions[]" value="{{ $k }}" @checked($has($k)) class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand-700"><span>{{ __($l) }}</span></label>
            @endforeach
        </div>
    </div>

    <div class="flex gap-2"><button class="btn btn-primary">{{ __('Save') }}</button><a href="{{ route('roles.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a></div>
</form>
@endsection
