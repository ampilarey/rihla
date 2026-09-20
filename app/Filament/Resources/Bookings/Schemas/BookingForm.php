<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\SeatHold;
use App\Support\TravelReadiness;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One booking, read back.
 *
 * Almost everything here is read-only, and that is the design rather than an
 * omission. The amounts are what was agreed, the travellers are what was
 * entered, and the status moves only through
 * {@see Booking::transitionTo()} — which records who and why — reached from
 * the actions on the page, never by editing a field.
 *
 * `notes` is the one editable thing: somewhere for the person who took the
 * phone call to write what was said.
 */
class BookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Booking')->columns(3)->schema([
                TextEntry::make('reference')->copyable(),

                TextEntry::make('status')
                    ->badge()
                    ->color(fn (string $state): string => BookingsTable::COLOURS[$state] ?? 'gray'),

                TextEntry::make('seats')->label('Seats'),

                TextEntry::make('departure.package.title')->label('Package'),

                TextEntry::make('departure.date_start')->label('Departs')->date('j M Y'),

                TextEntry::make('departure.date_end')->label('Returns')->date('j M Y'),
            ]),

            Section::make('Customer')->columns(3)->schema([
                TextEntry::make('customer.name')->label('Name'),
                TextEntry::make('customer.phone')->label('Phone')->copyable(),
                TextEntry::make('customer.email')->label('Email')->placeholder('—')->copyable(),
            ]),

            Section::make('Travellers')->schema([
                // A repeater would invite editing; this is a list. Passport
                // details are changed where they are collected, not here.
                TextEntry::make('travellers')
                    // Screen-reader only, not removed: the section heading
                    // above already says "Travellers", and a sighted reader
                    // seeing it twice is noise while a screen reader needs
                    // the entry named. label('') would not do this — it
                    // falls back to the generated headline and renders it.
                    ->hiddenLabel()
                    ->state(fn (Booking $record): array => $record->travellers
                        ->map(fn (BookingTraveller $line): string => sprintf(
                            '%s — %s, %s — %s',
                            $line->traveller->full_name,
                            ucfirst($line->occupancy),
                            $line->pax_type,
                            $line->money()->format(),
                        ))
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted(),
            ]),

            Section::make('Can they actually go?')
                ->description('A passport, a visa and an Umrah permit are three separate authorisations from three different bodies [R-4]. Computed here on every read (§5.4a) and never stored, because a stored "ready" is wrong from the moment any of the three changes — and the one time that matters is the morning somebody leaves for the airport.')
                ->schema([
                    TextEntry::make('readiness')
                        ->hiddenLabel()
                        ->state(fn (Booking $record): array => self::readiness($record))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('No travellers entered yet.'),
                ]),

            Section::make('Money')->columns(3)->schema([
                TextEntry::make('total_minor')
                    ->label('Total')
                    ->state(fn (Booking $record): string => $record->total()->format()),

                TextEntry::make('paid_minor')
                    ->label('Paid')
                    ->state(fn (Booking $record): string => $record->paid()->format())
                    // Always zero until payments exist. Said out loud rather
                    // than left to look like a bug: nothing writes this yet,
                    // because taking money needs a BML merchant account.
                    ->helperText('Payments are not recorded yet — this stays at zero until BML is connected.'),

                TextEntry::make('balance')
                    ->label('Balance')
                    ->state(fn (Booking $record): string => $record->balance()->format()),
            ]),

            Section::make('Seats held')->columns(2)->schema([
                TextEntry::make('hold_state')
                    ->label('Hold')
                    ->state(fn (Booking $record): string => self::holdState($record)),

                // Only for a hold that is actually live. Printing the
                // expiry of a lapsed one next to "No seats held" reads as a
                // deadline somebody still has time to meet.
                TextEntry::make('hold_expires')
                    ->label('Expires')
                    ->state(fn (Booking $record): string => $record->seatHolds
                        ->first(fn (SeatHold $hold): bool => $hold->isLive())
                        ?->expires_at->diffForHumans() ?? '—'),
            ]),

            Section::make('History')->schema([
                TextEntry::make('transitions')
                    ->hiddenLabel()
                    // `->user->name`, not `?->`: `??` suppresses a property
                    // access on null, so the nullsafe operator adds nothing
                    // and static analysis says so. A *method* call is the
                    // opposite — `$null->format() ?? 'x'` throws — which is
                    // why `lead_price?->format()` elsewhere keeps its `?->`.
                    ->state(fn (Booking $record): array => $record->transitions
                        ->map(fn ($transition): string => sprintf(
                            '%s → %s · %s%s',
                            $transition->from_status ?? 'new',
                            $transition->to_status,
                            $transition->user->name ?? 'system',
                            $transition->reason !== null ? ' · '.$transition->reason : '',
                        ))
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Nothing recorded yet.'),
            ])->collapsed(),

            Section::make('Notes')->schema([
                Textarea::make('notes')
                    ->hiddenLabel()
                    ->rows(3)
                    ->helperText('What was said on the phone. Visible to staff only.'),
            ]),
        ]);
    }

    /**
     * Each traveller, and what is still missing for them.
     *
     * Named requirements rather than one tick, because "not ready" is
     * useless to the person who has to fix it and "visa issued, permit not
     * requested" is a morning's work. A Rawdah slot is deliberately absent:
     * missing it is a disappointment, and folding it in would make a pilgrim
     * who cannot pray in the Rawdah look like one who cannot perform Umrah.
     *
     * @return list<string>
     */
    private static function readiness(Booking $booking): array
    {
        return $booking->travellers
            ->map(function (BookingTraveller $line) use ($booking): string {
                $missing = array_keys(array_filter(
                    TravelReadiness::forTraveller($booking, $line->traveller),
                    fn (bool $met): bool => ! $met,
                ));

                // Words rather than a tick and a cross. A glyph reads as
                // "check mark" to a screen reader and as nothing at all
                // when the font lacks it, and the whole value of this
                // section is that somebody can act on it without decoding
                // it.
                return $line->traveller->full_name.' — '.($missing === []
                    ? 'ready to travel'
                    : 'still needs '.implode(', ', array_map(
                        fn (string $requirement): string => self::REQUIREMENT_LABELS[$requirement],
                        $missing,
                    )));
            })
            ->all();
    }

    /** @var array<string, string> */
    private const REQUIREMENT_LABELS = [
        TravelReadiness::PASSPORT => 'passport',
        TravelReadiness::VISA => 'visa',
        TravelReadiness::PERMIT => 'Umrah permit',
    ];

    private static function holdState(Booking $booking): string
    {
        $live = $booking->seatHolds->first(fn ($hold): bool => $hold->isLive());

        if ($live !== null) {
            return sprintf('%d seat(s) held', $live->seats);
        }

        return $booking->status === Booking::CONFIRMED
            ? 'Seats confirmed'
            : 'No seats held';
    }
}
