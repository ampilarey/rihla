<?php

namespace App\Filament\Resources\HeroBanners\Schemas;

use App\Filament\Support\ReadableColour;
use App\Models\HeroBanner;
use App\Support\Brand;
use App\Support\HeroBannerStyle;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * The hero banner form — §9.2.
 *
 * A port of the Blade form with two deliberate changes, both about what a
 * person is *allowed* to choose rather than how the page looks:
 *
 *  - **Colours come from the palette, not a picker.** `<input type="color">`
 *    accepted anything, including the gold as a button with white text at
 *    1.49:1 — the one combination the owner has ruled out by name.
 *  - **A button must be readable.** Each call-to-action's text is held to
 *    WCAG AA against its own background when both are solid colours. A
 *    palette alone does not guarantee that: white on cream is two palette
 *    values and 1.05:1.
 *
 * Sizes, weights and radii are Tailwind classes read from
 * {@see HeroBannerStyle}, which Tailwind scans — see that class for why
 * this matters and what went wrong without it.
 */
class HeroBannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Words')
                ->description('English is required. A language left blank falls back to English rather than showing an empty banner.')
                ->schema([
                    Tabs::make('Translations')->tabs([
                        self::localeTab('en', 'English', required: true),
                        // English and Dhivehi, as the Blade form had. Arabic
                        // falls back to English, which is the site-wide rule
                        // until somebody who reads it writes it.
                        self::localeTab('dv', 'Dhivehi', required: false),
                    ])->columnSpanFull(),
                ]),

            Section::make('Where the buttons go')
                ->description('One address per button. The language is added to the front of it automatically, so it is the same in every language.')
                ->columns(2)
                ->schema([
                    TextInput::make('primary_cta_url')->label('Main button link')->maxLength(255),
                    TextInput::make('secondary_cta_url')->label('Second button link')->maxLength(255),
                ]),

            Section::make('Picture')->schema([
                // The responsive 768/1280/1920 variants are made by
                // CoverImageObserver when this saves, and removed when it is
                // replaced or the banner deleted — the controller used to do
                // both, and the observer now does them for every model.
                FileUpload::make('image_path')
                    ->label('Background photograph')
                    ->image()
                    ->disk('public')
                    ->directory('hero')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(8192)
                    ->imageEditor(),

                TextInput::make('overlay_opacity')
                    ->label('Darkening over the photograph')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->default(40)
                    ->helperText('How much the photograph is dimmed so white words stay readable on it. Nothing here can check that for you — it depends on the picture.'),
            ]),

            Section::make('Heading')->columns(3)->schema([
                self::colour('heading_color', 'Colour'),
                self::keepsDefaultWhenEmpty(Select::make('heading_size')->label('Size')->options(HeroBannerStyle::HEADING_SIZES)),
                self::keepsDefaultWhenEmpty(Select::make('heading_weight')->label('Weight')->options(HeroBannerStyle::WEIGHTS)),
            ]),

            Section::make('Line under the heading')->columns(3)->schema([
                self::colour('subheading_color', 'Colour'),
                self::keepsDefaultWhenEmpty(Select::make('subheading_size')->label('Size')->options(HeroBannerStyle::SUBHEADING_SIZES)),
                self::keepsDefaultWhenEmpty(Select::make('subheading_weight')->label('Weight')->options(HeroBannerStyle::WEIGHTS)),
            ]),

            Section::make('Main button')->columns(4)->schema([
                self::colour('primary_cta_bg_color', 'Background')
                    ->rule(self::primaryReadable()),
                self::colour('primary_cta_text_color', 'Words')
                    ->rule(self::primaryReadable()),
                self::keepsDefaultWhenEmpty(Select::make('primary_cta_size')->label('Size')->options(HeroBannerStyle::BUTTON_SIZES)),
                self::keepsDefaultWhenEmpty(Select::make('primary_cta_radius')->label('Corners')->options(HeroBannerStyle::RADII)),
            ]),

            Section::make('Second button')->columns(4)->schema([
                self::colour('secondary_cta_bg_color', 'Background')
                    ->rule(self::secondaryReadable()),
                self::colour('secondary_cta_text_color', 'Words')
                    ->rule(self::secondaryReadable()),
                self::keepsDefaultWhenEmpty(Select::make('secondary_cta_size')->label('Size')->options(HeroBannerStyle::BUTTON_SIZES)),
                self::keepsDefaultWhenEmpty(Select::make('secondary_cta_radius')->label('Corners')->options(HeroBannerStyle::RADII)),
            ]),

            Section::make('When it shows')->columns(2)->schema([
                Toggle::make('is_active')->label('Showing on the homepage')->default(true),
                TextInput::make('sort_order')->label('Order')->numeric()->minValue(0)->default(0)
                    ->helperText('Lower numbers come first.'),
                DateTimePicker::make('start_at')->label('From')->seconds(false)->native(false)
                    ->helperText('Blank means straight away.'),
                DateTimePicker::make('end_at')->label('Until')->seconds(false)->native(false)
                    ->after('start_at')
                    ->helperText('Blank means until somebody turns it off.'),
            ]),
        ]);
    }

    /**
     * A colour chosen from the palette — plus whatever this banner already
     * holds, so a save that changes nothing cannot repaint the homepage.
     * See {@see HeroBannerStyle::coloursIncluding()}.
     */
    private static function colour(string $field, string $label): Select
    {
        return self::keepsDefaultWhenEmpty(
            Select::make($field)
                ->label($label)
                ->options(fn (?HeroBanner $record): array => HeroBannerStyle::coloursIncluding($record?->{$field})),
        );
    }

    /**
     * An empty choice is left out of the save, so the column's own default
     * applies.
     *
     * Every style column is `NOT NULL` with a database default, and that
     * default *is* the site's default — the one `BrandColourTest` holds to
     * contrast. Sending `null` for "the site default" fails the insert;
     * repeating the default here as a form value would make it a second
     * copy of the same fact, to drift from the first. Leaving the field
     * out lets the database answer, which it already does correctly.
     *
     * On an edit, an omitted field is an unchanged field — so emptying a
     * select does not reset a banner. Resetting is choosing the default.
     */
    private static function keepsDefaultWhenEmpty(Select $select): Select
    {
        return $select
            ->placeholder('The site default')
            ->dehydrated(fn (mixed $state): bool => filled($state));
    }

    /**
     * The main button, measured on what renders. The defaults are the
     * column defaults, verbatim — an empty choice is left out of the save
     * and the database supplies these, so they are what a visitor sees.
     * See {@see ReadableColour} for the hole the first version had.
     */
    private static function primaryReadable(): Closure
    {
        return ReadableColour::pair('primary_cta_text_color', 'primary_cta_bg_color', '#ffffff', Brand::WINE);
    }

    private static function secondaryReadable(): Closure
    {
        return ReadableColour::pair('secondary_cta_text_color', 'secondary_cta_bg_color', '#ffffff', HeroBannerStyle::TRANSLUCENT_WHITE);
    }

    private static function localeTab(string $locale, string $label, bool $required): Tab
    {
        $direction = $locale === 'dv' ? ['dir' => 'rtl', 'lang' => 'dv'] : [];

        return Tab::make($label)->schema([
            TextInput::make("title.{$locale}")->label('Heading')->required($required)->maxLength(120)
                ->extraInputAttributes($direction),
            TextInput::make("subtitle.{$locale}")->label('Line under the heading')->maxLength(200)
                ->extraInputAttributes($direction),
            TextInput::make("primary_cta_text.{$locale}")->label('Main button words')->maxLength(60)
                ->extraInputAttributes($direction),
            TextInput::make("secondary_cta_text.{$locale}")->label('Second button words')->maxLength(60)
                ->extraInputAttributes($direction),
        ]);
    }
}
