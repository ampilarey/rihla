<?php

namespace App\Filament\Resources\Waitlist;

use App\Filament\Resources\Waitlist\Pages\ListWaitlistEntries;
use App\Filament\Resources\Waitlist\Tables\WaitlistEntriesTable;
use App\Models\WaitlistEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Who is waiting, and who has been offered seats that somebody needs to tell
 * them about.
 *
 * This screen is the notification channel. There is no SMTP configured and
 * no WhatsApp API, so an offer produces a signed claim link and appears
 * here as work: staff copy the link into the conversation they were going to
 * have anyway. A Mailable quietly posting into the log driver would look
 * finished and reach nobody.
 *
 * List only. An entry is joined by the customer, promoted by the system and
 * converted by the checkout; the one thing staff need to do to one by hand
 * is take it off, which is an action rather than a form.
 */
class WaitlistEntryResource extends Resource
{
    protected static ?string $model = WaitlistEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Waiting list';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return WaitlistEntriesTable::configure($table);
    }

    /** Offers waiting to be passed on — the only number here that is work. */
    public static function getNavigationBadge(): ?string
    {
        $offered = WaitlistEntry::offered()->count();

        return $offered > 0 ? (string) $offered : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListWaitlistEntries::route('/')];
    }
}
