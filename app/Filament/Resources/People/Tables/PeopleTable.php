<?php

namespace App\Filament\Resources\People\Tables;

use App\Models\Person;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PeopleTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('role')
                    ->badge()
                    ->state(fn (Person $record): string => $record->role_label),
                TextColumn::make('groups_led')->label('Groups led')->alignCenter()->placeholder('—'),
                IconColumn::make('is_published')->label('Live')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('role')->options([
                    Person::ROLE_TOUR_LEADER => 'Group leader',
                    Person::ROLE_SCHOLAR => 'Scholar',
                ]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Nobody here yet')
            ->emptyStateDescription('Pilgrims choose people, not packages.');
    }
}
