<?php

namespace App\Filament\Resources\Checklists\Tables;

use App\Filament\Support\DepartureOptions;
use App\Models\DepartureChecklistItem;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DepartureChecklistItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['departure.package', 'doneBy']))
            ->columns([
                IconColumn::make('done')
                    ->label('')
                    ->boolean()
                    ->state(fn (DepartureChecklistItem $record): bool => $record->isDone()),

                TextColumn::make('title')
                    ->label('What')
                    ->wrap()
                    ->searchable()
                    ->description(fn (DepartureChecklistItem $record): ?string => $record->isDone()
                        ? 'Done by '.($record->doneBy->name ?? 'somebody').' on '.$record->done_at?->format('j M')
                        : null),

                TextColumn::make('due_on')
                    ->label('Due')
                    ->date('j M Y')
                    ->placeholder('No date')
                    ->sortable()
                    ->color(fn (DepartureChecklistItem $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                TextColumn::make('is_blocking')
                    ->label('')
                    ->badge()
                    ->state(fn (DepartureChecklistItem $record): ?string => $record->is_blocking ? 'Blocks departure' : null)
                    ->color('danger'),

                TextColumn::make('departure_label')
                    ->label('Departure')
                    ->state(fn (DepartureChecklistItem $record): string => DepartureOptions::label($record->departure))
                    ->wrap(),
            ])
            // Open first, then by date: the list is read for what is left.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('done_at is not null')
                ->orderByRaw('due_on is null')
                ->orderBy('due_on')
                ->orderBy('sort_order'))
            ->filters([
                SelectFilter::make('departure_id')->label('Departure')->searchable()
                    ->options(fn (): array => DepartureOptions::all()),
                TernaryFilter::make('done')
                    ->label('Done')
                    ->placeholder('All')
                    ->trueLabel('Done')
                    ->falseLabel('Still to do')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('done_at'),
                        false: fn (Builder $query) => $query->whereNull('done_at'),
                    )
                    ->default(false),
            ])
            ->recordActions([
                Action::make('tick')
                    ->label('Done')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (DepartureChecklistItem $record): bool => ! $record->isDone())
                    ->authorize('tick')
                    ->action(fn (DepartureChecklistItem $record) => $record->markDone(auth()->user())),

                Action::make('untick')
                    ->label('Not done')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (DepartureChecklistItem $record): bool => $record->isDone())
                    ->authorize('tick')
                    ->action(fn (DepartureChecklistItem $record) => $record->markNotDone()),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Nothing on the list')
            ->emptyStateDescription('Add what has to happen before a departure leaves, or copy the list from an earlier departure. Nothing is pre-filled: the list is the office\'s.');
    }
}
