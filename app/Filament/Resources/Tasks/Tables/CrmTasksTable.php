<?php

namespace App\Filament\Resources\Tasks\Tables;

use App\Models\CrmTask;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CrmTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')
                    ->searchable()
                    ->wrap()
                    ->description(fn (CrmTask $record): string => $record->aboutLabel()),

                TextColumn::make('due_on')
                    ->label('When')
                    ->sortable()
                    // In words, not a date to subtract. "Overdue — 3 Sep"
                    // is a decision; "2026-09-03" is arithmetic.
                    ->state(fn (CrmTask $record): string => $record->whenLabel())
                    ->color(fn (CrmTask $record): string => match (true) {
                        $record->isDone() => 'gray',
                        $record->isOverdue() => 'danger',
                        $record->isDueToday() => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('owner.name')
                    ->label('Whose job')
                    // A value, not a placeholder: an unowned task is the
                    // cell that has to be noticed, and a placeholder would
                    // ignore the colour.
                    ->state(fn (CrmTask $record): string => $record->owner->name ?? 'Nobody')
                    ->color(fn (CrmTask $record): string => $record->owner === null ? 'danger' : 'gray'),
            ])
            ->defaultSort('due_on')
            ->filters([
                Filter::make('open')->label('Still to do')->query(self::onlyOpen(...))->default(),
                Filter::make('overdue')->label('Overdue')->query(self::onlyOverdue(...)),
                Filter::make('mine')->label('Mine')->query(self::onlyMine(...)),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label('Done')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (CrmTask $record): bool => ! $record->isDone()
                        && auth()->user()?->can('task.update') === true)
                    ->action(function (CrmTask $record): void {
                        $record->complete();

                        Notification::make()->success()->title('Done')->send();
                    }),

                Action::make('reopen')
                    ->label('Not done after all')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (CrmTask $record): bool => $record->isDone()
                        && auth()->user()?->can('task.update') === true)
                    ->action(function (CrmTask $record): void {
                        $record->reopen();

                        Notification::make()->warning()->title('Back on the list')->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Nothing to follow up')
            ->emptyStateDescription('A follow-up is "ring them Tuesday" and "chase the deposit on the 14th" — the second of which a single next-action field forces you to forget.');
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @return Builder<CrmTask>
     */
    private static function onlyOpen(Builder $query): Builder
    {
        return $query->open();
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @return Builder<CrmTask>
     */
    private static function onlyOverdue(Builder $query): Builder
    {
        return $query->overdue();
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @return Builder<CrmTask>
     */
    private static function onlyMine(Builder $query): Builder
    {
        return $query->where('owner_id', auth()->id());
    }
}
