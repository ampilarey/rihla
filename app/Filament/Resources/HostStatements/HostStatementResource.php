<?php

namespace App\Filament\Resources\HostStatements;

use App\Filament\Resources\HostStatements\Pages\ListHostStatements;
use App\Models\HostStatement;
use App\Services\Hosts\Statements;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Hosts' monthly statements — §16.9, the staff side. Read and download;
 * issuing is the monthly command's, or the button on the list.
 */
class HostStatementResource extends Resource
{
    protected static ?string $model = HostStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Host statements';

    protected static UnitEnum|string|null $navigationGroup = 'Stays';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('partner.viewAny') === true;
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('partner.name')->label('Host')->searchable(),
                TextColumn::make('period_start')->label('Month')->date('F Y')->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('gross_minor')->label('Gross')->state(fn (HostStatement $record): string => $record->money('gross_minor')->format()),
                TextColumn::make('commission_minor')->label('Commission')->state(fn (HostStatement $record): string => $record->money('commission_minor')->format()),
                TextColumn::make('rihla_holds_minor')->label('Rihla holds for them')->state(fn (HostStatement $record): string => $record->money('rihla_holds_minor')->format()),
                TextColumn::make('commission_outstanding_minor')->label('Commission to settle')->state(fn (HostStatement $record): string => $record->money('commission_outstanding_minor')->format()),
            ])
            ->defaultSort('period_start', 'desc')
            ->filters([
                SelectFilter::make('partner')->relationship('partner', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (HostStatement $record): StreamedResponse {
                        $pdf = app(Statements::class)->pdf($record);

                        return response()->streamDownload(fn () => print ($pdf), $record->reference.'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListHostStatements::route('/')];
    }
}
