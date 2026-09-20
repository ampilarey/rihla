<?php

namespace App\Filament\Resources\Visas;

use App\Filament\Resources\Visas\Pages\ListVisaApplications;
use App\Filament\Resources\Visas\Tables\VisaApplicationsTable;
use App\Models\VisaApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Visa applications — §5.4a.
 *
 * A separate screen from Nusuk permits on purpose [R-4]. They are different
 * authorisations from different systems with different failure modes, and
 * showing them as one list would invite exactly the mental shortcut that
 * strands a pilgrim: "visa issued, so they can go".
 *
 * List only. An application is opened from a booking, moved through its
 * stages by actions that record who and why, and never edited as a form —
 * a status dropdown would bypass the state machine and the evidence trail
 * together.
 */
class VisaApplicationResource extends Resource
{
    protected static ?string $model = VisaApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Visas';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return VisaApplicationsTable::configure($table);
    }

    /**
     * Applications nobody has moved inside their service level.
     *
     * The badge counts stalled work rather than open work: "sixty open" is
     * a fact nobody can act on, while "three overdue" is a morning's job.
     */
    public static function getNavigationBadge(): ?string
    {
        $stalled = VisaApplication::open()->get()
            ->filter(fn (VisaApplication $application): bool => $application->isStalled())
            ->count();

        return $stalled > 0 ? (string) $stalled : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return ['index' => ListVisaApplications::route('/')];
    }
}
