<?php

namespace App\Filament\Resources\Checklists;

use App\Filament\Resources\Checklists\Pages\ListDepartureChecklistItems;
use App\Filament\Resources\Checklists\Schemas\DepartureChecklistItemForm;
use App\Filament\Resources\Checklists\Tables\DepartureChecklistItemsTable;
use App\Models\DepartureChecklistItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Pre-departure checklists — §8.3.
 *
 * The badge counts **overdue** lines, not open ones: "forty things still to
 * do" three months out is normal, "two things past their date" is
 * somebody's morning.
 */
class DepartureChecklistItemResource extends Resource
{
    protected static ?string $model = DepartureChecklistItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Checklists';

    protected static ?string $modelLabel = 'checklist item';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return DepartureChecklistItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartureChecklistItemsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $overdue = DepartureChecklistItem::query()
            ->open()
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', today())
            ->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getBreadcrumb(): string
    {
        return 'Checklists';
    }

    public static function getPages(): array
    {
        return ['index' => ListDepartureChecklistItems::route('/')];
    }
}
