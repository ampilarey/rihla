<?php

namespace App\Filament\Resources\GuideSteps;

use App\Filament\Resources\GuideSteps\Pages\CreateGuideStep;
use App\Filament\Resources\GuideSteps\Pages\EditGuideStep;
use App\Filament\Resources\GuideSteps\Pages\ListGuideSteps;
use App\Filament\Resources\GuideSteps\Schemas\GuideStepForm;
use App\Filament\Resources\GuideSteps\Tables\GuideStepsTable;
use App\Models\GuideStep;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The step-by-step Umrah guide — moved from the Blade admin, §9.2.
 *
 * Authorisation is unchanged: `GuideStepPolicy` and the `guide.*`
 * permissions, and `/staff` and `/admin` both gate on `admin.access`.
 */
class GuideStepResource extends Resource
{
    protected static ?string $model = GuideStep::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Umrah guide';

    protected static ?string $modelLabel = 'guide step';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return GuideStepForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GuideStepsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuideSteps::route('/'),
            'create' => CreateGuideStep::route('/create'),
            'edit' => EditGuideStep::route('/{record}/edit'),
        ];
    }
}
