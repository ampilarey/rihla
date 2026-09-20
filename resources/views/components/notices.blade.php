@props(['notices'])

{{--
    What we need from you, on the page you open.

    Below the emergency notice and above everything else: this is the
    reason most people open a portal at all. Only outstanding ones — a
    passport request that has been dealt with is not news.
--}}
@if ($notices->isNotEmpty())
    <section class="card mb-6 border-s-4 border-s-gold">
        <h2 class="mb-3 text-lg font-bold text-ink">{{ __('messages.What we need from you') }}</h2>

        <ul class="space-y-3">
            @foreach ($notices as $notice)
                <li class="border-b border-cream-deep pb-3 last:border-0 last:pb-0">
                    <p class="font-medium text-ink">{{ $notice->headline }}</p>

                    @if ($notice->body)
                        <p class="mt-1 text-ink-muted">{{ $notice->body }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
