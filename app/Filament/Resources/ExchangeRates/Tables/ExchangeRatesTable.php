<?php

namespace App\Filament\Resources\ExchangeRates\Tables;

use App\Models\ExchangeRate;
use App\Support\Rates;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExchangeRatesTable
{
    public static function configure(Table $table): Table
    {
        $foreign = array_values(array_filter(Rates::currencies(), fn (string $currency): bool => $currency !== Rates::base()));

        return $table
            ->columns([
                TextColumn::make('currency')->badge()->color('gray'),
                TextColumn::make('rate')
                    ->label('1 unit = '.Rates::base())
                    ->state(fn (ExchangeRate $record): string => number_format($record->rate, 4))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('effective_on')->label('From')->date('j M Y')->sortable(),
                TextColumn::make('setter.name')->label('Set by')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('note')->placeholder('—')->wrap()->visibleFrom('md'),
            ])
            ->defaultSort('effective_on', 'desc')
            ->filters([
                SelectFilter::make('currency')->options(array_combine($foreign, $foreign)),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No rates yet')
            ->emptyStateDescription('Until a rate is set, a payment in another currency has to carry its own, and the site shows no equivalents.');
    }
}
