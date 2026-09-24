<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\Property;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Seasonal prices, across every room in the property.
 *
 * A relation manager on the property rather than on each room, because a
 * guesthouse owner thinks in seasons — "December is this much" — and
 * setting the same dates four times, once per room, is how one of them
 * ends up a month out.
 *
 * **Both ends of a season are inclusive**, unlike a stay's check-out. The
 * form says so on the field, because the two rules genuinely differ and
 * nothing on the screen would otherwise reveal it.
 */
class RatesRelationManager extends RelationManager
{
    protected static string $relationship = 'rates';

    protected static ?string $title = 'Seasonal rates';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('room_type_id')
                    ->label('Room')
                    ->options(fn (): array => $this->roomOptions())
                    ->required()
                    ->searchable(),

                TextInput::make('rate_minor')
                    ->label(fn (): string => 'Rate per night ('.$this->currency().')')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->formatStateUsing(fn (?int $state): int => Money::ofMinor((int) $state, $this->currency())->major())
                    ->dehydrateStateUsing(fn (?int $state): int => Money::ofMajor((int) $state, $this->currency())->minor),

                DatePicker::make('starts_on')
                    ->label('From')
                    ->native(false)
                    ->required(),

                DatePicker::make('ends_on')
                    ->label('To, and including this night')
                    ->native(false)
                    ->required()
                    ->afterOrEqual('starts_on')
                    ->helperText('A season written 1 to 31 December covers the 31st. (A guest\'s check-out date is the one date that is not slept.)'),

                TextInput::make('min_nights')
                    ->label('Minimum nights in this season')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30)
                    ->helperText('Leave blank to use the property\'s own minimum. Where seasons overlap, the highest minimum applies.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('roomType.name')->label('Room')->wrap(),

                TextColumn::make('starts_on')->label('From')->date('j M Y')->sortable(),

                TextColumn::make('ends_on')->label('To')->date('j M Y')->sortable(),

                TextColumn::make('rate_minor')
                    ->label('Per night')
                    ->formatStateUsing(fn (?int $state): string => Money::ofMinor((int) $state, $this->currency())->format()),

                TextColumn::make('min_nights')->label('Min nights')->alignCenter()->placeholder('—'),
            ])
            ->defaultSort('starts_on')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No seasonal rates')
            ->emptyStateDescription('Every night falls back to the room\'s own rate until a season covers it. Where two seasons overlap a night, the one starting later wins.');
    }

    /**
     * A relation manager on a ViewRecord page is read-only by default and
     * says nothing about it — `AGENTS.md` records that its Create action
     * simply does not render, and a test asserting the page loads still
     * passes. The real permission check is the policy.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /** @return array<int, string> */
    private function roomOptions(): array
    {
        $property = $this->getOwnerRecord();

        if (! $property instanceof Property) {
            return [];
        }

        return $property->roomTypes
            ->mapWithKeys(fn ($room): array => [$room->getKey() => (string) $room->name])
            ->all();
    }

    private function currency(): string
    {
        $property = $this->getOwnerRecord();

        return $property instanceof Property ? $property->currency : 'USD';
    }
}
