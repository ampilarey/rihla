<?php

namespace App\Filament\Pages;

use App\Filament\Support\DepartureOptions;
use App\Http\Controllers\OperationsController;
use App\Models\Departure;
use App\Services\Operations\Lists as ListBuilder;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Every list, manifest, card and tag for a departure, one tap each, as PDF
 * or CSV — Hijaz's lists page, brought across.
 *
 * A page rather than a resource: there is no `lists` table, and a print is
 * a request to {@see OperationsController}, which
 * audits it. The money and document lists are shown only to somebody who
 * may read them; the controller refuses them regardless.
 */
class Lists extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-printer';

    protected static ?string $navigationLabel = 'Lists';

    protected static ?string $title = 'Lists';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.lists';

    public ?int $departureId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('list.view') === true;
    }

    public function mount(): void
    {
        $requested = (int) request()->query('departure', 0);

        if ($requested > 0 && Departure::query()->whereKey($requested)->exists()) {
            $this->departureId = $requested;

            return;
        }

        // The next departure to leave: the one whose lists are being printed.
        $next = Departure::query()->where('date_end', '>=', today())->orderBy('date_start')->value('id');

        $this->departureId = $next === null ? null : (int) $next;
    }

    public function getDeparture(): ?Departure
    {
        if ($this->departureId === null) {
            return null;
        }

        return Departure::query()->with(['package', 'hotels', 'busGroups'])->find($this->departureId);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $departure = $this->getDeparture();
        $user = auth()->user();

        return [
            'departure' => $departure,
            'options' => DepartureOptions::all(recentOnly: true),
            'presets' => ListBuilder::PRESETS,
            'buses' => $departure === null ? collect() : $departure->busGroups,
            'hotels' => $departure === null ? collect() : $departure->hotels,
            'travellers' => $departure === null ? 0 : app(ListBuilder::class)->travellers($departure)->count(),
            'canSeeMoney' => $user?->can('payment.viewAny') === true,
            'canSeeDocuments' => $user?->can('document.viewAny') === true,
            'canPrintCards' => $user?->can('card.create') === true,
        ];
    }
}
