<?php

namespace App\Filament\Resources\Announcements;

use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Resources\Announcements\Tables\AnnouncementsTable;
use App\Models\Announcement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Announcements — §6.2's group-level news.
 *
 * Read by the pilgrim and by whoever they have given a family link to, so
 * everything here goes in front of households at home. That is why
 * publishing is a separate permission from writing, and why the badge
 * counts drafts: an announcement nobody published is one somebody meant to
 * send and forgot.
 */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $modelLabel = 'announcement';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    /**
     * Drafts on trips that have not come home.
     *
     * Not the count of announcements. An announcement somebody wrote and
     * never published is the one failure worth a number on a navigation
     * item: the family is waiting for news that is sitting in a box.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Announcement::query()
            ->whereNull('published_at')
            ->whereHas('departure', fn ($query) => $query->where('date_end', '>=', now()->subDays(7)))
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return ['index' => ListAnnouncements::route('/')];
    }
}
