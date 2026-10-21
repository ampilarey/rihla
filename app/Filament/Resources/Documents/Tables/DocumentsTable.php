<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\Reading\Acceptor;
use App\Services\Documents\Reading\Duplicates;
use App\Services\Documents\Reading\ReaderManager;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DocumentsTable
{
    private const COLOURS = [
        Document::PENDING => 'warning',
        Document::VERIFIED => 'success',
        Document::REJECTED => 'danger',
    ];

    /**
     * The proposed fields a reading may put on the record, as the form
     * labels them (batch B from Hijaz).
     *
     * @var array<string, string>
     */
    private const READING_LABELS = [
        'full_name' => 'Full name',
        'national_id' => 'ID number',
        'passport_number' => 'Passport number',
        'gender' => 'Gender (male or female)',
        'date_of_birth' => 'Date of birth',
        'nationality' => 'Nationality (2 letters)',
        'passport_issuing_country' => 'Issuing country (2 letters)',
        'passport_expiry' => 'Passport expiry',
        'id_expiry' => 'ID card expiry',
        'number' => 'Visa number',
        'issued_on' => 'Visa issued on',
        'expires_on' => 'Visa expiry',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('traveller.full_name')
                    ->label('Traveller')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->description(fn (Document $record): string => ucfirst($record->category)),

                // What the reader made of the current file: something to
                // accept, accepted, or nothing. A photo shows its check. On
                // a phone this is the column that matters, so it sits
                // before the status and the version count gives way to it.
                TextColumn::make('reading')
                    ->label('Reading')
                    ->badge()
                    ->state(fn (Document $record): ?string => self::readingState($record))
                    ->placeholder('—')
                    ->color(fn (Document $record): string => self::readingColour($record)),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->counts('versions')
                    ->alignCenter()
                    ->badge()
                    // Two or more means the document was replaced, which is
                    // exactly the case somebody chasing a visa needs to see.
                    ->color(fn (?int $state): string => ($state ?? 0) > 1 ? 'info' : 'gray')
                    ->visibleFrom('sm'),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Document $record): string => $record->expiresWithinWindow() ? 'danger' : 'gray')
                    ->description(fn (Document $record): ?string => $record->expiresWithinWindow()
                        ? 'Inside the '.config('documents.passport_validity_months').'-month window'
                        : null),

                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Document::STATUSES, array_map('ucfirst', Document::STATUSES)))
                    ->multiple(),

                Filter::make('reading')
                    ->label('Readings to accept')
                    ->query(fn ($query) => $query->whereHas('versions', fn ($version) => $version
                        ->whereNull('superseded_at')
                        ->whereHas('extractions', fn ($extraction) => $extraction
                            ->where('status', DocumentExtraction::PROPOSED)
                            ->whereNotNull('fields')))),

                Filter::make('expiring')
                    ->label('Expiring soon')
                    // The generic is named for the same reason
                    // PackageComparisonController names its own: the closure
                    // is handed a builder typed for the base Model, which
                    // has no expiringBefore() scope.
                    ->query(function ($query): void {
                        /** @var Builder<Document> $query */
                        $query->expiringBefore(
                            now()->addMonths((int) config('documents.passport_validity_months', 6)),
                        );
                    }),
            ])
            ->recordActions([
                // A fresh signed URL each time the row is drawn, valid for
                // minutes. The link is the only thing between a passport scan
                // and whoever ends up holding the URL.
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record): bool => auth()->user()?->can('document.download') === true
                        && $record->load('versions')->currentVersion() !== null)
                    ->url(fn (Document $record): string => app(DocumentWallet::class)
                        ->downloadUrl($record->load('versions')->currentVersion()), shouldOpenInNewTab: true),

                Action::make('verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Document $record): bool => $record->status !== Document::VERIFIED
                        && auth()->user()?->can('document.update') === true)
                    ->requiresConfirmation()
                    ->action(function (Document $record): void {
                        app(DocumentWallet::class)->verify($record);

                        Notification::make()->success()->title('Document verified')->send();
                    }),

                Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Document $record): bool => $record->status !== Document::REJECTED
                        && auth()->user()?->can('document.update') === true)
                    ->schema([
                        Textarea::make('reason')->label('What is wrong with it')->required()->rows(2),
                    ])
                    ->action(function (Document $record, array $data): void {
                        app(DocumentWallet::class)->reject($record, $data['reason']);

                        Notification::make()->warning()->title('Document rejected')->send();
                    }),

                self::acceptReadingAction(),
                self::ignoreReadingAction(),
                self::readAgainAction(),

                EditAction::make()->label('Open'),
            ])
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Passports, photographs and slips, kept per traveller. Every replacement is a new version — nothing is overwritten.');
    }

    // ── Readings — batch B from Hijaz ─────────────────────────────────────

    /**
     * Staff look at the scan beside what the reader proposed and say yes.
     * Every box starts with the proposal; the helper text says what the
     * record holds now; a blank box leaves the record as it is. Only then
     * does anything reach the traveller.
     */
    private static function acceptReadingAction(): Action
    {
        return Action::make('acceptReading')
            ->label('Accept reading')
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->visible(fn (Document $record): bool => auth()->user()?->can('document.update') === true
                && $record->latestReading()?->hasProposal() === true)
            ->modalHeading(fn (Document $record): string => 'Accept what the scan says: '.$record->typeLabel().' of '.$record->traveller->full_name)
            ->modalDescription(fn (Document $record): ?string => self::readingDescription($record))
            ->modalSubmitActionLabel('Accept')
            ->schema(fn (Document $record): array => self::readingSchema($record))
            ->action(function (Document $record, array $data): void {
                $reading = $record->latestReading();

                if ($reading === null || ! $reading->hasProposal()) {
                    Notification::make()->warning()->title('Nothing left to accept')->send();

                    return;
                }

                $verify = (bool) ($data['verify'] ?? false);
                $issueVisa = (bool) ($data['issue_visa'] ?? false);
                unset($data['verify'], $data['issue_visa']);

                $traveller = app(Acceptor::class)->accept($reading, $data, $verify, $issueVisa);
                $mismatches = $traveller->unexplainedMismatches();

                Notification::make()
                    ->success()
                    ->title('Reading accepted')
                    ->body($mismatches === [] ? null : 'The ID card and the passport disagree on '
                        .implode(', ', array_map(fn (array $mismatch): string => str_replace('_', ' ', (string) $mismatch['field']), $mismatches))
                        .'. Correct a reading, or explain it on the traveller.')
                    ->persistent()
                    ->send();
            });
    }

    private static function ignoreReadingAction(): Action
    {
        return Action::make('ignoreReading')
            ->label('Ignore reading')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->visible(fn (Document $record): bool => auth()->user()?->can('document.update') === true
                && $record->latestReading()?->hasProposal() === true)
            ->requiresConfirmation()
            ->modalDescription('The proposed fields are set aside and nothing is written to the record. The scan stays as it is.')
            ->action(function (Document $record): void {
                $reading = $record->latestReading();

                if ($reading !== null) {
                    app(Acceptor::class)->reject($reading);
                }

                Notification::make()->success()->title('Reading set aside')->send();
            });
    }

    private static function readAgainAction(): Action
    {
        return Action::make('readAgain')
            ->label('Read again')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (Document $record): bool => auth()->user()?->can('document.update') === true
                && ReaderManager::readable((string) $record->type)
                && $record->load('versions')->currentVersion() !== null
                && app(ReaderManager::class)->proposes())
            ->action(function (Document $record): void {
                $version = $record->load('versions')->currentVersion();

                if ($version === null) {
                    return;
                }

                $reading = app(ReaderManager::class)->read($version);

                Notification::make()
                    ->{$reading->hasProposal() ? 'success' : 'warning'}()
                    ->title($reading->hasProposal() ? count($reading->fields ?? []).' fields proposed' : 'Nothing could be read')
                    ->body($reading->error)
                    ->send();
            });
    }

    /** The badge, or nothing at all when nothing has read the file. */
    private static function readingState(Document $record): ?string
    {
        $words = self::readingWords($record);

        return $words === '—' ? null : $words;
    }

    private static function readingWords(Document $record): string
    {
        $reading = $record->latestReading();

        return match (true) {
            $reading === null => '—',
            $reading->reader === 'photo-check' => $reading->error === null ? 'Photo looks right' : 'Photo: check',
            $reading->hasProposal() => 'To accept',
            $reading->status === DocumentExtraction::ACCEPTED => 'Accepted',
            $reading->status === DocumentExtraction::REJECTED => 'Set aside',
            default => 'Nothing read',
        };
    }

    private static function readingColour(Document $record): string
    {
        return match (self::readingWords($record)) {
            'To accept' => 'warning',
            'Accepted', 'Photo looks right' => 'success',
            'Photo: check' => 'danger',
            default => 'gray',
        };
    }

    private static function readingDescription(Document $record): ?string
    {
        $reading = $record->latestReading();

        if ($reading === null) {
            return null;
        }

        $parts = ['Read by '.$reading->reader.'.'];

        if ($record->type === Document::PASSPORT) {
            $parts[] = match ($reading->mrz_valid) {
                true => 'The MRZ check digits pass.',
                false => 'The MRZ check digits FAIL: look at the number and the dates before accepting.',
                default => 'No machine-readable zone was read.',
            };
        }

        $low = $reading->lowConfidenceFields();

        if ($low !== []) {
            $parts[] = 'The reader was unsure of: '.implode(', ', array_map(fn (string $field): string => str_replace('_', ' ', $field), $low)).'.';
        }

        $warning = Duplicates::warningFor($record->traveller, $reading->fields ?? []);

        if ($warning !== null) {
            $parts[] = '⚠ '.$warning;
        }

        $parts[] = 'Blank a box to leave the record as it is.';

        return implode(' ', $parts);
    }

    /** @return list<Component> */
    private static function readingSchema(Document $record): array
    {
        $reading = $record->latestReading();
        $fields = $reading === null ? [] : ($reading->fields ?? []);
        $low = $reading === null ? [] : $reading->lowConfidenceFields();
        $traveller = $record->traveller;
        $type = (string) $record->type;
        $allowed = Acceptor::TRAVELLER_FIELDS[$type] ?? [];
        $expiry = Acceptor::EXPIRY_FIELD[$type] ?? null;
        $wanted = [...$allowed, ...($expiry === null ? [] : [$expiry]), ...($type === Document::VISA ? ['number', 'issued_on'] : [])];
        $components = [];

        foreach (array_unique($wanted) as $field) {
            if (! isset($fields[$field])) {
                continue;
            }

            $now = match (true) {
                $field === $expiry => $record->expires_at?->format('Y-m-d'),
                in_array($field, $allowed, true) => self::stringify($traveller->getAttribute($field)),
                default => null,
            };

            $components[] = TextInput::make($field)
                ->label(self::READING_LABELS[$field])
                ->default((string) $fields[$field])
                ->maxLength(120)
                ->helperText(($now === null || $now === '' ? 'Now: blank' : 'Now: '.$now)
                    .(in_array($field, $low, true) ? ' · the reader was unsure of this one' : ''));
        }

        $components[] = Checkbox::make('verify')
            ->label('Also mark the document verified')
            ->default(false);

        if ($type === Document::VISA && Acceptor::openVisaApplication($traveller) !== null) {
            $components[] = Checkbox::make('issue_visa')
                ->label('Mark the visa application issued, with this copy as its evidence')
                ->default(true);
        }

        return $components;
    }

    private static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Carbon => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
