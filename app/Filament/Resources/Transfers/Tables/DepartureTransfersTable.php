<?php

namespace App\Filament\Resources\Transfers\Tables;

use App\Filament\Support\DepartureOptions;
use App\Models\DepartureTransfer;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DepartureTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('departure.package'))
            ->columns([
                TextColumn::make('starts_at')->label('Leaves')->dateTime('j M Y, H:i', 'UTC')->sortable(),

                TextColumn::make('route')
                    ->label('From → to')
                    ->state(fn (DepartureTransfer $record): string => $record->from_place.' → '.$record->to_place)
                    ->description(fn (DepartureTransfer $record): string => DepartureTransfer::modeLabel($record->mode))
                    ->wrap(),

                TextColumn::make('departure_label')
                    ->label('Departure')
                    ->state(fn (DepartureTransfer $record): string => DepartureOptions::label($record->departure))
                    ->wrap(),

                TextColumn::make('provider')->label('Company')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('starts_at')
            ->filters([
                SelectFilter::make('departure_id')->label('Departure')->searchable()
                    ->options(fn (): array => DepartureOptions::all()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No ground transport recorded')
            ->emptyStateDescription('Airport transfers, coaches between Makkah and Madinah, the train. The meeting point is shown to pilgrims in their portal.');
    }
}
