<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Models\Booking;
use App\Models\PaymentSchedule;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Instalments: this much by this date.
 *
 * Information for the customer and a nudge for the office, not a rule the
 * ledger enforces. Set them and the invoice prints the dates; Today flags
 * any that fall behind. Money is still recorded and checked as before.
 */
class ScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'schedule';

    protected static ?string $title = 'Instalments';

    protected static ?string $modelLabel = 'instalment';

    /** Gated on the booking's own permissions; see LinesRelationManager. */
    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('booking.view') === true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function canCreate(): bool
    {
        return auth()->user()?->can('booking.update') === true;
    }

    protected function canEdit(Model $record): bool
    {
        return auth()->user()?->can('booking.update') === true;
    }

    protected function canDelete(Model $record): bool
    {
        return auth()->user()?->can('booking.update') === true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('label')
                ->required()
                ->maxLength(60)
                ->placeholder('Deposit / Second payment / Balance'),
            DatePicker::make('due_on')
                ->label('Due')
                ->required()
                ->native(false),
            // Whole rufiyaa on the screen, laari in the column; the
            // conversion lives here and in App\Support\Money, nowhere else.
            TextInput::make('amount_minor')
                ->label('Amount')
                ->numeric()
                ->integer()
                ->required()
                ->minValue(1)
                ->prefix(fn (): string => $this->booking()->currency)
                ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : intdiv($state, 100))
                ->dehydrateStateUsing(fn ($state): int => Money::ofMajor((int) $state, $this->booking()->currency)->minor)
                ->helperText('Whole units.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->description(fn (PaymentSchedule $record): string => 'due '.$record->due_on->format('j M Y')),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->state(fn (PaymentSchedule $record): string => $record->money()->format()),
                TextColumn::make('state')
                    ->label('')
                    ->badge()
                    ->state(fn (PaymentSchedule $record): string => match (true) {
                        $record->isOverdue() => 'Overdue',
                        $record->due_on->isPast() && ! $record->due_on->isToday() => 'Covered',
                        default => 'Upcoming',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Overdue' => 'danger',
                        'Covered' => 'success',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('due_on')
            ->headerActions([CreateAction::make()->label('Add an instalment')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->paginated(false)
            ->emptyStateHeading('No instalments set')
            ->emptyStateDescription('Optional. Set them and the invoice shows the dates; Today flags any that fall behind.');
    }

    private function booking(): Booking
    {
        /** @var Booking */
        return $this->getOwnerRecord();
    }
}
