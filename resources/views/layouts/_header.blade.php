{{-- Top bar: search · quick "New" · sync status · notifications · account menu --}}
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white" style="padding-top: env(safe-area-inset-top)">
    <div class="flex h-14 items-center gap-2 px-3 md:px-6" x-data="globalSearch('{{ route('search') }}')" @keydown.window="hotkey($event)" @keydown.escape.window="closeAll()">

        {{-- mobile: menu + brand --}}
        <button type="button" class="btn btn-ghost btn-sm md:hidden" @click="menu = true" aria-label="{{ __('Menu') }}"><x-icon name="menu" /></button>
        <a href="{{ route('dashboard') }}" class="min-w-0 truncate font-serif text-base font-semibold tracking-[.16em] text-brand-900 uppercase md:hidden">
            @if (biz('brand_dashboard_logo'))<img src="{{ brand_url('dashboard_logo', '') }}" alt="" class="max-h-9 max-w-[8rem] object-contain">@else Lumière @endif
        </a>

        {{-- desktop: global search --}}
        <div class="relative me-auto hidden w-full max-w-md md:block" @click.outside="open = false">
            <x-icon name="search" class="pointer-events-none absolute start-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
            <input x-ref="q" x-model="q" @input.debounce.250ms="run()" @focus="q.trim().length >= 2 && (open = true)"
                   @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()" @keydown.escape="open = false; $refs.q.blur()"
                   type="search" autocomplete="off" placeholder="{{ __('Search orders, customers, workers…') }}" aria-label="{{ __('Search') }}"
                   class="input !rounded-full !bg-slate-100 !ps-10 !pe-10 !py-2 ring-0 focus:!bg-white">
            <kbd class="pointer-events-none absolute end-3 top-1/2 hidden -translate-y-1/2 rounded-md bg-white px-1.5 text-[11px] font-semibold text-slate-400 ring-1 ring-slate-200 lg:block">/</kbd>
            <div x-show="open" x-cloak x-transition.opacity class="absolute start-0 top-12 z-50 max-h-[26rem] w-[32rem] max-w-[90vw] overflow-auto rounded-2xl bg-white pb-2 shadow-2xl ring-1 ring-slate-200">
                @include('layouts._search_results')
            </div>
        </div>

        <div class="ms-auto flex items-center gap-1.5 md:ms-0">
            {{-- offline / sync status --}}
            <a href="{{ route('sync') }}" x-data x-show="$store.sync.pending || $store.sync.failed || !$store.sync.online" x-cloak
               class="flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
               :class="!$store.sync.online ? 'bg-amber-100 text-amber-800' : ($store.sync.failed ? 'bg-rose-100 text-rose-800' : 'bg-sky-100 text-sky-800')">
                <x-icon name="sync" class="h-4 w-4" x-bind:class="$store.sync.syncing && 'animate-spin'" />
                <span class="hidden sm:inline" x-text="!$store.sync.online ? '{{ __('Offline') }}' : ($store.sync.failed ? '{{ __('Sync issue') }}' : '{{ __('Syncing') }}')"></span>
                <span x-show="$store.sync.pending + $store.sync.failed > 0" x-text="$store.sync.pending + $store.sync.failed"></span>
            </a>

            {{-- mobile: search --}}
            <button type="button" class="btn btn-ghost btn-sm md:hidden" @click="openMobile()" aria-label="{{ __('Search') }}"><x-icon name="search" /></button>

            {{-- desktop: quick "New" menu --}}
            @if ($quick->isNotEmpty())
                <div class="relative hidden md:block" x-data="{ o: false }" @click.outside="o = false" @keydown.escape.window="o = false">
                    <button type="button" class="btn btn-primary btn-sm" @click="o = !o" :aria-expanded="o"><x-icon name="plus" class="h-4 w-4" />{{ __('New') }}<x-icon name="chevron" class="h-3.5 w-3.5" /></button>
                    <div x-show="o" x-cloak x-transition.opacity class="absolute end-0 mt-2 w-60 rounded-2xl bg-white p-1.5 shadow-2xl ring-1 ring-slate-200">
                        @foreach ($quick as $q)<a href="{{ $q[1] }}" class="nav-link !py-2">{{ $q[0] }}</a>@endforeach
                    </div>
                </div>
            @endif

            {{-- notifications --}}
            <div class="md:relative" x-data="notifPanel('{{ route('notifications.latest') }}', '{{ route('notifications.read-all') }}', {{ $unread }})" @click.outside="open = false" @keydown.escape.window="open = false">
                <button type="button" class="btn btn-ghost btn-sm relative" @click="toggle()" aria-label="{{ __('Notifications') }}" :aria-expanded="open">
                    <x-icon name="bell" />
                    <span x-show="unread > 0" x-cloak x-text="unread > 9 ? '9+' : unread" class="absolute -end-1 -top-1 min-w-[1.1rem] rounded-full bg-rose-600 px-1 text-center text-[10px] leading-[1.1rem] font-bold text-white"></span>
                </button>
                <div x-show="open" x-cloak x-transition.opacity class="fixed inset-x-3 top-16 z-50 overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 md:absolute md:inset-x-auto md:end-0 md:top-auto md:mt-2 md:w-96">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <p class="font-semibold">{{ __('Notifications') }}</p>
                        <button type="button" class="flex items-center gap-1 text-xs font-semibold text-brand-700 disabled:opacity-40" @click="markAll()" :disabled="!unread"><x-icon name="check" class="h-4 w-4" />{{ __('Mark all read') }}</button>
                    </div>
                    <div class="max-h-[60vh] overflow-auto">
                        <p x-show="loading" class="p-4 text-sm text-slate-500">{{ __('Loading…') }}</p>
                        <p x-show="failed" x-cloak class="p-4 text-sm text-slate-500">{{ __('Could not load (offline?).') }}</p>
                        <p x-show="!loading && !failed && !items.length" x-cloak class="p-6 text-center text-sm text-slate-500">{{ __('You are all caught up.') }}</p>
                        <template x-for="n in items" :key="n.id">
                            <a :href="n.url || '{{ route('notifications') }}'" class="flex gap-3 border-b border-slate-100 px-4 py-3 text-sm hover:bg-slate-50 last:border-0">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : 'bg-rose-500'"></span>
                                <span class="min-w-0"><span class="block font-medium text-slate-900" x-text="n.title"></span><span class="block truncate text-xs text-slate-500" x-text="n.body"></span><span class="block text-[11px] text-slate-400" x-text="n.time"></span></span>
                            </a>
                        </template>
                    </div>
                    <a href="{{ route('notifications') }}" class="block border-t border-slate-200 px-4 py-3 text-center text-sm font-semibold text-brand-700">{{ __('View all') }}</a>
                </div>
            </div>

            {{-- account menu --}}
            <div class="md:relative" x-data="{ o: false }" @click.outside="o = false" @keydown.escape.window="o = false">
                <button type="button" class="flex items-center gap-2 rounded-full p-0.5 hover:bg-slate-100 md:pe-2" @click="o = !o" :aria-expanded="o" aria-label="{{ __('Account') }}">
                    <x-avatar :user="$u" class="h-9 w-9 text-sm" />
                    <span class="hidden text-start leading-tight lg:block"><span class="block max-w-32 truncate text-sm font-semibold text-slate-900">{{ $u->name }}</span><span class="block text-[11px] text-slate-500">{{ $u->roleLabel() }}</span></span>
                    <x-icon name="chevron" class="hidden h-4 w-4 text-slate-400 md:block" />
                </button>
                <div x-show="o" x-cloak x-transition.opacity class="fixed inset-x-3 top-16 z-50 rounded-2xl bg-white p-2 shadow-2xl ring-1 ring-slate-200 md:absolute md:inset-x-auto md:end-0 md:top-auto md:mt-2 md:w-80">
                    <a href="{{ route('profile') }}" class="flex items-center gap-3 rounded-xl p-3 hover:bg-slate-50">
                        <x-avatar :user="$u" class="h-12 w-12 text-base" />
                        <span class="min-w-0"><span class="block truncate font-semibold text-slate-900">{{ $u->name }}</span><span class="block truncate text-xs text-slate-500">{{ $u->email }}</span><span class="badge mt-1 bg-brand-100 text-brand-800">{{ $u->roleLabel() }}</span></span>
                    </a>
                    <div class="my-1 border-t border-slate-200"></div>
                    <a href="{{ route('profile') }}" class="nav-link !py-2.5"><x-icon name="user" class="h-5 w-5" />{{ __('My profile') }}</a>
                    @if ($hasWorker)<a href="{{ route('my-ledger') }}" class="nav-link !py-2.5"><x-icon name="doc" class="h-5 w-5" />{{ __('My ledger') }}</a>@endif
                    <a href="{{ route('help') }}" class="nav-link !py-2.5"><x-icon name="doc" class="h-5 w-5" />{{ __('Help') }}</a>
                    @can('settings.view')<a href="{{ route('settings') }}" class="nav-link !py-2.5"><x-icon name="cog" class="h-5 w-5" />{{ __('Settings & notifications') }}</a>@endcan
                    <div class="my-1 border-t border-slate-200"></div>

                    <div class="px-3 py-2">
                        <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold tracking-wider text-slate-400 uppercase"><x-icon name="globe" class="h-4 w-4" />{{ __('Language') }}</p>
                        <div class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 text-sm font-semibold">
                            <a href="{{ route('locale', 'en') }}" class="seg {{ ! $rtl ? 'seg-on' : '' }}">English</a>
                            <a href="{{ route('locale', 'ur') }}" class="seg {{ $rtl ? 'seg-on' : '' }}">اردو</a>
                        </div>
                    </div>
                    <div class="px-3 pb-2">
                        <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold tracking-wider text-slate-400 uppercase"><x-icon name="moon" class="h-4 w-4" />{{ __('Appearance') }}</p>
                        <div class="grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1 text-sm font-semibold">
                            <button type="button" class="seg" :class="$store.theme.mode === 'light' && 'seg-on'" @click="$store.theme.set('light')"><x-icon name="sun" class="h-4 w-4" />{{ __('Light') }}</button>
                            <button type="button" class="seg" :class="$store.theme.mode === 'dark' && 'seg-on'" @click="$store.theme.set('dark')"><x-icon name="moon" class="h-4 w-4" />{{ __('Dark') }}</button>
                            <button type="button" class="seg" :class="$store.theme.mode === 'auto' && 'seg-on'" @click="$store.theme.set('auto')"><x-icon name="screen" class="h-4 w-4" />{{ __('Auto') }}</button>
                        </div>
                    </div>
                    <div class="my-1 border-t border-slate-200"></div>
                    <form method="POST" action="{{ route('logout') }}" onsubmit="navigator.serviceWorker?.controller?.postMessage({type:'clear'})">@csrf
                        <button class="nav-link w-full !py-2.5 !text-rose-600"><x-icon name="logout" class="h-5 w-5" />{{ __('Log out') }}</button></form>
                </div>
            </div>
        </div>

        {{-- mobile search overlay --}}
        <div x-show="mobile" x-cloak class="fixed inset-0 z-50 flex flex-col bg-white md:hidden">
            <div class="flex items-center gap-2 border-b border-slate-200 p-3" style="padding-top: calc(0.75rem + env(safe-area-inset-top))">
                <x-icon name="search" class="h-5 w-5 shrink-0 text-slate-400" />
                <input x-ref="mq" x-model="q" @input.debounce.250ms="run()" @keydown.enter.prevent="go()" type="search" autocomplete="off"
                       placeholder="{{ __('Search orders, customers, workers…') }}" class="min-w-0 flex-1 bg-transparent text-base text-slate-900 outline-none placeholder:text-slate-400">
                <button type="button" class="btn btn-ghost btn-sm" @click="mobile = false; q = ''; groups = []; flat = []">{{ __('Cancel') }}</button>
            </div>
            <div class="flex-1 overflow-auto pb-6">@include('layouts._search_results')</div>
        </div>
    </div>
</header>
