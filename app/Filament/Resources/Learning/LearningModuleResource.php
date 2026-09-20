<?php

namespace App\Filament\Resources\Learning;

use App\Filament\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Learning\Pages\EditLearningModule;
use App\Filament\Resources\Learning\Pages\ListLearningModules;
use App\Filament\Resources\Learning\Schemas\LearningModuleForm;
use App\Filament\Resources\Learning\Tables\LearningModulesTable;
use App\Models\LearningModule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Learning Academy's modules — §7.3.
 *
 * The badge counts modules waiting on a scholar, for the reason the other
 * two content resources give: a count of modules says somebody has been
 * writing, which is not news.
 */
class LearningModuleResource extends Resource
{
    protected static ?string $model = LearningModule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Learning modules';

    protected static ?string $modelLabel = 'module';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return LearningModuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LearningModulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\QuizQuestionsRelationManager::class,
            ReferencesRelationManager::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = LearningModule::awaitingAScholar()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getBreadcrumb(): string
    {
        return 'Learning modules';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLearningModules::route('/'),
            'edit' => EditLearningModule::route('/{record}/edit'),
        ];
    }
}
