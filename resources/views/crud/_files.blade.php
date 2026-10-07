@php
    $existing = $model?->attachments
        ? $model->attachments->map(fn ($a) => ['id' => $a->id, 'url' => Storage::url($a->path), 'name' => $a->name, 'image' => str_starts_with((string) $a->mime, 'image/'), 'removed' => false])->values()
        : collect();
@endphp
<div x-data="filesField({{ \Illuminate\Support\Js::from($existing) }})" class="space-y-3">

    {{-- already saved (edit form) --}}
    <div x-show="existing.length" x-cloak>
        <p class="mb-1 text-xs font-medium text-slate-500">{{ __('Saved files') }} <span class="text-slate-400">— {{ __('tap ✕ to remove, ↺ to undo') }}</span></p>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
            <template x-for="a in existing" :key="a.id">
                <div class="relative aspect-square overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200" :class="a.removed && 'opacity-40'">
                    <a :href="a.url" :target="a.image ? null : '_blank'" :data-lightbox="a.image ? '' : null" class="block h-full w-full" :class="a.removed && 'pointer-events-none'">
                        <img x-show="a.image" :src="a.url" alt="" class="h-full w-full object-cover" loading="lazy">
                        <span x-show="!a.image" class="flex h-full w-full items-center justify-center p-2 text-center text-[11px] break-all text-slate-600" x-text="a.name"></span>
                    </a>
                    <button type="button" @click="a.removed = !a.removed"
                            class="absolute end-1 top-1 flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold text-white shadow"
                            :class="a.removed ? 'bg-emerald-600' : 'bg-rose-600'" :aria-label="a.removed ? 'Undo' : 'Remove'" x-text="a.removed ? '↺' : '✕'"></button>
                    <span x-show="a.removed" class="absolute inset-x-0 bottom-0 bg-rose-600/90 py-0.5 text-center text-[10px] font-semibold text-white">{{ __('Will be removed') }}</span>
                    <input type="hidden" name="remove_attachments[]" :value="a.id" :disabled="!a.removed">
                </div>
            </template>
        </div>
    </div>

    {{-- newly chosen (previews) --}}
    <div x-show="list.length" x-cloak>
        <p class="mb-1 text-xs font-medium text-slate-500">{{ __('New files to upload') }} (<span x-text="list.length"></span>)</p>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
            <template x-for="(f, i) in list" :key="f.key">
                <div class="relative aspect-square overflow-hidden rounded-xl bg-slate-100 ring-2 ring-gold-400">
                    <img x-show="f.preview" :src="f.preview" alt="" class="h-full w-full object-cover">
                    <span x-show="!f.preview" class="flex h-full w-full items-center justify-center p-2 text-center text-[11px] break-all text-slate-600" x-text="f.file.name"></span>
                    <button type="button" @click="removeNew(i)" class="absolute end-1 top-1 flex h-7 w-7 items-center justify-center rounded-full bg-rose-600 text-sm font-bold text-white shadow" aria-label="Remove">✕</button>
                    <span class="absolute inset-x-0 bottom-0 truncate bg-black/55 px-1 py-0.5 text-center text-[10px] text-white" x-text="size(f.file.size)"></span>
                </div>
            </template>
        </div>
    </div>

    <input type="file" x-ref="input" name="files[]" multiple accept="image/*,application/pdf" class="hidden" @change="add($event)">
    <button type="button" @click="$refs.input.click()" class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-brand-200 bg-brand-50/60 px-4 py-4 text-sm font-semibold text-brand-800 hover:bg-brand-100">
        <x-icon name="plus" class="h-5 w-5" /><span x-text="list.length || existing.length ? '{{ __('Add more photos / files') }}' : '{{ __('Add photos / files') }}'"></span>
    </button>
    <p class="text-xs text-slate-500">{{ __('You can select several at once. Photos are resized automatically to upload faster.') }}</p>
</div>

@once
@push('scripts')
<script>
function filesField(existing) {
    return {
        existing,
        list: [],
        size: (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB',
        // downscale big camera photos (faster upload, smaller offline queue)
        shrink(file, max = 1800) {
            if (!file.type.startsWith('image/') || file.type === 'image/gif' || file.size < 700 * 1024) return Promise.resolve(file);
            return new Promise((res) => {
                const img = new Image();
                const url = URL.createObjectURL(file);
                img.onload = () => {
                    const s = Math.min(1, max / Math.max(img.width, img.height));
                    const c = document.createElement('canvas');
                    c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
                    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                    c.toBlob((b) => {
                        URL.revokeObjectURL(url);
                        res(b && b.size < file.size ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
                    }, 'image/jpeg', 0.85);
                };
                img.onerror = () => { URL.revokeObjectURL(url); res(file); };
                img.src = url;
            });
        },
        async add(e) {
            const picked = [...e.target.files];
            for (const f of picked) {
                const file = await this.shrink(f);
                const dupe = this.list.some((x) => x.file.name === file.name && x.file.size === file.size);
                if (!dupe) this.list.push({ key: Math.random().toString(36).slice(2), file, preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null });
            }
            this.sync();
        },
        removeNew(i) {
            const [gone] = this.list.splice(i, 1);
            if (gone?.preview) URL.revokeObjectURL(gone.preview);
            this.sync();
        },
        // the real <input> must carry every chosen file so the form (and offline queue) submits them
        sync() {
            const dt = new DataTransfer();
            this.list.forEach((x) => dt.items.add(x.file));
            this.$refs.input.files = dt.files;
        },
    };
}
</script>
@endpush
@endonce
