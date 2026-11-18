<?php

namespace App\Filament\Widgets;

use App\Services\Finance\Books;
use App\Support\Brand;
use App\Support\Money;
use App\Support\Rates;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use InvalidArgumentException;

/**
 * Money in against money out, month by month, from the books (companies
 * plan, Phase 3g). In the base currency where a rate is known; a currency
 * with none is left out and the chart says so, rather than inventing a
 * figure (the books' own rule).
 */
class MoneyChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Money in and out';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return Filament::auth()->user()?->can('books.view') === true;
    }

    public function getDescription(): ?string
    {
        $left = $this->unconvertible();

        return 'Last six months, in '.Rates::base().($left === [] ? '' : ' · without '.implode(', ', $left).': no rate known');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = array_reverse(app(Books::class)->monthly(6));

        return [
            'datasets' => [
                ['label' => 'In', 'data' => array_map(fn (array $m): float => self::inBase($m['in']) / 100, $months), 'backgroundColor' => Brand::WINE, 'borderRadius' => 4],
                ['label' => 'Out', 'data' => array_map(fn (array $m): float => self::inBase($m['out'], $m['refunds']) / 100, $months), 'backgroundColor' => Brand::GOLD, 'borderRadius' => 4],
            ],
            'labels' => array_column($months, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['maxTicksLimit' => 5]]],
        ];
    }

    /**
     * @param  array<string, int>  ...$buckets
     */
    private static function inBase(array ...$buckets): int
    {
        $total = 0;

        foreach ($buckets as $bucket) {
            foreach ($bucket as $currency => $minor) {
                try {
                    $total += Rates::toBase(Money::ofMinor($minor, $currency))->minor;
                } catch (InvalidArgumentException) {
                    // Named in the description instead.
                }
            }
        }

        return $total;
    }

    /** @return list<string> */
    private function unconvertible(): array
    {
        $left = [];

        foreach (app(Books::class)->monthly(6) as $month) {
            foreach ([...array_keys($month['in']), ...array_keys($month['out']), ...array_keys($month['refunds'])] as $currency) {
                try {
                    Rates::toBase(Money::ofMinor(1, $currency));
                } catch (InvalidArgumentException) {
                    $left[$currency] = $currency;
                }
            }
        }

        return array_values($left);
    }
}
