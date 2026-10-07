{{-- Search results list (used by the desktop dropdown and the mobile overlay) --}}
<template x-if="offline"><p class="p-4 text-sm text-slate-500">{{ __('Search needs an internet connection.') }}</p></template>
<template x-if="!offline && !loading && q.trim().length >= 2 && !flat.length"><p class="p-4 text-sm text-slate-500">{{ __('No results for') }} “<span x-text="q"></span>”.</p></template>
<template x-if="!offline && q.trim().length < 2"><p class="p-4 text-sm text-slate-400">{{ __('Type at least 2 letters — orders, customers, invoices, workers, expenses…') }}</p></template>
<template x-for="(g, gi) in groups" :key="g.title">
    <div class="py-1">
        <p class="px-4 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-slate-400 uppercase" x-text="g.title"></p>
        <template x-for="(it, ii) in g.items" :key="it.url">
            <a :href="it.url" :data-active="active === idx(gi, ii)" @mouseenter="active = idx(gi, ii)"
               class="mx-2 flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-sm" :class="active === idx(gi, ii) ? 'bg-brand-100' : ''">
                <span class="truncate font-medium text-slate-900" x-text="it.label"></span>
                <span class="truncate text-xs text-slate-500" x-text="it.sub"></span>
            </a>
        </template>
    </div>
</template>
