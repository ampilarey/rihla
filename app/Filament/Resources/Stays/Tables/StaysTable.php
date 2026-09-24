<?php

namespace App\Filament\Resources\Stays\Tables;

use App\Exceptions\RoomNotAvailable;
use App\Models\Stay;
use App\Services\Stays\StayBooking;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StaysTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),

                TextColumn::make('customer.name')->label('Guest')->searchable()->wrap(),

                TextColumn::make('property.name')->label('Property')->wrap()->toggleable(),

                TextColumn::make('roomType.name')->label('Room')->wrap()->toggleable(),

                TextColumn::make('check_in')
                    ->label('Nights')
                    ->formatStateUsing(fn (Stay $record): string => sprintf(
                        '%s → %s (%d)',
                        $record->check_in->format('j M'),
                        $record->check_out->format('j M Y'),
                        $record->nights,
                    ))
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Stay::REQUESTED => 'warning',
                        Stay::HELD => 'info',
                        Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('total_minor')
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state, Stay $record): string => Money::ofMinor((int) $state, $record->currency)->format())
                    ->toggleable(),

                TextColumn::make('paid_minor')
                    ->label('Paid')
                    ->formatStateUsing(fn (?int $state, Stay $record): string => Money::ofMinor((int) $state, $record->currency)->format())
                    ->toggleable(),

                // The clock, in words. "expires_at" as a timestamp makes a
                // member of staff do date arithmetic to find out whether
                // this one needs chasing today.
                TextColumn::make('expires_at')
                    ->label('Deposit due')
                    ->since()
                    ->placeholder('—')
                    ->color(fn (Stay $record): string => $record->status === Stay::HELD && $record->expires_at?->isPast() ? 'danger' : 'gray')
                    ->toggleable(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        Stay::STATUSES,
                        array_map(fn (string $s): string => ucfirst(str_replace('_', ' ', $s)), Stay::STATUSES),
                    ))
                    ->multiple(),

                SelectFilter::make('property')->relationship('property', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                self::confirm(),
                self::decline(),
            ])
            ->emptyStateHeading('No stays yet')
            ->emptyStateDescription('A stay appears here when somebody asks for dates. Ring the partner, then confirm or decline it.');
    }

    /**
     * The partner said yes.
     *
     * The dates are taken here and not before, which is why this can fail:
     * somebody else may have taken them while the phone was ringing. That
     * failure is shown as a notification rather than an exception page —
     * it is an ordinary outcome of a real business, not a fault.
     */
    private static function confirm(): Action
    {
        return Action::make('confirm')
            ->label('Partner confirmed')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Stay $record): bool => $record->status === Stay::REQUESTED)
            ->authorize(fn (): bool => auth()->user()?->can('stay.confirm') ?? false)
            ->requiresConfirmation()
            ->modalHeading('The partner has confirmed these dates')
            ->modalDescription('This takes the room off the calendar and starts the deposit clock. Nobody else can book it while it is held.')
            ->action(function (Stay $record): void {
                try {
                    app(StayBooking::class)->confirmWithPartner($record);
                } catch (RoomNotAvailable $refusal) {
                    Notification::make()
                        ->title('Those dates have gone')
                        ->body($refusal->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Held for '.$record->fresh()->customer->name)
                    ->body('The deposit is due by '.$record->fresh()->expires_at?->format('j M Y, H:i').'.')
                    ->success()
                    ->send();
            });
    }

    /** The partner said no. The reason is shown to the customer, so it is required. */
    private static function decline(): Action
    {
        return Action::make('decline')
            ->label('Partner declined')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Stay $record): bool => $record->status === Stay::REQUESTED)
            ->authorize(fn (): bool => auth()->user()?->can('stay.confirm') ?? false)
            ->schema([
                Textarea::make('reason')
                    ->label('What should the guest be told?')
                    ->rows(3)
                    ->required()
                    ->helperText('They see this. "The guesthouse is full that week" is kinder and more useful than silence.'),
            ])
            ->action(function (Stay $record, array $data): void {
                app(StayBooking::class)->decline($record, $data['reason']);

                Notification::make()
                    ->title('Declined')
                    ->body('The dates stay on the calendar for somebody else.')
                    ->success()
                    ->send();
            });
    }
}
