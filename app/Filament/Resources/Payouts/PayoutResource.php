<?php

namespace App\Filament\Resources\Payouts;

use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Models\Payout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every transfer Rihla has made to a host — §16.9, §16 Phase 16. Read
 * only: a payout is recorded against a statement (Host statements →
 * Record payout), and never edited or deleted afterwards.
 */
class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Host payouts';

    protected static UnitEnum|string|null $navigationGroup = 'Stays';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('payout.viewAny') === true;
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
                TextColumn::make('paid_on')->label('Sent on')->date()->sortable(),
                TextColumn::make('partner.name')->label('Host')->searchable(),
                TextColumn::make('amount_minor')->label('Amount')->state(fn (Payout $record): string => $record->amount()->format()),
                TextColumn::make('statement.reference')->label('Statement')->placeholder('—'),
                TextColumn::make('reference')->label('Bank reference')->searchable(),
                TextColumn::make('recordedBy.name')->label('Recorded by')->placeholder('—'),
            ])
            ->defaultSort('paid_on', 'desc')
            ->filters([
                SelectFilter::make('partner')->relationship('partner', 'name')->searchable()->preload(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayouts::route('/')];
    }
}
