@component('pdf._document', [
    'title' => 'Travel letter',
    'number' => $members->count() === 1 || $members->pluck('booking_id')->unique()->count() === 1 ? $members->first()->booking->reference : ($departure->package->title ?? 'Departure'),
    'issuedOn' => now(),
    'issuer' => $issuer,
    'locale' => 'en',
])

{{--
    One page per traveller (companies plan, Phase 3h, from Hijaz): who is
    travelling, on which flights, where they stay and on which bus. Facts
    only — no wording anybody has not stated, the rule the invoice keeps.
    `withPassport` is the office's copy; the portal's leaves the number out,
    because a portal link is the kind of thing that gets forwarded.
    English only for now; generated on demand and never stored.
--}}
@foreach($members as $member)
    @php($booking = $member->booking)
    <div @if(! $loop->last) style="page-break-after: always;" @endif>
        <p>Dear {{ $member->traveller->full_name }},</p>
        <p>
            We confirm that you are travelling with {{ $issuer['name'] }} on
            <strong>{{ $departure->package->title ?? 'your Umrah' }}</strong>,
            {{ $departure->datesLabel() }}@if($member->flightGroup), in {{ $member->flightGroup->name }}@endif.
            Your details are below. Please keep this letter with your passport.
        </p>

        <table class="parties">
            <tr>
                <td>
                    <div class="muted">Traveller</div>
                    <div><strong>{{ $member->traveller->full_name }}</strong></div>
                    @if($withPassport && $member->traveller->passport_number)
                        <div class="muted ltr">Passport {{ $member->traveller->passport_number }}@if($member->traveller->passport_expiry), valid to {{ $member->traveller->passport_expiry->format('j M Y') }}@endif</div>
                    @endif
                </td>
                <td>
                    <div class="muted">Booking</div>
                    <div class="ltr"><strong>{{ $booking->reference }}</strong></div>
                    <div class="muted">{{ $booking->customer->name }}</div>
                    @if($member->busGroup)
                        <div class="muted">{{ $member->busGroup->name }}@if($member->busGroup->leaderLabel()) · leader {{ $member->busGroup->leaderLabel() }}@endif @if($member->busGroup->leader_phone)<span class="ltr">{{ $member->busGroup->leader_phone }}</span>@endif</div>
                    @endif
                </td>
            </tr>
        </table>

        @php($flights = $member->flightGroup
            ? collect([$member->flightGroup->outboundFlight, $member->flightGroup->returnFlight])->filter()
            : $departure->flights)
        @if($flights->isNotEmpty())
            <table class="lines">
                <thead><tr><th>Flight</th><th>From</th><th>To</th><th>Leaves</th></tr></thead>
                <tbody>
                    @foreach($flights as $flight)
                        <tr>
                            <td class="ltr">{{ trim($flight->airline.' '.$flight->flight_number) }}</td>
                            <td class="ltr">{{ $flight->from_airport }}</td>
                            <td class="ltr">{{ $flight->to_airport }}</td>
                            <td class="ltr">{{ $flight->departs_at->format('j M Y, H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if($route !== [])
            <table class="lines" style="margin-top: 14px;">
                <thead><tr><th>Where</th><th>Stay</th><th>Dates</th></tr></thead>
                <tbody>
                    @foreach($route as $stop)
                        <tr>
                            <td>{{ $stop['place'] }}</td>
                            <td>{{ $stop['hotel'] ?? '' }}</td>
                            <td class="ltr">
                                {{ $stop['arrives']?->format('j M') ?? 'to be set' }}@if($stop['leaves'] && $stop['arrives'] && ! $stop['leaves']->isSameDay($stop['arrives']))–{{ $stop['leaves']->format('j M') }}@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endforeach

@endcomponent
