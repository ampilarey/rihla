<?php

namespace App\Filament\Resources\ExchangeRates\Pages;

use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Support\Rates;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExchangeRates extends ListRecords
{
    protected static string $resource = ExchangeRateResource::class;

    protected static ?string $title = 'Exchange rates';

    public function getSubheading(): ?string
    {
        $parts = [];

        foreach (Rates::currencies() as $currency) {
            if ($currency === Rates::base()) {
                continue;
            }

            $rate = Rates::rate($currency);
            $parts[] = $rate === null
                ? 'No rate for '.$currency.' — a payment in it has to carry its own'
                : 'Today 1 '.$currency.' = '.number_format($rate, 4).' '.Rates::base().(Rates::current($currency) === null ? ' (from config)' : '');
        }

        return implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Set a rate')];
    }
}
