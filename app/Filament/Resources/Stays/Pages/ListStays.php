<?php

namespace App\Filament\Resources\Stays\Pages;

use App\Filament\Resources\Stays\StayResource;
use App\Models\Stay;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabs rather than a filter dropdown, because the board's whole job is to
 * make "what is waiting on me" the first thing somebody sees.
 */
class ListStays extends ListRecords
{
    protected static string $resource = StayResource::class;

    public function getTabs(): array
    {
        return [
            'waiting' => Tab::make('Waiting on us')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Stay::REQUESTED))
                ->badge(Stay::where('status', Stay::REQUESTED)->count() ?: null),

            'held' => Tab::make('Awaiting deposit')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Stay::HELD)),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED,
                ])),

            'all' => Tab::make('All'),
        ];
    }
}
