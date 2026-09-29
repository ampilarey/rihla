<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Models\Partner;
use Filament\Resources\Pages\CreateRecord;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    /**
     * A partner entered by Rihla's staff is one they have already rung and
     * agreed terms with — the check §16's verification exists to make — so
     * it starts verified and active, as every partner before §16 did.
     * A host who signs themselves up (Phase 14) starts at the column
     * defaults instead: unverified and pending.
     */
    protected function afterCreate(): void
    {
        /** @var Partner $partner */
        $partner = $this->record;

        $partner->forceFill([
            'verification' => Partner::VERIFIED,
            'verified_at' => now(),
            'verified_by' => auth()->id(),
            'status' => Partner::STATUS_ACTIVE,
        ])->save();
    }
}
