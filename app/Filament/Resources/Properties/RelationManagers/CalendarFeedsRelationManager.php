<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Exceptions\CalendarFeedRefused;
use App\Models\CalendarFeed;
use App\Models\Property;
use App\Models\RoomType;
use App\Services\Stays\CalendarFetcher;
use App\Services\Stays\CalendarImport;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Other sites' calendars — §16 Phase 16. Used by both panels.
 *
 * A feed is added, synced and removed; never edited, because the link is a
 * secret this screen does not show back. A wrong link is removed and added
 * again. Removing a feed releases the nights it blocked.
 */
class CalendarFeedsRelationManager extends RelationManager
{
    protected static string $relationship = 'calendarFeeds';

    protected static ?string $title = 'Other calendars';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('room_type_id')
                ->label('Room')
                ->options(fn (): array => $this->property()->roomTypes()
                    ->where('quantity', 1)
                    ->whereDoesntHave('calendarFeed')
                    ->get()
                    ->mapWithKeys(fn (RoomType $room): array => [$room->id => $room->getTranslation('name', 'en')])
                    ->all())
                ->required()
                ->helperText('Only a room type with one room can take a calendar: a closed night closes the whole type.'),

            TextInput::make('label')->label('Which site')->required()->maxLength(60)->placeholder('Airbnb'),

            TextInput::make('url')
                ->label('Calendar link (iCal)')
                ->required()
                ->maxLength(2000)
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        $fetcher = app(CalendarFetcher::class);
                        $fetcher->publicAddress($fetcher->checkedHost((string) $value));
                    } catch (CalendarFeedRefused $refusal) {
                        $fail($refusal->getMessage());
                    }
                }])
                ->helperText('The "export calendar" or "iCal" link from the other site. It is stored encrypted and never shown again.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('roomType.name')->label('Room'),
                TextColumn::make('label')->label('Site'),
                TextColumn::make('link')->label('Link')->state(fn (CalendarFeed $record): string => $record->maskedUrl()),
                TextColumn::make('last_synced_at')->label('Last read')->since()->placeholder('Not yet'),
                TextColumn::make('nights_blocked')->label('Nights closed'),
                TextColumn::make('last_error')->label('Problem')->placeholder('—')->wrap()->color('danger'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add a calendar')
                    ->using(function (array $data): Model {
                        $room = $this->property()->roomTypes()->whereKey($data['room_type_id'])->firstOrFail();

                        $feed = new CalendarFeed(['room_type_id' => $room->getKey(), 'label' => $data['label'], 'url' => trim((string) $data['url'])]);
                        $feed->save();

                        app(CalendarImport::class)->sync($feed);

                        return $feed;
                    }),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label('Read now')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (CalendarFeed $record): void {
                        $nights = app(CalendarImport::class)->sync($record);

                        $nights === null
                            ? Notification::make()->danger()->title((string) $record->last_error)->send()
                            : Notification::make()->success()->title("{$nights} night(s) closed from {$record->label}")->send();
                    }),
                DeleteAction::make()
                    ->modalDescription('The nights this calendar closed are opened again.')
                    ->before(fn (CalendarFeed $record) => app(CalendarImport::class)->release($record)),
            ])
            ->emptyStateHeading('No other calendars')
            ->emptyStateDescription('Selling the same room on another site? Add its calendar link and the nights booked there close here, every hour.');
    }

    /** An edit page, not a view page — a relation manager on a view page is read-only (AGENTS.md). */
    public function isReadOnly(): bool
    {
        return false;
    }

    private function property(): Property
    {
        /** @var Property */
        return $this->getOwnerRecord();
    }
}
