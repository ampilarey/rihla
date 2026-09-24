<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\BlockedDate;
use App\Models\Property;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Nights a room is not for sale.
 *
 * Deliberately separate from occupancy. A blocked night has no stay against
 * it and never will — the partner took it back, the room is being repaired
 * — and it is not freed when a hold lapses. Counting the two together would
 * make the room's quantity mean something different on those days.
 */
class BlockedDatesRelationManager extends RelationManager
{
    protected static string $relationship = 'blockedDates';

    protected static ?string $title = 'Calendar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('room_type_id')
                    ->label('Room')
                    ->options(fn (): array => $this->roomOptions())
                    ->required()
                    ->searchable(),

                DatePicker::make('date')
                    ->label('Night')
                    ->native(false)
                    ->required()
                    ->helperText('One night at a time. The night somebody checks out is not a night, so it does not need blocking.'),

                Select::make('source')
                    ->label('Who blocked it')
                    ->options([
                        BlockedDate::ADMIN => 'Rihla',
                        BlockedDate::PARTNER => 'The partner asked',
                        BlockedDate::ICAL => 'An imported calendar',
                    ])
                    ->default(BlockedDate::ADMIN)
                    ->required(),

                TextInput::make('note')
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Why. A blocked night with no reason is one nobody dares unblock.'),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('roomType.name')->label('Room')->wrap(),

                TextColumn::make('date')->label('Night')->date('D j M Y')->sortable(),

                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        BlockedDate::PARTNER => 'Partner',
                        BlockedDate::ICAL => 'Calendar feed',
                        default => 'Rihla',
                    }),

                TextColumn::make('note')->placeholder('—')->wrap()->toggleable(),
            ])
            ->defaultSort('date')
            ->filters([
                SelectFilter::make('source')->options([
                    BlockedDate::ADMIN => 'Rihla',
                    BlockedDate::PARTNER => 'The partner asked',
                    BlockedDate::ICAL => 'An imported calendar',
                ]),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('Nothing blocked')
            ->emptyStateDescription('Every night is for sale, up to the number of each room the property has.');
    }

    /** See the note on RatesRelationManager. */
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
}
