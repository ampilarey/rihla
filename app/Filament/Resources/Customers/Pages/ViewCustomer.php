<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Support\CustomerDossier;
use App\Support\ReferralCredit;
use App\Support\Referrals;
use Filament\Actions\EditAction;
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
        return [EditAction::make()->label('Edit their details')];
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
