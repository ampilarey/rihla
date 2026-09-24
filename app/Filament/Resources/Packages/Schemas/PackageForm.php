<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\Package;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The package form, in both languages.
 *
 * Fields are named `title.en` / `title.dv` and bind to the whole translation
 * array, which App\Filament\Concerns\EditsTranslations puts into the form
 * state. Binding to `title` alone would edit whichever language the staff
 * member's own locale happens to be and silently discard the other.
 *
 * English is required and Dhivehi is not, throughout. Per AGENTS.md the
 * Dhivehi on this site is being removed rather than trusted, and a field
 * left empty falls back to English — which is the correct outcome. Forcing
 * a Dhivehi value is how machine-translated text got onto the site before.
 */
class PackageForm
{
    /** @var array<string, string> */
    public const LOCALES = ['en' => 'English', 'dv' => 'Dhivehi'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')
                ->description('What this package is. Written once, however many times it runs.')
                ->schema([
                    Tabs::make('Translations')->tabs([
                        self::localeTab('en'),
                        self::localeTab('dv'),
                    ])->columnSpanFull(),
                ]),

            Section::make('Details')->columns(2)->schema([
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('The public URL. Changing it breaks existing links.')
                    // Only on create: a slug that follows the title around
                    // would silently change a live package's URL every time
                    // somebody fixed a typo in the heading.
                    ->default(fn (): string => '')
                    ->live(onBlur: true),

                TextInput::make('nights')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(60)
                    ->helperText('Leave blank to take it from each departure\'s dates.'),

                // §15.3 (Phase 8.7). Live, because the extension fields
                // below appear and become required from this answer.
                Select::make('type')
                    ->label('Kind of package')
                    ->options([
                        Package::UMRAH => 'Umrah',
                        Package::UMRAH_PLUS => 'Umrah Plus — an Umrah with an extension',
                        Package::ISLAND_HOLIDAY => 'Island holiday — a local island, sold to Maldivians',
                    ])
                    ->default(Package::UMRAH)
                    ->required()
                    ->live()
                    ->helperText('Umrah and Umrah Plus sit under the Umrah menu. An island holiday is a guesthouse product and sits under Stays.'),
            ]),

            // Only an Umrah Plus has one, so the block is not there to be
            // half-filled on the other two.
            Section::make('The extension')
                ->description('The segment that makes this an Umrah Plus — a few nights somewhere on the way home.')
                ->columns(2)
                ->visible(fn ($get): bool => $get('type') === Package::UMRAH_PLUS)
                ->schema([
                    TextInput::make('extension_destination')
                        ->label('Where')
                        ->maxLength(120)
                        // Required only when the block is showing: a package
                        // saved as an Umrah must not be held up by a field
                        // its type does not have.
                        ->required(fn ($get): bool => $get('type') === Package::UMRAH_PLUS)
                        ->helperText('"Istanbul, Türkiye". This is what the package page shows first.'),

                    TextInput::make('extension_nights')
                        ->label('How many nights')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(30),

                    Textarea::make('extension_details')
                        ->label('What the extension includes')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),

            Section::make('Publishing')->columns(2)->schema([
                Select::make('accessibility_rating')
                    ->options([
                        'easy' => 'Easy — short walks, lifts throughout',
                        'moderate' => 'Moderate — some walking and stairs',
                        'demanding' => 'Demanding — long walks, stairs, little rest',
                    ])
                    ->helperText('Nobody in this market publishes this. It is the question older pilgrims ask first.'),

                FileUpload::make('cover_image')
                    ->image()
                    ->directory('packages')
                    ->imageEditor(),

                Toggle::make('is_published')
                    ->helperText('An unpublished package is invisible on the site, departures and all.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers come first.'),
            ]),
        ]);
    }

    private static function localeTab(string $locale): Tab
    {
        $required = $locale === 'en';
        $rtl = $locale === 'dv';

        return Tab::make(self::LOCALES[$locale])
            ->schema([
                TextInput::make("title.{$locale}")
                    ->label('Title')
                    ->required($required)
                    ->maxLength(255)
                    ->extraInputAttributes($rtl ? ['dir' => 'rtl', 'lang' => 'dv'] : [])
                    // The slug is derived from the English title only, and
                    // only while creating.
                    ->live(onBlur: $locale === 'en')
                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set) use ($locale): void {
                        if ($locale === 'en' && $operation === 'create' && filled($state)) {
                            $set('slug', Str::slug($state));
                        }
                    }),

                Textarea::make("summary.{$locale}")
                    ->label('Summary')
                    ->rows(2)
                    ->required($required)
                    ->extraInputAttributes($rtl ? ['dir' => 'rtl', 'lang' => 'dv'] : []),

                Textarea::make("details.{$locale}")
                    ->label('Details')
                    ->rows(6)
                    ->extraInputAttributes($rtl ? ['dir' => 'rtl', 'lang' => 'dv'] : []),

                // A list, not prose. "What's included" is the most asked
                // question about an Umrah package and burying it in a
                // paragraph means nobody reads it.
                Repeater::make("inclusions.{$locale}")
                    ->label('What is included')
                    ->simple(
                        TextInput::make('item')
                            ->required()
                            ->maxLength(255)
                            ->extraInputAttributes($rtl ? ['dir' => 'rtl', 'lang' => 'dv'] : []),
                    )
                    ->addActionLabel('Add an inclusion')
                    ->reorderable()
                    ->defaultItems(0),

                Repeater::make("exclusions.{$locale}")
                    ->label('What is not included')
                    ->simple(
                        TextInput::make('item')
                            ->required()
                            ->maxLength(255)
                            ->extraInputAttributes($rtl ? ['dir' => 'rtl', 'lang' => 'dv'] : []),
                    )
                    ->addActionLabel('Add an exclusion')
                    ->reorderable()
                    ->defaultItems(0),

                Repeater::make("accessibility_notes.{$locale}")
                    ->label('Accessibility notes')
                    ->simple(
                        TextInput::make('item')
                            ->maxLength(255)
                            ->extraInputAttributes($rtl ? ['dir' => 'rtl', 'lang' => 'dv'] : []),
                    )
                    ->addActionLabel('Add a note')
                    ->defaultItems(0)
                    ->helperText('Walking distances, stairs, wheelchair suitability.'),
            ]);
    }
}
