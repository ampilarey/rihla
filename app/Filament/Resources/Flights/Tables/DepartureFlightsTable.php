<?php

namespace App\Filament\Resources\Flights\Tables;

use App\Filament\Support\DepartureOptions;
use App\Models\DepartureFlight;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DepartureFlightsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('departure.package'))
            ->columns([
                TextColumn::make('departs_at')->label('Departs')->dateTime('j M Y, H:i', 'UTC')->sortable(),

                TextColumn::make('flight')
                    ->label('Flight')
                    ->state(fn (DepartureFlight $record): string => $record->label())
                    ->description(fn (DepartureFlight $record): string => $record->airline),

                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => DepartureFlight::directionLabel($state)),

                TextColumn::make('departure_label')
                    ->label('Departure')
                    ->state(fn (DepartureFlight $record): string => DepartureOptions::label($record->departure))
                    ->wrap(),

                TextColumn::make('seats')->placeholder('—'),
            ])
            ->defaultSort('departs_at')
            ->filters([
                SelectFilter::make('departure_id')->label('Departure')->searchable()
                    ->options(fn (): array => DepartureOptions::all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No flights recorded')
            ->emptyStateDescription('Each leg a departure flies, with local times. The portal shows pilgrims their times; the departure board checks there are enough seats.');
    }
}
