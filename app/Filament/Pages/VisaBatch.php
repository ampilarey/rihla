<?php

namespace App\Filament\Pages;

use App\Filament\Support\DepartureOptions;
use App\Models\Departure;
use App\Services\Documents\Reading\VisaBatch as Batch;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use UnitEnum;

/**
 * A departure's visas filed in one go — Hijaz's bulk visa upload.
 *
 * Two screens on one page. The first takes the departure and the files;
 * the second shows every page read, who it was matched to and why, with
 * a box to change or clear the match, and files only what is left
 * matched. Nothing is written until the second button, and the batch
 * is thrown away afterwards whatever happened.
 *
 * @property-read Schema $form
 */
class VisaBatch extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = 'Visas in bulk';

    protected static ?string $title = 'Visas in bulk';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'visa-batch';

    protected string $view = 'filament.pages.visa-batch';

    /** @var array<string, mixed> */
    public array $data = [];

    public string $batch = '';

    public ?int $departureId = null;

    /** @var list<array<string, mixed>> */
    public array $pages = [];

    /** @var array<int, string> */
    public array $candidates = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->can('document.create') === true && $user->can('visa.update') === true;
    }

    public function mount(): void
    {
        app(Batch::class)->sweep();

        $requested = request()->integer('departure');

        $this->form->fill([
            'departure_id' => $requested > 0 && Departure::query()->whereKey($requested)->exists() ? $requested : null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('departure_id')
                    ->label('Departure')
                    ->options(DepartureOptions::all(recentOnly: true))
                    ->searchable()
                    ->required(),

                FileUpload::make('files')
                    ->label('The visas')
                    ->multiple()
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(40960)
                    ->maxFiles(80)
                    ->storeFiles(false)
                    ->required()
                    ->helperText(Batch::canSplit()
                        ? 'One PDF with a page per pilgrim, a PDF per visa, or photos. Up to 40 MB a file.'
                        : 'Photos, or a PDF per visa. This host cannot take a many-page PDF apart (poppler is not installed).'),
            ])
            ->statePath('data');
    }

    /** Read every page and offer each to a traveller. Nothing is filed yet. */
    public function read(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = $this->form->getState();
        $departure = Departure::query()->findOrFail((int) ($data['departure_id'] ?? 0));
        $batch = app(Batch::class);

        $batch->discard($this->batch);
        $token = Batch::newBatch();
        $pages = [];
        $problems = [];

        foreach ((array) ($data['files'] ?? []) as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                $pages = [...$pages, ...$batch->split($token, $file)];
            } catch (RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if ($pages === []) {
            $batch->discard($token);
            $this->reset('batch', 'pages', 'candidates');

            Notification::make()
                ->danger()
                ->title('Nothing to file')
                ->body($problems === [] ? 'No page could be read from those files.' : implode(' ', $problems))
                ->persistent()
                ->send();

            return;
        }

        $this->batch = $token;
        $this->departureId = (int) $departure->getKey();
        $this->pages = $batch->match($departure, $pages);
        $this->candidates = $batch->candidates($departure);

        if ($problems !== []) {
            Notification::make()->warning()->title('Some files were left out')->body(implode(' ', $problems))->persistent()->send();
        }
    }

    /** File what is matched, and let the rest go. */
    public function file(): void
    {
        abort_unless(self::canAccess(), 403);

        if ($this->pages === [] || $this->batch === '' || $this->departureId === null) {
            Notification::make()->warning()->title('Nothing read yet')->send();

            return;
        }

        $departure = Departure::query()->findOrFail($this->departureId);
        $result = app(Batch::class)->file($departure, $this->pages, $this->batch);

        $this->reset('batch', 'pages', 'candidates');
        $this->form->fill(['departure_id' => $departure->getKey()]);

        $notification = Notification::make()
            ->title($result['filed'].' '.($result['filed'] === 1 ? 'visa' : 'visas').' filed'
                .($result['left'] > 0 ? ' · '.$result['left'].' '.($result['left'] === 1 ? 'page' : 'pages').' left out' : ''));

        if ($result['notes'] !== []) {
            $notification->warning()->body(implode(' ', $result['notes']))->persistent();
        } elseif ($result['filed'] === 0) {
            $notification->warning()->body('No page had a traveller chosen.');
        } else {
            $notification->success()->body('Each is in the traveller\'s documents as their visa, verified, and the visa application is marked issued.');
        }

        $notification->send();
    }

    /** Throw the batch away and start over. */
    public function discard(): void
    {
        abort_unless(self::canAccess(), 403);

        app(Batch::class)->discard($this->batch);
        $this->reset('batch', 'pages', 'candidates');
    }

    /** What the page says, in a line. */
    public function describe(array $page): string
    {
        $fields = is_array($page['fields'] ?? null) ? $page['fields'] : [];
        $parts = array_filter([
            isset($fields['number']) ? 'Visa '.$fields['number'] : null,
            isset($fields['passport_number']) ? 'passport '.$fields['passport_number'] : null,
            $fields['full_name'] ?? null,
            isset($fields['expires_on']) ? 'until '.$fields['expires_on'] : null,
        ]);

        return $parts === [] ? 'Nothing could be read on this page.' : implode(' · ', $parts);
    }

    /** @return array{string, string} the badge's words and colour */
    public function matchBadge(array $page): array
    {
        return match ($page['match'] ?? null) {
            Batch::BY_PASSPORT => ['Passport number', 'success'],
            Batch::BY_NAME => ['Name only — check', 'warning'],
            default => ['Nobody matched', 'gray'],
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'matched' => count(array_filter($this->pages, fn (array $page): bool => (int) ($page['traveller_id'] ?? 0) > 0)),
        ];
    }
}
