<?php

namespace App\Filament\Resources\OperationsLog\Tables;

use App\Models\Departure;
use App\Models\OperationsLogEntry;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OperationsLogEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('happened_on')
                    ->label('Day')
                    ->date('j M Y')
                    ->sortable(),

                TextColumn::make('body')
                    ->label('What happened')
                    ->wrap()
                    ->searchable()
                    ->limit(240),

                TextColumn::make('departure.date_start')
                    ->label('Departure')
                    ->date('j M Y')
                    ->sortable(),

                TextColumn::make('recorder.name')
                    ->label('Written by')
                    ->state(fn (OperationsLogEntry $record): string => $record->recorder->name ?? 'Unknown')
                    ->toggleable(),
            ])
            // Most recent day first: the log is read from the end.
            ->defaultSort('happened_on', 'desc')
            ->filters([
                SelectFilter::make('departure_id')
                    ->label('Departure')
                    ->searchable()
                    ->options(fn (): array => Departure::query()
                        ->with('package')
                        ->orderByDesc('date_start')
                        ->get()
                        ->mapWithKeys(fn (Departure $departure): array => [
                            $departure->getKey() => sprintf(
                                '%s (%s)',
                                $departure->package->title ?? 'Departure',
                                $departure->date_start->format('j M Y'),
                            ),
                        ])
                        ->all()),
            ])
            ->recordActions([ActionGroup::make([EditAction::make()])])
            ->emptyStateHeading('Nothing written up yet')
            ->emptyStateDescription('The ordinary account of a day on the trip. Anything that went wrong belongs in Incidents, where it gets a severity and an owner.');
    }
}
