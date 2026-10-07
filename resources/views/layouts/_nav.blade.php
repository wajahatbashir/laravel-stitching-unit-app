{{-- Navigation groups. $collapsible = accordion (mobile menu), otherwise static headings (desktop sidebar). --}}
@foreach ($groups as $g => $items)
    @php
        $items = array_filter($items, $canSee);
        $hasActive = collect($items)->contains(fn ($i) => request()->routeIs($i[4]));
    @endphp
    @if ($items)
        @if ($collapsible)
            <div x-data="{ open: {{ $hasActive ? 'true' : 'false' }} }" class="rounded-xl">
                <button type="button" @click="open = !open" class="flex min-h-11 w-full items-center justify-between px-3 text-[11px] font-semibold tracking-wider text-slate-400 uppercase">
                    <span>{{ $g }}</span><x-icon name="chevron" class="h-4 w-4 transition-transform" x-bind:class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-transition.opacity.duration.150ms>
                    @foreach ($items as $i)
                        <a href="{{ route($i[1]) }}" class="nav-link !min-h-12 {{ request()->routeIs($i[4]) ? 'active' : '' }}"><x-icon :name="$i[2]" class="h-5 w-5 shrink-0" />{{ $i[0] }}</a>
                    @endforeach
                </div>
            </div>
        @else
            <div>
                <p class="mb-1 px-3 text-[11px] font-semibold tracking-wider text-slate-400 uppercase">{{ $g }}</p>
                @foreach ($items as $i)
                    <a href="{{ route($i[1]) }}" class="nav-link {{ request()->routeIs($i[4]) ? 'active' : '' }}"><x-icon :name="$i[2]" class="h-5 w-5 shrink-0" />{{ $i[0] }}</a>
                @endforeach
            </div>
        @endif
    @endif
@endforeach
