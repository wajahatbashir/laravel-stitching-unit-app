@extends('layouts.app')
@section('title', __('Audit log'))
@section('content')
<div class="card overflow-x-auto">
    <table class="min-w-full divide-y">
        <thead class="bg-slate-50"><tr><th class="th">{{ __('When') }}</th><th class="th">{{ __('User') }}</th><th class="th">{{ __('Action') }}</th><th class="th">{{ __('Record') }}</th><th class="th">{{ __('Changes') }}</th></tr></thead>
        <tbody class="divide-y">
            @foreach ($logs as $l)
                <tr><td class="td">{{ $l->created_at->format('d M Y H:i') }}</td><td class="td">{{ $l->user?->name ?? '—' }}</td>
                    <td class="td"><span class="badge {{ $l->action === 'deleted' ? 'bg-rose-100 text-rose-800' : ($l->action === 'created' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800') }}">{{ $l->action }}</span></td>
                    <td class="td">{{ $l->model }} #{{ $l->model_id }}</td>
                    <td class="td max-w-md truncate text-xs text-slate-500" title="{{ json_encode($l->changes) }}">{{ \Illuminate\Support\Str::limit(json_encode($l->changes, JSON_UNESCAPED_UNICODE), 90) }}</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
