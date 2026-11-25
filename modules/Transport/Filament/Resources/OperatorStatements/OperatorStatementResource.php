<?php

namespace Modules\Transport\Filament\Resources\OperatorStatements;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Transport\Filament\Resources\OperatorStatements\Pages\ManageOperatorStatements;
use Modules\Transport\Models\OperatorStatement;
use Modules\Transport\Services\OperatorStatements;
use Modules\Transport\Support\OperatorContext;
use UnitEnum;

/**
 * Operator statements — docs/TRANSPORT_PLAN.md (T5). Rihla's staff issue a
 * month's at once and see every operator's; inside an operator's panel the
 * same screen lists only theirs, read-only.
 */
class OperatorStatementResource extends Resource
{
    protected static ?string $model = OperatorStatement::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Operator statements';

    protected static ?string $modelLabel = 'statement';

    protected static UnitEnum|string|null $navigationGroup = 'Transport';

    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        return OperatorContext::inPanel() ? 'Statements' : 'Operator statements';
    }

    public static function canViewAny(): bool
    {
        // An operator's owner reads them; crew do not see the money.
        return auth()->user()?->can(OperatorContext::inPanel() ? 'transport.manage' : 'transport.viewAny') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $id = OperatorContext::id();

        if ($id !== null) {
            $query->where('partner_id', $id);
        } elseif (OperatorContext::inPanel()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('operator'))
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('operator.name')->label('Operator')->hidden(fn (): bool => OperatorContext::inPanel()),
                TextColumn::make('period_start')->label('Month')->date('F Y')->sortable(),
                TextColumn::make('online_count')->label('Sold online')->alignEnd()
                    ->description(fn (OperatorStatement $record): string => $record->money($record->online_paid_minor)->format()),
                TextColumn::make('commission_minor')->label('Commission')->alignEnd()
                    ->state(fn (OperatorStatement $record): string => $record->money($record->commission_minor)->format()),
                TextColumn::make('payable_minor')->label('Payable to operator')->alignEnd()
                    ->state(fn (OperatorStatement $record): string => $record->money($record->payable_minor)->format()),
                TextColumn::make('desk_count')->label('Sold at their desk')->alignEnd()
                    ->description(fn (OperatorStatement $record): string => $record->money($record->desk_total_minor)->format()),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (OperatorStatement $record) {
                        $pdf = app(OperatorStatements::class)->pdf($record);

                        return response()->streamDownload(fn () => print ($pdf->output()), $record->reference.'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ])
            ->emptyStateHeading('No statements yet')
            ->emptyStateDescription('A month\'s statements are issued once it is over.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageOperatorStatements::route('/')];
    }
}
