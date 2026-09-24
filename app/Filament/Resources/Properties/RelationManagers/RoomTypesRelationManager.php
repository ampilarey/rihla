<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Http\Middleware\SetLocale;
use App\Models\Property;
use App\Models\RoomType;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The rooms inside a property, edited inside the property that owns them.
 *
 * `quantity` is the one field here that is not description: it is how many
 * of this room the building physically has, and it is the ceiling the
 * no-double-booking invariant counts against. Phase 9.2 enforces that.
 * Typing 4 where the guesthouse has 3 is how a stay gets sold that nobody
 * can honour, which is why the field says so rather than reading "Quantity".
 *
 * The rate is entered in whole units and stored in minor ones — [R-7]. Every
 * multiplication by 100 in this application goes through App\Support\Money,
 * and these two callbacks are this form's share of that.
 */
class RoomTypesRelationManager extends RelationManager
{
    use EditsTranslations;

    protected static string $relationship = 'roomTypes';

    protected static ?string $title = 'Rooms';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Tabs::make('Translations')
                    ->tabs(array_map($this->localeTab(...), SetLocale::SUPPORTED))
                    ->columnSpanFull(),
            ]),

            Section::make('The room itself')->columns(2)->schema([
                TextInput::make('sleeps')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(12)
                    ->default(2)
                    ->required(),

                TextInput::make('beds')
                    ->maxLength(120)
                    ->helperText('"1 double", "2 singles + 1 sofa bed".'),

                TextInput::make('size_m2')
                    ->label('Size (m²)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(500),

                TextInput::make('quantity')
                    ->label('How many of this room the property has')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(100)
                    ->default(1)
                    ->required()
                    ->helperText('The real count. Rihla will never sell more of this room on one night than this number — and will never refuse a booking it could have taken because this number is too low.'),

                TextInput::make('base_rate_minor')
                    ->label(fn (): string => 'Rate per night ('.$this->currency().')')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    // Entered in whole units, stored in minor — the same
                    // conversion the price tiers use, in the same one place.
                    ->formatStateUsing(fn (?int $state): int => Money::ofMinor((int) $state, $this->currency())->major())
                    ->dehydrateStateUsing(fn (?int $state): int => Money::ofMajor((int) $state, $this->currency())->minor)
                    ->helperText('Before any seasonal rate. In the property\'s currency — a room cannot be quoted in another, because a stay is one payment.'),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers come first.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Room')->wrap(),

                TextColumn::make('sleeps')->alignCenter(),

                TextColumn::make('quantity')->label('How many')->alignCenter()->badge(),

                TextColumn::make('base_rate_minor')
                    ->label('Per night')
                    ->formatStateUsing(fn (?int $state): string => Money::ofMinor((int) $state, $this->currency())->format()),

                TextColumn::make('beds')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => self::withoutEmptyLocales($data, (new RoomType)->translatable)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, RoomType $record): array {
                        $translations = [];

                        foreach ($record->translatable as $attribute) {
                            $translations[$attribute] = $record->getTranslations($attribute);
                        }

                        return self::withTranslationArrays($data, $translations);
                    })
                    ->mutateDataUsing(fn (array $data): array => self::withoutEmptyLocales($data, (new RoomType)->translatable)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No rooms yet')
            ->emptyStateDescription('A property with no rooms cannot be booked. Add what a night in it actually buys.');
    }

    /** The currency belongs to the property, and every rate here is in it. */
    private function currency(): string
    {
        $property = $this->getOwnerRecord();

        return $property instanceof Property ? $property->currency : 'USD';
    }

    private function localeTab(string $locale): Tab
    {
        $required = $locale === 'en';
        $attributes = SetLocale::isRtl($locale) ? ['dir' => 'rtl', 'lang' => $locale] : [];

        return Tab::make(PropertyForm::LOCALES[$locale])
            ->schema([
                TextInput::make("name.{$locale}")
                    ->label('Name')
                    ->required($required)
                    ->maxLength(255)
                    ->extraInputAttributes($attributes),

                Textarea::make("description.{$locale}")
                    ->label('Description')
                    ->rows(3)
                    ->extraInputAttributes($attributes),

                Repeater::make("amenities.{$locale}")
                    ->label('Amenities')
                    ->simple(
                        TextInput::make('item')
                            ->required()
                            ->maxLength(255)
                            ->extraInputAttributes($attributes),
                    )
                    ->addActionLabel('Add an amenity')
                    ->defaultItems(0),
            ]);
    }
}
