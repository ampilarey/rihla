<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BookingsTable
{
    /** The colour each status wears, everywhere it is shown. */
    public const COLOURS = [
        Booking::DRAFT => 'gray',
        Booking::HELD => 'warning',
        Booking::CONFIRMED => 'success',
        Booking::COMPLETED => 'info',
        Booking::EXPIRED => 'gray',
        Booking::CANCELLED => 'danger',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            // On a phone: the reference, who, and where it stands; the
            // departure from 640px, the figures from 768px. Every column is
            // on the booking itself.
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('medium'),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Booking $record): ?string => $record->customer->phone),

                TextColumn::make('departure.date_start')
                    ->visibleFrom('sm')
                    ->label('Departure')
                    ->date('j M Y')
                    ->sortable()
                    ->description(fn (Booking $record): ?string => $record->departure->package?->title),

                TextColumn::make('seats')
                    ->visibleFrom('md')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                // Formatted through Money, never by dividing by 100 here:
                // [R-7] keeps that conversion in exactly one place.
                TextColumn::make('total_minor')
                    ->visibleFrom('md')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state, Booking $record): string => Money::ofMinor($state, $record->currency)->format())
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->visibleFrom('md')
                    ->label('Booked')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            // Newest first: the booking somebody is asking about on the phone
            // is almost always the one just made.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Booking::STATUSES, array_map('ucfirst', Booking::STATUSES)))
                    ->multiple(),

                SelectFilter::make('departure')
                    ->relationship('departure', 'date_start')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                // One "Open" each: the edit page for whoever may change the
                // booking, the view page for whoever may only read it.
                EditAction::make()->label('Open'),
                ViewAction::make()->label('Open')
                    ->visible(fn (Booking $record): bool => ! BookingResource::canEdit($record)),
            ])
            ->emptyStateHeading('No bookings yet')
            ->emptyStateDescription('Bookings made through the website appear here the moment somebody holds a seat.');
    }
}
