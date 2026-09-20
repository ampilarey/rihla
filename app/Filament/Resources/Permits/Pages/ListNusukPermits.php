<?php

namespace App\Filament\Resources\Permits\Pages;

use App\Filament\Resources\Permits\NusukPermitResource;
use App\Models\NusukPermit;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListNusukPermits extends ListRecords
{
    protected static string $resource = NusukPermitResource::class;

    /**
     * The day's work first, then the two kinds apart.
     *
     * Umrah permits and Rawdah slots get their own tab rather than a shared
     * list with a column, because the question staff actually arrive with is
     * one or the other: "who still cannot enter the Mataf" and "who is
     * waiting on a Rawdah slot" are different mornings.
     */
    public function getTabs(): array
    {
        return [
            // The generic is named because the closure is handed a builder
            // typed for the base Model, which has no open() scope.
            'open' => Tab::make('Open')
                ->modifyQueryUsing(function ($query): void {
                    /** @var Builder<NusukPermit> $query */
                    $query->open();
                })
                ->badge(NusukPermit::open()->count()),

            'umrah' => Tab::make('Umrah permits')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', NusukPermit::UMRAH)),

            'rawdah' => Tab::make('Rawdah slots')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', NusukPermit::RAWDAH)),

            'refused' => Tab::make('Refused')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', NusukPermit::REFUSED)),

            'all' => Tab::make('All'),
        ];
    }
}
