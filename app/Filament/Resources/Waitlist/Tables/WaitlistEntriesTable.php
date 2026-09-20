<?php

namespace App\Filament\Resources\Waitlist\Tables;

use App\Models\WaitlistEntry;
use App\Services\Booking\Waitlist;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WaitlistEntriesTable
{
    private const COLOURS = [
        WaitlistEntry::WAITING => 'gray',
        WaitlistEntry::OFFERED => 'warning',
        WaitlistEntry::CONVERTED => 'success',
        WaitlistEntry::EXPIRED => 'gray',
        WaitlistEntry::CANCELLED => 'danger',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Who')
                    ->searchable()
                    ->description(fn (WaitlistEntry $record): ?string => $record->customer->phone),

                TextColumn::make('departure.date_start')
                    ->label('Departure')
                    ->date('j M Y')
                    ->sortable()
                    ->description(fn (WaitlistEntry $record): ?string => $record->departure->package?->title),

                TextColumn::make('seats')->alignCenter()->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                // The whole point of the screen: something to paste into
                // WhatsApp. Copyable rather than a link, because staff are
                // sending it, not following it.
                //
                // The cell shows a word, not the URL. A signed claim link is
                // a hundred-odd characters of host, path, expiry and
                // signature; truncated into a column it reads as
                // "http://rihla.mv/en/waitlis…", which tells nobody anything
                // and makes the row unreadable. copyableState() keeps the
                // real URL on the copy button, where it is used.
                TextColumn::make('claim')
                    ->label('Claim link')
                    ->state(fn (WaitlistEntry $record): string => app(Waitlist::class)->claimUrl($record) === null
                        ? '—'
                        : 'Copy link')
                    ->copyable(fn (WaitlistEntry $record): bool => app(Waitlist::class)->claimUrl($record) !== null)
                    ->copyableState(fn (WaitlistEntry $record): ?string => app(Waitlist::class)->claimUrl($record))
                    ->copyMessage('Claim link copied')
                    ->tooltip(fn (WaitlistEntry $record): ?string => app(Waitlist::class)->claimUrl($record)),

                TextColumn::make('offer_expires_at')
                    ->label('Offer ends')
                    ->since()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            // Offers first, then the queue in the order people joined it.
            ->defaultSort('created_at')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(WaitlistEntry::STATUSES, array_map('ucfirst', WaitlistEntry::STATUSES)))
                    ->multiple()
                    ->default([WaitlistEntry::WAITING, WaitlistEntry::OFFERED]),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Remove')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WaitlistEntry $record): bool => in_array(
                        $record->status,
                        [WaitlistEntry::WAITING, WaitlistEntry::OFFERED],
                        true,
                    ))
                    ->requiresConfirmation()
                    ->modalDescription('Any seats held for this offer go back on the departure and are offered to whoever is next.')
                    ->schema([
                        Textarea::make('reason')->label('Note for the record')->rows(2),
                    ])
                    ->action(function (WaitlistEntry $record, array $data): void {
                        app(Waitlist::class)->cancel($record);

                        if (filled($data['reason'] ?? null)) {
                            $record->forceFill(['notes' => $data['reason']])->save();
                        }

                        Notification::make()->success()->title('Removed from the waiting list')->send();
                    }),
            ])
            ->emptyStateHeading('Nobody is waiting')
            ->emptyStateDescription('People join from a sold-out departure. When seats come back they are offered here.');
    }
}
