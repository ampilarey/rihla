<?php

namespace App\Filament\Resources\Visas\Tables;

use App\Models\Document;
use App\Models\User;
use App\Models\VisaApplication;
use App\Services\Visa\VisaDesk;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisaApplicationsTable
{
    private const COLOURS = [
        VisaApplication::NOT_STARTED => 'gray',
        VisaApplication::PREPARING => 'warning',
        VisaApplication::SUBMITTED => 'info',
        VisaApplication::ISSUED => 'success',
        VisaApplication::REJECTED => 'danger',
        VisaApplication::CANCELLED => 'gray',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('traveller.full_name')
                    ->label('Traveller')
                    ->searchable()
                    ->sortable()
                    ->description(fn (VisaApplication $record): ?string => $record->booking->reference),

                TextColumn::make('attempt')
                    ->label('Attempt')
                    ->alignCenter()
                    // A second attempt is the interesting row on the screen:
                    // somebody was refused and is trying again.
                    ->color(fn (int $state): string => $state > 1 ? 'warning' : 'gray')
                    ->badge(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('visa_type')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : (config('visa.types.'.$state) ?? $state)),

                TextColumn::make('officer.name')
                    ->label('Officer')
                    ->placeholder('Unassigned')
                    ->sortable(),

                TextColumn::make('submitted_at')
                    ->label('Waiting')
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    // Computed against config/visa.php on every render,
                    // never stored: a stored "overdue" flag is wrong the
                    // moment the clock passes it.
                    ->color(fn (VisaApplication $record): string => $record->isStalled() ? 'danger' : 'gray')
                    ->description(fn (VisaApplication $record): ?string => $record->isStalled()
                        ? 'Past its service level'
                        : null),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        VisaApplication::STATUSES,
                        array_map(fn (string $s): string => ucfirst(str_replace('_', ' ', $s)), VisaApplication::STATUSES),
                    ))
                    ->multiple(),

                SelectFilter::make('assigned_to')
                    ->label('Officer')
                    ->options(fn (): array => User::role(Access::VISA_STAFF)->pluck('name', 'id')->all()),

                Filter::make('stalled')
                    ->label('Past its service level')
                    // Filtered in PHP because the threshold differs per
                    // status and lives in config; a SQL expression would
                    // hard-code what §5.4b says must stay configuration.
                    ->query(fn (Builder $query): Builder => $query->whereKey(
                        VisaApplication::open()->get()
                            ->filter(fn (VisaApplication $a): bool => $a->isStalled())
                            ->modelKeys(),
                    )),
            ])
            ->recordActions([
                self::assignAction(),
                self::advanceAction(),
                self::reapplyAction(),
            ])
            ->emptyStateHeading('No visa applications')
            ->emptyStateDescription('Applications are opened from a booking — one per traveller, because a visa is granted to a person and not to a party.');
    }

    /** Work with no name against it is work nobody does. */
    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Assign')
            ->icon('heroicon-o-user')
            ->visible(fn (VisaApplication $record): bool => ! $record->isClosed()
                && auth()->user()?->can('visa.update') === true)
            ->schema([
                Select::make('assigned_to')
                    ->label('Visa officer')
                    ->options(fn (): array => User::role(Access::VISA_STAFF)->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (VisaApplication $record, array $data): void {
                $record->forceFill(['assigned_to' => $data['assigned_to']])->save();

                Notification::make()->success()->title('Assigned')->send();
            });
    }

    /**
     * Moving a stage, through the state machine and never around it.
     *
     * The evidence document is §5.4a's requirement that every stage carries
     * one — the submission receipt, the visa scan, the refusal letter. A
     * stage with a date and no evidence is somebody's memory.
     */
    private static function advanceAction(): Action
    {
        return Action::make('advance')
            ->label('Move on')
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->visible(fn (VisaApplication $record): bool => ! $record->isClosed()
                && auth()->user()?->can('visa.update') === true)
            ->schema([
                Select::make('to')
                    ->label('To')
                    ->options(fn (VisaApplication $record): array => array_combine(
                        VisaApplication::TRANSITIONS[$record->status] ?? [],
                        array_map(
                            fn (string $s): string => ucfirst(str_replace('_', ' ', $s)),
                            VisaApplication::TRANSITIONS[$record->status] ?? [],
                        ),
                    ))
                    ->required()
                    ->live(),

                Select::make('document_id')
                    ->label('Evidence')
                    ->options(fn (VisaApplication $record): array => Document::where('traveller_id', $record->traveller_id)
                        ->get()
                        ->mapWithKeys(fn (Document $d): array => [$d->getKey() => ucfirst($d->type)])
                        ->all())
                    ->searchable()
                    ->helperText('The receipt, the visa scan, the refusal letter — whatever was actually seen.'),

                Textarea::make('reason')
                    ->label('Note')
                    ->rows(2)
                    ->required(fn (callable $get): bool => $get('to') === VisaApplication::REJECTED)
                    ->helperText('Required for a refusal: the stated reason is what the next attempt has to answer.'),
            ])
            ->action(function (VisaApplication $record, array $data): void {
                $record->transitionTo(
                    (string) $data['to'],
                    $data['reason'] ?: null,
                    null,
                    isset($data['document_id']) ? Document::find($data['document_id']) : null,
                );

                Notification::make()->success()->title('Moved on')->send();
            });
    }

    /** A refusal ends an application; trying again starts a new one. */
    private static function reapplyAction(): Action
    {
        return Action::make('reapply')
            ->label('Apply again')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (VisaApplication $record): bool => $record->status === VisaApplication::REJECTED
                && auth()->user()?->can('visa.create') === true)
            ->requiresConfirmation()
            ->modalDescription('This opens a fresh attempt. The refused application is kept exactly as it is — its date, reference and stated reason are the evidence of what was sent.')
            ->action(function (VisaApplication $record): void {
                $next = app(VisaDesk::class)->reapply($record);

                Notification::make()
                    ->success()
                    ->title('Attempt '.$next->attempt.' opened')
                    ->send();
            });
    }
}
