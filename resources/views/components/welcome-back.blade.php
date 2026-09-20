@props(['personal'])

{{--
    §4.2's "personalisation for returning users", and the whole of it.

    It appears only for somebody signed in who has actually travelled with
    Rihla or has a journey booked. There is no cookie, no behavioural
    profile and no "people like you also viewed" — see App\Support\Personalisation
    for why that is a decision rather than a gap.

    It also recommends nothing. The first version listed "journeys on sale
    you have not been on", and on the homepage that was the same three
    departures shown directly underneath. What personalises this page is
    knowing who the reader is, not repeating the page back to them.
--}}
@if ($personal->hasSomethingToSay())
    <section class="bg-cream-deep py-5 md:py-6">
        <div class="container mx-auto px-4">
            <div class="card p-5">
                <p class="font-semibold text-ink">{{ $personal->greeting() }}</p>

                @if (! $personal->shouldSellAnything())
                    {{--
                        Somebody with a departure coming is not somebody to
                        sell to. The useful thing is their own portal;
                        offering them another package instead is how a
                        travel company reads as a shop rather than as the
                        people taking them.
                    --}}
                    <p class="mt-1 text-brand-body">
                        {{ __('messages.Everything about your journey — documents, payments and what to read before you go — is in your portal.') }}
                    </p>
                @endif
            </div>
        </div>
    </section>
@endif
