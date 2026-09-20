<?php

namespace App\Filament\Resources\Learning\Tables;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\LearningModule;
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

class LearningModulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Module')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('days_before_departure')
                    ->label('Shown')
                    ->alignCenter()
                    // A number of days means nothing on its own here; the
                    // column has to say what the number is counting.
                    ->state(fn (LearningModule $record): string => $record->days_before_departure === 0
                        ? 'On the day'
                        : $record->days_before_departure.' days before'),

                TextColumn::make('status')
                    ->label('Where it is')
                    ->badge()
                    ->state(fn (LearningModule $record): string => $record->statusLabel())
                    ->color(fn (LearningModule $record): string => match ($record->status) {
                        LearningModule::PUBLISHED => 'success',
                        LearningModule::APPROVED => 'info',
                        LearningModule::IN_REVIEW => 'warning',
                        LearningModule::WITHDRAWN => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('references_count')
                    ->label('Sources')
                    ->counts('references')
                    ->alignCenter()
                    ->color(fn (LearningModule $record): string => $record->references_count > 0 ? 'gray' : 'danger'),

                TextColumn::make('quiz_questions_count')
                    ->label('Questions')
                    ->counts('quizQuestions')
                    ->alignCenter()
                    // Not red at zero. A module with no quiz is a normal
                    // module; §7.3 asks for quizzes, not for one per page.
                    ->toggleable(),

                TextColumn::make('reviewer.name')
                    ->label('Scholar')
                    ->state(fn (LearningModule $record): string => $record->reviewer->name ?? 'Nobody yet')
                    ->color(fn (LearningModule $record): string => $record->reviewed_by === null ? 'warning' : 'gray'),
            ])
            ->defaultSort('days_before_departure', 'desc')
            ->filters([
                Filter::make('awaiting')
                    ->label('Waiting on a scholar')
                    ->query(self::onlyAwaiting(...)),

                SelectFilter::make('status')->label('Where it is')->options(
                    fn (): array => collect(LearningModule::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [
                            $s => (new LearningModule(['status' => $s]))->statusLabel(),
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
            ->emptyStateDescription('Nothing ships with this feature on purpose. A module teaching somebody how to perform a rite needs a source and a named scholar first (§7.3, §6.4).');
    }

    /**
     * @param  Builder<LearningModule>  $query
     * @return Builder<LearningModule>
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
            ->visible(fn (LearningModule $record): bool => in_array(
                $record->status,
                [LearningModule::DRAFT, LearningModule::WITHDRAWN],
                true,
            ) && auth()->user()?->can('learning.update') === true)
            ->action(function (LearningModule $record): void {
                $record->sendForReview();

                Notification::make()->success()->title('With a scholar now')->send();
            });
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Sign it off')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (LearningModule $record): bool => $record->status === LearningModule::IN_REVIEW
                && auth()->user()?->can('learning.review') === true)
            ->schema([
                Select::make('scholar_id')
                    ->label('Signed off by')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Person::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Their name appears on the module. §7.1 asks for a named reviewer, not a department.'),

                Textarea::make('notes')->label('Anything to record')->rows(3),
            ])
            ->modalDescription(fn (LearningModule $record): ?string => $record->whyNotApprovable())
            ->action(function (LearningModule $record, array $data): void {
                try {
                    $record->approve(Person::findOrFail($data['scholar_id']), $data['notes'] ?? null);
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('Not yet')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Signed off')->send();
            });
    }

    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Put it on the site')
            ->icon('heroicon-o-globe-alt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(fn (LearningModule $record): string => 'This goes onto every pilgrim\'s plan under '.($record->reviewer->name ?? 'nobody').'\'s name.')
            ->visible(fn (LearningModule $record): bool => $record->status === LearningModule::APPROVED
                && auth()->user()?->can('learning.publish') === true)
            ->action(function (LearningModule $record): void {
                try {
                    $record->publish();
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('Not yet')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('On every plan now')->send();
            });
    }

    private static function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label('Take it down')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->visible(fn (LearningModule $record): bool => $record->status === LearningModule::PUBLISHED
                && auth()->user()?->can('learning.publish') === true)
            ->schema([
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(3)
                    ->helperText('Kept on the record. What pilgrims already read, they have read — this stops it being handed to anybody else.'),
            ])
            ->action(function (LearningModule $record, array $data): void {
                $record->withdraw($data['reason']);

                Notification::make()->warning()->title('Taken down')->send();
            });
    }
}
