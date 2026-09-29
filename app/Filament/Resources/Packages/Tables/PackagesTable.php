<?php

namespace App\Filament\Resources\Packages\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Package')
                    ->searchable(query: fn ($query, string $search) => $query->where('title', 'like', "%{$search}%"))
                    ->sortable()
                    ->wrap(),

                // §16 Phase 16: a package a host wrote says whose it is.
                TextColumn::make('partner.name')
                    ->label('Written by')
                    ->placeholder('Rihla')
                    ->toggleable(),

                TextColumn::make('departures_count')
                    ->label('Departures')
                    ->counts('departures')
                    ->alignCenter()
                    ->badge(),

                TextColumn::make('nights')
                    ->alignCenter()
                    ->placeholder('—'),

                IconColumn::make('is_published')
                    ->label('Live')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                TernaryFilter::make('is_published')->label('Published'),
                Filter::make('from_hosts_waiting')
                    ->label('From hosts, waiting for pricing')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('partner_id')
                        ->whereNotNull('submitted_at')
                        ->where('is_published', false)),
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
            ->emptyStateHeading('No packages yet')
            ->emptyStateDescription('A package is what Rihla sells. Each time it runs is a departure.');
    }
}
