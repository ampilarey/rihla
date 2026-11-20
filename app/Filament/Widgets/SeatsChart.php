<?php

namespace App\Filament\Widgets;

use App\Support\Brand;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Modules\Umrah\Models\Departure;

/** How full each open departure is: confirmed, held while the money comes in, and free (companies plan, Phase 3g). */
class SeatsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Seats by departure';

    protected ?string $description = 'The next six open departures';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && ($user->can('departure.viewAny') || $user->can('departure.board'));
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $departures = Departure::query()->open()->aheadOrUndated()->with('package')
            ->orderByRaw('date_start is null')->orderBy('date_start')->limit(6)->get();

        return [
            'datasets' => [
                ['label' => 'Confirmed', 'data' => $departures->map(fn (Departure $d): int => $d->capacity_confirmed)->all(), 'backgroundColor' => Brand::WINE],
                ['label' => 'Held', 'data' => $departures->map(fn (Departure $d): int => $d->capacity_held)->all(), 'backgroundColor' => Brand::GOLD],
                ['label' => 'Free', 'data' => $departures->map(fn (Departure $d): int => $d->seats_remaining)->all(), 'backgroundColor' => Brand::BORDER],
            ],
            'labels' => $departures->map(fn (Departure $d): string => mb_strimwidth((string) ($d->package->title ?? 'Departure'), 0, 18, '…').' · '.$d->startLabel('j M'))->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['stacked' => true, 'beginAtZero' => true], 'y' => ['stacked' => true]],
        ];
    }
}
