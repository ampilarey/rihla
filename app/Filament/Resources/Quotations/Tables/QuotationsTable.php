<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\Quotation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),

                TextColumn::make('enquiry.name')
                    ->label('Who for')
                    ->searchable()
                    ->description(fn (Quotation $record): string => $record->package->title
                        ?? 'No package named'),

                TextColumn::make('total_minor')
                    ->label('Total')
                    ->alignEnd()
                    // Formatted once, through Money. Nothing here divides
                    // by 100 itself.
                    ->state(fn (Quotation $record): string => (string) $record->total())
                    ->description(fn (Quotation $record): string => $record->perPerson().' each'),

                TextColumn::make('status')
                    ->label('Where it is')
                    ->badge()
                    // `statusLabel()` folds the expiry in, so a quotation
                    // whose date has passed says so rather than still
                    // reading "with the customer".
                    ->state(fn (Quotation $record): string => $record->statusLabel())
                    ->color(fn (Quotation $record): string => match (true) {
                        $record->isExpired() => 'danger',
                        $record->status === Quotation::ACCEPTED => 'success',
                        $record->status === Quotation::SENT => 'warning',
                        $record->status === Quotation::DECLINED => 'gray',
                        $record->status === Quotation::SUPERSEDED => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('valid_until')
                    ->label('Good until')
                    ->date('j M Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('open')
                    ->label('Still waiting on an answer')
                    ->query(self::onlyOpen(...)),

                SelectFilter::make('status')->label('Where it is')->options(
                    fn (): array => collect(Quotation::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [
                            $s => (new Quotation(['status' => $s]))->statusLabel(),
                        ])
                        ->all(),
                ),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::sendAction(),
                    self::acceptAction(),
                    self::declineAction(),
                    EditAction::make()
                        // Only a draft. A sent quotation is superseded, so
                        // the record keeps saying what the customer was
                        // told — which is the only reason to keep one.
                        ->visible(fn (Quotation $record): bool => $record->status === Quotation::DRAFT),
                ]),
            ])
            ->emptyStateHeading('No quotations yet')
            ->emptyStateDescription('A quotation is the answer to "what did we quote them?" a fortnight later. Today that lives in a WhatsApp message and nowhere else.');
    }

    /**
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    private static function onlyOpen(Builder $query): Builder
    {
        return $query->awaitingAnAnswer();
    }

    private static function sendAction(): Action
    {
        return Action::make('send')
            ->label('Mark it sent')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalDescription('This records that the customer has been given this price. After this it can only be replaced, not edited.')
            ->visible(fn (Quotation $record): bool => $record->status === Quotation::DRAFT
                && auth()->user()?->can('quotation.send') === true)
            ->action(function (Quotation $record): void {
                try {
                    $record->markSent();
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('No')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Recorded as sent')->send();
            });
    }

    private static function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('They said yes')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            // No booking required at this moment: somebody says yes on the
            // phone before anybody has taken a seat, and refusing to record
            // that until the booking exists means one person remembers it.
            ->modalDescription('Record the acceptance now; link the booking when it is made.')
            ->visible(fn (Quotation $record): bool => $record->isOpen()
                && auth()->user()?->can('quotation.send') === true)
            ->action(function (Quotation $record): void {
                try {
                    $record->accept();
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('No')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Accepted')->send();
            });
    }

    private static function declineAction(): Action
    {
        return Action::make('decline')
            ->label('They said no')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (Quotation $record): bool => $record->isOpen()
                && auth()->user()?->can('quotation.send') === true)
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(3)
                    ->helperText('"Too expensive" and "dates did not work" lead to different next offers. A blank tells the next person nothing.'),
            ])
            ->action(function (Quotation $record, array $data): void {
                try {
                    $record->decline($data['reason']);
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('No')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Recorded')->send();
            });
    }
}
