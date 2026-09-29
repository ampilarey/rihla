@extends('layouts.app')

@section('title', __('messages.Book :name', ['name' => $property->name]))

{{--
    Booking a stay — §16.7 (Phase 13.3). The quote restated, the policy the
    stay will be held to, what is paid now, and the guest's details, on one
    server-rendered page that works with no JavaScript.

    Every figure here is what StayBooking::request() will freeze onto the
    stay: the same quote, the same deposit percentage, the same Green Tax.
    A page that promised one number and a stay that recorded another would
    be the defect the snapshot exists to prevent.
--}}
@section('content')
<div class="container mx-auto max-w-3xl px-4 section-y-tight">
    <header class="mb-6">
        <p dir="auto" class="text-sm text-ink-muted">
            <a href="{{ route('stays.show', ['property' => $property->slug] + $filters->toQuery()) }}" class="text-wine-700 underline hover:no-underline">
                {{ $property->name }}
            </a>
        </p>
        <h1 dir="auto" class="section-title text-start">{{ __('messages.Book :name', ['name' => $room->name]) }}</h1>
    </header>

    <section class="card mb-6" aria-labelledby="book-summary-heading">
        <h2 id="book-summary-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Your stay') }}</h2>
        <dl dir="auto" class="space-y-2 text-sm text-ink">
            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('messages.Dates') }}</dt>
                <dd class="text-end">{{ $filters->checkIn->isoFormat('D MMM YYYY') }} – {{ $filters->checkOut->isoFormat('D MMM YYYY') }}
                    ({{ trans_choice('messages.:count night|:count nights', $quote->nights(), ['count' => $quote->nights()]) }})</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('messages.Guests') }}</dt>
                <dd class="text-end">{{ $adults + $children }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('messages.Prices for') }}</dt>
                <dd class="text-end">{{ $filters->audience === 'local' ? __('messages.A Maldivian citizen or resident') : __('messages.A visitor to the Maldives') }}</dd>
            </div>
            @if($quote->discount)
                <div class="flex justify-between gap-4">
                    <dt class="text-ink-muted">{{ trans_choice('messages.:count night|:count nights', $quote->nights(), ['count' => $quote->nights()]) }}</dt>
                    <dd class="text-end">{{ $quote->subtotal()->format() }}</dd>
                </div>
                <div class="flex justify-between gap-4 text-success-dark">
                    <dt>{{ __('messages.Includes :name — :percent% off', ['name' => $quote->discount['name'], 'percent' => $quote->discount['percent']]) }}</dt>
                    <dd class="text-end">−{{ $quote->discountAmount()->format() }}</dd>
                </div>
            @endif
            <div class="flex justify-between gap-4 border-t border-gray-200 pt-2 text-base font-bold">
                <dt>{{ __('messages.Total') }}</dt>
                <dd class="text-end">{{ $quote->total()->format() }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-ink-muted">{{ __('messages.Paid now') }}</dt>
                <dd class="text-end font-medium">
                    @if($instant)
                        {{ __('messages.:amount deposit', ['amount' => $deposit->format()]) }}
                    @else
                        {{ __('messages.Nothing until the host confirms') }}
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="card mb-6" aria-labelledby="book-policy-heading">
        <h2 id="book-policy-heading" dir="auto" class="mb-3 text-lg font-bold text-ink">{{ __('messages.Paying and cancelling') }}</h2>
        <ul dir="auto" class="space-y-2 text-sm text-brand-body">
            @unless($instant)
                <li>{{ __('messages.We will confirm with the host within 24 hours. You pay nothing until then.') }}</li>
            @endunless
            <li>{{ __('messages.:percent% deposit when the guesthouse confirms.', ['percent' => $property->deposit_pct]) }} ({{ $deposit->format() }})</li>
            <li>{{ __('messages.The rest is due :days days before you arrive.', ['days' => $property->balance_days_before]) }}</li>
            <li>{{ __('messages.Cancel more than :days days before and the deposit comes back.', ['days' => $property->free_cancel_days]) }}</li>
            @if($greenTaxApplies && $greenTaxAtProperty)
                <li>
                    {{ __('messages.Green tax is paid at the guesthouse, not here.') }}
                    @if($greenTaxEstimate)
                        <strong>{{ __('messages.For your dates and party: :amount.', ['amount' => $greenTaxEstimate->format()]) }}</strong>
                    @endif
                </li>
            @endif
        </ul>
    </section>

    <form method="POST" action="{{ route('stays.book.store', ['property' => $property->slug]) }}" class="card space-y-4" novalidate>
        @csrf
        <h2 dir="auto" class="text-lg font-bold text-ink">{{ __('messages.Your details') }}</h2>

        <input type="hidden" name="room" value="{{ $room->getKey() }}">
        <input type="hidden" name="from" value="{{ $filters->checkIn->toDateString() }}">
        <input type="hidden" name="to" value="{{ $filters->checkOut->toDateString() }}">
        <input type="hidden" name="adults" value="{{ $adults }}">
        <input type="hidden" name="children" value="{{ $children }}">
        <input type="hidden" name="audience" value="{{ $filters->audience }}">

        @if($errors->any())
            <div role="alert" class="rounded-xl border-s-4 border-s-error bg-cream-deep px-4 py-3 text-sm text-ink">
                <ul class="list-disc ps-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label for="book-name" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Your name') }}</label>
            <input id="book-name" name="name" type="text" required maxlength="255" autocomplete="name" dir="auto"
                   value="{{ old('name') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="book-email" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Email') }}</label>
                <input id="book-email" name="email" type="email" required maxlength="255" autocomplete="email" dir="ltr"
                       value="{{ old('email') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
            <div>
                <label for="book-phone" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Phone or WhatsApp') }}</label>
                <input id="book-phone" name="phone" type="tel" required maxlength="40" autocomplete="tel" dir="ltr"
                       value="{{ old('phone') }}" class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">
            </div>
        </div>

        {{-- Nationality fixes the price — §16.3 decision 6. Asked as the
             one question that matters to it rather than a country list. --}}
        <fieldset>
            <legend class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Are you a Maldivian citizen?') }}</legend>
            @php($citizenship = old('citizenship', $filters->audience === 'local' ? 'maldivian' : 'other'))
            <div class="flex flex-wrap gap-4">
                <label class="inline-flex items-center gap-2 text-sm text-ink">
                    <input type="radio" name="citizenship" value="maldivian" @checked($citizenship === 'maldivian')>
                    {{ __('messages.Yes') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-ink">
                    <input type="radio" name="citizenship" value="other" @checked($citizenship === 'other')>
                    {{ __('messages.No, I am visiting') }}
                </label>
            </div>
            <p dir="auto" class="mt-1 text-xs text-ink-muted">{{ __('messages.The guesthouse checks this against your ID at check-in.') }}</p>
        </fieldset>

        {{-- Add-ons — §16 Phase 15. Paid at the property, on the bill with
             the rest of the extras; nothing here changes the deposit. --}}
        @if($addons->isNotEmpty())
            <fieldset>
                <legend dir="auto" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Add to your stay (optional)') }}</legend>
                <p dir="auto" class="mb-2 text-xs text-ink-muted">{{ __('messages.Paid to the host at the property, not now.') }}</p>
                <div class="space-y-2">
                    @foreach($addons as $addon)
                        @php($each = $addon->priceFor($filters->audience))
                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input type="checkbox" name="addons[]" value="{{ $addon->getKey() }}" class="mt-1"
                                   @checked(in_array((string) $addon->getKey(), array_map('strval', (array) old('addons', [])), true))>
                            <span dir="auto">
                                <span class="font-medium">{{ $addon->name }}</span>
                                — {{ $each->format() }} {{ $addon->pricingLabel() }}@if($addon->pricing === \App\Models\PropertyAddon::PER_PERSON && $guests > 1) ({{ __('messages.:amount for your party', ['amount' => \App\Support\Money::ofMinor($each->minor * $addon->quantityFor($guests), $each->currency)->format()]) }})@endif
                                @if($addon->description)
                                    <span class="block text-xs text-ink-muted">{{ $addon->description }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif

        <div>
            <label for="book-requests" class="mb-1 block text-sm font-medium text-ink">{{ __('messages.Anything the host should know? (optional)') }}</label>
            <textarea id="book-requests" name="special_requests" rows="3" maxlength="1000" dir="auto"
                      class="w-full rounded-lg border border-gray-500 px-3 py-2 text-ink">{{ old('special_requests') }}</textarea>
        </div>

        {{-- A field no human sees. --}}
        <div class="hidden" aria-hidden="true">
            <label for="book-website">Website</label>
            <input id="book-website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <label class="flex items-start gap-2 text-sm text-ink">
            <input type="checkbox" name="accept" value="1" class="mt-1" @checked(old('accept'))>
            <span dir="auto">{{ __('messages.I have read how paying and cancelling work for this stay.') }}</span>
        </label>

        <button type="submit" class="btn-primary w-full">
            {{ $instant ? __('messages.Book and go to payment') : __('messages.Send my request') }}
        </button>
    </form>
</div>
@endsection
