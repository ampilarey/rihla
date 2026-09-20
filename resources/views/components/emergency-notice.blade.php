@props(['broadcasts'])

{{--
    Sent emergency broadcasts, on the page the person actually opens.

    The only channel that works on this host today: no SMTP, no SMS
    provider, no account anybody has to open. It is at the top of the page
    and it does not collapse, because the one thing worse than not sending
    an emergency message is sending one nobody notices.
--}}
@if ($broadcasts->isNotEmpty())
    <div class="mb-6 space-y-3" role="region" aria-label="Urgent news">
        @foreach ($broadcasts as $broadcast)
            <div class="card border-s-4 border-s-wine bg-cream">
                <p class="text-lg font-bold text-ink">{{ $broadcast->headline }}</p>

                @if ($broadcast->body)
                    <p class="mt-2 text-ink">{{ $broadcast->body }}</p>
                @endif

                <p class="mt-2 text-sm text-ink-muted" dir="ltr">
                    {{ $broadcast->sent_at->format('j M Y, H:i') }}
                </p>
            </div>
        @endforeach
    </div>
@endif
