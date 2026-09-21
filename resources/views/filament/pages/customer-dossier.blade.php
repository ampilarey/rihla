<x-filament-panels::page>
    {{--
        Filament components throughout. The panel has no custom theme, so
        Tailwind utilities do nothing here and this would render as
        unstyled running text with every test still passing — the trap
        AGENTS.md records.

        Dates use format(), not translatedFormat(): the /staff panel is
        English throughout (Filament carries no Dhivehi here), and a
        translated month inside a `:description` attribute cannot be
        isolated in a <bdi> the way <x-local-date> does on the public site.
        BidiTest catches the other spelling, which is how this comment
        came to be written.
    --}}
    @php($d = $this->getDossier())

    <x-filament::section>
        <x-slot name="heading">{{ $d->customer->name }}</x-slot>

        <x-slot name="afterHeader">
            @foreach ($d->customer->tags as $tag)
                <x-filament::badge color="gray">{{ $tag->tag }}</x-filament::badge>
            @endforeach
        </x-slot>

        <x-slot name="description">
            {{ $d->customer->phone ?: 'No phone on file' }} ·
            {{ $d->customer->email ?: 'No email on file' }}
            @if ($d->customer->referrer)
                · Referred by {{ $d->customer->referrer->name }}
            @endif
        </x-slot>

        {{-- The three things somebody wants before they pick up the phone. --}}
        <x-filament::callout
            color="gray"
            icon="heroicon-o-identification"
            heading="{{ $d->journeysTaken() === 0 ? 'Has not travelled with us yet' : ($d->journeysTaken() === 1 ? 'One journey with us' : $d->journeysTaken() . ' journeys with us') }}"
            :description="$d->monthsSinceLastJourney() === null
                ? 'No completed journey on file.'
                : 'Last travelled ' . $d->monthsSinceLastJourney() . ' months ago.'"
        />

        @if ($d->outstanding()->isNotEmpty())
            {{-- Never summed across currencies: [R-7], and the one mistake
                 in this area that looks right on screen. --}}
            <x-filament::callout
                color="danger"
                icon="heroicon-o-banknotes"
                heading="Still owed"
                :description="$d->outstanding()->map(fn ($money) => (string) $money)->implode(' · ')"
            />
        @endif

        @if ($d->openTasks->isNotEmpty())
            <x-filament::callout
                color="warning"
                icon="heroicon-o-clipboard-document-check"
                heading="{{ $d->openTasks->count() === 1 ? 'One thing to do' : $d->openTasks->count() . ' things to do' }}"
                :description="$d->openTasks->map(fn ($t) => $t->subject . ' (' . $t->whenLabel() . ', ' . ($t->owner?->name ?? 'nobody') . ')')->implode(' · ')"
            />
        @endif
    </x-filament::section>

    <x-filament::section :collapsed="$d->bookings->isEmpty()">
        <x-slot name="heading">Bookings</x-slot>

        @forelse ($d->bookings as $booking)
            <x-filament::callout
                color="gray"
                icon="heroicon-o-ticket"
                :heading="$booking->reference . ' — ' . ($booking->departure?->package?->title ?? 'no package')"
                :description="($booking->departure->date_start->format('j M Y') ?? 'no date')
                    . ' · ' . $booking->seats . ' ' . ($booking->seats === 1 ? 'seat' : 'seats')
                    . ' · ' . $booking->total()
                    . ' · paid ' . $booking->paid()"
            />
        @empty
            <x-filament::callout color="gray" icon="heroicon-o-ticket" heading="No bookings" description="Nothing has been booked under this customer." />
        @endforelse
    </x-filament::section>

    <x-filament::section :collapsed="$d->enquiries->isEmpty()">
        <x-slot name="heading">Enquiries and quotations</x-slot>

        @forelse ($d->enquiries as $enquiry)
            <x-filament::callout
                :color="$enquiry->isOpen() ? 'warning' : 'gray'"
                icon="heroicon-o-inbox"
                :heading="$enquiry->created_at->format('j M Y') . ' — ' . ucfirst($enquiry->status)"
                :description="\Illuminate\Support\Str::limit($enquiry->message, 160)
                    . ' · ' . ($enquiry->owner->name ?? 'nobody owns this')"
            />
        @empty
            <x-filament::callout color="gray" icon="heroicon-o-inbox" heading="No enquiries" description="Nothing on record before their first booking." />
        @endforelse

        @foreach ($d->quotations as $quotation)
            <x-filament::callout
                :color="$quotation->isOpen() ? 'info' : 'gray'"
                icon="heroicon-o-document-currency-dollar"
                :heading="$quotation->reference . ' — ' . $quotation->total() . ' (' . $quotation->statusLabel() . ')'"
                :description="'For ' . $quotation->party_size . ' ' . ($quotation->party_size === 1 ? 'person' : 'people')
                    . ' · valid until ' . $quotation->valid_until->format('j M Y')
                    . ($quotation->decline_reason ? ' · declined: ' . $quotation->decline_reason : '')"
            />
        @endforeach
    </x-filament::section>

    @if ($d->questions->isNotEmpty())
        <x-filament::section collapsed>
            <x-slot name="heading">Questions they have asked</x-slot>

            <x-slot name="description">
                From Ask a Scholar. Shown here because the person on the phone should know what
                somebody has already asked — not so it can be repeated to anybody else.
            </x-slot>

            @foreach ($d->questions as $question)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-chat-bubble-left-right"
                    :heading="\Illuminate\Support\Str::limit($question->body, 90)"
                    :description="$question->statusLabel() . ($question->scholar ? ' · ' . $question->scholar->name : '')"
                />
            @endforeach
        </x-filament::section>
    @endif

    @php($credit = $this->getReferralCredit())
    @php($referrer = $this->getReferrer())

    @if ($credit !== null || $referrer !== null)
        <x-filament::section>
            <x-slot name="heading">Referrals</x-slot>

            <x-slot name="description">
                §8.1 records a referral against a customer already on file rather than a name in a
                box, so the person who made it can actually be found afterwards. This is that.
            </x-slot>

            @if ($referrer !== null)
                <x-filament::callout
                    color="gray"
                    icon="heroicon-o-user-plus"
                    :heading="'Sent to us by ' . $referrer->name"
                >
                    <x-slot name="footer">
                        <x-filament::link :href="route('filament.staff.resources.customers.view', $referrer)">
                            Open their record
                        </x-filament::link>
                    </x-slot>
                </x-filament::callout>
            @endif

            @if ($credit !== null)
                <x-filament::callout
                    :color="$credit->tone()"
                    icon="heroicon-o-hand-raised"
                    :heading="'They have sent us ' . $credit->spoken()"
                    :description="$credit->isUnacknowledged()
                        ? 'Their most recent referral travelled on ' . $credit->lastArrival->format('j M Y')
                            . ', and nothing has been written down about them since. A follow-up task is the place to put that.'
                        : ($credit->lastArrival === null
                            ? 'None of them has travelled yet, so nothing is owed here — it is counted so it is not forgotten.'
                            : 'Last follow-up recorded ' . $credit->lastNoted->format('j M Y')
                                . '. That is not the same as having thanked them, and this screen does not claim it is.')"
                />
            @endif
        </x-filament::section>
    @endif

    {{--
        Rendered by hand, because this page has a custom view and a custom
        view is not the one Filament draws relation managers from: the tags
        table simply did not appear, on a page whose own test passed because
        it tested the relation manager component directly. That is the trap
        AGENTS.md records about relation managers, from the other side.
    --}}
    @livewire(\App\Filament\Resources\Customers\RelationManagers\TagsRelationManager::class, [
        'ownerRecord' => $this->getRecord(),
        'pageClass' => static::class,
    ])
</x-filament-panels::page>
