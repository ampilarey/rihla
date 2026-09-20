<?php

namespace App\Filament\Resources\Notices;

use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Filament\Resources\Notices\Tables\NoticesTable;
use App\Models\Notice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Who still owes us something — §11.2's notifications, as work.
 *
 * There is no WhatsApp Business API, no SMTP and no SMS provider, and this
 * codebase already records that it will not pretend otherwise. So the
 * "notification system" is honest about what it is: a list of people who
 * have not done the thing, each with the WhatsApp conversation prepared,
 * so the message staff were going to send anyway takes one tap.
 *
 * No form. A notice is raised from a record that already exists and is
 * never typed — the moment somebody can write one by hand, the portal
 * starts carrying claims nothing backs.
 */
class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Chasing list';

    protected static ?string $modelLabel = 'notice';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return NoticesTable::configure($table);
    }

    /**
     * People who owe us something and have not been chased.
     *
     * Not the count of notices: "you travel in nine days" needs no chasing,
     * and a badge that counted it would be a number nobody can drive to
     * zero.
     */
    public static function getNavigationBadge(): ?string
    {
        $chasing = Notice::needsChasing()->count();

        return $chasing > 0 ? (string) $chasing : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return ['index' => ListNotices::route('/')];
    }
}
