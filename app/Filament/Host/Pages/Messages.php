<?php

namespace App\Filament\Host\Pages;

use App\Filament\Host\Resources\Bookings\BookingResource;
use App\Models\Stay;
use App\Models\StayMessage;
use App\Models\User;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The host's conversations with guests — §16.6, §16.11.
 *
 * One row per stay that has any, unread first, then the most recent. The
 * conversation itself lives on the booking, where the host can see the
 * dates and the bill while answering.
 */
class Messages extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $title = 'Messages';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.host.housekeeping';

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::MESSAGES);
    }

    public static function getNavigationBadge(): ?string
    {
        $host = HostContext::current();

        if ($host === null) {
            return null;
        }

        $unread = self::unreadFor($host->getKey());

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Messages from guests and Rihla the host has not opened. */
    public static function unreadFor(int $hostId): int
    {
        return StayMessage::query()
            ->unreadBy(StayMessage::HOST)
            ->whereHas('stay.property', fn (Builder $query) => $query->where('partner_id', $hostId))
            ->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Stay::query()
                ->whereHas('property', fn (Builder $query) => $query->where('partner_id', (int) HostContext::current()?->getKey()))
                ->whereHas('messages')
                ->with('customer')
                ->withCount(['messages as unread_count' => fn (Builder $query) => $query
                    ->where('sender', '!=', StayMessage::HOST)
                    ->whereNull('read_at')])
                ->withMax('messages', 'sent_at'))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('unread_count')->orderByDesc('messages_max_sent_at'))
            ->columns([
                TextColumn::make('customer.name')->label('Guest'),
                TextColumn::make('reference'),
                TextColumn::make('check_in')->label('Arrives')->date('j M Y'),
                TextColumn::make('messages_max_sent_at')->label('Last message')->since(),
                TextColumn::make('unread_count')
                    ->label('Unread')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
            ])
            ->recordUrl(fn (Stay $record): string => BookingResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Guests can write to you from their stay page. Their messages appear here.');
    }
}
