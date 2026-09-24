<?php

namespace App\Filament\Resources\Stays;

use App\Filament\Resources\Stays\Pages\ListStays;
use App\Filament\Resources\Stays\Pages\ViewStay;
use App\Filament\Resources\Stays\Schemas\StayDetails;
use App\Filament\Resources\Stays\Tables\StaysTable;
use App\Models\Stay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Stays board — §15.4 (Phase 9.3).
 *
 * Deliberately no Create and no Delete. A stay is made by a customer asking
 * and is *cancelled* — a status, with a row saying who and why — never
 * removed. That is the same rule bookings follow, and for the same reason:
 * it is a commercial record.
 *
 * The work this screen exists for is one decision, made once per request:
 * **the partner said yes, or the partner said no.** Everything else on it
 * is there to let somebody make that decision without opening anything
 * else.
 */
class StayResource extends Resource
{
    protected static ?string $model = Stay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Stays';

    protected static UnitEnum|string|null $navigationGroup = 'Stays';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'reference';

    /**
     * How many are waiting on somebody at Rihla.
     *
     * Requests only. A held stay is waiting on the customer's money and a
     * confirmed one is waiting on nothing, so counting either would turn
     * the badge into a number nobody can act on — which is a number people
     * stop reading.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Stay::where('status', Stay::REQUESTED)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Stay::where('status', Stay::REQUESTED)->exists() ? 'warning' : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return StayDetails::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStays::route('/'),
            'view' => ViewStay::route('/{record}'),
        ];
    }
}
