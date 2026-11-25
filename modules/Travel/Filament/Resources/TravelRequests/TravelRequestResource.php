<?php

namespace Modules\Travel\Filament\Resources\TravelRequests;

use App\Exceptions\DeskRefusal;
use App\Filament\Actions\RecordPayment;
use App\Filament\Concerns\AuthorizesThroughCanMethods;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Travel\Filament\Resources\TravelRequests\Pages\ManageTravelRequests;
use Modules\Travel\Models\TravelDocument;
use Modules\Travel\Models\TravelQuoteOption;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Models\VisaProduct;
use Modules\Travel\Services\TravelDocuments;
use Modules\Travel\Services\TravelRequests;
use Modules\Travel\Services\VisaDesk;
use UnitEnum;

/**
 * The trip requests board — docs/TRAVEL_AGENCY_PLAN.md (A1). Requests
 * arrive from "Plan my trip"; staff add priced options, send the quote
 * with an expiry, and record the money once the customer has chosen.
 */
class TravelRequestResource extends Resource
{
    use AuthorizesThroughCanMethods;

    protected static ?string $model = TravelRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Trip requests';

    protected static ?string $modelLabel = 'trip request';

    protected static UnitEnum|string|null $navigationGroup = 'Travel agency';

    protected static ?int $navigationSort = 0;

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

