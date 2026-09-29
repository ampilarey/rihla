<?php

namespace App\Filament\Host\Pages;

use App\Filament\Host\Resources\Bookings\BookingResource;
use App\Models\Partner;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Who is in which room, night by night — §16.6.
 *
 * Rooms down the side, the nights of one month across. A stay is a run of
 * coloured cells: filled once it has the room (held, confirmed, in house),
 * outlined while it is only a request. A stay not yet put in a room sits
 * on its kind's own row. Click a stay to open it; click a free night to
 * book that room from that night.
 *
 * A table of plain cells with inline styles and Filament's own colour
 * variables — no JavaScript, no library, and no Tailwind utilities, which
 * do nothing inside a panel (AGENTS.md).
 */
class Calendar extends Page
{
    /** What a night in a room can show. */
    public const SHOWN = [Stay::REQUESTED, Stay::HELD, Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Calendar';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.host.calendar';

    #[Url]
    public string $month = '';

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::BOOKINGS);
    }

    public function mount(): void
    {
        $this->month = $this->start()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->start()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->start()->addMonth()->format('Y-m');
    }

    public function start(): CarbonImmutable
    {
        try {
            return preg_match('/^\d{4}-\d{2}$/', $this->month) === 1
                ? CarbonImmutable::createFromFormat('!Y-m', $this->month)->startOfMonth()
                : CarbonImmutable::today()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::today()->startOfMonth();
        }
    }

    /** @return list<CarbonImmutable> */
    public function nights(): array
    {
        $start = $this->start();

        return array_map(fn (int $d): CarbonImmutable => $start->addDays($d), range(0, $start->daysInMonth - 1));
    }

    /**
     * One row per room, and one per kind of room for stays not yet in one.
     *
     * @return list<array{label: string, detail: string, unit: ?int, cells: array<string, list<Stay>>}>
     */
    public function rows(): array
    {
        $host = $this->host();
        $nights = $this->nights();
        $stays = $this->stays($host, $nights[0], end($nights)->addDay());

        $rows = [];

        foreach ($this->roomTypes($host) as $room) {
            foreach ($room->units as $unit) {
                $rows[] = [
                    'label' => $unit->label,
                    'detail' => $room->getTranslation('name', 'en'),
                    'unit' => $unit->getKey(),
                    'cells' => $this->cells($stays->where('unit_id', $unit->getKey()), $nights),
                ];
            }

            $loose = $stays->where('room_type_id', $room->getKey())->whereNull('unit_id');

            if ($loose->isNotEmpty() || $room->units->isEmpty()) {
                $rows[] = [
                    'label' => $room->units->isEmpty() ? $room->getTranslation('name', 'en') : 'Not yet in a room',
                    'detail' => $room->units->isEmpty()
                        ? $room->property->getTranslation('name', 'en').' · '.$room->quantity.' of this kind'
                        : $room->getTranslation('name', 'en'),
                    'unit' => null,
                    'cells' => $this->cells($loose, $nights),
                ];
            }
        }

        return $rows;
    }

    /**
     * A row's nights as runs: one cell per stay, spanning its nights, so a
     * guest's name has the width of their whole stay; free nights one each.
     *
     * @param  array<string, list<Stay>>  $cells
     * @return list<array{night: string, span: int, stays: list<Stay>}>
     */
    public static function runs(array $cells): array
    {
        $runs = [];

        foreach ($cells as $night => $stays) {
            $last = $runs === [] ? null : $runs[array_key_last($runs)];
            $key = array_map(fn (Stay $stay): int => (int) $stay->getKey(), $stays);

            if ($stays !== [] && $last !== null && $last['stays'] !== []
                && array_map(fn (Stay $stay): int => (int) $stay->getKey(), $last['stays']) === $key) {
                $runs[array_key_last($runs)]['span']++;

                continue;
            }

            $runs[] = ['night' => $night, 'span' => 1, 'stays' => $stays];
        }

        return $runs;
    }

    /** Filled once it has the room; outlined while only asked for. */
    public static function style(Stay $stay): string
    {
        return match ($stay->status) {
            Stay::REQUESTED => 'background: transparent; box-shadow: inset 0 0 0 2px var(--warning-500);',
            Stay::HELD => 'background: var(--info-200);',
            Stay::CHECKED_IN => 'background: var(--primary-300);',
            Stay::COMPLETED => 'background: var(--gray-200);',
            default => 'background: var(--success-200);',
        };
    }

    public function stayUrl(Stay $stay): string
    {
        return BookingResource::getUrl('view', ['record' => $stay]);
    }

    public function bookUrl(int $unit, CarbonImmutable $night): string
    {
        return NewBooking::getUrl(['unit' => $unit, 'check_in' => $night->toDateString()]);
    }

    // ── Reading ──────────────────────────────────────────────────────────

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }

    /** @return Collection<int, RoomType> */
    private function roomTypes(Partner $host): Collection
    {
        return RoomType::query()
            ->with([
                'property',
                'units' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('label'),
            ])
            ->whereHas('property', fn ($query) => $query->where('partner_id', $host->getKey()))
            ->orderBy('property_id')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, Stay> */
    private function stays(Partner $host, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return Stay::query()
            ->with('customer')
            ->whereIn('status', self::SHOWN)
            ->whereHas('property', fn ($query) => $query->where('partner_id', $host->getKey()))
            ->overlapping($from, $until)
            ->orderBy('check_in')
            ->get();
    }

    /**
     * @param  Collection<int, Stay>  $stays
     * @param  list<CarbonImmutable>  $nights
     * @return array<string, list<Stay>>
     */
    private function cells(Collection $stays, array $nights): array
    {
        $cells = [];

        foreach ($nights as $night) {
            $cells[$night->toDateString()] = $stays
                ->filter(fn (Stay $stay): bool => $stay->check_in->lte($night) && $stay->check_out->gt($night))
                ->values()
                ->all();
        }

        return $cells;
    }
}
