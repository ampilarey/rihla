<?php

namespace App\Filament\Resources\Stays\Schemas;

use App\Models\Stay;
use App\Models\StayGuest;
use App\Services\Stays\StayBooking;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One stay, read-only.
 *
 * The screen a member of staff opens with the partner on the phone, so what
 * it shows is what gets asked: which room, which nights, how many people,
 * and what was promised about money.
 *
 * **The policy shown here is the one frozen onto the stay**, not the
 * property's current one. Those can differ — the property may have been
 * edited since — and the customer is held to what the page said on the day.
 * Showing today's terms on a stay agreed under last month's is how a
 * dispute gets answered with the wrong number.
 */
class StayDetails
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The stay')->columns(3)->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                TextEntry::make('customer.name')->label('Guest'),

                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('roomType.name')->label('Room'),
                TextEntry::make('nights')
                    ->label('Nights')
                    ->formatStateUsing(fn (?int $state, Stay $record): string => sprintf(
                        '%s → %s (%d night%s)',
                        $record->check_in->format('j M Y'),
                        $record->check_out->format('j M Y'),
                        (int) $state,
                        (int) $state === 1 ? '' : 's',
                    )),

                TextEntry::make('adults')
                    ->label('Guests')
                    ->formatStateUsing(fn (?int $state, Stay $record): string => $record->children > 0
                        ? sprintf('%d adult%s, %d child%s', (int) $state, (int) $state === 1 ? '' : 's', $record->children, $record->children === 1 ? '' : 'ren')
                        : sprintf('%d adult%s', (int) $state, (int) $state === 1 ? '' : 's')),

                TextEntry::make('special_requests')
                    ->label('What they asked for')
                    ->placeholder('Nothing')
                    ->columnSpan(2),
            ]),

            Section::make('Money')->columns(3)->schema([
                TextEntry::make('total_minor')
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state, Stay $record): string => Money::ofMinor((int) $state, $record->currency)->format()),

                TextEntry::make('deposit_minor')
                    ->label('Deposit')
                    ->formatStateUsing(fn (?int $state, Stay $record): string => Money::ofMinor((int) $state, $record->currency)->format()),

                TextEntry::make('paid_minor')
                    ->label('Paid so far')
                    ->formatStateUsing(fn (?int $state, Stay $record): string => Money::ofMinor((int) $state, $record->currency)->format()),

                TextEntry::make('expires_at')
                    ->label('Deposit due by')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—'),

                TextEntry::make('check_in')
                    ->label('Balance due by')
                    ->formatStateUsing(fn ($state, Stay $record): string => app(StayBooking::class)
                        ->balanceDueAt($record)
                        ->format('j M Y')),

                TextEntry::make('currency')
                    ->label('Still owed')
                    ->formatStateUsing(fn ($state, Stay $record): string => $record->outstanding()->format()),
            ]),

            // Read from the snapshot, deliberately. See the class docblock.
            Section::make('What the guest was told')
                ->description('The terms frozen onto this stay when it was requested. The property may have been edited since; this is what applies.')
                ->columns(3)
                ->schema([
                    TextEntry::make('rate_snapshot.policy.deposit_pct')
                        ->label('Deposit')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : $state.'% on confirmation'),

                    TextEntry::make('rate_snapshot.policy.balance_days_before')
                        ->label('Balance')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : $state.' days before check-in'),

                    TextEntry::make('rate_snapshot.policy.free_cancel_days')
                        ->label('Free cancellation')
                        ->formatStateUsing(fn ($state): string => $state === null ? '—' : 'until '.$state.' days before'),
                ]),

            // §15.6 (Phase 11). Maldivian law requires this and somebody
            // will ask for it, so it is on the screen rather than in a
            // report nobody runs. Shown whole, not masked: the protection
            // is the encryption in the column and the permission on this
            // page, not asterisks in front of staff who already have both.
            Section::make('Who stayed')
                ->description('The guest register. Required by law, and kept encrypted.')
                ->visible(fn (Stay $record): bool => $record->guests()->exists())
                ->schema([
                    RepeatableEntry::make('guests')
                        ->hiddenLabel()
                        ->columns(4)
                        ->schema([
                            TextEntry::make('full_name')
                                ->label('Name')
                                ->formatStateUsing(fn (?string $state, StayGuest $record): string => $record->is_lead
                                    ? (string) $state.' · '.__('messages.Lead guest')
                                    : (string) $state),

                            TextEntry::make('nationality')->placeholder('—'),

                            TextEntry::make('date_of_birth')->label('Born')->date('j M Y')->placeholder('—'),

                            TextEntry::make('id_number')
                                ->label(fn (StayGuest $record): string => $record->identifierLabel())
                                ->placeholder('—'),
                        ]),
                ]),

            Section::make('Why it ended')
                ->visible(fn (Stay $record): bool => filled($record->cancellation_reason))
                ->schema([
                    TextEntry::make('cancellation_reason')->label('Reason the guest was given')->hiddenLabel(),
                ]),
        ]);
    }
}
