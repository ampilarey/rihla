<?php

namespace App\Filament\Resources\Properties\Schemas;

use App\Http\Middleware\SetLocale;
use App\Models\Property;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The property form, in all three languages.
 *
 * Three tabs rather than the package form's two: §15.4 wants a guesthouse
 * page to carry English, Arabic and Dhivehi in one row, because the share
 * kit is a clean URL per language and a foreign visitor reading Arabic is
 * exactly who this line is for.
 *
 * English is required and the other two are not. Per `AGENTS.md`, a field
 * left empty falls back to English — which is the correct outcome, and the
 * only honest one until a human translator fills it in. Forcing a value is
 * how machine-translated Dhivehi reached the live site before.
 */
class PropertyForm
{
    /**
     * One label per locale the site serves.
     *
     * The tabs are built by walking `SetLocale::SUPPORTED` and reading this
     * map — deliberately without a `isset()` guard around it. A fourth
     * locale added to the middleware and not to here should break loudly the
     * first time somebody opens this form, not quietly render one tab fewer:
     * a missing Arabic tab looks exactly like an Arabic tab nobody has
     * filled in, and the property would go live with no way to translate it.
     * `StaysAdminTest` holds the two lists to each other.
     *
     * @var array<string, string>
     */
    public const LOCALES = ['en' => 'English', 'ar' => 'Arabic', 'dv' => 'Dhivehi'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')
                ->description('What a visitor reads. English is required; the others fall back to it.')
                ->schema([
                    Tabs::make('Translations')
                        ->tabs(array_map(self::localeTab(...), SetLocale::SUPPORTED))
                        ->columnSpanFull(),
                ]),

            Section::make('The building')->columns(2)->schema([
                Select::make('partner_id')
                    ->label('Partner')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Whose guesthouse this is. Rihla does not own it.'),

                Select::make('type')
                    ->label('Kind')
                    ->options([
                        Property::GUESTHOUSE => 'Guesthouse — an island, sold to visitors',
                        Property::RENTAL => 'Rental — a room in Malé, booked by the night',
                    ])
                    ->default(Property::GUESTHOUSE)
                    ->required(),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('The public URL, one per language. Changing it breaks every link already shared.'),

                TextInput::make('island')
                    ->maxLength(120)
                    ->helperText('The island filter on the Stays page reads this.'),

                TimePicker::make('check_in_time')->seconds(false),
                TimePicker::make('check_out_time')->seconds(false),

                FileUpload::make('cover_image')
                    ->image()
                    ->directory('properties')
                    ->imageEditor()
                    ->columnSpanFull(),
            ]),

            Section::make('How it is booked')->columns(2)->schema([
                Select::make('currency')
                    ->options(['USD' => 'USD', 'MVR' => 'MVR'])
                    ->default('USD')
                    ->required()
                    ->helperText('The currency belongs to the product, not the reader. Guesthouses are quoted in USD; Malé rooms in MVR.'),

                TextInput::make('min_nights')
                    ->label('Minimum nights')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30)
                    ->default(1),

                Toggle::make('instant_book')
                    ->label('Book without asking the partner')
                    ->helperText('Leave this off unless the partner has given Rihla a written allotment. Availability nobody has promised is not Rihla\'s to sell, and a double-booked holiday is the worst first impression this line can make.'),

                Toggle::make('is_published')
                    ->helperText('An unpublished property is invisible on the site, rooms and all.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers come first.'),
            ]),

            // Stored on the row rather than taken from a constant, because
            // the page prints the policy this stay will actually be held to.
            // A constant would move later and reprint a policy the customer
            // never agreed to.
            Section::make('The policy this property is sold under')
                ->description('Defaults are the house standard. Change them only where a partner has signed something else — whatever is here is what the customer is shown and held to.')
                ->columns(3)
                ->schema([
                    TextInput::make('deposit_pct')
                        ->label('Deposit %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(30)
                        ->required(),

                    TextInput::make('balance_days_before')
                        ->label('Balance due, days before check-in')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(120)
                        ->default(14)
                        ->required(),

                    TextInput::make('free_cancel_days')
                        ->label('Free cancellation until, days before')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(120)
                        ->default(14)
                        ->required(),
                ]),
        ]);
    }

    private static function localeTab(string $locale): Tab
    {
        $required = $locale === 'en';
        $rtl = SetLocale::isRtl($locale);
        $attributes = $rtl ? ['dir' => 'rtl', 'lang' => $locale] : [];

        return Tab::make(self::LOCALES[$locale])
            ->schema([
                TextInput::make("name.{$locale}")
                    ->label('Name')
                    ->required($required)
                    ->maxLength(255)
                    ->extraInputAttributes($attributes)
                    // The slug follows the English name only, and only while
                    // creating: a slug that chased the name would change a
                    // live URL every time somebody fixed a typo, and every
                    // link already shared with it would 404.
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
                    ->extraInputAttributes($attributes),

                Textarea::make("description.{$locale}")
                    ->label('Description')
                    ->rows(6)
                    ->extraInputAttributes($attributes),

                Textarea::make("house_rules.{$locale}")
                    ->label('House rules')
                    ->rows(4)
                    ->extraInputAttributes($attributes)
                    ->helperText('What the guesthouse expects. On a local island this is the part a first-time visitor most needs to read.'),

                Textarea::make("check_in_instructions.{$locale}")
                    ->label('Getting there and checking in')
                    ->rows(4)
                    ->extraInputAttributes($attributes)
                    ->helperText('Shown on the confirmation, once the stay is paid for.'),

                Repeater::make("amenities.{$locale}")
                    ->label('Amenities')
                    ->simple(
                        TextInput::make('item')
                            ->required()
                            ->maxLength(255)
                            ->extraInputAttributes($attributes),
                    )
                    ->addActionLabel('Add an amenity')
                    ->reorderable()
                    ->defaultItems(0),
            ]);
    }
}
