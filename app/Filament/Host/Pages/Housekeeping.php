<?php

namespace App\Filament\Host\Pages;

use App\Models\PropertyUnit;
use App\Models\Stay;
use App\Models\User;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which rooms are ready — §16.10.
 *
 * Each room, whether it is clean, who is in it and when they leave. Check-out
 * marks a room dirty; somebody marks it clean, and somebody may inspect it.
 * Nothing else: no task assignment, no rota. Reception may use it.
 */
class Housekeeping extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Housekeeping';

    protected static ?string $title = 'Housekeeping';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.host.housekeeping';

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::BOOKINGS);
    }

    public static function getNavigationBadge(): ?string
    {
        $host = HostContext::current();

        if ($host === null) {
            return null;
        }

        $dirty = self::unitsOf($host->getKey())->where('housekeeping', PropertyUnit::DIRTY)->count();

        return $dirty > 0 ? (string) $dirty : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Builder<PropertyUnit> */
    public static function unitsOf(int $hostId): Builder
    {
        return PropertyUnit::query()
            ->where('is_active', true)
            ->whereHas('property', fn (Builder $query) => $query->where('partner_id', $hostId));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::unitsOf((int) HostContext::current()?->getKey())->with(['property', 'roomType']))
            ->defaultSort('sort_order')
            ->columns([
                // On a phone: the room, its state and who is in it.
                TextColumn::make('label')->label('Room'),
                TextColumn::make('roomType.name')->visibleFrom('md')->label('Kind')->toggleable(),
                TextColumn::make('property.name')->visibleFrom('md')->label('Listing')->toggleable(),
                TextColumn::make('housekeeping')
                    ->label('State')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PropertyUnit::DIRTY => 'warning',
                        PropertyUnit::INSPECTED => 'success',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PropertyUnit::DIRTY => 'Needs cleaning',
                        PropertyUnit::INSPECTED => 'Inspected',
                        default => 'Clean',
                    }),
                TextColumn::make('guest')
                    ->label('In it now')
                    ->wrap()
                    ->state(fn (PropertyUnit $record): ?string => $this->inHouse($record)?->customer?->name)
                    ->placeholder('Empty'),
                TextColumn::make('leaving')
                    ->visibleFrom('md')
                    ->label('Leaves')
                    ->state(fn (PropertyUnit $record): ?string => $this->inHouse($record)?->check_out->format('D j M'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('housekeeping')->label('State')->options([
                    PropertyUnit::DIRTY => 'Needs cleaning',
                    PropertyUnit::CLEAN => 'Clean',
                    PropertyUnit::INSPECTED => 'Inspected',
                ]),
            ])
            ->recordActions([
                $this->mark('markClean', PropertyUnit::CLEAN, 'Mark clean', 'heroicon-o-sparkles'),
                $this->mark('markInspected', PropertyUnit::INSPECTED, 'Mark inspected', 'heroicon-o-check-badge'),
                $this->mark('markDirty', PropertyUnit::DIRTY, 'Needs cleaning', 'heroicon-o-exclamation-triangle'),
            ])
            ->emptyStateHeading('No rooms yet')
            ->emptyStateDescription('Add your rooms on each listing (Rooms tab) and they appear here.');
    }

    private function mark(string $name, string $state, string $label, string $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            // A button, icon only on a phone: as labelled links, the two a
            // row offers pushed themselves off the edge of the screen, and
            // labelledFrom() only applies to a button.
            ->button()
            ->color('gray')
            ->size('sm')
            ->labeledFrom('md')
            ->visible(fn (PropertyUnit $record): bool => $record->housekeeping !== $state)
            ->authorize(fn (PropertyUnit $record): bool => self::canAccess()
                && HostContext::owns(HostContext::current() ?? abort(404), $record))
            ->action(fn (PropertyUnit $record) => $record->forceFill(['housekeeping' => $state])->save());
    }

    private function inHouse(PropertyUnit $unit): ?Stay
    {
        return Stay::query()
            ->with('customer')
            ->where('unit_id', $unit->getKey())
            ->where('status', Stay::CHECKED_IN)
            ->first();
    }
}
