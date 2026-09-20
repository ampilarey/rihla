<?php

namespace App\Filament\Resources\Visas\Pages;

use App\Filament\Resources\Visas\VisaApplicationResource;
use App\Models\VisaApplication;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListVisaApplications extends ListRecords
{
    protected static string $resource = VisaApplicationResource::class;

    /** The day's work first, the archive last. */
    public function getTabs(): array
    {
        return [
            // The generic is named because the closure is handed a builder
            // typed for the base Model, which has no open() scope.
            'open' => Tab::make('Open')
                ->modifyQueryUsing(function ($query): void {
                    /** @var Builder<VisaApplication> $query */
                    $query->open();
                })
                ->badge(VisaApplication::open()->count()),

            'issued' => Tab::make('Issued')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', VisaApplication::ISSUED)),

            'rejected' => Tab::make('Refused')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', VisaApplication::REJECTED)),

            'all' => Tab::make('All'),
        ];
    }
}
