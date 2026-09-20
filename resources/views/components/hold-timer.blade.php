@props(['hold'])

{{--
    How long the seats stay held.

    Server-rendered and static, refreshed on every step. A ticking clock
    would need JavaScript for something this page can state plainly, and a
    checkout that depends on a script is a checkout that fails on the phone
    on mobile data in Malé that most of this traffic is.

    Minutes remaining rather than a clock time: the application runs in UTC
    and nobody has set a Maldivian timezone, so "held until 14:32" would be
    five hours wrong for every person reading it. A duration is right in
    every timezone.
--}}
@php($minutes = (int) ceil($hold->secondsRemaining() / 60))

<div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-xl border border-gold-500 bg-gold-50 p-4']) }}>
    <svg class="mt-0.5 h-5 w-5 shrink-0 text-gold-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path stroke-linecap="round" d="M12 7v5l3 2" />
    </svg>
    <p dir="auto" class="text-sm text-ink">
        <span class="font-semibold">
            {{ trans_choice(
                '{1}:count seat is held for you|[2,*]:count seats are held for you',
                $hold->seats,
                ['count' => $hold->seats],
            ) }}
        </span>
        —
        {{ trans_choice(
            '{1}another :count minute to complete this booking|[2,*]another :count minutes to complete this booking',
            max($minutes, 1),
            ['count' => max($minutes, 1)],
        ) }}.
    </p>
</div>
