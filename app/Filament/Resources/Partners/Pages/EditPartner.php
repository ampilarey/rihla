<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Models\Partner;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

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
            $record->forceFill([
                'site_enabled' => (bool) $data['site_enabled'],
                'powered_by_rihla' => (bool) ($data['powered_by_rihla'] ?? false),
                'site_domain' => filled($data['site_domain'] ?? null) ? strtolower(trim((string) $data['site_domain'])) : null,
            ]);
        }

        unset($data['site_enabled'], $data['powered_by_rihla'], $data['site_domain']);

        return parent::handleRecordUpdate($record, $data);
    }
}
