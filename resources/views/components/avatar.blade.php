@props(['user', 'class' => 'h-9 w-9 text-sm'])
@if ($user->avatarUrl())
    <img src="{{ $user->avatarUrl() }}" alt="" {{ $attributes->merge(['class' => "$class shrink-0 rounded-full object-cover ring-2 ring-gold-400/70"]) }}>
@else
    <span {{ $attributes->merge(['class' => "$class flex shrink-0 items-center justify-center rounded-full bg-[#0a0a0a] font-semibold tracking-wide text-gold-400 ring-2 ring-gold-400/70"]) }} aria-hidden="true">{{ $user->initials() }}</span>
@endif
