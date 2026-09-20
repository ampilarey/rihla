<?php

namespace App\Filament\Resources\RollCalls;

use App\Filament\Resources\RollCalls\Pages\ListRollCalls;
use App\Filament\Resources\RollCalls\Schemas\RollCallForm;
use App\Filament\Resources\RollCalls\Tables\RollCallsTable;
use App\Models\RollCall;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Attendance — §8.3.
 *
 * Not a daily register. A count is taken at the moments where somebody can
 * actually be left behind, so there are three in a day or none.
 *
 * The screen exists to answer one question: **who is not accounted for?**
 * See {@see RollCall} for why an unmarked traveller is not "present".
 */
class RollCallResource extends Resource
{
    protected static ?string $model = RollCall::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Attendance';

    protected static ?string $modelLabel = 'head count';

    protected static ?string $pluralModelLabel = 'head counts';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return RollCallForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RollCallsTable::configure($table);
    }

    /**
     * Counts on a trip that is under way with somebody unaccounted for.
     *
     * Only current and upcoming departures: an unfinished count on a trip
     * that came home last March is a tidying job, not somebody standing in
     * a car park.
     */
    public static function getNavigationBadge(): ?string
    {
        $unsettled = RollCall::query()
            ->whereHas('departure', fn ($query) => $query->where('date_end', '>=', now()->startOfDay()))
            ->with(['marks.traveller', 'departure'])
            ->get()
            ->filter(fn (RollCall $rollCall): bool => ! $rollCall->isSettled())
            ->count();

        return $unsettled > 0 ? (string) $unsettled : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * The breadcrumb root, which otherwise comes from the plural model
     * label and read "Head Counts" while the navigation said "Attendance".
     * The model labels stay as they are — "Start a head count" is the right
     * sentence on the button.
     */
    public static function getBreadcrumb(): string
    {
        return 'Attendance';
    }

    public static function getPages(): array
    {
        return ['index' => ListRollCalls::route('/')];
    }
}
