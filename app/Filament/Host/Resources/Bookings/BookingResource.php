<?php

namespace App\Filament\Host\Resources\Bookings;

use App\Filament\Host\Resources\Bookings\Pages\ListBookings;
use App\Filament\Host\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Pages\NewStay;
use App\Filament\Resources\Stays\Schemas\StayDetails;
use App\Models\Stay;
use App\Services\Stays\StayBill;
use App\Support\HostContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * A host's bookings, from every source — §16.6, §16 Phase 14.3.
 *
 * Tenant-scoped through `Stay::partner()` (a stay has no host column; it
 * belongs to a host through its building), and every action checked again
 * by the policy's host branch. No Create here — a direct booking is its own
 * page — and no Delete anywhere: a stay ends as a status, never a missing
 * row.
 */
class BookingResource extends Resource
{
    protected static ?string $model = Stay::class;

    protected static ?string $slug = 'bookings';

    protected static ?string $tenantOwnershipRelationshipName = 'partner';

    protected static ?string $tenantRelationshipName = 'stays';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Bookings';

    protected static ?string $modelLabel = 'booking';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 1;

    /** Requests waiting for this host's yes — the one number they act on. */
    public static function getNavigationBadge(): ?string
    {
        $host = HostContext::current();

        if ($host === null) {
            return null;
        }

        $waiting = $host->stays()->where('stays.status', Stay::REQUESTED)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return StayDetails::configure($schema, forHost: true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('customer.name')->label('Guest')->searchable()->wrap(),
                TextColumn::make('property.name')->label('Listing')->wrap()->toggleable(),
                TextColumn::make('roomType.name')->label('Room')->wrap(),
                TextColumn::make('check_in')
                    ->label('Nights')
                    ->formatStateUsing(fn (Stay $record): string => sprintf(
                        '%s → %s (%d)',
                        $record->check_in->format('j M'),
                        $record->check_out->format('j M Y'),
                        $record->nights,
                    ))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColour($state))
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->sortable(),
                TextColumn::make('source')
                    ->label('From')
                    ->formatStateUsing(fn (?string $state): string => self::sourceLabel($state))
                    ->placeholder('Rihla')
                    ->toggleable(),
                TextColumn::make('total_minor')
                    ->label('Balance')
                    ->state(fn (Stay $record): string => StayBill::for($record)->balance()->format())
                    ->toggleable(),
            ])
            ->defaultSort('check_in')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Stay::STATUSES, array_map(self::statusLabel(...), Stay::STATUSES)))
                    ->multiple(),
                SelectFilter::make('property_id')
                    ->label('Listing')
                    ->options(fn (): array => HostContext::current()?->properties()->get()
                        ->mapWithKeys(fn ($property): array => [$property->getKey() => (string) $property->name])
                        ->all() ?? []),
                SelectFilter::make('source')
                    ->label('From')
                    ->options(['marketplace' => 'Rihla marketplace'] + NewStay::SOURCES),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('Staying on or after'),
                        DatePicker::make('until')->label('Arriving on or before'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $from): Builder => $q->where('check_out', '>', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, $until): Builder => $q->where('check_in', '<=', $until))),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No bookings yet')
            ->emptyStateDescription('Every booking for your listings appears here — from Rihla, and the ones you enter yourself.');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Stay::REQUESTED => 'Waiting for your answer',
            Stay::HELD => 'Waiting for the deposit',
            Stay::CHECKED_IN => 'In house',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function statusColour(string $status): string
    {
        return match ($status) {
            Stay::REQUESTED => 'warning',
            Stay::HELD => 'info',
            Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED => 'success',
            default => 'gray',
        };
    }

    public static function sourceLabel(?string $source): string
    {
        return $source === 'marketplace' ? 'Rihla marketplace' : (NewStay::SOURCES[$source] ?? 'Rihla');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'view' => ViewBooking::route('/{record}'),
        ];
    }
}
