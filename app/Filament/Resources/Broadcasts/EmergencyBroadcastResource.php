<?php

namespace App\Filament\Resources\Broadcasts;

use App\Filament\Resources\Broadcasts\Pages\ListEmergencyBroadcasts;
use App\Filament\Resources\Broadcasts\Schemas\EmergencyBroadcastForm;
use App\Filament\Resources\Broadcasts\Tables\EmergencyBroadcastsTable;
use App\Models\EmergencyBroadcast;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Emergency broadcasts — §6.5's "group-wide emergency broadcast".
 *
 * The badge counts **drafts on live trips**, for the same reason the
 * announcements badge does and more urgently: a broadcast somebody wrote
 * during an incident and never sent is the worst row in this table.
 */
class EmergencyBroadcastResource extends Resource
{
    protected static ?string $model = EmergencyBroadcast::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Emergency broadcast';

    protected static ?string $modelLabel = 'broadcast';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return EmergencyBroadcastForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmergencyBroadcastsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $unsent = EmergencyBroadcast::query()
            ->whereNull('sent_at')
            ->whereHas('departure', fn ($query) => $query->where('date_end', '>=', now()->subDays(7)))
            ->count();

        return $unsent > 0 ? (string) $unsent : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return ['index' => ListEmergencyBroadcasts::route('/')];
    }
}
