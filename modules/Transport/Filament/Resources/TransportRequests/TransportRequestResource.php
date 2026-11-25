<?php

namespace Modules\Transport\Filament\Resources\TransportRequests;

use App\Exceptions\DeskRefusal;
use App\Filament\Actions\RecordPayment;
use App\Filament\Concerns\AuthorizesThroughCanMethods;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Transport\Filament\Resources\TransportRequests\Pages\ManageTransportRequests;
use Modules\Transport\Models\TransportRequest;
use Modules\Transport\Services\TransportRequests;
use UnitEnum;

/**
 * Cargo and charter requests — docs/TRANSPORT_PLAN.md (T4). They arrive
 * from the public form; here staff quote them, record the money, and move
 * them along to delivered. Never edited or deleted: every step is a line.
 */
class TransportRequestResource extends Resource
{
    use AuthorizesThroughCanMethods;

    protected static ?string $model = TransportRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Cargo & charters';

    protected static ?string $modelLabel = 'cargo or charter request';

    protected static UnitEnum|string|null $navigationGroup = 'Transport';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('transport.viewAny') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = TransportRequest::query()->where('status', TransportRequest::REQUESTED)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function table(Table $table): Table
    {
        $canBook = fn (): bool => auth()->user()?->can('transport.book') === true;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['origin', 'destination']))
            ->columns([
                TextColumn::make('reference')->searchable()
                    ->description(fn (TransportRequest $record): string => TransportRequest::KINDS[$record->kind] ?? $record->kind),
                TextColumn::make('route')->label('From → to')
                    ->state(fn (TransportRequest $record): string => ($record->origin->name ?? '?').' → '.($record->destination->name ?? '?'))
                    ->description(fn (TransportRequest $record): string => $record->wanted_on->format('D j M Y')),
                TextColumn::make('what')->label('What')->wrap()
                    ->state(fn (TransportRequest $record): string => $record->kind === TransportRequest::CARGO
                        ? (string) $record->cargo_description.($record->cargo_weight_kg ? ' · '.$record->cargo_weight_kg.' kg' : '')
                        : $record->passengers.' people'),
                TextColumn::make('contact_name')->label('Asked by')->searchable()
                    ->description(fn (TransportRequest $record): string => $record->contact_phone),
                TextColumn::make('quote_minor')->label('Quote')->alignEnd()
                    ->state(fn (TransportRequest $record): string => $record->quote()?->format() ?? '—')
                    ->description(fn (TransportRequest $record): ?string => $record->quote() !== null && $record->owed()->minor > 0 ? 'Owed '.$record->owed()->format() : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => TransportRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        TransportRequest::REQUESTED => 'warning',
                        TransportRequest::QUOTED => 'info',
                        TransportRequest::CANCELLED => 'gray',
                        default => 'success',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(TransportRequest::STATUSES),
                SelectFilter::make('kind')->options(TransportRequest::KINDS),
            ])
            ->recordActions([
                Action::make('quote')
                    ->label(fn (TransportRequest $record): string => $record->status === TransportRequest::QUOTED ? 'Quote again' : 'Quote')
                    ->icon('heroicon-o-currency-dollar')
                    ->visible(fn (TransportRequest $record): bool => $canBook() && in_array($record->status, TransportRequest::OPEN, true))
                    ->modalDescription('The asker is put on the notice queue with the price, and their page shows it.')
                    ->schema([
                        TextInput::make('amount')->label('Price')->prefix('MVR')->numeric()->integer()->minValue(1)->required(),
                        Textarea::make('note')->label('What it covers')->maxLength(1000)->rows(2),
                    ])
                    ->action(function (TransportRequest $record, array $data): void {
                        self::attempt(fn () => app(TransportRequests::class)->quote($record, Money::ofMajor((int) $data['amount'], $record->currency), $data['note'] ?? null, auth()->user()), 'Quoted');
                    }),
                RecordPayment::action(fn (Model $record): bool => $record instanceof TransportRequest && $record->status === TransportRequest::QUOTED),
                Action::make('advance')
                    ->label(fn (TransportRequest $record): string => 'Mark '.strtolower(TransportRequest::STATUSES[$record->nextStep() ?? ''] ?? 'done'))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('success')
                    ->visible(fn (TransportRequest $record): bool => $canBook() && $record->nextStep() !== null)
                    ->requiresConfirmation()
                    ->action(function (TransportRequest $record): void {
                        self::attempt(fn () => app(TransportRequests::class)->advance($record, auth()->user()), 'Moved on');
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TransportRequest $record): bool => $canBook() && in_array($record->status, TransportRequest::OPEN, true))
                    ->schema([Textarea::make('reason')->label('Why (the asker sees this)')->required()->maxLength(500)->rows(2)])
                    ->action(function (TransportRequest $record, array $data): void {
                        self::attempt(fn () => app(TransportRequests::class)->cancel($record, (string) $data['reason'], auth()->user()), 'Cancelled');
                    }),
            ])
            ->emptyStateHeading('No cargo or charter requests yet')
            ->emptyStateDescription('They arrive from the "Ask for a price" form on the boats page.');
    }

    private static function attempt(callable $change, string $done): void
    {
        try {
            $change();
            Notification::make()->success()->title($done)->send();
        } catch (DeskRefusal $refusal) {
            Notification::make()->danger()->title($refusal->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageTransportRequests::route('/')];
    }
}
