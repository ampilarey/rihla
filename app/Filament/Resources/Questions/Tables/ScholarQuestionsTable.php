<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\Person;
use App\Models\ScholarQuestion;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScholarQuestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('body')
                    ->label('What they asked')
                    ->wrap()
                    ->searchable()
                    ->description(fn (ScholarQuestion $record): string => $record->booking?->reference
                        ? 'Booking '.$record->booking->reference
                        : 'No booking on file'),

                TextColumn::make('created_at')
                    ->label('Waiting')
                    ->alignCenter()
                    // How long somebody has been waiting, not when they
                    // asked. The date is arithmetic the reader has to do;
                    // "3 weeks" is a person who has been ignored.
                    ->state(fn (ScholarQuestion $record): string => $record->isWaiting()
                        ? (string) $record->created_at?->diffForHumans()
                        : '—')
                    ->color(fn (ScholarQuestion $record): string => $record->isWaiting()
                        && $record->created_at?->lt(now()->subDays(14)) === true ? 'danger' : 'gray'),

                TextColumn::make('status')
                    ->label('Where it is')
                    ->badge()
                    ->state(fn (ScholarQuestion $record): string => $record->statusLabel())
                    ->color(fn (ScholarQuestion $record): string => match ($record->status) {
                        ScholarQuestion::ANSWERED => 'success',
                        ScholarQuestion::DECLINED => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('scholar.name')
                    ->label('Answered by')
                    ->state(fn (ScholarQuestion $record): string => $record->scholar->name ?? '—'),

                TextColumn::make('may_publish')
                    ->label('Shown to others')
                    ->badge()
                    // Three states, not two, and the middle one is the
                    // point: consent given is not the same as published,
                    // and neither is the same as consent withheld.
                    ->state(fn (ScholarQuestion $record): string => match (true) {
                        ! $record->may_publish => 'Private — they said no',
                        $record->isPublic() => 'On the site',
                        default => 'They agreed; not up yet',
                    })
                    ->color(fn (ScholarQuestion $record): string => match (true) {
                        ! $record->may_publish => 'gray',
                        $record->isPublic() => 'success',
                        default => 'info',
                    }),
            ])
            ->defaultSort('created_at')
            ->filters([
                Filter::make('waiting')
                    ->label('Waiting for a scholar')
                    ->query(self::onlyWaiting(...)),

                SelectFilter::make('status')->label('Where it is')->options(
                    fn (): array => collect(ScholarQuestion::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [
                            $s => (new ScholarQuestion(['status' => $s]))->statusLabel(),
                        ])
                        ->all(),
                ),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::readAction(),
                    self::answerAction(),
                    self::declineAction(),
                    self::publishAction(),
                    self::unpublishAction(),
                ]),
            ])
            ->emptyStateHeading('Nobody has asked anything yet')
            ->emptyStateDescription('Pilgrims ask from their own portal. Questions arrive here and stay until somebody answers or declines them — there is no way for one to quietly go away.');
    }

    /**
     * @param  Builder<ScholarQuestion>  $query
     * @return Builder<ScholarQuestion>
     */
    private static function onlyWaiting(Builder $query): Builder
    {
        return $query->waiting();
    }

    /** The whole question and the whole answer, which the row deliberately truncates. */
    private static function readAction(): Action
    {
        return Action::make('read')
            ->label('Read it')
            ->icon('heroicon-o-eye')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalHeading('The question')
            ->modalDescription(fn (ScholarQuestion $record): string => $record->body)
            ->modalContent(fn (ScholarQuestion $record) => $record->answer === null
                ? null
                : view('filament.scholar-answer', ['question' => $record]));
    }

    /**
     * A named scholar answers.
     *
     * The name is chosen rather than taken from the logged-in user: the
     * scholar who answers may never hold a staff login, and §7.1 wants a
     * name the person who asked can see.
     */
    private static function answerAction(): Action
    {
        return Action::make('answer')
            ->label('Answer it')
            ->icon('heroicon-o-pencil-square')
            ->color('success')
            ->visible(fn (ScholarQuestion $record): bool => auth()->user()?->can('question.answer') === true)
            ->fillForm(fn (ScholarQuestion $record): array => ['answer' => $record->answer])
            ->modalDescription(fn (ScholarQuestion $record): string => $record->body)
            ->schema([
                Select::make('scholar_id')
                    ->label('Answered by')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Person::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText('Their name goes to the person who asked, and onto the page if this is ever published.'),

                Textarea::make('answer')
                    ->label('The answer')
                    ->required()
                    ->rows(8)
                    ->helperText('Add any sources on the Sources tab. If this is not something to answer, decline it instead and say who to ask — that is a better answer than a careful one that avoids the question.'),
            ])
            ->action(function (ScholarQuestion $record, array $data): void {
                try {
                    $record->answerWith(Person::findOrFail($data['scholar_id']), $data['answer']);
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('Not yet')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Answered')->send();
            });
    }

    private static function declineAction(): Action
    {
        return Action::make('decline')
            ->label('We cannot answer this')
            ->icon('heroicon-o-hand-raised')
            ->visible(fn (ScholarQuestion $record): bool => $record->isWaiting()
                && auth()->user()?->can('question.answer') === true)
            ->schema([
                Textarea::make('reason')
                    ->label('What to tell them')
                    ->required()
                    ->rows(4)
                    ->helperText('This goes to the person who asked. Point them somewhere if you can — silence is the one thing that is not an answer.'),
            ])
            ->action(function (ScholarQuestion $record, array $data): void {
                $record->decline($data['reason']);

                Notification::make()->success()->title('They have been told')->send();
            });
    }

    /**
     * Putting it where other people can read it.
     *
     * Hidden outright without the asker's consent, rather than shown and
     * refused: an office screen offering a button that cannot be pressed
     * teaches people that consent is an obstacle. The model refuses too.
     */
    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Show this to others')
            ->icon('heroicon-o-globe-alt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The person who asked agreed to this when they asked. Their name is not shown — only the question and the answer.')
            ->visible(fn (ScholarQuestion $record): bool => $record->may_publish
                && $record->status === ScholarQuestion::ANSWERED
                && ! $record->isPublic()
                && auth()->user()?->can('question.publish') === true)
            ->action(function (ScholarQuestion $record): void {
                try {
                    $record->publish();
                } catch (EditorialStandardNotMet $e) {
                    Notification::make()->danger()->title('No')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('On the site')->send();
            });
    }

    private static function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label('Take it down')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (ScholarQuestion $record): bool => $record->isPublic()
                && auth()->user()?->can('question.publish') === true)
            ->action(function (ScholarQuestion $record): void {
                $record->unpublish();

                Notification::make()->warning()->title('Taken down')->send();
            });
    }
}
