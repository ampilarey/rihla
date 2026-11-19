<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Models\Partner;
use App\Support\CompanyFeatures;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No DeleteAction, matching the table: a partner with properties cannot be
 * removed, and `is_active` is how one leaves.
 */
class EditPartner extends EditRecord
{
    protected static string $resource = PartnerResource::class;

    /**
     * The website switches are not fillable (a company must never switch on
     * its own site), so `update()` would drop them without a word. Written
     * here, and only for an Umrah company (companies plan, Phase 4a).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Partner $record */
        if ($record->is_umrah_company && array_key_exists('site_enabled', $data)) {
            $paidAt = filled($data['registration_paid_at'] ?? null) ? $data['registration_paid_at'] : null;

            // The registration fee opens the basic website (decision 13).
            if ((bool) $data['site_enabled'] && $paidAt === null) {
                throw ValidationException::withMessages([
                    'data.site_enabled' => 'Record the registration fee as paid first: it is what opens their website.',
                ]);
            }

            $record->forceFill([
                'site_enabled' => (bool) $data['site_enabled'],
                'powered_by_rihla' => (bool) ($data['powered_by_rihla'] ?? false),
                'site_domain' => filled($data['site_domain'] ?? null) ? strtolower(trim((string) $data['site_domain'])) : null,
                // What they have paid for (Phase 5a).
                'features' => CompanyFeatures::clean((array) ($data['features'] ?? [])),
                'registration_fee_minor' => filled($data['registration_fee'] ?? null) ? (int) round(((float) $data['registration_fee']) * 100) : null,
                'registration_fee_currency' => filled($data['registration_fee'] ?? null) ? ($data['registration_fee_currency'] ?? 'MVR') : null,
                'registration_paid_at' => $paidAt,
                'staff_seats' => filled($data['staff_seats'] ?? null) ? (int) $data['staff_seats'] : null,
            ]);
        }

        unset(
            $data['site_enabled'], $data['powered_by_rihla'], $data['site_domain'],
            $data['features'], $data['registration_fee'], $data['registration_fee_currency'], $data['registration_paid_at'], $data['staff_seats'],
        );

        return parent::handleRecordUpdate($record, $data);
    }
}
