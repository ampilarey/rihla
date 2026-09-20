<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected static ?string $title = 'Customers';

    /**
     * No create button.
     *
     * A customer is created by a booking or an import, and one typed here
     * by hand is a duplicate of somebody already in the table — which is
     * the exact problem the Phase 3 import spent its time on.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
