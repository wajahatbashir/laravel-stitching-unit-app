@extends('layouts.app')
@section('title', __('Roles & permissions'))
@section('content')
<div class="mb-4 flex justify-end">@can('users.create')<a href="{{ route('roles.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" class="h-4 w-4" />{{ __('New role') }}</a>@endcan</div>
@if ($errors->any())<div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{{ $errors->first() }}</div>@endif
<div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
    @foreach ($roles as $r)
        <div class="card p-4">
            <div class="flex items-start justify-between"><p class="font-semibold">{{ $r->name }}</p><span class="badge bg-slate-100 text-slate-700">{{ $r->users_count }} {{ __('users') }}</span></div>
            <p class="mt-1 text-sm text-slate-500">{{ $r->name === 'Super Admin' ? __('Full access to everything') : $r->permissions_count.' '.__('permissions') }}</p>
            @if ($r->name !== 'Super Admin')
                <div class="mt-3 flex gap-2">
                    @can('users.edit')<a href="{{ route('roles.edit', $r) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="h-4 w-4" />{{ __('Edit permissions') }}</a>@endcan
                    @if (! in_array($r->name, ['Admin', 'User']) && auth()->user()->can('users.delete'))
                        <form method="POST" action="{{ route('roles.destroy', $r) }}" onsubmit="return confirm('{{ __('Delete this role?') }}')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><x-icon name="trash" class="h-4 w-4" /></button></form>
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div>
@endsection
