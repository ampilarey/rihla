<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\Property;
use App\Models\PropertyPhoto;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A listing's gallery — §16.5. Used by both panels.
 *
 * The files are the model's to manage: {@see PropertyPhoto} makes the
 * responsive variants when a photo is saved and deletes the original with
 * them when it is replaced or removed. Nothing here touches the disk.
 *
 * The caption is English only on this screen; a translated caption falls
 * back to it, which is the §15.4 rule for everything a listing says.
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Photos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Photo')
                ->image()
                ->disk('public')
                ->directory('properties/photos')
                ->maxSize(8192)
                ->required()
                ->columnSpanFull(),

            TextInput::make('caption.en')->label('Caption')->maxLength(160),

            Select::make('room_type_id')
                ->label('Which room (if it shows one)')
                ->options(fn (): array => $this->property()->roomTypes()
                    ->get()
                    ->mapWithKeys(fn ($room): array => [$room->id => $room->getTranslation('name', 'en')])
                    ->all()),

            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('path')->label('')->disk('public')->square(),
                TextColumn::make('caption')->placeholder('No caption')->wrap(),
                TextColumn::make('roomType.name')->label('Room')->placeholder('The building'),
            ])
            ->headerActions([CreateAction::make()->label('Add a photo')])
            ->recordActions([
                // Filled with every language, not the reader's one string —
                // otherwise saving an edit would drop the other captions.
                EditAction::make()->mutateRecordDataUsing(fn (array $data, PropertyPhoto $record): array => [
                    ...$data,
                    'caption' => $record->getTranslations('caption'),
                ]),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No photos yet')
            ->emptyStateDescription('The cover is shown first; these follow it in the gallery.');
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
