<?php

namespace App\Filament\Resources\HeroBanners\Tables;

use App\Models\HeroBanner;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class HeroBannersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->height(48),

                TextColumn::make('title')
                    ->label('Heading')
                    ->wrap()
                    // Whether it is on the homepage *now* — switched on and
                    // inside its dates. "Showing" alone would say yes about
                    // a banner scheduled for next Ramadan.
                    ->description(fn (HeroBanner $record): string => $record->isCurrentlyVisible()
                        ? 'On the homepage now'
                        : 'Not on the homepage now'),

                // A toggle in the row, as the Blade screen had. Turning a
                // banner off is the most common thing done on this screen,
                // and opening a form to change one switch is friction that
                // leaves stale banners up.
                ToggleColumn::make('is_active')->label('Showing'),

                TextColumn::make('sort_order')->label('Order')->sortable(),
            ])
            // Drag to reorder — the Blade screen's `update-order` endpoint,
            // done the way Filament does it, writing `sort_order`.
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No banners')
            ->emptyStateDescription('With none showing, the homepage uses its own heading instead of a photograph.');
    }
}
