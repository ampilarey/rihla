<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\Property;
use App\Models\RoomType;
use App\Models\StayDiscount;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A listing's promotions and long-stay discounts — §16 Phase 16. Used by
 * both panels. Changing or deleting one changes quotes from now on; a
 * booked stay keeps the discount frozen into its snapshot.
 */
class DiscountsRelationManager extends RelationManager
{
    protected static string $relationship = 'discounts';

    protected static ?string $title = 'Discounts';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Name guests see')->required()->maxLength(80)->placeholder('Week-long stay'),

            Select::make('kind')->options(StayDiscount::KINDS)->required()->default(StayDiscount::LONG_STAY)->live(),

            TextInput::make('percent')->label('Percent off')->numeric()->integer()->minValue(1)->maxValue(StayDiscount::MAX_PERCENT)->required()
                ->helperText('Up to '.StayDiscount::MAX_PERCENT.'%. If a stay qualifies for two discounts, only the larger applies.'),

            TextInput::make('min_nights')->label('From how many nights')->numeric()->integer()->minValue(2)->maxValue(365)
                ->required(fn (Get $get): bool => $get('kind') === StayDiscount::LONG_STAY),

            DatePicker::make('starts_on')->label('Check-in from')
                ->required(fn (Get $get): bool => $get('kind') === StayDiscount::PROMOTION)
                ->visible(fn (Get $get): bool => $get('kind') === StayDiscount::PROMOTION),

            DatePicker::make('ends_on')->label('Check-in until')->afterOrEqual('starts_on')
                ->required(fn (Get $get): bool => $get('kind') === StayDiscount::PROMOTION)
                ->visible(fn (Get $get): bool => $get('kind') === StayDiscount::PROMOTION),

            Select::make('room_type_id')->label('Room')->placeholder('Every room')
                ->options(fn (): array => $this->property()->roomTypes()->get()
                    ->mapWithKeys(fn (RoomType $room): array => [$room->id => $room->getTranslation('name', 'en')])->all()),

            Select::make('audience')->label('For')->options(['both' => 'Every guest', 'tourist' => 'Visitors', 'local' => 'Maldivians'])->default('both')->required(),

            Toggle::make('is_active')->label('On')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->wrap(),
                TextColumn::make('kind')->formatStateUsing(fn (?string $state): string => StayDiscount::KINDS[$state] ?? '—'),
                TextColumn::make('percent')->suffix('%'),
                TextColumn::make('min_nights')->label('From nights')->placeholder('—'),
                TextColumn::make('window')->label('Check-in window')->state(fn (StayDiscount $record): string => $record->starts_on
                    ? $record->starts_on->format('j M Y').' – '.$record->ends_on?->format('j M Y')
                    : '—'),
                TextColumn::make('roomType.name')->label('Room')->placeholder('Every room'),
                IconColumn::make('is_active')->label('On')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add a discount')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No discounts')
            ->emptyStateDescription('A percentage off for longer stays, or for check-ins in a quiet month.');
    }

    /** An edit page, not a view page — a relation manager on a view page is read-only (AGENTS.md). */
    public function isReadOnly(): bool
    {
        return false;
    }

    private function property(): Property
    {
        /** @var Property */
        return $this->getOwnerRecord();
    }
}
