<?php

namespace App\Filament\Resources\WhySections\Schemas;

use App\Filament\Support\ReadableColour;
use App\Models\WhySection;
use App\Support\Brand;
use App\Support\PaletteChoices;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * The "why Rihla" section's own fields — §9.2.
 *
 * The colours here are nullable columns, and the homepage falls back to a
 * brand default when one is empty (`section-why.blade.php`). So an empty
 * choice genuinely means "the default" and is saved as null — unlike the
 * hero banner, whose style columns are `NOT NULL` with database defaults.
 * Checked against the schema rather than assumed, because assuming it the
 * other way is what failed the hero banner's first save.
 *
 * Every colour is measured on what it will sit on. The section is on
 * `bg-cream`, so the heading and the line under it are checked against
 * cream; each button is checked as a pair, with an empty side resolved to
 * the default the homepage actually uses.
 */
class WhySectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Words')
                ->description('English is required. A language left blank falls back to English rather than showing an empty block.')
                ->schema([
                    Tabs::make('Translations')->tabs([
                        self::localeTab('en', 'English', required: true),
                        // English and Dhivehi, as the Blade form had.
                        self::localeTab('dv', 'Dhivehi', required: false),
                    ])->columnSpanFull(),
                ]),

            Section::make('Where the buttons go')->columns(2)->schema([
                TextInput::make('primary_cta_url')->label('Main button link')->url()->maxLength(500),
                TextInput::make('secondary_cta_url')->label('Second button link')->url()->maxLength(500),
            ]),

            Section::make('Picture')->schema([
                // A replaced picture is deleted by the model now — the Blade
                // controller did it by hand and this upload would not.
                FileUpload::make('image_path')
                    ->label('Picture above the heading')
                    ->image()
                    ->disk('public')
                    ->directory('why')
                    ->maxSize(2048),
            ]),

            Section::make('Heading colours')
                ->description('Both sit on the section\'s cream background.')
                ->columns(2)
                ->schema([
                    self::colour('title_color', 'Heading')
                        ->rule(ReadableColour::on(Brand::CREAM, 'The heading')),
                    self::colour('subtitle_color', 'Line under the heading')
                        ->rule(ReadableColour::on(Brand::CREAM, 'The line under the heading')),
                ]),

            Section::make('Main button')->columns(2)->schema([
                self::colour('primary_cta_bg_color', 'Background')->rule(self::primaryReadable()),
                self::colour('primary_cta_text_color', 'Words')->rule(self::primaryReadable()),
            ]),

            Section::make('Second button')->columns(2)->schema([
                self::colour('secondary_cta_bg_color', 'Background')->rule(self::secondaryReadable()),
                self::colour('secondary_cta_text_color', 'Words')->rule(self::secondaryReadable()),
            ]),

            Toggle::make('is_active')->label('Showing on the homepage'),
        ]);
    }

    /**
     * The defaults are the ones `section-why.blade.php` falls back to when
     * a colour is empty — what a visitor actually sees.
     */
    private static function primaryReadable(): \Closure
    {
        return ReadableColour::pair('primary_cta_text_color', 'primary_cta_bg_color', Brand::WHITE, Brand::WINE);
    }

    private static function secondaryReadable(): \Closure
    {
        return ReadableColour::pair('secondary_cta_text_color', 'secondary_cta_bg_color', Brand::INK, Brand::WHITE);
    }

    private static function colour(string $field, string $label): Select
    {
        return Select::make($field)
            ->label($label)
            ->options(fn (?WhySection $record): array => PaletteChoices::including($record?->{$field}))
            ->placeholder('The site default');
    }

    private static function localeTab(string $locale, string $label, bool $required): Tab
    {
        $direction = $locale === 'dv' ? ['dir' => 'rtl', 'lang' => 'dv'] : [];

        return Tab::make($label)->schema([
            TextInput::make("title.{$locale}")->label('Heading')->required($required)->maxLength(255)
                ->extraInputAttributes($direction),
            Textarea::make("subtitle.{$locale}")->label('Line under the heading')->rows(2)->maxLength(1000)
                ->extraInputAttributes($direction),
            TextInput::make("primary_cta_text.{$locale}")->label('Main button words')->maxLength(255)
                ->extraInputAttributes($direction),
            TextInput::make("secondary_cta_text.{$locale}")->label('Second button words')->maxLength(255)
                ->extraInputAttributes($direction),
        ]);
    }
}
