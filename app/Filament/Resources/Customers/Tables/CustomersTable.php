<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('phone')->searchable()->toggleable(),

                TextColumn::make('bookings_count')
                    ->label('Bookings')
                    ->counts('bookings')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('tags')
                    ->label('Tags')
                    // The facts the office keeps about a person, on the row
                    // rather than one click in: they are what somebody
                    // needs before picking up the phone.
                    ->state(fn (Customer $record): string => $record->tags->pluck('tag')->implode(', ') ?: '—')
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('referrer.name')
                    ->label('Referred by')
                    ->state(fn (Customer $record): string => $record->referrer->name
                        ?? match ($record->referral_source) {
                            null, '' => '—',
                            'google' => 'Found us online',
                            'facebook' => 'Facebook',
                            'instagram' => 'Instagram',
                            'walk_in' => 'Walked in',
                            'returning' => 'Travelled before',
                            default => 'Somewhere else',
                        })
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                Filter::make('referred')
                    ->label('Came from a referral')
                    ->query(self::onlyReferred(...)),
            ])
            ->recordActions([ViewAction::make()->label('Everything about them')])
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('A customer appears here the first time somebody books, or when historical records are imported.');
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private static function onlyReferred(Builder $query): Builder
    {
        return $query->whereNotNull('referred_by_customer_id');
    }
}
