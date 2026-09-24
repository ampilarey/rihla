<?php

namespace App\Filament\Resources\Media\Tables;

use App\Models\Media;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MediaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // The model's own thumbnail: YouTube's for a YouTube video,
                // the stored one for a photograph, nothing otherwise.
                ImageColumn::make('thumbnail_url')->label('')->height(48),

                TextColumn::make('title')
                    ->label('Title')
                    ->wrap()
                    ->searchable()
                    ->placeholder('Untitled'),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === Media::TYPE_VIDEO ? 'info' : 'gray'),

                TextColumn::make('trip.title')->label('Trip')->placeholder('Standalone')->toggleable(),

                ToggleColumn::make('is_published')
                    ->label('In the gallery')
                    ->disabled(fn (): bool => auth()->user()?->can('media.update') !== true),

                TextColumn::make('sort_order')->label('Order')->sortable()->toggleable(),

                TextColumn::make('created_at')->label('Added')->date()->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options([Media::TYPE_PHOTO => 'Photos', Media::TYPE_VIDEO => 'Videos']),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No photos or videos yet');
    }
}
