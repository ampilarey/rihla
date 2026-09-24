<?php

namespace App\Filament\Resources\Checklists\Pages;

use App\Filament\Resources\Checklists\DepartureChecklistItemResource;
use App\Filament\Support\DepartureOptions;
use App\Models\Departure;
use App\Models\DepartureChecklistItem;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListDepartureChecklistItems extends ListRecords
{
    protected static string $resource = DepartureChecklistItemResource::class;

    protected static ?string $title = 'Checklists';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add an item'),
            self::copyAction(),
        ];
    }

    /**
     * Start a departure's list from another one's.
     *
     * The same things happen before every Umrah departure, and typing them
     * out again is how one gets forgotten. Due dates keep their distance
     * from the start date — "three weeks before" stays three weeks before —
     * and nothing arrives ticked, whatever state it was in on the original.
     */
    private static function copyAction(): Action
    {
        return Action::make('copy')
            ->label('Copy a list')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->authorize('create', DepartureChecklistItem::class)
            ->schema([
                Select::make('from')
                    ->label('Copy the list from')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => DepartureOptions::all()),
                Select::make('to')
                    ->label('Onto')
                    ->required()
                    ->searchable()
                    ->different('from')
                    ->options(fn (): array => DepartureOptions::all(recentOnly: true)),
            ])
            ->action(function (array $data): void {
                $copied = self::copy(Departure::findOrFail($data['from']), Departure::findOrFail($data['to']));

                Notification::make()
                    ->success()
                    ->title($copied === 1 ? 'One item copied' : $copied.' items copied')
                    ->send();
            });
    }

    public static function copy(Departure $from, Departure $to): int
    {
        $shift = (int) $from->date_start->diffInDays($to->date_start, false);

        return DB::transaction(function () use ($from, $to, $shift): int {
            $count = 0;

            foreach ($from->checklist as $item) {
                DepartureChecklistItem::create([
                    'departure_id' => $to->getKey(),
                    'title' => $item->title,
                    'due_on' => $item->due_on?->copy()->addDays($shift),
                    'is_blocking' => $item->is_blocking,
                    'notes' => $item->notes,
                    'sort_order' => $item->sort_order,
                ]);

                $count++;
            }

            return $count;
        });
    }
}
