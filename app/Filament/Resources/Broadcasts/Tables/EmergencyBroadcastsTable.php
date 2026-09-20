<?php

namespace App\Filament\Resources\Broadcasts\Tables;

use App\Models\EmergencyBroadcast;
use App\Services\Broadcasts\Broadcaster;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmergencyBroadcastsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('headline')
                    ->label('What it says')
                    ->wrap()
                    ->searchable()
                    ->description(fn (EmergencyBroadcast $record): string => $record->departure->package->title ?? 'Departure'),

                TextColumn::make('sent_at')
                    ->label('Status')
                    ->badge()
                    // `->state()`, not `->formatStateUsing()`: "Not sent" is
                    // the null case, and a null state short-circuits
                    // formatting into an empty cell.
                    ->state(fn (EmergencyBroadcast $record): string => $record->isSent()
                        ? 'Sent '.$record->sent_at->format('j M, H:i')
                        : 'Not sent')
                    ->color(fn (EmergencyBroadcast $record): string => $record->isSent() ? 'success' : 'danger'),

                TextColumn::make('reached')
                    ->label('Reached')
                    ->alignCenter()
                    // How many people it got to, not how many were tried.
                    // "We told everybody" is worthless without a number.
                    ->state(fn (EmergencyBroadcast $record): string => $record->isSent()
                        ? (string) $record->reached()
                        : '—'),

                TextColumn::make('sender.name')
                    ->label('Sent by')
                    ->state(fn (EmergencyBroadcast $record): string => $record->sender->name ?? '—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('unsent')
                    ->label('Written but not sent')
                    ->query(self::onlyUnsent(...)),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::sendAction(),
                    self::deliveriesAction(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing broadcast')
            ->emptyStateDescription('A broadcast reaches everybody on a departure at once, on their portal page and on any family link they have given out. It cannot be unsent.');
    }

    /**
     * @param  Builder<EmergencyBroadcast>  $query
     * @return Builder<EmergencyBroadcast>
     */
    private static function onlyUnsent(Builder $query): Builder
    {
        return $query->whereNull('sent_at');
    }

    /**
     * Pressing send.
     *
     * The confirmation names the channels that actually work on this host
     * and says which do not, because the decision to phone people instead
     * has to be made before this button, not after it.
     */
    private static function sendAction(): Action
    {
        return Action::make('send')
            ->label('Send it to everybody')
            ->icon('heroicon-o-bell-alert')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Send this to the whole departure?')
            ->modalDescription(fn (): string => 'This reaches everybody on the departure and every family link they have given out, and it cannot be unsent. '.self::channelSentence())
            ->visible(fn (EmergencyBroadcast $record): bool => ! $record->isSent()
                && auth()->user()?->can('broadcast.send') === true)
            ->action(function (EmergencyBroadcast $record): void {
                $sent = app(Broadcaster::class)->send($record);

                Notification::make()
                    ->success()
                    ->title('Sent')
                    ->body('Reached '.$sent->reached().' booking(s). Open "Who it reached" for the detail.')
                    ->send();
            });
    }

    /**
     * Who it reached, and who it did not.
     *
     * The answer to the question that gets asked after an incident, which
     * is the reason every attempt is written down.
     */
    private static function deliveriesAction(): Action
    {
        return Action::make('deliveries')
            ->label('Who it reached')
            ->icon('heroicon-o-list-bullet')
            ->color('gray')
            ->visible(fn (EmergencyBroadcast $record): bool => $record->isSent())
            ->modalHeading(fn (EmergencyBroadcast $record): string => $record->headline)
            ->modalContent(fn (EmergencyBroadcast $record) => view('filament.broadcast-deliveries', [
                'broadcast' => $record,
                'deliveries' => $record->deliveries()->with('booking.customer')->get()->groupBy('channel'),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private static function channelSentence(): string
    {
        $working = [];
        $missing = [];

        foreach (app(Broadcaster::class)->readiness() as $channel) {
            if ($channel['available']) {
                $working[] = $channel['channel'];
            } else {
                $missing[] = $channel['channel'];
            }
        }

        $sentence = $working === []
            ? 'No channel is working, so this will reach nobody.'
            : 'Working now: '.implode(', ', $working).'.';

        if ($missing !== []) {
            $sentence .= ' Not set up: '.implode(', ', $missing).' — those people will need a phone call.';
        }

        return $sentence;
    }
}
