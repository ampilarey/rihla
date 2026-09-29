<?php

namespace App\Filament\Host\Resources\Listings;

use App\Filament\Host\Resources\Listings\Pages\CreateListing;
use App\Filament\Host\Resources\Listings\Pages\EditListing;
use App\Filament\Host\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Properties\RelationManagers\AddonsRelationManager;
use App\Filament\Resources\Properties\RelationManagers\BlockedDatesRelationManager;
use App\Filament\Resources\Properties\RelationManagers\CalendarFeedsRelationManager;
use App\Filament\Resources\Properties\RelationManagers\DiscountsRelationManager;
use App\Filament\Resources\Properties\RelationManagers\PhotosRelationManager;
use App\Filament\Resources\Properties\RelationManagers\RatesRelationManager;
use App\Filament\Resources\Properties\RelationManagers\RoomTypesRelationManager;
use App\Filament\Resources\Properties\RelationManagers\UnitsRelationManager;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Models\Property;
use App\Support\HostContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A host's own listings — §16.6, §16 Phase 14.2.
 *
 * Scoped to the tenant by Filament (`partner`), and every action checked
 * again by the shared policies' host branch ({@see HostContext}).
 * The same form and relation managers as `/staff`, with the partner picker
 * and sort order taken away: the tenant is the partner, and the order on
 * the site is Rihla's.
 *
 * A listing a host makes starts as a **draft**; **Submit for approval**
 * puts it in front of Rihla, and nothing is shown to a guest until a
 * person there approves it.
 */
class ListingResource extends Resource
{
    protected static ?string $model = Property::class;

    protected static ?string $slug = 'listings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $navigationLabel = 'Listings';

    protected static ?string $modelLabel = 'listing';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PropertyForm::configure($schema, forHost: true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('island')->placeholder('—'),
                TextColumn::make('approval')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Property::APPROVED => 'success',
                        Property::CHANGES_REQUESTED => 'danger',
                        Property::PENDING => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => self::approvalLabel($state)),
                IconColumn::make('is_published')->label('Shown when approved')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No listings yet')
            ->emptyStateDescription('Add your guesthouse, home or rooms. Rihla checks each listing before guests can see it.');
    }

    public static function approvalLabel(string $state): string
    {
        return match ($state) {
            Property::APPROVED => 'Approved',
            Property::PENDING => 'Waiting for Rihla',
            Property::CHANGES_REQUESTED => 'Changes requested',
            Property::WITHDRAWN => 'Withdrawn',
            default => 'Draft',
        };
    }

    public static function getRelations(): array
    {
        return [
            RoomTypesRelationManager::class,
            UnitsRelationManager::class,
            PhotosRelationManager::class,
            RatesRelationManager::class,
            BlockedDatesRelationManager::class,
            AddonsRelationManager::class,
            CalendarFeedsRelationManager::class,
            DiscountsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'create' => CreateListing::route('/create'),
            'edit' => EditListing::route('/{record}/edit'),
        ];
    }
}
