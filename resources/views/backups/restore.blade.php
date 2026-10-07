@extends('layouts.app')
@section('title', __('Restore backup'))
@section('content')
<form method="POST" action="{{ route('backups.restore', $b['name']) }}" class="mx-auto max-w-xl space-y-4" x-data="{ busy: false }" @submit="busy = true">
    @csrf
    <div class="rounded-2xl bg-rose-50 p-4 text-sm text-rose-900 ring-1 ring-rose-300">
        <p class="text-base font-bold">⚠ {{ __('This replaces everything with the backup from') }} {{ \Illuminate\Support\Carbon::parse($b['created_at'])->format('d M Y, H:i') }}</p>
        <ul class="mt-2 list-disc space-y-1 ps-5">
            <li>{{ __('All data entered AFTER that backup will be gone (orders, expenses, payments, users…).') }}</li>
            <li>{{ __('Uploaded files (receipts, photos) are replaced by the ones in the backup.') }}</li>
            <li>{{ __('Everyone, including you, will be signed out and must sign in again.') }}</li>
            <li>{{ __('A safety backup of the CURRENT state is taken first (type "Before restore"), so you can undo this restore.') }}</li>
        </ul>
    </div>

    <div class="card space-y-4 p-4 md:p-6">
        <p class="text-sm"><strong>{{ __('Backup') }}:</strong> {{ $b['name'] }} · {{ number_format($b['size'] / 1048576, 2) }} MB</p>
        @if ($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif
        <div><label class="label" for="confirm">{{ __('Type RESTORE to confirm') }}</label><input id="confirm" name="confirm" class="input font-mono" autocomplete="off" required></div>
        <div><label class="label" for="pw">{{ __('Your password') }}</label><input id="pw" type="password" name="password" class="input" autocomplete="current-password" required></div>
        <div class="flex flex-wrap gap-2">
            <button class="btn btn-danger" :disabled="busy"><span x-text="busy ? '{{ __('Restoring… do not close this page') }}' : '{{ __('Restore now') }}'"></span></button>
            <a href="{{ route('backups.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        </div>
    </div>
</form>
@endsection
