<?php

namespace App\Filament\Resources\Properties\Tables;

use App\Models\Property;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PropertiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Property')
                    ->searchable(query: fn ($query, string $search) => $query->where('name', 'like', "%{$search}%"))
                    ->sortable()
                    ->wrap(),

                TextColumn::make('partner.name')->label('Partner')->searchable()->sortable()->toggleable(),

                TextColumn::make('island')->searchable()->sortable()->placeholder('—'),

                TextColumn::make('room_types_count')
                    ->label('Rooms')
                    ->counts('roomTypes')
                    ->alignCenter()
                    ->badge(),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Property::RENTAL => 'Rental',
                        default => 'Guesthouse',
                    })
                    ->toggleable(),

                IconColumn::make('instant_book')->label('Instant')->boolean()->toggleable(),

                IconColumn::make('is_published')->label('Live')->boolean()->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('type')->options([
                    Property::GUESTHOUSE => 'Guesthouse',
                    Property::RENTAL => 'Rental',
                ]),
                SelectFilter::make('partner')->relationship('partner', 'name')->searchable()->preload(),
                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No properties yet')
            ->emptyStateDescription('A property is a building Rihla sells nights in. Add the partner who owns it first.');
    }
}
