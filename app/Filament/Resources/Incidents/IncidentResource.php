<?php

namespace App\Filament\Resources\Incidents;

use App\Filament\Resources\Incidents\Pages\ListIncidents;
use App\Filament\Resources\Incidents\Schemas\IncidentForm;
use App\Filament\Resources\Incidents\Tables\IncidentsTable;
use App\Models\Incident;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Incidents on the ground — §8.3, and the minimum §6.5 asks for.
 *
 * The screen is built around one question: **what is open that nobody is
 * on?** An incident list sorted by date is a diary; this one finds the
 * emergency with no name against it and puts a number on the navigation.
 */
class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Incidents';

    protected static ?string $modelLabel = 'incident';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return IncidentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IncidentsTable::configure($table);
    }

    /**
     * Open emergencies with nobody on them.
     *
     * Not "how many are open". "Twelve open" is a fact nobody can act on;
     * "one emergency with nobody on it" is somebody's next five minutes.
     */
    public static function getNavigationBadge(): ?string
    {
        $unattended = Incident::unattended()->count();

        return $unattended > 0 ? (string) $unattended : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return ['index' => ListIncidents::route('/')];
    }
}
