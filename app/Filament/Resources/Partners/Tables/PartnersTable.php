<?php

namespace App\Filament\Resources\Partners\Tables;

use App\Models\Partner;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap(),

                TextColumn::make('island')->searchable()->sortable()->placeholder('—'),

                TextColumn::make('properties_count')
                    ->label('Properties')
                    ->counts('properties')
                    ->alignCenter()
                    ->badge(),

                TextColumn::make('pricing_model')
                    ->label('Paid by')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Partner::COMMISSION => 'Commission',
                        default => 'Net rate',
                    }),

                TextColumn::make('whatsapp')->label('WhatsApp')->placeholder('—')->toggleable(),

                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label('Working with us'),
            ])
            // No delete action, and no bulk delete. A partner with
            // properties cannot be removed — the foreign key restricts it —
            // and offering a button that throws a database error is worse
            // than not offering one. `is_active` is how a partner leaves.
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('No partners yet')
            ->emptyStateDescription('A partner is the guesthouse owner Rihla markets for. Their properties hang off this record.');
    }
}
