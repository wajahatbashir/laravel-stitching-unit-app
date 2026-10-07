@extends('layouts.app')
@section('title', __('My ledger'))
@section('content')
<div class="card mx-auto max-w-lg p-6 text-center">
    <p class="text-lg font-semibold">{{ __('No worker profile is linked to your login') }}</p>
    <p class="mt-2 text-sm text-slate-600">{{ __('"My ledger" shows the earnings, payments and balance of a worker. To see it, an admin must open the worker under Workers & Staff → Edit and choose this user as the "Login account".') }}</p>
    <div class="mt-4 flex flex-wrap justify-center gap-2">
        @can('workers.view')<a href="{{ route('workers.index') }}" class="btn btn-primary btn-sm">{{ __('Workers & Staff') }}</a>@endcan
        <a href="{{ route('dashboard') }}" class="btn btn-ghost btn-sm">{{ __('Dashboard') }}</a>
    </div>
</div>
@endsection
