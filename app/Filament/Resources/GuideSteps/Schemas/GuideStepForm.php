<?php

namespace App\Filament\Resources\GuideSteps\Schemas;

use App\Models\GuideStep;
use App\Support\GuideStepImage;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * One step of the Umrah guide, in both languages — the same fields and the
 * same limits `GuideStepRequest` held for the Blade form.
 */
class GuideStepForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('step_number')
                    ->label('Step number')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(255)
                    // Unique now that a step is one record rather than one
                    // per language. Two rows sharing a number used to be
                    // how a step was translated.
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'This step number is already taken.'])
                    ->default(fn (): int => (int) GuideStep::max('step_number') + 1),

                Toggle::make('is_published')
                    ->label('On the guide')
                    ->helperText('Off keeps it as a draft.')
                    ->default(true)
                    ->inline(false),

                TextInput::make('title.en')->label('Title')->required()->maxLength(120),

                Textarea::make('summary.en')
                    ->label('Summary')
                    ->required()
                    ->rows(3)
                    ->helperText('Shown on the step card — one or two sentences.')
                    ->columnSpanFull(),

                Textarea::make('details.en')->label('Details')->rows(6)->columnSpanFull(),

                TextInput::make('reference_text.en')
                    ->label('Reference')
                    ->maxLength(500)
                    ->helperText("Qur'an and hadith this step is based on.")
                    ->columnSpanFull(),

                self::lines('fiqh_notes.en', 'Fiqh notes', 500)
                    ->helperText('One note per line — each is shown as a separate point.'),

                self::lines('checklist.en', 'Checklist', 255)->helperText('One item per line.'),
            ]),

            Section::make('The same in both languages')->columns(2)->schema([
                Textarea::make('dua_text')
                    ->label("Du'a")
                    ->rows(3)
                    ->extraInputAttributes(['dir' => 'rtl', 'lang' => 'ar'])
                    ->helperText('Arabic, shown unchanged in both languages.')
                    ->columnSpanFull(),

                TextInput::make('video_url')
                    ->label('Video link')
                    ->url()
                    ->maxLength(255)
                    ->helperText('YouTube or Vimeo.'),

                FileUpload::make('image_path')
                    ->label('Picture')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(6144)
                    ->disk('public')
                    ->directory(GuideStepImage::DIRECTORY)
                    // Resized and re-encoded exactly as the Blade form did.
                    // Left to itself the upload is stored as it arrived.
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => GuideStepImage::store($file))
                    ->helperText('Max 6 MB. Stored as a 1200px WebP with a thumbnail.'),
            ]),

            Section::make('Dhivehi')
                ->description('Left blank, a reader sees the English. AGENTS.md records what machine-filled Dhivehi has already cost this site, and religious text is the worst place to repeat it.')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('title.dv')->label('Title (Dhivehi)')->maxLength(120),
                    Textarea::make('summary.dv')->label('Summary (Dhivehi)')->rows(3)->columnSpanFull(),
                    Textarea::make('details.dv')->label('Details (Dhivehi)')->rows(6)->columnSpanFull(),
                    TextInput::make('reference_text.dv')->label('Reference (Dhivehi)')->maxLength(500)->columnSpanFull(),
                    self::lines('fiqh_notes.dv', 'Fiqh notes (Dhivehi)', 500),
                    self::lines('checklist.dv', 'Checklist (Dhivehi)', 255),
                ]),
        ]);
    }

    /**
     * A list edited as a textarea, one item per line; a JSON array in the
     * column. Blank lines are dropped, so an empty box stores an empty list
     * and the page's "Save" drops that locale rather than storing it.
     */
    private static function lines(string $name, string $label, int $max): Textarea
    {
        return Textarea::make($name)
            ->label($label)
            ->rows(4)
            ->formatStateUsing(fn ($state): ?string => is_array($state) ? implode("\n", $state) : $state)
            ->dehydrateStateUsing(fn ($state): array => self::split($state))
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($max): void {
                foreach (self::split($value) as $line) {
                    if (mb_strlen($line) > $max) {
                        $fail("Each line may be at most {$max} characters.");

                        return;
                    }
                }
            });
    }

    /** @return list<string> */
    public static function split(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'filled'));
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) $value) ?: []),
            'filled',
        ));
    }
}
