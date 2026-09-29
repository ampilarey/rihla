<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\Property;
use App\Models\PropertyUnit;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The rooms with doors — §16.5. Used by both panels.
 *
 * A room type's quantity is what is sold; units are what exists. They are
 * not forced to agree (a room under repair is a unit that is not sold), so
 * the table says when they differ rather than refusing a save.
 */
class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    protected static ?string $title = 'Rooms (units)';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('room_type_id')
                ->label('Room type')
                ->required()
                ->options(fn (): array => $this->property()->roomTypes()
                    ->get()
                    ->mapWithKeys(fn ($room): array => [$room->id => $room->getTranslation('name', 'en')])
                    ->all()),
            TextInput::make('label')->label('What reception calls it')->required()->maxLength(60)->placeholder('Room 4'),
            TextInput::make('floor')->maxLength(20),
            Select::make('housekeeping')->options([
                PropertyUnit::CLEAN => 'Clean',
                PropertyUnit::DIRTY => 'Needs cleaning',
                PropertyUnit::INSPECTED => 'Cleaned and checked',
            ])->default(PropertyUnit::CLEAN)->required(),
            Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->columns([
                TextColumn::make('label')->sortable(),
                TextColumn::make('roomType.name')->label('Room type')
                    ->description(fn (PropertyUnit $record): ?string => $this->mismatch($record)),
                TextColumn::make('housekeeping')->badge(),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add a room')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No rooms listed yet')
            ->emptyStateDescription('Add the rooms reception assigns guests to. Bookings still work without them.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    /** "3 sold, 2 in use" — said, not enforced (§16.5). */
    private function mismatch(PropertyUnit $unit): ?string
    {
        $sold = (int) $unit->roomType?->quantity;
        $inUse = PropertyUnit::where('room_type_id', $unit->room_type_id)->where('is_active', true)->count();

        return $sold !== $inUse ? "{$sold} sold, {$inUse} in use" : null;
    }

    private function property(): Property
    {
        /** @var Property */
        return $this->getOwnerRecord();
    }
}
