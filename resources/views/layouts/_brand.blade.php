<a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-5 py-5">
    @if (biz('brand_dashboard_logo'))
        <img src="{{ brand_url('dashboard_logo', '') }}" alt="{{ biz('business_name') }}" class="max-h-12 max-w-[11rem] object-contain">
    @else
        <img src="/brand/icon-black.png" alt="" class="h-11 w-11 rounded-full">
        <div class="leading-tight">
            <p class="font-serif text-lg font-semibold tracking-[.18em] text-brand-900 uppercase">Lumière</p>
            <p class="text-[10px] tracking-[.35em] text-gold-500 uppercase">Premium</p>
        </div>
    @endif
</a>
