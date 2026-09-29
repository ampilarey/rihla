<?php

namespace App\Filament\Resources\Reviews;

use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Models\Review;
use App\Services\Stays\Reviews;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Guests' reviews — §16.11, the staff side.
 *
 * A review publishes itself after a short delay; this screen is where a
 * person stops one that should not be shown. **Hide** needs a reason, and
 * the reason is shown to the guest who wrote it, never to the public.
 * Nothing here edits what a guest wrote.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Reviews';

    protected static UnitEnum|string|null $navigationGroup = 'Stays';

    protected static ?int $navigationSort = 5;

    /** Written, not yet public: the window in which hiding one is quiet. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Review::query()->whereNull('hidden_at')->where('published_at', '>', now())->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('property.name')->label('Listing'),
            TextEntry::make('rating')->formatStateUsing(fn (int $state): string => $state.' / 5'),
            TextEntry::make('body')->label('What they wrote')->placeholder('Stars only')->columnSpanFull(),
            TextEntry::make('host_reply')->label('The host replied')->placeholder('No reply')->columnSpanFull(),
            TextEntry::make('hidden_reason')->label('Hidden because')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('submitted_at')->label('Written')->since()->sortable(),
                TextColumn::make('property.name')->label('Listing')->wrap(),
                TextColumn::make('rating')->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state)),
                TextColumn::make('body')->label('What they wrote')->limit(80)->wrap()->placeholder('Stars only'),
                TextColumn::make('state')
                    ->label('Shown?')
                    ->badge()
                    ->state(fn (Review $record): string => self::state($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Hidden' => 'danger',
                        'Waiting' => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('state')
                    ->options(['waiting' => 'Waiting to be published', 'published' => 'Published', 'hidden' => 'Hidden'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'waiting' => $query->whereNull('hidden_at')->where('published_at', '>', now()),
                        'published' => Review::onlyVisible($query),
                        'hidden' => $query->whereNotNull('hidden_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('hide')
                    ->label('Hide')
                    ->icon('heroicon-o-eye-slash')
                    ->color('danger')
                    ->visible(fn (Review $record): bool => ! $record->isHidden())
                    ->authorize(fn (Review $record): bool => auth()->user()?->can('update', $record) === true)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why? The guest who wrote it is shown this; the public is not.')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (Review $record, array $data): void {
                        app(Reviews::class)->hide($record, $data['reason'], auth()->user());
                        Notification::make()->success()->title('Hidden')->send();
                    }),
                Action::make('unhide')
                    ->label('Show again')
                    ->icon('heroicon-o-eye')
                    ->visible(fn (Review $record): bool => $record->isHidden())
                    ->authorize(fn (Review $record): bool => auth()->user()?->can('update', $record) === true)
                    ->requiresConfirmation()
                    ->action(function (Review $record): void {
                        app(Reviews::class)->unhide($record);
                        Notification::make()->success()->title('Shown again')->send();
                    }),
            ])
            ->emptyStateHeading('No reviews yet')
            ->emptyStateDescription('Guests are asked a day after they check out.');
    }

    public static function state(Review $review): string
    {
        return match (true) {
            $review->isHidden() => 'Hidden',
            $review->isVisible() => 'Published',
            default => 'Waiting',
        };
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListReviews::route('/')];
    }
}
