@extends('layouts.app')
@section('title', __('Backups'))
@section('actions')
    <form method="POST" action="{{ route('backups.store') }}" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <button class="btn btn-primary btn-sm" :disabled="busy"><x-icon name="shield" class="h-4 w-4" /><span x-text="busy ? '{{ __('Backing up… please wait') }}' : '{{ __('Back up now') }}'"></span></button>
    </form>
@endsection
@section('content')
@php
    $age = $lastAuto ? \Illuminate\Support\Carbon::parse($lastAuto['created_at']) : null;
    $stale = $age && $age->lt(now()->subHours(36)); // red only when an automatic backup that should exist is missing
    $typeLabel = ['nightly' => __('Nightly'), 'monthly' => __('Monthly'), 'manual' => __('Manual'), 'uploaded' => __('Uploaded'), 'pre-restore' => __('Before restore'), 'pre-reset' => __('Before data reset')];
    $typeCls = ['nightly' => 'bg-sky-100 text-sky-800', 'monthly' => 'bg-emerald-100 text-emerald-800', 'manual' => 'bg-amber-100 text-amber-800', 'uploaded' => 'bg-slate-100 text-slate-700', 'pre-restore' => 'bg-rose-100 text-rose-800', 'pre-reset' => 'bg-rose-100 text-rose-800'];