    public static function getNavigationBadge(): ?string
    {
        $waiting = TravelRequest::query()->where('status', TravelRequest::REQUESTED)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function table(Table $table): Table
    {
        $canQuote = fn (): bool => auth()->user()?->can('travel.quote') === true;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['options', 'acceptedOption']))
            ->columns([
                TextColumn::make('reference')->searchable()
                    ->description(fn (TravelRequest $record): string => ucfirst($record->wants())),
                TextColumn::make('trip')->label('Trip')->wrap()
                    ->state(fn (TravelRequest $record): string => ($record->origin ? $record->origin.' → ' : '').$record->destination)
                    ->description(fn (TravelRequest $record): string => $record->depart_on->format('j M Y').($record->return_on ? ' – '.$record->return_on->format('j M Y') : '')
                        .' · '.$record->adults.' adult(s)'.($record->children > 0 ? ', '.$record->children.' child(ren)' : '')),
                TextColumn::make('contact_name')->label('Asked by')->searchable()
                    ->description(fn (TravelRequest $record): string => $record->contact_phone),
                TextColumn::make('options')->label('Options')
                    ->state(fn (TravelRequest $record): string => $record->acceptedOption !== null
                        ? 'Chose: '.$record->acceptedOption->title.' · '.$record->acceptedOption->price()->format()
                        : ($record->options->map(fn (TravelQuoteOption $o): string => $o->title.' · '.$o->price()->format())->join("\n") ?: '—'))
                    ->wrap()
                    ->description(fn (TravelRequest $record): ?string => $record->status === TravelRequest::QUOTED && $record->quote_expires_at !== null
                        ? ($record->quoteIsLive() ? 'Good until '.$record->quote_expires_at->format('j M') : 'Expired '.$record->quote_expires_at->format('j M'))
                        : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => TravelRequest::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        TravelRequest::REQUESTED => 'warning',
                        TravelRequest::QUOTED => 'info',
                        TravelRequest::CANCELLED => 'gray',
                        TravelRequest::ACCEPTED, TravelRequest::PAID => 'primary',
                        default => 'success',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(TravelRequest::STATUSES)])
            ->recordActions([
                Action::make('addOption')
                    ->label('Add option')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (TravelRequest $record): bool => $canQuote() && in_array($record->status, TravelRequest::QUOTABLE, true))
                    ->schema([
                        TextInput::make('title')->label('Option')->required()->maxLength(160)
                            ->placeholder('Emirates via Dubai, 4-star hotel'),
                        Textarea::make('details')->label('What it includes')->rows(3)->maxLength(2000),
                        TextInput::make('price')->label('Price for everybody')->prefix('MVR')->numeric()->integer()->minValue(1)->required(),
                    ])
                    ->action(function (TravelRequest $record, array $data): void {
                        self::attempt(fn () => app(TravelRequests::class)->addOption($record, (string) $data['title'], $data['details'] ?? null, Money::ofMajor((int) $data['price'], $record->currency)), 'Option added');
                    }),
                Action::make('sendQuote')
                    ->label(fn (TravelRequest $record): string => $record->status === TravelRequest::QUOTED ? 'Send again' : 'Send quote')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (TravelRequest $record): bool => $canQuote() && in_array($record->status, TravelRequest::QUOTABLE, true) && $record->options->isNotEmpty())
                    ->modalDescription('The customer is put on the notice queue, and their page lets them choose one option until the quote expires.')
                    ->schema([TextInput::make('days')->label('Good for (days)')->numeric()->integer()->minValue(1)->maxValue(30)->default(TravelRequests::QUOTE_DAYS)->required()])
                    ->action(function (TravelRequest $record, array $data): void {
                        self::attempt(fn () => app(TravelRequests::class)->sendQuote($record, (int) $data['days'], auth()->user()), 'Quote sent');
                    }),
                RecordPayment::action(fn (Model $record): bool => $record instanceof TravelRequest && $record->status === TravelRequest::ACCEPTED),
                Action::make('upload')
                    ->label('Upload document')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (TravelRequest $record): bool => $canQuote() && in_array($record->status, [TravelRequest::PAID, TravelRequest::ISSUED], true))
                    ->modalDescription('Encrypted on the documents disk. The customer downloads it from their trip page.')
                    ->schema([
                        Select::make('kind')->label('What it is')->options(TravelDocument::KINDS)->required()->default('ticket')->native(false),
                        TextInput::make('label')->label('Name it')->maxLength(160)->placeholder('Return flight, Mariyam'),
                        FileUpload::make('file')->label('File')->required()
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize((int) config('documents.max_kilobytes', 8192))
                            // The service encrypts it; Filament never writes it in the clear.
                            ->storeFiles(false),
                    ])
                    ->action(function (TravelRequest $record, array $data): void {
                        self::attempt(fn () => app(TravelDocuments::class)->attach($record, $data['file'], (string) $data['kind'], (string) ($data['label'] ?? ''), auth()->user()), 'Uploaded');
                    }),
                Action::make('issue')
                    ->label('Mark issued')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (TravelRequest $record): bool => $canQuote() && $record->status === TravelRequest::PAID && $record->documents()->exists())
                    ->requiresConfirmation()
                    ->modalDescription(fn (TravelRequest $record): string => $record->documents()->count().' document(s) uploaded. The customer is told their documents are ready.')
                    ->action(function (TravelRequest $record): void {
                        self::attempt(fn () => app(TravelDocuments::class)->markIssued($record, auth()->user()), 'Issued');
                    }),
                Action::make('visa')
                    ->label('Start visa application')
                    ->icon('heroicon-o-identification')
                    ->color('gray')
                    ->visible(fn (TravelRequest $record): bool => $canQuote() && $record->wants_visa && $record->status !== TravelRequest::CANCELLED)
                    ->schema([
                        Select::make('product')->label('Visa')->required()->searchable()
                            ->options(fn (): array => VisaProduct::query()->where('is_active', true)->orderBy('country')->get()
                                ->mapWithKeys(fn (VisaProduct $v): array => [$v->id => $v->label()])->all()),
                        TextInput::make('applicant')->label('Applicant, as on the passport')->required()->maxLength(120),
                    ])
                    ->action(function (TravelRequest $record, array $data): void {
                        self::attempt(fn () => app(VisaDesk::class)->open($record, VisaProduct::query()->findOrFail($data['product']), (string) $data['applicant'], auth()->user()), 'Visa application started');
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TravelRequest $record): bool => $canQuote() && ! in_array($record->status, [TravelRequest::PAID, TravelRequest::CANCELLED], true))
                    ->schema([Textarea::make('reason')->label('Why (the customer sees this)')->required()->maxLength(500)->rows(2)])
                    ->action(function (TravelRequest $record, array $data): void {
                        self::attempt(fn () => app(TravelRequests::class)->cancel($record, (string) $data['reason'], auth()->user()), 'Cancelled');
                    }),
            ])
            ->emptyStateHeading('No trip requests yet')
            ->emptyStateDescription('They arrive from the "Plan my trip" page.');
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
        return ['index' => ManageTravelRequests::route('/')];
    }
}
