<?php

namespace App\Filament\Resources\LearningPaths;

use App\Filament\Resources\LearningPaths\Pages\ListLearningPaths;
use App\Filament\Resources\LearningPaths\Schemas\LearningPathForm;
use App\Filament\Resources\LearningPaths\Tables\LearningPathsTable;
use App\Models\LearningPath;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * §7.3's learning paths.
 *
 * No review queue and therefore no badge: a path asserts nothing religious,
 * and everything in it was signed off by a named person before it could be
 * put in. See {@see LearningPath}.
 */
class LearningPathResource extends Resource
{
    protected static ?string $model = LearningPath::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Learning paths';

    protected static ?string $modelLabel = 'path';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return LearningPathForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LearningPathsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListLearningPaths::route('/')];
    }
}
