<?php

namespace App\Filament\Resources\Knowledge\Tables;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\KnowledgeArticle;
use App\Models\Person;
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

class KnowledgeArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Article')
                    ->wrap()
                    ->searchable()
                    ->description(fn (KnowledgeArticle $record): string => $record->categoryLabel()),

                TextColumn::make('status')
                    ->label('Where it is')
                    ->badge()
                    ->state(fn (KnowledgeArticle $record): string => $record->statusLabel())
                    ->color(fn (KnowledgeArticle $record): string => match ($record->status) {
                        KnowledgeArticle::PUBLISHED => 'success',
                        KnowledgeArticle::APPROVED => 'info',
                        KnowledgeArticle::IN_REVIEW => 'warning',
                        KnowledgeArticle::WITHDRAWN => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('references_count')
                    ->label('Sources')
                    ->counts('references')
                    ->alignCenter()
                    // The number §7.1 is about. Zero is red wherever it
                    // appears, because zero is the state the whole
                    // apparatus exists to keep off the site.
                    ->color(fn (KnowledgeArticle $record): string => $record->references_count > 0 ? 'gray' : 'danger'),

                TextColumn::make('reviewer.name')
                    ->label('Scholar')
                    // A value, not a placeholder: a placeholder ignores the
                    // column colour, and "nobody" is the cell that has to
                    // be noticed.
                    ->state(fn (KnowledgeArticle $record): string => $record->reviewer->name ?? 'Nobody yet')
                    ->color(fn (KnowledgeArticle $record): string => $record->reviewed_by === null ? 'warning' : 'gray'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                Filter::make('awaiting')
                    ->label('Waiting on a scholar')
                    ->query(self::onlyAwaiting(...)),

                SelectFilter::make('status')->label('Where it is')->options(
                    fn (): array => collect(KnowledgeArticle::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [
                            $s => (new KnowledgeArticle(['status' => $s]))->statusLabel(),
                        ])
                        ->all(),
                ),

                SelectFilter::make('category')->options(
                    fn (): array => collect(KnowledgeArticle::CATEGORIES)
                        ->mapWithKeys(fn (string $c): array => [
                            $c => (new KnowledgeArticle(['category' => $c]))->categoryLabel(),
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
            ->emptyStateHeading('Nothing written yet')
            ->emptyStateDescription('Nothing ships with this feature on purpose. Every article needs a source and a named scholar before it can go on the site (§7.1, §6.4).');
    }

    /**
     * @param  Builder<KnowledgeArticle>  $query
     * @return Builder<KnowledgeArticle>
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
            ->visible(fn (KnowledgeArticle $record): bool => in_array(
                $record->status,
                [KnowledgeArticle::DRAFT, KnowledgeArticle::WITHDRAWN],
                true,
            ) && auth()->user()?->can('knowledge.update') === true)
            ->action(function (KnowledgeArticle $record): void {
                $record->sendForReview();

                Notification::make()->success()->title('With a scholar now')->send();
            });
    }

    /**
     * A named scholar signs it off.
     *
     * The action names who is signing, because §7.1 wants a name a reader
     * can see rather than "reviewed by the team". It refuses without a
     * source, and says so in words instead of a validation code.
     */
    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Sign it off')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (KnowledgeArticle $record): bool => $record->status === KnowledgeArticle::IN_REVIEW
                && auth()->user()?->can('knowledge.review') === true)
            ->schema([
                Select::make('scholar_id')
                    ->label('Signed off by')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Person::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Their name appears on the article. §7.1 asks for a named reviewer, not a department.'),

                Textarea::make('notes')->label('Anything to record')->rows(3),
            ])
            ->action(function (KnowledgeArticle $record, array $data): void {
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
            ->modalDescription(fn (KnowledgeArticle $record): string => 'This goes public under '.($record->reviewer->name ?? 'nobody').'\'s name.')
            ->visible(fn (KnowledgeArticle $record): bool => $record->status === KnowledgeArticle::APPROVED
                && auth()->user()?->can('knowledge.publish') === true)
            ->action(function (KnowledgeArticle $record): void {
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
            ->visible(fn (KnowledgeArticle $record): bool => $record->status === KnowledgeArticle::PUBLISHED
                && auth()->user()?->can('knowledge.publish') === true)
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(3)
                    ->helperText('Kept on the record. A page that vanished for no recorded cause is one nobody can explain a year later.'),
            ])
            ->action(function (KnowledgeArticle $record, array $data): void {
                $record->withdraw($data['reason']);

                Notification::make()->warning()->title('Taken down')->send();
            });
    }
}
