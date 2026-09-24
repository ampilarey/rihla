<?php

namespace App\Filament\Resources\HeroBanners;

use App\Filament\Resources\HeroBanners\Pages\CreateHeroBanner;
use App\Filament\Resources\HeroBanners\Pages\EditHeroBanner;
use App\Filament\Resources\HeroBanners\Pages\ListHeroBanners;
use App\Filament\Resources\HeroBanners\Schemas\HeroBannerForm;
use App\Filament\Resources\HeroBanners\Tables\HeroBannersTable;
use App\Models\HeroBanner;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The homepage's hero banners — moved from the Blade admin, §9.2.
 *
 * The first of the six screens §9.2 said would "move across in Phase 3,
 * one at a time", and the one the others follow. Two admin panels meant two
 * navigations, two authorisation styles and two sets of views to keep on
 * the palette; the drift between them is how a free colour picker survived
 * a rebrand.
 *
 * Authorisation is unchanged: `HeroBannerPolicy` and the `heroBanner.*`
 * permissions, and `/staff` and `/admin` both gate on `admin.access`. The
 * same people can do the same things, in one place.
 */
class HeroBannerResource extends Resource
{
    protected static ?string $model = HeroBanner::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Hero banners';

    protected static UnitEnum|string|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return HeroBannerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HeroBannersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHeroBanners::route('/'),
            'create' => CreateHeroBanner::route('/create'),
            'edit' => EditHeroBanner::route('/{record}/edit'),
        ];
    }
}
