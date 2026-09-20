<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Pages\Today;
use App\Filament\Resources\Tasks\Pages\ListCrmTasks;
use App\Filament\Resources\Tasks\Schemas\CrmTaskForm;
use App\Filament\Resources\Tasks\Tables\CrmTasksTable;
use App\Models\CrmTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Follow-up tasks — §8.1.
 *
 * The list is here; the day's work is on {@see Today},
 * which is where somebody actually looks. This screen exists to write one,
 * to find an old one, and to see the whole board.
 */
class CrmTaskResource extends Resource
{
    protected static ?string $model = CrmTask::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    protected static ?string $navigationLabel = 'Follow-ups';

    protected static ?string $modelLabel = 'follow-up';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return CrmTaskForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CrmTasksTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListCrmTasks::route('/')];
    }
}
