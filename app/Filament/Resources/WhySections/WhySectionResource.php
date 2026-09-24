<?php

namespace App\Filament\Resources\WhySections;

use App\Filament\Resources\WhySections\Pages\EditWhySection;
use App\Filament\Resources\WhySections\Pages\ListWhySections;
use App\Filament\Resources\WhySections\RelationManagers\FeaturesRelationManager;
use App\Filament\Resources\WhySections\Schemas\WhySectionForm;
use App\Models\WhySection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The homepage's "why Rihla" section — moved from the Blade admin, §9.2.
 *
 * There is exactly one section, edited in place, so there is no list and
 * no create: the index forwards to the section's own edit page, creating it
 * with English defaults the first time. Its feature cards are a relation
 * manager on that page, which is where the Blade screen listed them too.
 *
 * Authorisation is unchanged — `WhySectionPolicy` / `WhyFeaturePolicy` and
 * the `whySection.*` / `whyFeature.*` permissions, behind the same
 * `admin.access` gate both panels use.
 */
class WhySectionResource extends Resource
{
    protected static ?string $model = WhySection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Why Rihla section';

    protected static UnitEnum|string|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return WhySectionForm::configure($schema);
    }

    public static function getRelations(): array
    {
        return [FeaturesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhySections::route('/'),
            'edit' => EditWhySection::route('/{record}/edit'),
        ];
    }

    /** One section, edited in place — there is nothing to create. */
    public static function canCreate(): bool
    {
        return false;
    }
}
