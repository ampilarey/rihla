<?php

namespace App\Filament\Resources\Enquiries\Pages;

use App\Filament\Resources\Enquiries\EnquiryResource;
use App\Models\Enquiry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEnquiries extends ListRecords
{
    protected static string $resource = EnquiryResource::class;

    /**
     * The problems first.
     *
     * "Adrift" — open, with no owner or no next action — is the default
     * tab, because it is the list §8.1 says the minimal CRM exists to
     * empty. A screen that opens on "all enquiries" is a shared inbox with
     * extra steps.
     */
    public function getTabs(): array
    {
        return [
            'adrift' => Tab::make('Nobody has promised anything')
                ->modifyQueryUsing(function ($query): void {
                    /** @var Builder<Enquiry> $query */
                    $query->adrift();
                })
                ->badge(Enquiry::adrift()->count()),

            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing(function ($query): void {
                    /** @var Builder<Enquiry> $query */
                    $query->overdue();
                })
                ->badge(Enquiry::overdue()->count()),

            'mine' => Tab::make('Mine')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())),

            'open' => Tab::make('All open')
                ->modifyQueryUsing(function ($query): void {
                    /** @var Builder<Enquiry> $query */
                    $query->open();
                }),

            'lost' => Tab::make('Did not book')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Enquiry::LOST)),

            'all' => Tab::make('All'),
        ];
    }
}
