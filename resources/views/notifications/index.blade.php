@extends('layouts.app')
@section('title', __('Notifications'))
@section('content')
<div class="mx-auto max-w-2xl space-y-2">
    @forelse ($items as $n)
        <a href="{{ $n->data['url'] ?? '#' }}" class="card block p-4"><p class="font-semibold">{{ $n->data['title'] ?? '' }}</p><p class="text-sm text-slate-600">{{ $n->data['body'] ?? '' }}</p><p class="mt-1 text-xs text-slate-400">{{ $n->created_at->diffForHumans() }}</p></a>
    @empty<div class="card p-8 text-center text-slate-500">{{ __('No notifications.') }}</div>@endforelse
    {{ $items->links() }}
</div>
@endsection
