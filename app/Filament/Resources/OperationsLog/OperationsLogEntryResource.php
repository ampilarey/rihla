<?php

namespace App\Filament\Resources\OperationsLog;

use App\Filament\Resources\OperationsLog\Pages\ListOperationsLogEntries;
use App\Filament\Resources\OperationsLog\Schemas\OperationsLogEntryForm;
use App\Filament\Resources\OperationsLog\Tables\OperationsLogEntriesTable;
use App\Models\OperationsLogEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The daily operations log — §8.3.
 *
 * What happened, ordinarily. Deliberately not an incident: the coach being
 * forty minutes late and the hotel moving the group to the third floor
 * belong here, while things that went *wrong* carry a severity, an owner
 * and a resolution. Keeping them apart is what stops the incident list
 * filling with weather.
 *
 * No navigation badge. A log is not a queue and there is nothing to drive
 * to zero; a number on it would only ever say "somebody wrote things down",
 * which is not news.
 */
class OperationsLogEntryResource extends Resource
{
    protected static ?string $model = OperationsLogEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Daily log';

    protected static ?string $modelLabel = 'log entry';

    protected static ?string $pluralModelLabel = 'log entries';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return OperationsLogEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperationsLogEntriesTable::configure($table);
    }

    /**
     * The breadcrumb root, which otherwise read "Log Entries" while the
     * navigation said "Daily log".
     */
    public static function getBreadcrumb(): string
    {
        return 'Daily log';
    }

    public static function getPages(): array
    {
        return ['index' => ListOperationsLogEntries::route('/')];
    }
}
