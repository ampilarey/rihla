<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Services\Portal\Gatekeeper;
use App\Support\CustomerDossier;
use App\Support\ReferralCredit;
use App\Support\Referrals;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Everything this office knows about one person — §8.1's customer 360.
 *
 * A view page rather than an edit page: almost nothing here is editable,
 * and a screen full of disabled form fields reads as broken. What can be
 * changed — the referral and the tags — has its own action and its own
 * relation manager.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.pages.customer-dossier';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit their details'),
            // The portal password (Phase 3h): the office's reset when one was
            // chosen by the wrong person or forgotten. The customer chooses a
            // new one from the next link they are sent.
            Action::make('clearPortalPassword')
                ->label('Clear their portal password')
                ->icon('heroicon-o-key')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->can('customer.update') === true
                    && Customer::query()->whereKey($this->getRecord()->getKey())->first()?->hasPortalPassword() === true)
                ->requiresConfirmation()
                ->modalDescription('Signs them out everywhere. They choose a new password from the next portal link you send.')
                ->action(function (): void {
                    app(Gatekeeper::class)->clearPassword(Customer::query()->findOrFail($this->getRecord()->getKey()));

                    Notification::make()->success()->title('Portal password cleared')->send();
                }),
        ];
    }

    /**
     * Filament types `getRecord()` as a bare `Model`, so the customer is
     * read back by key rather than asserted with an inline annotation —
     * one indexed read, and the type is real rather than promised.
     */
    public function getDossier(): CustomerDossier
    {
        return CustomerDossier::build(Customer::findOrFail($this->getRecord()->getKey()));
    }

    /**
     * What this person has sent Rihla, if anything — §8.1's referral
     * tracking, on the page where somebody looking at them wants it.
     *
     * Null when they have referred nobody, so the section disappears
     * rather than printing a zero at everybody who has not.
     */
    public function getReferralCredit(): ?ReferralCredit
    {
        return Referrals::forCustomer(Customer::findOrFail($this->getRecord()->getKey()));
    }

    /** Who sent them, when somebody did. */
    public function getReferrer(): ?Customer
    {
        return Customer::findOrFail($this->getRecord()->getKey())->referrer;
    }
}
