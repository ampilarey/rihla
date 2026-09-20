<?php

namespace App\Filament\Resources\Enquiries;

use App\Filament\Resources\Enquiries\Pages\ListEnquiries;
use App\Filament\Resources\Enquiries\Tables\EnquiriesTable;
use App\Models\Enquiry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Enquiries — §8.1's minimal CRM.
 *
 * "Every enquiry becomes a tracked lead with an owner and a next action —
 * that alone beats a shared inbox." So the screen is built around finding
 * the ones that have neither, rather than around a pipeline.
 */
class EnquiryResource extends Resource
{
    protected static ?string $model = Enquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Enquiries';

    protected static ?string $pluralModelLabel = 'enquiries';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return EnquiriesTable::configure($table);
    }

    /**
     * How many enquiries nobody has promised to do anything about.
     *
     * Not how many are open. "Forty open" is a fact nobody can act on;
     * "six with no owner or no next action" is the list to work through
     * before going home, and it is the number this whole screen exists to
     * drive to zero.
     */
    public static function getNavigationBadge(): ?string
    {
        $adrift = Enquiry::adrift()->count() + Enquiry::overdue()->count();

        return $adrift > 0 ? (string) $adrift : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return ['index' => ListEnquiries::route('/')];
    }
}
