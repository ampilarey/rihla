<?php

namespace App\Filament\Resources\Ziyarah\Tables;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\Person;
use App\Models\ZiyarahLocation;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ZiyarahLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Location')
                    ->wrap()
                    ->searchable()
                    ->description(fn (ZiyarahLocation $record): string => $record->cityLabel()),

                TextColumn::make('status')
                    ->label('Where it is')
                    ->badge()
                    ->state(fn (ZiyarahLocation $record): string => $record->statusLabel())
                    ->color(fn (ZiyarahLocation $record): string => match ($record->status) {
                        ZiyarahLocation::PUBLISHED => 'success',
                        ZiyarahLocation::APPROVED => 'info',
                        ZiyarahLocation::IN_REVIEW => 'warning',
                        ZiyarahLocation::WITHDRAWN => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('references_count')
                    ->label('Sources')
                    ->counts('references')
                    ->alignCenter()
                    // Zero is red wherever it appears: it is the state the
                    // whole apparatus exists to keep off the site.
                    ->color(fn (ZiyarahLocation $record): string => $record->references_count > 0 ? 'gray' : 'danger'),

                TextColumn::make('misconceptions_count')
                    ->label('Corrections')
                    ->counts('misconceptions')
                    ->alignCenter()
                    // Not red at zero. A location with no misconception
                    // recorded is normal; §7.2 asks for them where they
                    // exist, not for one per place.
                    ->toggleable(),

                TextColumn::make('reviewer.name')
                    ->label('Scholar')
                    // A value, not a placeholder: a placeholder ignores the
                    // column colour, and "nobody" is the cell that has to
                    // be noticed.
                    ->state(fn (ZiyarahLocation $record): string => $record->reviewer->name ?? 'Nobody yet')
                    ->color(fn (ZiyarahLocation $record): string => $record->reviewed_by === null ? 'warning' : 'gray'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Filter::make('awaiting')
                    ->label('Waiting on a scholar')
                    ->query(self::onlyAwaiting(...)),

                SelectFilter::make('status')->label('Where it is')->options(
                    fn (): array => collect(ZiyarahLocation::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [
                            $s => (new ZiyarahLocation(['status' => $s]))->statusLabel(),
                        ])
                        ->all(),
                ),

                SelectFilter::make('city')->options(
                    fn (): array => collect(ZiyarahLocation::CITIES)
                        ->mapWithKeys(fn (string $c): array => [
                            $c => (new ZiyarahLocation(['city' => $c]))->cityLabel(),
                        ])
                        ->all(),
                ),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::sendForReviewAction(),
                    self::approveAction(),
                    self::publishAction(),
                    self::withdrawAction(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('No locations yet')
            ->emptyStateDescription('Nothing ships with this feature on purpose. Every location needs its sources and a named scholar before it can go on the site (§7.2, §6.4).');
    }

    /**
     * @param  Builder<ZiyarahLocation>  $query
     * @return Builder<ZiyarahLocation>
     */
    private static function onlyAwaiting(Builder $query): Builder
    {
        return $query->awaitingAScholar();
    }

    private static function sendForReviewAction(): Action
    {
        return Action::make('send_for_review')
            ->label('Send it to a scholar')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (ZiyarahLocation $record): bool => in_array(
                $record->status,
                [ZiyarahLocation::DRAFT, ZiyarahLocation::WITHDRAWN],
                true,
            ) && auth()->user()?->can('ziyarah.update') === true)
            ->action(function (ZiyarahLocation $record): void {
                $record->sendForReview();

                Notification::make()->success()->title('With a scholar now')->send();
            });
    }

    /**
     * A named scholar signs it off.
     *
     * The modal says up front why it would be refused, because the scholar
     * opening it is the person who has to go and get the missing source.
     * The model throws the same sentence either way — this is the screen
     * agreeing with it rather than the screen being the rule.
     */
    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Sign it off')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (ZiyarahLocation $record): bool => $record->status === ZiyarahLocation::IN_REVIEW
                && auth()->user()?->can('ziyarah.review') === true)
            ->schema([
                Select::make('scholar_id')
                    ->label('Signed off by')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Person::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Their name appears on the page. §7.1 asks for a named reviewer, not a department.'),

                Textarea::make('notes')->label('Anything to record')->rows(3),
            ])
            ->modalDescription(fn (ZiyarahLocation $record): ?string => $record->whyNotApprovable())
            ->action(function (ZiyarahLocation $record, array $data): void {
                try {
                    $record->approve(Person::findOrFail($data['scholar_id']), $data['notes'] ?? null);
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('Not yet')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Signed off')->send();
            });
    }

    /**
     * Putting it on the site.
     *
     * A separate permission from signing off, and deliberately not held by
     * the same role: a reviewer who can also publish performs the check on
     * themselves (§6.4).
     */
    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Put it on the site')
            ->icon('heroicon-o-globe-alt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (ZiyarahLocation $record): string => 'This goes public under '.($record->reviewer->name ?? 'nobody').'\'s name.')
            ->visible(fn (ZiyarahLocation $record): bool => $record->status === ZiyarahLocation::APPROVED
                && auth()->user()?->can('ziyarah.publish') === true)
            ->action(function (ZiyarahLocation $record): void {
                try {
                    $record->publish();
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('Not yet')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('On the site')->send();
            });
    }

    private static function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label('Take it down')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (ZiyarahLocation $record): bool => $record->status === ZiyarahLocation::PUBLISHED
                && auth()->user()?->can('ziyarah.publish') === true)
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(3)
                    ->helperText('Kept on the record. Anybody who already saved this guide to their phone keeps the copy they have until they next open it online.'),
            ])
            ->action(function (ZiyarahLocation $record, array $data): void {
                $record->withdraw($data['reason']);

                Notification::make()->warning()->title('Taken down')->send();
            });
    }
}
