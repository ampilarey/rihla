<?php

namespace Modules\Travel\Filament\Resources\TravelVisaApplications;

use App\Exceptions\DeskRefusal;
use App\Filament\Concerns\AuthorizesThroughCanMethods;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Travel\Filament\Resources\TravelVisaApplications\Pages\ManageTravelVisaApplications;
use Modules\Travel\Models\TravelDocument;
use Modules\Travel\Models\TravelVisaApplication;
use Modules\Travel\Services\TravelDocuments;
use Modules\Travel\Services\VisaDesk;
use UnitEnum;

/**
 * Visa applications for other countries — docs/TRAVEL_AGENCY_PLAN.md (A3).
 * Opened from a trip request; here staff see the checklist fill up, open
 * what the customer sent, tick paper handed in, submit, and record the
 * embassy's decision.
 */
class TravelVisaApplicationResource extends Resource
{
    use AuthorizesThroughCanMethods;

    protected static ?string $model = TravelVisaApplication::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Visa applications';

    protected static ?string $modelLabel = 'visa application';

    protected static UnitEnum|string|null $navigationGroup = 'Travel agency';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('travel.viewAny') === true;
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

    public static function table(Table $table): Table
    {
        $canQuote = fn (): bool => auth()->user()?->can('travel.quote') === true;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['request', 'product', 'documents']))
            ->columns([
                TextColumn::make('applicant_name')->label('Applicant')->searchable()
                    ->description(fn (TravelVisaApplication $record): string => $record->request->reference.' · '.$record->request->contact_phone),
                TextColumn::make('product')->label('Visa')->state(fn (TravelVisaApplication $record): string => $record->product->label()),
                TextColumn::make('checklist')->label('Documents')
                    ->state(fn (TravelVisaApplication $record): string => $record->received().' of '.count($record->checklist).' in')
                    ->description(fn (TravelVisaApplication $record): ?string => $record->isComplete() ? null
                        : 'Waiting for: '.implode(', ', array_column(array_filter($record->checklist, fn (array $l): bool => $l['received_at'] === null), 'item'))),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => TravelVisaApplication::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        TravelVisaApplication::APPROVED => 'success',
                        TravelVisaApplication::REFUSED => 'danger',
                        TravelVisaApplication::SUBMITTED => 'info',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(TravelVisaApplication::STATUSES)])
            ->recordActions([
                Action::make('open')
                    ->label('Open a document')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (TravelVisaApplication $record): bool => $record->documents->isNotEmpty())
                    ->schema(fn (TravelVisaApplication $record): array => [
                        Select::make('document')->label('Document')->required()
                            ->options($record->documents->mapWithKeys(fn (TravelDocument $d): array => [$d->id => $d->label])->all()),
                    ])
                    ->action(function (TravelVisaApplication $record, array $data) {
                        /** @var TravelDocument $file */
                        $file = $record->documents()->whereKey($data['document'])->firstOrFail();
                        $download = app(TravelDocuments::class)->download($file, auth()->user(), 'staff');

                        return response()->streamDownload(fn () => print ($download['contents']), $download['name'], ['Content-Type' => $download['mime']]);
                    }),
                Action::make('tick')
                    ->label('Handed in')
                    ->icon('heroicon-o-check')
                    ->visible(fn (TravelVisaApplication $record): bool => $canQuote() && $record->status === TravelVisaApplication::COLLECTING && ! $record->isComplete())
                    ->schema(fn (TravelVisaApplication $record): array => [
                        Select::make('item')->label('Which document')->required()
                            ->options(collect($record->checklist)->filter(fn (array $l): bool => $l['received_at'] === null)->map(fn (array $l): string => $l['item'])->all()),
                    ])
                    ->action(function (TravelVisaApplication $record, array $data): void {
                        self::attempt(fn () => app(VisaDesk::class)->tick($record, (int) $data['item'], auth()->user()), 'Ticked');
                    }),
                Action::make('submit')
                    ->label('Submitted')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (TravelVisaApplication $record): bool => $canQuote() && $record->status === TravelVisaApplication::COLLECTING && $record->isComplete())
                    ->requiresConfirmation()
                    ->modalDescription('Record that it has gone to the embassy or the online portal.')
                    ->action(function (TravelVisaApplication $record): void {
                        self::attempt(fn () => app(VisaDesk::class)->submit($record, auth()->user()), 'Submitted');
                    }),
                Action::make('approve')
                    ->label('Approved')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (TravelVisaApplication $record): bool => $canQuote() && $record->status === TravelVisaApplication::SUBMITTED)
                    ->modalDescription('Upload the visa itself on the trip request, as a document.')
                    ->schema([Textarea::make('note')->label('Note for the customer')->maxLength(500)->rows(2)])
                    ->action(function (TravelVisaApplication $record, array $data): void {
                        self::attempt(fn () => app(VisaDesk::class)->decide($record, true, $data['note'] ?? null, auth()->user()), 'Approved');
                    }),
                Action::make('refuse')
                    ->label('Refused')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TravelVisaApplication $record): bool => $canQuote() && $record->status === TravelVisaApplication::SUBMITTED)
                    ->schema([Textarea::make('note')->label('Why (the customer sees this)')->required()->maxLength(500)->rows(2)])
                    ->action(function (TravelVisaApplication $record, array $data): void {
                        self::attempt(fn () => app(VisaDesk::class)->decide($record, false, (string) $data['note'], auth()->user()), 'Refused');
                    }),
            ])
            ->emptyStateHeading('No visa applications yet')
            ->emptyStateDescription('Start one from a trip request.');
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
        return ['index' => ManageTravelVisaApplications::route('/')];
    }
}
