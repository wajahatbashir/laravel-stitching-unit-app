{{-- Phone navigation: bottom bar, quick-add sheet, slide-out menu --}}
@php
    $tab = fn ($label, $route, $icon, $pattern, $perm = null) => ['l' => $label, 'r' => $route, 'i' => $icon, 'p' => $pattern, 'ok' => $perm === null || $u->can($perm)];
    $tabsL = [$tab(__('Home'), 'dashboard', 'home', 'dashboard'), $tab(__('Orders'), 'orders.index', 'orders', 'orders.*', 'orders.view')];
    $tabsR = [$tab(__('Expenses'), 'expenses.index', 'cash', 'expenses.*', 'expenses.view'), $tab(__('Labour'), 'workers.index', 'users', 'workers.*', 'workers.view')];
@endphp

<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white md:hidden" style="padding-bottom: env(safe-area-inset-bottom)">
    <div class="mx-auto grid max-w-md grid-cols-5 items-end text-[11px] font-medium text-slate-500">
        @foreach ($tabsL as $t)
            <a href="{{ $t['ok'] ? route($t['r']) : route('dashboard') }}" class="tab {{ request()->routeIs($t['p']) ? 'tab-on' : '' }}"><x-icon :name="$t['i']" class="h-6 w-6" />{{ $t['l'] }}</a>
        @endforeach
        <div class="relative flex justify-center">
            <button type="button" @click="quick = true" class="-mt-6 flex h-14 w-14 items-center justify-center rounded-full bg-brand-700 text-white shadow-lg ring-4 ring-white" aria-label="{{ __('Quick add') }}"><x-icon name="plus" class="h-7 w-7" /></button>
        </div>
        @foreach ($tabsR as $t)
            <a href="{{ $t['ok'] ? route($t['r']) : route('dashboard') }}" class="tab {{ request()->routeIs($t['p']) ? 'tab-on' : '' }}"><x-icon :name="$t['i']" class="h-6 w-6" />{{ $t['l'] }}</a>
        @endforeach
    </div>
</nav>

{{-- quick add sheet --}}
<div x-show="quick" x-cloak class="fixed inset-0 z-40 md:hidden" @keydown.escape.window="quick = false">
    <div class="absolute inset-0 bg-black/50" @click="quick = false"></div>
    <div class="absolute inset-x-0 bottom-0 rounded-t-3xl bg-white p-5 shadow-2xl" style="padding-bottom: calc(1.5rem + env(safe-area-inset-bottom))">
        <div class="mx-auto mb-3 h-1.5 w-10 rounded-full bg-slate-300"></div>
        <div class="mb-3 flex items-center justify-between"><h2 class="text-base font-semibold">{{ __('Quick add') }}</h2><button @click="quick = false" class="btn btn-ghost btn-sm" aria-label="{{ __('Close') }}"><x-icon name="x" /></button></div>
        <div class="grid grid-cols-2 gap-3">
            @foreach ($quick as $q)<a href="{{ $q[1] }}" class="btn btn-ghost justify-start !min-h-14">{{ $q[0] }}</a>@endforeach
        </div>
    </div>
</div>

{{-- slide-out menu --}}
<div x-show="menu" x-cloak class="fixed inset-0 z-40 md:hidden" @keydown.escape.window="menu = false">
    <div class="absolute inset-0 bg-black/50" @click="menu = false" x-transition.opacity></div>
    <aside class="absolute inset-y-0 start-0 flex w-[19rem] max-w-[88%] flex-col bg-white shadow-2xl">
        <div class="flex items-center justify-between gap-2 px-4 pt-4" style="padding-top: calc(1rem + env(safe-area-inset-top))">
            <a href="{{ route('profile') }}" class="flex min-w-0 items-center gap-3">
                <x-avatar :user="$u" class="h-12 w-12 text-base" />
                <span class="min-w-0"><span class="block truncate font-semibold text-slate-900">{{ $u->name }}</span><span class="block text-xs text-slate-500">{{ $u->roleLabel() }} · {{ __('View profile') }}</span></span>
            </a>
            <button @click="menu = false" class="btn btn-ghost btn-sm" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
        </div>
        <nav class="mt-3 flex-1 space-y-1 overflow-y-auto px-3 pb-4">
            @include('layouts._nav', ['collapsible' => true])
        </nav>
        <div class="space-y-2 border-t border-slate-200 p-3" style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom))">
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('locale', $rtl ? 'en' : 'ur') }}" class="btn btn-ghost btn-sm"><x-icon name="globe" class="h-4 w-4" />{{ $rtl ? 'English' : 'اردو' }}</a>
                <button type="button" class="btn btn-ghost btn-sm" @click="$store.theme.set(document.documentElement.classList.contains('dark') ? 'light' : 'dark')"><x-icon name="moon" class="h-4 w-4" />{{ __('Dark / Light') }}</button>
            </div>
            <form method="POST" action="{{ route('logout') }}" onsubmit="navigator.serviceWorker?.controller?.postMessage({type:'clear'})">@csrf
                <button class="btn btn-danger w-full"><x-icon name="logout" class="h-5 w-5" />{{ __('Log out') }}</button></form>
        </div>
    </aside>
</div>
