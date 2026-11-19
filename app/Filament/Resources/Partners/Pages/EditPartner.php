<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Models\Partner;
use App\Services\Companies\CompanyStanding;
use App\Support\CompanyFeatures;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
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
     * Suspend and reactivate an Umrah company (Phase 5b). The reason stays
     * with the company; the audit trail records who and when.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-pause-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->company()?->is_umrah_company === true && ! $this->company()->isSuspended() && auth()->user()?->can('partner.update') === true)
                ->modalDescription('Their people are shut out of their panel, their site and portals go offline and their bot stops. Nothing is deleted; reactivating puts it all back.')
                ->schema([Textarea::make('reason')->label('Why')->required()->maxLength(1000)->rows(3)])
                ->action(function (array $data): void {
                    app(CompanyStanding::class)->suspend($this->company(), (string) $data['reason']);
                    Notification::make()->success()->title('Suspended')->send();
                }),
            Action::make('reactivate')
                ->label('Reactivate')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn (): bool => $this->company()?->is_umrah_company === true && $this->company()->isSuspended() && auth()->user()?->can('partner.update') === true)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(CompanyStanding::class)->reactivate($this->company());
                    Notification::make()->success()->title('Reactivated')->send();
                }),
        ];
    }

    private function company(): ?Partner
    {
        $record = $this->getRecord();

        return $record instanceof Partner ? $record->refresh() : null;
    }

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
                // The licence (Phase 5b).
                'licence_number' => filled($data['licence_number'] ?? null) ? trim((string) $data['licence_number']) : null,
                'licence_expires_on' => filled($data['licence_expires_on'] ?? null) ? $data['licence_expires_on'] : null,
                'licence_register' => filled($data['licence_register'] ?? null) ? trim((string) $data['licence_register']) : null,
            ]);
        }

        unset(
            $data['site_enabled'], $data['powered_by_rihla'], $data['site_domain'],
            $data['features'], $data['registration_fee'], $data['registration_fee_currency'], $data['registration_paid_at'], $data['staff_seats'],
            $data['licence_number'], $data['licence_expires_on'], $data['licence_register'],
        );

        return parent::handleRecordUpdate($record, $data);
    }
}
