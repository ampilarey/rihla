<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Models\AuditLog;
use App\Models\Partner;
use App\Providers\Filament\CompanyPanelProvider;
use App\Services\Companies\CompanyBilling;
use App\Services\Companies\CompanyExport;
use App\Services\Companies\CompanyStanding;
use App\Services\Companies\SupportAccess;
use App\Support\AuditContext;
use App\Support\CompanyFeatures;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
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
            // Support access (Phase 5c): Rihla asks, the company's Owner
            // decides on their Support access page.
            Action::make('askSupport')
                ->label('Ask for support access')
                ->icon('heroicon-o-lifebuoy')
                ->color('gray')
                ->visible(fn (): bool => $this->company()?->is_umrah_company === true && ! $this->company()->is_rihla && auth()->user()?->can('partner.update') === true)
                ->modalDescription('Their Owner sees your name and this reason, decides, and chooses for how long. You will be able to look and not change anything, and every page you open is listed for them.')
                ->schema([Textarea::make('reason')->label('Why you need to look')->required()->maxLength(1000)->rows(3)])
                ->action(function (array $data): void {
                    app(SupportAccess::class)->ask($this->company(), auth()->user(), (string) $data['reason']);
                    Notification::make()->success()->title('Asked')->body('You can open their panel once their Owner allows it.')->send();
                }),
            Action::make('openPanel')
                ->label('Open their panel')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => $this->company() !== null && auth()->user() !== null && SupportAccess::liveFor($this->company(), auth()->user()) !== null)
                ->url(fn (): string => url('/'.CompanyPanelProvider::PATH.'/'.$this->company()?->slug), shouldOpenInNewTab: true),
            // Billing automation: what the daily run would issue, now — so a
            // company added this afternoon has its registration invoice
            // before tomorrow morning.
            Action::make('billNow')
                ->label('Issue invoices now')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('gray')
                ->visible(fn (): bool => $this->company()?->is_umrah_company === true && ! $this->company()->is_rihla && auth()->user()?->can('partner.update') === true)
                ->requiresConfirmation()
                ->modalDescription('Issues the registration invoice if the fee is set and unpaid, and the monthly invoice for every month from "Billed from" that has none. The company\'s Owners are e-mailed each one.')
                ->action(function (): void {
                    $issued = app(CompanyBilling::class)->issueDue($this->company());
                    Notification::make()->success()
                        ->title($issued === [] ? 'Nothing to issue' : count($issued).' '.(count($issued) === 1 ? 'invoice' : 'invoices').' issued')
                        ->send();
                }),
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
            // Phase 5d: everything the company holds, in one download, and
            // closing the account. Neither deletes anything.
            Action::make('export')
                ->label('Export everything')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => $this->company()?->is_umrah_company === true && ! $this->company()->is_rihla && auth()->user()?->can('partner.update') === true)
                ->requiresConfirmation()
                ->modalDescription('One zip of every record and file they hold: customers, travellers, bookings, payments, the books, documents and the audit trail. It contains passport scans — hand it only to the company. The download is recorded.')
                ->action(function () {
                    $company = $this->company();
                    $relative = app(CompanyExport::class)->make($company);
                    $user = auth()->user();

                    AuditLog::create([
                        'user_id' => $user?->getKey(),
                        'user_name' => $user?->name,
                        'user_email' => $user?->email,
                        'event' => AuditLog::DOWNLOADED,
                        'channel' => AuditContext::channel(),
                        'auditable_type' => $company->getMorphClass(),
                        'auditable_id' => $company->getKey(),
                        'old_values' => null,
                        'new_values' => ['export' => 'everything the company holds', 'file' => basename($relative)],
                        'ip_address' => request()->ip(),
                        'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
                    ]);

                    return response()->download(Storage::disk('local')->path($relative))->deleteFileAfterSend();
                }),
            Action::make('close')
                ->label('Close account')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->visible(fn (): bool => $this->company()?->is_umrah_company === true && ! $this->company()->isClosed() && auth()->user()?->can('partner.update') === true)
                ->modalDescription('Their panel, site, portals and bot go off for good. Nothing is deleted: you can still export everything, and reactivate if they come back. Export first if they have asked for their data.')
                ->schema([Textarea::make('reason')->label('Why')->required()->maxLength(1000)->rows(3)])
                ->action(function (array $data): void {
                    app(CompanyStanding::class)->close($this->company(), (string) $data['reason']);
                    Notification::make()->success()->title('Account closed')->send();
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
                // Billing automation.
                'monthly_fee_minor' => filled($data['monthly_fee'] ?? null) ? (int) round(((float) $data['monthly_fee']) * 100) : null,
                'monthly_fee_currency' => filled($data['monthly_fee'] ?? null) ? ($data['monthly_fee_currency'] ?? 'MVR') : null,
                'billing_starts_on' => filled($data['monthly_fee'] ?? null) && filled($data['billing_starts_on'] ?? null) ? $data['billing_starts_on'] : null,
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
            $data['monthly_fee'], $data['monthly_fee_currency'], $data['billing_starts_on'],
            $data['licence_number'], $data['licence_expires_on'], $data['licence_register'],
        );

        return parent::handleRecordUpdate($record, $data);
    }
}
