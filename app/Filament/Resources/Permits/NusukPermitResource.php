<?php

namespace App\Filament\Resources\Permits;

use App\Filament\Resources\Permits\Pages\ListNusukPermits;
use App\Filament\Resources\Permits\Tables\NusukPermitsTable;
use App\Models\NusukPermit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Nusuk permits — §5.4b.
 *
 * A separate screen from visas on purpose [R-4]. They are different
 * authorisations from different systems with different failure modes, and
 * one combined list would invite the shortcut that strands a pilgrim:
 * "visa issued, so they can go". A traveller holding a valid visa is still
 * barred from the Mataf without an Umrah permit.
 *
 * List only, like visas. A permit is opened from a booking and moved through
 * its stages by actions that record who, why and what they saw — never by
 * editing a status field, which would bypass both the state machine and the
 * prerequisite gate.
 */
class NusukPermitResource extends Resource
{
    protected static ?string $model = NusukPermit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Nusuk permits';

    protected static ?string $modelLabel = 'Nusuk permit';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return NusukPermitsTable::configure($table);
    }

    /**
     * Permits nobody has moved inside their service level.
     *
     * Stalled work rather than open work, for the same reason the visa badge
     * counts stalled: "sixty open" is a fact nobody can act on, "three
     * overdue" is a morning's job.
     */
    public static function getNavigationBadge(): ?string
    {
        $stalled = NusukPermit::open()->get()
            ->filter(fn (NusukPermit $permit): bool => $permit->isStalled())
            ->count();

        return $stalled > 0 ? (string) $stalled : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return ['index' => ListNusukPermits::route('/')];
    }
}
