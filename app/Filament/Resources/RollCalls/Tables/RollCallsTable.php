<?php

namespace App\Filament\Resources\RollCalls\Tables;

use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Traveller;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RollCallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('moment')
                    ->label('The moment')
                    ->searchable()
                    ->wrap()
                    ->description(fn (RollCall $record): string => $record->taken_at->format('j M Y, H:i')),

                TextColumn::make('departure.date_start')
                    ->label('Departure')
                    ->date('j M Y')
                    ->sortable(),

                TextColumn::make('counted')
                    ->label('Counted')
                    ->alignCenter()
                    ->state(fn (RollCall $record): string => sprintf(
                        '%d of %d',
                        $record->marks->count(),
                        $record->expected()->count(),
                    ))
                    ->color(fn (RollCall $record): string => $record->isComplete() ? 'gray' : 'warning'),

                TextColumn::make('unaccounted')
                    ->label('Unaccounted for')
                    ->alignCenter()
                    // A value, not a placeholder: a placeholder is rendered
                    // with Filament's own muted styling and ignores the
                    // column's colour, so the one cell that has to be red
                    // would come out the palest grey.
                    ->state(function (RollCall $record): string {
                        $missing = $record->unaccountedFor()->count();

                        return $missing === 0 ? 'Nobody' : (string) $missing;
                    })
                    ->badge()
                    ->color(fn (RollCall $record): string => $record->isSettled() ? 'success' : 'danger'),

                TextColumn::make('taker.name')->label('Taken by')->toggleable(),
            ])
            ->defaultSort('taken_at', 'desc')
            ->filters([
                Filter::make('unsettled')
                    ->label('Somebody unaccounted for')
                    ->query(self::onlyUnsettled(...)),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::markAction(),
                    self::whoIsMissingAction(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('No head counts yet')
            ->emptyStateDescription('A count belongs to a moment where somebody could be left behind — boarding at Velana, off the coach in Madinah. There may be three in a day, or none.');
    }

    /**
     * Unsettled counts cannot be found in SQL.
     *
     * "Unaccounted for" is unmarked *plus* marked-absent, and "unmarked" is
     * the difference between a departure's confirmed travellers and the
     * rows that exist — which is a set difference across three tables and a
     * booking status. Narrowing in the database first, then filtering in
     * PHP, keeps it honest at the cost of one query. There are tens of
     * these per trip, not thousands.
     *
     * @param  Builder<RollCall>  $query
     * @return Builder<RollCall>
     */
    private static function onlyUnsettled(Builder $query): Builder
    {
        $unsettled = RollCall::query()
            ->with(['marks.traveller', 'departure'])
            ->get()
            ->filter(fn (RollCall $rollCall): bool => ! $rollCall->isSettled())
            ->modelKeys();

        return $query->whereIn('id', $unsettled);
    }

    /**
     * Mark one person.
     *
     * One at a time rather than a grid, because that is how it happens: a
     * leader at a coach door reads a name, looks up, and taps. A form that
     * has to be filled in for eleven people before any of it is saved is
     * one that gets abandoned halfway and saved as nothing.
     */
    private static function markAction(): Action
    {
        return Action::make('mark')
            ->label('Mark somebody')
            ->icon('heroicon-o-check-circle')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('attendance.update') === true)
            ->schema([
                Select::make('traveller_id')
                    ->label('Who')
                    ->required()
                    ->searchable()
                    // Everybody, not just the unmarked: a leader changes a
                    // mark when somebody turns up two minutes later, and
                    // hiding them would mean the count stays wrong.
                    ->options(fn (RollCall $record): array => $record->expected()
                        ->pluck('full_name', 'id')
                        ->all()),

                Select::make('state')
                    ->label('And?')
                    ->required()
                    ->default(RollCallMark::PRESENT)
                    ->options([
                        RollCallMark::PRESENT => 'Here',
                        RollCallMark::ABSENT => 'Not here',
                        RollCallMark::EXCUSED => 'Not here, and that is known and fine',
                    ]),

                TextInput::make('note')
                    ->maxLength(255)
                    ->helperText('Only if it needs one — "stayed at the hotel with a fever".'),
            ])
            ->action(function (RollCall $record, array $data): void {
                $traveller = Traveller::find($data['traveller_id']);

                // updateOrCreate, because a leader changes a mark when
                // somebody turns up late. The unique index would otherwise
                // throw in their face at the coach door.
                RollCallMark::updateOrCreate(
                    ['roll_call_id' => $record->getKey(), 'traveller_id' => $data['traveller_id']],
                    ['state' => $data['state'], 'note' => $data['note'] ?? null, 'marked_by' => auth()->id()],
                );

                Notification::make()
                    ->success()
                    ->title(($traveller->full_name ?? 'They').' — '.$data['state'])
                    ->send();
            });
    }

    /** The whole point of the screen, as a list of names. */
    private static function whoIsMissingAction(): Action
    {
        return Action::make('missing')
            ->label('Who is not accounted for')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->modalHeading(fn (RollCall $record): string => $record->moment)
            ->modalContent(fn (RollCall $record) => view('filament.roll-call-missing', [
                'rollCall' => $record,
                'unmarked' => $record->unmarked(),
                'absent' => $record->marks->where('state', RollCallMark::ABSENT),
                'excused' => $record->marks->where('state', RollCallMark::EXCUSED),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }
}
