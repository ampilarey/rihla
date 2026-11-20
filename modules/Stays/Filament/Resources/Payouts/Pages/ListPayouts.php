<?php

namespace Modules\Stays\Filament\Resources\Payouts\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Stays\Filament\Resources\Payouts\PayoutResource;

class ListPayouts extends ListRecords
{
    protected static string $resource = PayoutResource::class;
}
