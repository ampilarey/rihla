<?php

namespace App\Filament\Resources\Incidents\Tables;

use App\Models\Incident;
use App\Models\User;
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

class IncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('severity')
                    ->label('How bad')
                    ->badge()
                    // `->state()`, not `->formatStateUsing()`: a null column
                    // state short-circuits formatting and renders an empty
                    // cell. That exact defect shipped on the rooming screen
                    // and was found by opening the page.
                    ->state(fn (Incident $record): string => $record->severityLabel())
                    ->color(fn (Incident $record): string => match ($record->severity) {
                        Incident::EMERGENCY => 'danger',
                        Incident::SERIOUS => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('summary')
                    ->label('What happened')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Incident $record): string => sprintf(
                        '%s · %s%s',
                        $record->categoryLabel(),
                        $record->happened_at->format('j M Y, H:i'),
                        $record->location ? ' · '.$record->location : '',
                    )),

                TextColumn::make('departure.date_start')
                    ->label('Departure')
                    ->date('j M Y')
                    ->sortable(),

                TextColumn::make('traveller.full_name')
                    ->label('Who')
                    ->placeholder('Not one person'),

                TextColumn::make('assignee.name')
                    ->label('On it')
                    // The whole point of the screen: an open incident with
                    // nobody's name against it.
                    //
                    // `->state()` rather than `->placeholder()`. A
                    // placeholder is rendered with Filament's own muted
                    // styling and ignores the column's colour, so the one
                    // cell this column exists to make red came out the
                    // palest grey on the screen. Found by opening it.
                    ->state(fn (Incident $record): string => $record->assignee->name ?? 'Nobody')
                    ->color(fn (Incident $record): string => $record->isOpen() && $record->assigned_to === null
                        ? 'danger'
                        : 'gray'),

                TextColumn::make('status')
                    ->label('State')
                    ->badge()
                    ->state(fn (Incident $record): string => $record->isOpen() ? 'Open' : 'Resolved')
                    ->color(fn (Incident $record): string => $record->isOpen() ? 'warning' : 'success'),
            ])
            // Worst first, then most recent. A list sorted by date alone
            // puts a minor lost umbrella above an emergency from yesterday
            // — which is exactly what this did until somebody looked at the
            // screen and read the comment against the code.
            //
            // A CASE rather than a rank column: three values that are never
            // going to be sorted any other way do not need a second column
            // to keep in step with the first. The expression is plain
            // enough for both SQLite and MySQL.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('CASE severity WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                    Incident::EMERGENCY,
                    Incident::SERIOUS,
                ])
                ->orderByDesc('happened_at'))
            ->filters([
                Filter::make('unattended')
                    ->label('Open emergencies with nobody on them')
                    ->query(fn (Builder $query): Builder => $query->unattended()),

                Filter::make('open')
                    ->label('Open only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->open()),

                SelectFilter::make('severity')
                    ->label('How bad')
                    ->options([
                        Incident::EMERGENCY => 'Emergency',
                        Incident::SERIOUS => 'Serious',
                        Incident::MINOR => 'Minor',
                    ]),

                SelectFilter::make('category')->label('What kind')->options(
                    fn (): array => collect(Incident::CATEGORIES)
                        ->mapWithKeys(fn (string $c): array => [$c => (new Incident(['category' => $c]))->categoryLabel()])
                        ->all(),
                ),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::noteAction(),
                    self::assignAction(),
                    self::resolveAction(),
                    self::reopenAction(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing recorded')
            ->emptyStateDescription('An incident is anything that went wrong on a trip — from a lost bag to a hospital. Recording the small ones is what makes the pattern visible.');
    }

    /**
     * Add to the narrative.
     *
     * Append-only: there is no edit and no delete on a note anywhere in
     * this panel. An incident report that can be quietly rewritten after
     * the fact is not evidence.
     */
    private static function noteAction(): Action
    {
        return Action::make('note')
            ->label('Add a note')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('incident.update') === true)
            ->schema([
                Textarea::make('body')
                    ->label('What has happened since')
                    ->required()
                    ->rows(4)
                    ->helperText('This is added to the record and cannot be edited afterwards.'),
            ])
            ->action(function (Incident $record, array $data): void {
                $record->notes()->create(['body' => $data['body']]);

                Notification::make()->success()->title('Added to '.$record->reference)->send();
            });
    }

    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Put somebody on it')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => auth()->user()?->can('incident.assign') === true)
            ->schema([
                Select::make('assigned_to')
                    ->label('Who')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => User::query()
                        ->whereNotNull('name')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->action(function (Incident $record, array $data): void {
                $record->forceFill(['assigned_to' => $data['assigned_to']])->save();

                $record->notes()->create([
                    'body' => 'Assigned to '.(User::find($data['assigned_to'])->name ?? 'somebody').'.',
                ]);

                Notification::make()->success()->title('Assigned')->send();
            });
    }

    /**
     * Saying it is over, and saying how.
     *
     * The resolution is required. "Resolved" with no sentence is the state
     * this exists to prevent: six months later nobody can say what was
     * done, and the record looks complete while being useless.
     */
    private static function resolveAction(): Action
    {
        return Action::make('resolve')
            ->label('It is over')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Incident $record): bool => $record->isOpen()
                && auth()->user()?->can('incident.resolve') === true)
            ->schema([
                Textarea::make('resolution')
                    ->label('What was done')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Incident $record, array $data): void {
                $record->resolve($data['resolution']);

                Notification::make()->success()->title($record->reference.' closed')->send();
            });
    }

    private static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label('It is not over')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (Incident $record): bool => ! $record->isOpen()
                && auth()->user()?->can('incident.resolve') === true)
            ->schema([
                Textarea::make('why')->label('What has happened')->required()->rows(3),
            ])
            ->action(function (Incident $record, array $data): void {
                $record->reopen($data['why']);

                Notification::make()->warning()->title($record->reference.' reopened')->send();
            });
    }
}
