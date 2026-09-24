<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No DeleteAction, matching the table: a partner with properties cannot be
 * removed, and `is_active` is how one leaves.
 */
class EditPartner extends EditRecord
{
    protected static string $resource = PartnerResource::class;
}
