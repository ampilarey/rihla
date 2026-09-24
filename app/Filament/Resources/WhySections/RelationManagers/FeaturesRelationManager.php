<?php

namespace App\Filament\Resources\WhySections\RelationManagers;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Support\ReadableColour;
use App\Models\WhyFeature;
use App\Support\PaletteChoices;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * The feature cards under the "why Rihla" heading — §9.2.
 *
 * Listed on the section's page, as the Blade screen listed them. Each card
 * saved here clears the homepage cache through the model, and a replaced
 * picture is deleted through the model — neither depends on this screen.
 */
class FeaturesRelationManager extends RelationManager
{
    use EditsTranslations;

    protected static string $relationship = 'features';

    protected static ?string $title = 'Feature cards';

    protected static ?string $modelLabel = 'feature card';

    /**
     * The weakest text a card prints: its body, in Tailwind's `gray-600`
     * (`section-why.blade.php`). A card colour is held to contrast against
     * this, because a dark card with grey words on it reads as empty.
     * `WhySectionAdminTest` fails if this stops matching the Tailwind
     * config, so the two cannot drift.
     */
    public const CARD_BODY_TEXT = '#564F66';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Translations')->tabs([
                self::localeTab('en', 'English', required: true),
                self::localeTab('dv', 'Dhivehi', required: false),
            ])->columnSpanFull(),

            TextInput::make('icon')->label('Icon')->maxLength(255)
                ->helperText('A single emoji or symbol. Shown only when there is no picture.'),

            TextInput::make('link_url')->label('Link')->url()->maxLength(500),

            FileUpload::make('image_path')
                ->label('Picture')
                ->image()
                ->disk('public')
                ->directory('why/features')
                ->maxSize(2048),

            Select::make('background_color')
                ->label('Card colour')
                ->options(fn (?WhyFeature $record): array => PaletteChoices::including($record?->background_color))
                ->placeholder('The site default')
                ->rule(ReadableColour::behind(self::CARD_BODY_TEXT, 'The card\'s words')),

            TextInput::make('sort_order')->label('Order')->numeric()->minValue(0)->default(0)->required(),

            Toggle::make('is_active')->label('Showing')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('icon')->label(''),
                TextColumn::make('title')->label('Card')->wrap(),
                ToggleColumn::make('is_active')->label('Showing'),
                TextColumn::make('sort_order')->label('Order'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Add a card')
                    ->mutateDataUsing(fn (array $data): array => self::withoutEmptyLocales($data, (new WhyFeature)->translatable)),
            ])
            ->recordActions([
                EditAction::make()
                    // No fill override: this version of spatie/translatable
                    // returns the whole {en, dv} array from
                    // attributesToArray(), which is what the default fill
                    // reads — so both languages are in the form already.
                    // `WhySectionAdminTest` holds that an English-only edit
                    // keeps the Dhivehi, so a library change that broke it
                    // would be caught rather than trusted.
                    ->mutateDataUsing(fn (array $data, WhyFeature $record): array => self::withoutEmptyLocales($data, $record->translatable)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No cards yet')
            ->emptyStateDescription('Three short reasons to travel with Rihla, shown under the heading on the homepage.');
    }

    private static function localeTab(string $locale, string $label, bool $required): Tab
    {
        $direction = $locale === 'dv' ? ['dir' => 'rtl', 'lang' => 'dv'] : [];

        return Tab::make($label)->schema([
            TextInput::make("title.{$locale}")->label('Heading')->required($required)->maxLength(255)
                ->extraInputAttributes($direction),
            Textarea::make("text.{$locale}")->label('Words')->rows(3)->maxLength(1000)
                ->extraInputAttributes($direction),
            TextInput::make("link_text.{$locale}")->label('Link words')->maxLength(100)
                ->extraInputAttributes($direction),
        ]);
    }
}
