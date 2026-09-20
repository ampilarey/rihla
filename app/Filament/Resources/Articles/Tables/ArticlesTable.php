<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Models\Article;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('author.name')->placeholder('—')->toggleable(),

                // Three states, not two: a draft has no date, a scheduled
                // article has a future one, and both are invisible for
                // different reasons.
                TextColumn::make('published_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Article $record): string => match (true) {
                        $record->published_at === null => 'Draft',
                        $record->published_at->isFuture() => 'Scheduled '.$record->published_at->diffForHumans(),
                        default => 'Published '.$record->published_at->format('j M Y'),
                    })
                    ->color(fn (Article $record): string => match (true) {
                        $record->published_at === null => 'gray',
                        $record->published_at->isFuture() => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
            ])
            ->defaultSort('published_at', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Nothing written yet')
            ->emptyStateDescription('What to pack, how the visa works, what to expect on arrival.');
    }
}