@endphp
<div class="space-y-4">
    @if ($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>@endif

    <div class="grid gap-3 md:grid-cols-3">
        <div class="stat {{ $stale ? 'ring-2 !ring-rose-400' : '' }}">
            <p class="text-xs text-slate-500">{{ __('Last automatic backup') }}</p>
            <p class="mt-1 text-lg font-bold">{{ $age ? $age->diffForHumans() : __('Never') }}</p>
            @if ($age)<p class="text-xs text-slate-400">{{ $age->format('d M Y, H:i') }}</p>@endif
            @if (! $age)<p class="mt-1 text-xs text-slate-500">{{ __('The first automatic backup runs tonight at 02:00 (the scheduler must be running).') }}</p>@endif
            @if ($stale)<p class="mt-1 text-xs font-semibold text-rose-600">{{ __('No automatic backup in the last 36 hours — check that the scheduler (cron) is running.') }}</p>@endif
        </div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Backups stored') }}</p><p class="mt-1 text-lg font-bold tabular-nums">{{ count($backups) }}</p><p class="text-xs text-slate-400">{{ number_format($totalSize / 1048576, 1) }} MB</p></div>
        <div class="stat"><p class="text-xs text-slate-500">{{ __('Schedule') }}</p><p class="mt-1 text-sm font-semibold">{{ __('Every night at 02:00') }}</p><p class="text-xs text-slate-400">{{ __('Keeps the last :n nightly + :m monthly', ['n' => \App\Services\BackupService::KEEP_NIGHTLY, 'm' => \App\Services\BackupService::KEEP_MONTHLY]) }}</p></div>
    </div>

    <div class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 text-sm">
                <p class="font-semibold">{{ __('Off-site copy') }}
                    @if ($offsite['mode'])<span class="badge bg-emerald-100 text-emerald-800">{{ __('On') }}</span>@else<span class="badge bg-slate-100 text-slate-600">{{ __('Not set up') }}</span>@endif</p>
                @if ($offsite['mode'])
                    <p class="mt-1 text-slate-600">{{ __('Every new backup is also copied to') }} <code class="rounded bg-slate-100 px-1">{{ $offsite['target'] }}</code></p>
                    @if ($offsite['last'])
                        <p class="mt-1 text-xs {{ $offsite['last']['ok'] ? 'text-emerald-700' : 'font-semibold text-rose-600' }}">
                            {{ $offsite['last']['ok'] ? __('Last copy OK') : __('Last copy FAILED') }} · {{ \Illuminate\Support\Carbon::parse($offsite['last']['at'])->diffForHumans() }}
                            @if (! $offsite['last']['ok']) — {{ $offsite['last']['error'] }}@endif</p>
                    @endif
                @else
                    <p class="mt-1 text-slate-600">{{ __('Keeps a second copy somewhere that survives this server failing. Ask whoever manages the server to set ONE of these in the .env file, then run "php artisan config:clear":') }}</p>
                    <ul class="mt-1 list-disc space-y-0.5 ps-5 text-xs text-slate-600">
                        <li><code>BACKUP_OFFSITE_PATH=/mnt/d/LumiereBackups</code> — {{ __('a folder on an external drive, or a folder synced by Google Drive / OneDrive / Dropbox.') }}</li>
                        <li><code>BACKUP_OFFSITE_SSH=user@host:/backups/lumiere</code> — {{ __('another server over SSH (key login). Optional BACKUP_OFFSITE_SSH_KEY and BACKUP_OFFSITE_SSH_PORT.') }}</li>
                    </ul>
                @endif
            </div>
            @if ($offsite['mode'])
                <form method="POST" action="{{ route('backups.offsite') }}" x-data="{ busy: false }" @submit="busy = true">@csrf
                    <button class="btn btn-ghost btn-sm" :disabled="busy"><span x-text="busy ? '{{ __('Copying…') }}' : '{{ __('Copy latest now') }}'"></span></button></form>
            @endif
        </div>
    </div>

    <div class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
        <strong>{{ __('Important:') }}</strong> {{ __('Backups live on this computer/server. Download a copy now and then and keep it somewhere else (USB, cloud drive) — if the machine is lost, the backups on it are lost too. A backup holds the database and uploaded files (receipts, photos), not passwords stored in .env.') }}
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
            <h2 class="font-semibold">{{ __('Available backups') }}</h2>
            <form method="POST" action="{{ route('backups.upload') }}" enctype="multipart/form-data" class="flex items-center gap-2" x-data>
                @csrf
                <input type="file" name="backup" accept=".zip,application/zip" class="hidden" x-ref="f" @change="$el.submit()">
                <button type="button" class="btn btn-ghost btn-sm" @click="$refs.f.click()"><x-icon name="plus" class="h-4 w-4" />{{ __('Upload a backup file') }}</button>
            </form>
        </div>
        @forelse ($backups as $b)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold"><span class="badge {{ $typeCls[$b['type']] ?? 'bg-slate-100' }}">{{ $typeLabel[$b['type']] ?? $b['type'] }}</span>
                        {{ \Illuminate\Support\Carbon::parse($b['created_at'])->format('d M Y, H:i') }}
                        <span class="font-normal text-slate-400">· {{ number_format($b['size'] / 1048576, 2) }} MB</span></p>
                    <p class="truncate text-xs text-slate-400">{{ $b['name'] }}@isset($b['tables']) · {{ $b['tables'] }} {{ __('tables') }} · {{ $b['files'] ?? 0 }} {{ __('files') }}@endisset @if (! empty($b['by'])) · {{ $b['by'] }}@endif</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('backups.download', $b['name']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" class="h-4 w-4" />{{ __('Download') }}</a>
                    @if ($canRestore)<a href="{{ route('backups.restore.form', $b['name']) }}" class="btn btn-ghost btn-sm !text-amber-700">{{ __('Restore…') }}</a>@endif
                    <form method="POST" action="{{ route('backups.destroy', $b['name']) }}" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Delete this backup permanently?')) }})">@csrf @method('DELETE')
                        <button class="btn btn-danger btn-sm" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="h-4 w-4" /></button></form>
                </div>
            </div>
        @empty
            <div class="flex flex-col items-center px-6 py-12 text-center text-slate-500"><x-icon name="shield" class="mb-2 h-8 w-8 text-slate-300" />{{ __('No backups yet. Press “Back up now”.') }}</div>
        @endforelse
    </div>
</div>
@endsection
