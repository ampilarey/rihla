<?php

namespace App\Filament\Resources\Notices\Tables;

use App\Models\Notice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NoticesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.customer.name')
                    ->label('Who')
                    ->searchable()
                    ->description(fn (Notice $record): string => (string) $record->booking->reference),

                TextColumn::make('headline')
                    ->label('What they need to know')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Notice $record): string => $record->kindLabel()),

                TextColumn::make('booking.departure.date_start')
                    ->label('Travels')
                    ->date('j M Y')
                    ->sortable(),

                TextColumn::make('seen_at')
                    ->label('Opened it?')
                    ->badge()
                    // `->state()`, not `->formatStateUsing()`: "Not yet" is
                    // the null case, and a null state renders an empty cell.
                    ->state(fn (Notice $record): string => $record->seen_at === null
                        ? 'Not yet'
                        : $record->seen_at->diffForHumans())
                    ->color(fn (Notice $record): string => $record->seen_at === null ? 'warning' : 'gray'),

                TextColumn::make('handled_at')
                    ->label('Chased?')
                    ->badge()
                    ->state(fn (Notice $record): string => $record->isHandled() ? 'Done' : 'Outstanding')
                    ->color(fn (Notice $record): string => $record->isHandled() ? 'success' : 'danger'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('needs_chasing')
                    ->label('Owes us something, not yet chased')
                    ->default()
                    ->query(self::onlyNeedsChasing(...)),

                SelectFilter::make('kind')->label('What kind')->options(
                    fn (): array => collect(Notice::KINDS)
                        ->mapWithKeys(fn (string $k): array => [$k => (new Notice(['kind' => $k]))->kindLabel()])
                        ->all(),
                ),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::whatsappAction(),
                    self::handledAction(),
                ]),
            ])
            ->emptyStateHeading('Nobody is waiting on anything')
            ->emptyStateDescription('This list is raised by `notices:sweep` from records that already exist — a missing passport, a departure coming up. Nothing here is typed by hand.');
    }

    /**
     * @param  Builder<Notice>  $query
     * @return Builder<Notice>
     */
    private static function onlyNeedsChasing(Builder $query): Builder
    {
        return $query->needsChasing();
    }

    /**
     * Open the conversation, with the message already written.
     *
     * The bridge while there is no messaging API, and the same pattern the
     * waitlist claim link already uses. Hidden rather than broken when
     * there is no number: a button that opens nothing is worse than no
     * button.
     */
    private static function whatsappAction(): Action
    {
        return Action::make('whatsapp')
            ->label('Message them on WhatsApp')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('success')
            ->visible(fn (Notice $record): bool => $record->whatsappUrl() !== null)
            ->url(fn (Notice $record): string => (string) $record->whatsappUrl())
            ->openUrlInNewTab();
    }

    private static function handledAction(): Action
    {
        return Action::make('handled')
            ->label('I have chased this')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (Notice $record): bool => ! $record->isHandled()
                && auth()->user()?->can('notice.handle') === true)
            ->action(function (Notice $record): void {
                $record->markHandled();

                Notification::make()->success()->title('Marked as chased')->send();
            });
    }
}
