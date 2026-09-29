<?php

namespace App\Filament\Host\Resources\Reviews;

use App\Exceptions\ReviewRefused;
use App\Filament\Host\Resources\Reviews\Pages\ListReviews;
use App\Filament\Resources\Reviews\ReviewResource as StaffReviews;
use App\Models\Review;
use App\Services\Stays\Reviews;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A host's reviews — §16.6, §16.11. Read them, and reply once; a reply
 * can be corrected for a day after it is written. Tenant-scoped through
 * the review's own `partner`.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $slug = 'reviews';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('submitted_at')->label('Written')->since()->sortable(),
                TextColumn::make('property.name')->label('Listing')->wrap(),
                TextColumn::make('rating')->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state)),
                TextColumn::make('body')->label('What they wrote')->wrap()->placeholder('Stars only'),
                TextColumn::make('host_reply')->label('Your reply')->wrap()->placeholder('Not yet'),
                TextColumn::make('state')
                    ->label('Shown?')
                    ->badge()
                    ->state(fn (Review $record): string => StaffReviews::state($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Hidden' => 'danger',
                        'Waiting' => 'warning',
                        default => 'success',
                    }),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordActions([
                Action::make('reply')
                    ->label(fn (Review $record): string => $record->host_reply ? 'Edit reply' : 'Reply')
                    ->icon('heroicon-o-chat-bubble-left')
                    ->visible(fn (Review $record): bool => $record->replyIsEditable())
                    ->authorize(fn (Review $record): bool => auth()->user()?->can('update', $record) === true)
                    ->fillForm(fn (Review $record): array => ['reply' => $record->host_reply])
                    ->modalDescription('Shown under the review. You can correct it for '.Review::REPLY_EDITABLE_HOURS.' hours after you first reply.')
                    ->schema([Textarea::make('reply')->required()->rows(4)->maxLength(2000)])
                    ->action(function (Review $record, array $data): void {
                        try {
                            app(Reviews::class)->reply($record, $data['reply']);
                        } catch (ReviewRefused $refusal) {
                            Notification::make()->danger()->title('Not saved')->body($refusal->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Reply saved')->send();
                    }),
            ])
            ->emptyStateHeading('No reviews yet')
            ->emptyStateDescription('Guests are asked for a review a day after they check out.');
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
