<?php

namespace App\Filament\Resources\Permits\Tables;

use App\Exceptions\PrerequisitesNotMet;
use App\Models\Document;
use App\Models\NusukPermit;
use App\Models\User;
use App\Services\Nusuk\PermitDesk;
use App\Support\Access;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NusukPermitsTable
{
    private const COLOURS = [
        NusukPermit::NOT_STARTED => 'gray',
        NusukPermit::REQUESTED => 'info',
        NusukPermit::ISSUED => 'success',
        NusukPermit::REFUSED => 'danger',
        NusukPermit::CANCELLED => 'gray',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('traveller.full_name')
                    ->label('Traveller')
                    ->searchable()
                    ->sortable()
                    ->description(fn (NusukPermit $record): ?string => $record->booking->reference),

                // The first column anybody reads, because the two kinds
                // fail differently: a missing Rawdah slot is a
                // disappointment, a missing Umrah permit is a wasted
                // journey.
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === NusukPermit::UMRAH
                        ? 'Umrah permit'
                        : 'Rawdah slot')
                    ->color(fn (string $state): string => $state === NusukPermit::UMRAH ? 'warning' : 'gray'),

                TextColumn::make('attempt')
                    ->label('Attempt')
                    ->alignCenter()
                    ->badge()
                    // A second attempt is the interesting row: somebody was
                    // refused and is asking again.
                    ->color(fn (int $state): string => $state > 1 ? 'warning' : 'gray'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('slot_at')
                    ->label('Slot')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('—')
                    ->sortable()
                    // A warning, never a refusal: Nusuk decides what it will
                    // accept, and the lead time in config is our belief
                    // about their rule, not their rule.
                    ->color(fn (NusukPermit $record): string => $record->slotLooksOutOfRange() ? 'warning' : 'gray')
                    ->description(fn (NusukPermit $record): ?string => $record->slotLooksOutOfRange()
                        ? 'Further ahead than Nusuk is thought to allow'
                        : null),

                TextColumn::make('officer.name')
                    ->label('Officer')
                    ->placeholder('Unassigned')
                    ->sortable(),

                TextColumn::make('requested_at')
                    ->label('Waiting')
                    ->since()
                    ->placeholder('—')
                    ->sortable()
                    // Computed against config/nusuk.php on every render,
                    // never stored: a stored "overdue" flag is wrong the
                    // moment the clock passes it.
                    ->color(fn (NusukPermit $record): string => $record->isStalled() ? 'danger' : 'gray')
                    ->description(fn (NusukPermit $record): ?string => $record->isStalled()
                        ? 'Past its service level'
                        : null),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('kind')
                    ->options([
                        NusukPermit::UMRAH => 'Umrah permit',
                        NusukPermit::RAWDAH => 'Rawdah slot',
                    ]),

                SelectFilter::make('status')
                    ->options(array_combine(
                        NusukPermit::STATUSES,
                        array_map(fn (string $s): string => ucfirst(str_replace('_', ' ', $s)), NusukPermit::STATUSES),
                    ))
                    ->multiple(),

                SelectFilter::make('assigned_to')
                    ->label('Officer')
                    ->options(fn (): array => self::officers()),

                Filter::make('stalled')
                    ->label('Past its service level')
                    // Filtered in PHP because the threshold differs per
                    // status and lives in config; a SQL expression would
                    // hard-code what §5.4b says must stay configuration.
                    ->query(fn (Builder $query): Builder => $query->whereKey(
                        NusukPermit::open()->get()
                            ->filter(fn (NusukPermit $permit): bool => $permit->isStalled())
                            ->modelKeys(),
                    )),
            ])
            // Grouped behind one control rather than laid out along the
            // row. Seven columns and three buttons do not fit a laptop
            // screen: rendered flat, "Move on" — the one thing this screen
            // exists to do — sat past the right edge behind a horizontal
            // scroll.
            ->recordActions([
                ActionGroup::make([
                    self::assignAction(),
                    self::advanceAction(),
                    self::rerequestAction(),
                ]),
            ])
            ->emptyStateHeading('No permits requested')
            ->emptyStateDescription('Umrah permits are opened from a booking. Rawdah slots are asked for one at a time — the slots are scarce, and requesting one for somebody who did not ask spends a slot another pilgrim needed.');
    }

    /**
     * Who a permit can be assigned to.
     *
     * Named roles rather than `User::permission('permit.update')`, because
     * Super Admin holds no permission rows at all — it reaches everything
     * through `Gate::before` — so a permission query would silently leave
     * out the one account that certainly exists. Two roles rather than the
     * visa screen's one: at an operator this size the Operations Manager
     * may be the only person sitting in front of Nusuk.
     *
     * @return array<int|string, string>
     */
    private static function officers(): array
    {
        return User::role([Access::VISA_STAFF, Access::OPERATIONS_MANAGER])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** Work with no name against it is work nobody does. */
    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Assign')
            ->icon('heroicon-o-user')
            ->visible(fn (NusukPermit $record): bool => ! $record->isClosed()
                && auth()->user()?->can('permit.update') === true)
            ->schema([
                Select::make('assigned_to')
                    ->label('Officer')
                    ->options(fn (): array => self::officers())
                    ->required(),
            ])
            ->action(function (NusukPermit $record, array $data): void {
                $record->forceFill(['assigned_to' => $data['assigned_to']])->save();

                Notification::make()->success()->title('Assigned')->send();
            });
    }

    /**
     * Moving a stage, through the state machine and never around it.
     *
     * Requesting is refused outright when the departure's accommodation and
     * transport are not recorded. That refusal is the point of the gate: a
     * request Nusuk will bounce for a reason we could see from here is a
     * wasted round trip and a status nobody can read.
     */
    private static function advanceAction(): Action
    {
        return Action::make('advance')
            ->label('Move on')
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->visible(fn (NusukPermit $record): bool => ! $record->isClosed()
                && auth()->user()?->can('permit.update') === true)
            ->schema([
                Select::make('to')
                    ->label('To')
                    ->options(fn (NusukPermit $record): array => array_combine(
                        NusukPermit::TRANSITIONS[$record->status] ?? [],
                        array_map(
                            fn (string $s): string => ucfirst(str_replace('_', ' ', $s)),
                            NusukPermit::TRANSITIONS[$record->status] ?? [],
                        ),
                    ))
                    ->required()
                    ->live(),

                TextInput::make('reference')
                    ->label('Nusuk reference')
                    ->maxLength(255)
                    ->helperText('Whatever Nusuk gave back. Leave it blank rather than inventing one.'),

                Select::make('document_id')
                    ->label('Evidence')
                    ->options(fn (NusukPermit $record): array => Document::where('traveller_id', $record->traveller_id)
                        ->get()
                        ->mapWithKeys(fn (Document $d): array => [$d->getKey() => ucfirst($d->type)])
                        ->all())
                    ->searchable()
                    ->helperText('The permit itself, the confirmation, the refusal — whatever was actually seen.'),

                Textarea::make('reason')
                    ->label('Note')
                    ->rows(2)
                    ->required(fn (callable $get): bool => $get('to') === NusukPermit::REFUSED)
                    ->helperText('Required for a refusal: the stated reason is what the next attempt has to answer.'),
            ])
            ->action(function (NusukPermit $record, array $data): void {
                if (filled($data['reference'] ?? null)) {
                    $record->forceFill(['reference' => $data['reference']])->save();
                }

                try {
                    $record->transitionTo(
                        (string) $data['to'],
                        $data['reason'] ?: null,
                        null,
                        isset($data['document_id']) ? Document::find($data['document_id']) : null,
                    );
                } catch (PrerequisitesNotMet $e) {
                    // The gate, said out loud. Silently failing here would
                    // leave a permit sitting at "not started" with nobody
                    // able to say why.
                    Notification::make()
                        ->danger()
                        ->title('Not yet')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title('Moved on')->send();
            });
    }

    /** A refusal ends a permit; asking again is a new attempt. */
    private static function rerequestAction(): Action
    {
        return Action::make('rerequest')
            ->label('Ask again')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (NusukPermit $record): bool => $record->status === NusukPermit::REFUSED
                && auth()->user()?->can('permit.create') === true)
            ->requiresConfirmation()
            ->modalDescription('This opens a fresh attempt. The refused permit is kept exactly as it is — its date and its stated reason are the evidence of what was asked and what came back.')
            ->action(function (NusukPermit $record): void {
                $next = app(PermitDesk::class)->rerequest($record);

                Notification::make()
                    ->success()
                    ->title('Attempt '.$next->attempt.' opened')
                    ->send();
            });
    }
}
