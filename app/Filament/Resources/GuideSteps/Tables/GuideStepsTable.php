<?php

namespace App\Filament\Resources\GuideSteps\Tables;

use App\Models\GuideStep;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * One list, in guide order. It was two once — one per language — because a
 * step was two rows joined by nothing but a shared step number.
 *
 * The Blade screen's three JSON endpoints (reorder, toggle, bulk status)
 * are the table's own drag handle, row toggle and bulk actions here.
 */
class GuideStepsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('step_number')->label('Step')->sortable(),

                ImageColumn::make('image_path')->label('')->disk('public')->height(40),

                TextColumn::make('title')->label('Title')->wrap()->searchable(),

                // Whether a Dhivehi reader gets their own words or the
                // English fallback — the column the old list had.
                IconColumn::make('in_dhivehi')
                    ->label('Dhivehi')
                    ->boolean()
                    ->state(fn (GuideStep $record): bool => filled($record->getTranslation('title', 'dv', false))),

                ToggleColumn::make('is_published')
                    ->label('On the guide')
                    ->disabled(fn (): bool => auth()->user()?->can('guide.update') !== true),
            ])
            ->reorderable('step_number', fn (): bool => auth()->user()?->can('guide.update') === true)
            ->defaultSort('step_number')
            ->paginated(false)
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::publishing(true),
                    self::publishing(false),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No steps yet')
            ->emptyStateDescription('`php artisan db:seed --class=UmrahGuideSeeder` loads the reviewed guide.');
    }

    private static function publishing(bool $on): BulkAction
    {
        return BulkAction::make($on ? 'publish' : 'unpublish')
            ->label($on ? 'Put on the guide' : 'Take off the guide')
            ->icon($on ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
            ->authorize(fn (): bool => auth()->user()?->can('guide.update') === true)
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($on): void {
                GuideStep::whereKey($records->modelKeys())->update(['is_published' => $on]);
            });
    }
}
