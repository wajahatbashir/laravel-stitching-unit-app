@php
    $u = auth()->user();
    $rtl = app()->getLocale() === 'ur';
    // [label, route, icon, permission, active pattern] — defined once in App\Support\Menu; Settings → Menu can hide items
    $groups = \App\Support\Menu::groups();
    $hasWorker = $u->worker()->exists(); // "My ledger" only makes sense for a login linked to a worker profile
    $canSee = fn ($i) => ! \App\Support\Menu::isHidden($i[1]) && ($i[3] === null || $u->can($i[3])) && ($i[1] !== 'my-ledger' || $hasWorker) && ($i[1] !== 'reset.index' || $u->hasRole('Super Admin')) && ($i[1] !== 'reports.index' || collect(\App\Support\Perms::REPORTS)->keys()->contains(fn ($k) => $u->can("reports.$k")));
    $quick = collect([
        [__('New expense'), route('expenses.create'), 'expenses.create', 'expenses.index'],
        [__('Work entry'), route('work-entries.create'), 'work_entries.create', 'work-entries.index'],
        [__('Pay worker / advance'), route('worker-payments.create'), 'worker_payments.create', 'worker-payments.index'],
        [__('New order'), route('orders.create'), 'orders.create', 'orders.index'],
        [__('Log production'), route('production-logs.create'), 'production.create', 'production-logs.index'],
        [__('New delivery'), route('deliveries.create'), 'deliveries.create', 'deliveries.index'],
        [__('New invoice'), route('invoices.create'), 'invoices.create', 'invoices.index'],
        [__('Customer payment'), route('customer-payments.create'), 'customer_payments.create', 'customer-payments.index'],
        [__('Pay a vendor'), route('vendor-payments.create'), 'vendor_payments.create', 'vendor-payments.index'],
        [__('Stock movement'), route('stock.create'), 'inventory.create', 'stock.index'],
        [__('Investment'), route('investments.create'), 'investments.create', 'investments.index'],
        [__('Cash from bank'), route('cash-transfers.create'), 'transfers.create', 'cash-transfers.index'],
    ])->filter(fn ($q) => $u->can($q[2]) && ! \App\Support\Menu::isHidden($q[3]))->values();
    $unread = $u->unreadNotifications()->count();
    $precache = $quick->pluck(1)->map(fn ($x) => parse_url($x, PHP_URL_PATH))->merge(['/dashboard', '/sync'])->values();
    // breadcrumbs: Home › Module › current page (derived from the route name and the menu above)
    $routeName = request()->route()?->getName() ?? '';
    $prefix = \Illuminate\Support\Str::before($routeName, '.');
    $action = str_contains($routeName, '.') ? \Illuminate\Support\Str::after($routeName, '.') : '';
    $module = collect($groups)->flatten(1)->first(fn ($i) => \Illuminate\Support\Str::before($i[1], '.') === $prefix);
    $helpKey = array_key_exists($routeName, config('help')) ? $routeName : (array_key_exists($prefix, config('help')) ? $prefix : '');
    $pageTitle = html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES); // inline @section values arrive HTML-escaped
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? __('Good morning') : ($hour < 17 ? __('Good afternoon') : __('Good evening'));
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0a0a0a">
    <meta name="precache" content='@json($precache)'>
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="{{ brand_url('favicon_180', '/apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" href="{{ brand_url('favicon_32', '/icons/icon-192.png') }}">
    <title>{{ $pageTitle ?: config('app.name') }} · {{ biz('business_name', 'Lumiere Premium') }}</title>
    <script>(function(){try{var t=localStorage.getItem('theme')||'auto';if(t==='dark'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');}catch(e){}})();</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="{ menu: false, quick: false }" class="min-h-dvh">

{{-- Desktop sidebar --}}
<aside class="fixed inset-y-0 start-0 z-30 hidden w-64 flex-col border-e border-slate-200 bg-white md:flex">
    @include('layouts._brand')
    <nav class="flex-1 space-y-5 overflow-y-auto px-3 pb-4">
        @include('layouts._nav', ['collapsible' => false])
    </nav>
    <a href="{{ route('profile') }}" class="flex items-center gap-3 border-t border-slate-200 p-3 hover:bg-slate-50">
        <x-avatar :user="$u" class="h-10 w-10 text-sm" />
        <span class="min-w-0 leading-tight"><span class="block truncate text-sm font-semibold text-slate-900">{{ $u->name }}</span><span class="block text-xs text-slate-500">{{ $u->roleLabel() }}</span></span>
    </a>
</aside>

<div class="md:ps-64">
    @include('layouts._header')

    <main class="mx-auto max-w-7xl p-4 pb-32 md:p-6 md:pb-10">
        {{-- page header: breadcrumbs · title · page actions --}}
        @if ($pageTitle !== '')
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div class="min-w-0">
                    @if ($routeName !== 'dashboard')
                        <nav aria-label="Breadcrumb" class="mb-1 flex flex-wrap items-center gap-1 text-xs text-slate-500">
                            <a href="{{ route('dashboard') }}" class="hover:text-brand-700"><x-icon name="home" class="h-4 w-4" /></a>
                            @if ($module && $action && ! in_array($action, ['index', 'board'], true) && $prefix !== 'dashboard')
                                <span>›</span><a href="{{ route($module[1]) }}" class="hover:text-brand-700">{{ $module[0] }}</a>
                            @endif
                            <span>›</span><span class="font-medium text-slate-700">{{ \Illuminate\Support\Str::limit($pageTitle, 40) }}</span>
                        </nav>
                    @endif
                    <h1 class="flex items-center gap-2 text-xl font-bold tracking-tight text-slate-900 md:text-2xl"><span class="truncate">{{ $routeName === 'dashboard' ? $greeting.', '.\Illuminate\Support\Str::before($u->name, ' ') : $pageTitle }}</span>@if ($routeName !== 'help')<a href="{{ route('help') }}#{{ $helpKey }}" title="{{ __('Help for this page') }}" class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500 hover:bg-brand-100 hover:text-brand-800">?</a>@endif</h1>
                    @if ($routeName === 'dashboard')<p class="text-sm text-slate-500">{{ now()->translatedFormat('l, d F Y') }}</p>@endif
                </div>
                <div class="flex flex-wrap items-center gap-2">@yield('actions')</div>
            </div>
        @endif

        @if (session('success'))
            <div class="mb-4 rounded-xl bg-emerald-50 p-3 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200" x-data x-init="setTimeout(() => $el.remove(), 6000)">{{ session('success') }}</div>
        @endif
        @if ($errors->any() && !request()->routeIs('*.create', '*.edit'))
            <div class="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
        @endif
        @yield('content')
    </main>
</div>

@include('layouts._mobile')

@stack('scripts')
</body>
</html>
