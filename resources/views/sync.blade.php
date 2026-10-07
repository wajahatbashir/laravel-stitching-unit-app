@extends('layouts.app')
@section('title', __('Offline sync'))
@section('content')
<div class="mx-auto max-w-2xl" x-data="syncPage()" x-init="load()">
    <div class="card mb-4 flex items-center justify-between p-4">
        <div>
            <p class="text-sm text-slate-500">{{ __('Connection') }}</p>
            <p class="font-semibold" :class="$store.sync.online ? 'text-emerald-700' : 'text-amber-700'" x-text="$store.sync.online ? '{{ __('Online') }}' : '{{ __('Offline') }}'"></p>
        </div>
        <button class="btn btn-primary btn-sm" @click="syncNow()" :disabled="!$store.sync.online"><x-icon name="sync" class="h-4 w-4" />{{ __('Sync now') }}</button>
    </div>

    <template x-if="!items.length"><div class="card p-8 text-center text-slate-500">{{ __('Nothing waiting to sync.') }}</div></template>
    <div class="space-y-3">
        <template x-for="it in items" :key="it.id">
            <div class="card p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold" x-text="it.label"></p>
                        <p class="text-xs text-slate-500" x-text="new Date(it.created).toLocaleString()"></p>
                        <p x-show="it.error" class="mt-1 text-sm text-rose-700" x-text="it.error"></p>
                    </div>
                    <span class="badge" :class="it.status === 'failed' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800'" x-text="it.status === 'failed' ? '{{ __('Rejected') }}' : '{{ __('Waiting') }}'"></span>
                </div>
                <div class="mt-3 flex gap-2">
                    <button x-show="it.status === 'failed'" class="btn btn-ghost btn-sm" @click="retry(it)">{{ __('Retry') }}</button>
                    <button class="btn btn-danger btn-sm" @click="remove(it)">{{ __('Discard') }}</button>
                </div>
            </div>
        </template>
    </div>
</div>
@push('scripts')
<script>
function syncPage() {
    return {
        items: [],
        async load() { this.items = await window.outbox.all(); },
        async syncNow() { window.dispatchEvent(new Event('online')); setTimeout(() => this.load(), 1500); },
        async retry(it) { it.status = 'queued'; it.error = null; await window.outbox.put(it); window.dispatchEvent(new Event('outbox-changed')); await this.syncNow(); },
        async remove(it) { if (!confirm('{{ __('Discard this unsynced record?') }}')) return; await window.outbox.del(it.id); window.dispatchEvent(new Event('outbox-changed')); await this.load(); },
    };
}
</script>
@endpush
@endsection
