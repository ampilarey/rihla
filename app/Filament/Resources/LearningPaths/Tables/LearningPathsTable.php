<?php

namespace App\Filament\Resources\LearningPaths\Tables;

use App\Models\LearningPath;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LearningPathsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Path')->wrap()->searchable(),

                TextColumn::make('audience')
                    ->label('Who it is for')
                    ->badge()
                    ->state(fn (LearningPath $record): string => $record->audienceLabel()),

                TextColumn::make('modules_count')
                    ->label('Modules')
                    ->counts('modules')
                    ->alignCenter(),

                TextColumn::make('published_modules_count')
                    ->label('Live')
                    ->counts('publishedModules')
                    ->alignCenter()
                    // The number a pilgrim would actually see. A path of
                    // eight modules where none is signed off is an empty
                    // path, and the list should say so rather than showing
                    // an eight.
                    ->color(fn (LearningPath $record): string => $record->published_modules_count > 0 ? 'gray' : 'danger'),

                TextColumn::make('is_published')
                    ->label('Offered')
                    ->badge()
                    ->state(fn (LearningPath $record): string => $record->is_published ? 'Offered' : 'Not offered')
                    ->color(fn (LearningPath $record): string => $record->is_published ? 'success' : 'gray'),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('audience')->label('Who it is for')->options(
                    fn (): array => collect(LearningPath::AUDIENCES)
                        ->mapWithKeys(fn (string $a): array => [
                            $a => (new LearningPath(['audience' => $a]))->audienceLabel(),
                        ])
                        ->all(),
                ),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No paths yet')
            ->emptyStateDescription('A path is an ordering of modules for one kind of pilgrim — first time, been before, for families. Optional: the plan works without one.');
    }
}
