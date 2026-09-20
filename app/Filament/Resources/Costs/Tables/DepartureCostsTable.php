<?php

namespace App\Filament\Resources\Costs\Tables;

use App\Models\DepartureCost;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DepartureCostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('departure.date_start')
                    ->label('Departure')
                    ->date('j M Y')
                    ->sortable()
                    ->description(fn (DepartureCost $record): string => $record->departure->package->title ?? '—'),

                TextColumn::make('category')
                    ->label('What for')
                    ->badge()
                    ->state(fn (DepartureCost $record): string => $record->categoryLabel())
                    ->description(fn (DepartureCost $record): string => $record->supplier ?: 'no supplier named'),

                TextColumn::make('amount_minor')
                    ->label('Unit price')
                    ->alignEnd()
                    // Formatted once, through Money. The row shows the unit
                    // price and says what it is multiplied by, rather than
                    // a total that would be wrong the moment somebody joins
                    // or leaves the departure.
                    ->state(fn (DepartureCost $record): string => (string) $record->unit())
                    ->description(fn (DepartureCost $record): string => $record->basisLabel()),

                TextColumn::make('status')
                    ->label('How firm')
                    ->badge()
                    ->state(fn (DepartureCost $record): string => $record->statusLabel())
                    ->color(fn (DepartureCost $record): string => match ($record->status) {
                        DepartureCost::PAID => 'success',
                        DepartureCost::COMMITTED => 'info',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('How firm')->options(
                    fn (): array => collect(DepartureCost::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [
                            $s => (new DepartureCost(['status' => $s]))->statusLabel(),
                        ])
                        ->all(),
                ),

                SelectFilter::make('category')->label('What for')->options(
                    fn (): array => collect(DepartureCost::CATEGORIES)
                        ->mapWithKeys(fn (string $c): array => [
                            $c => (new DepartureCost(['category' => $c]))->categoryLabel(),
                        ])
                        ->all(),
                ),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No costs recorded')
            ->emptyStateDescription('Until something is here, the profit report has half the arithmetic and says so rather than showing the revenue as margin.');
    }
}
