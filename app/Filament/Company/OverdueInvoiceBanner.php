<?php

namespace App\Filament\Company;

use App\Filament\Company\Pages\CompanySettings;
use App\Models\CompanyInvoice;
use App\Models\Partner;
use App\Support\CompanyRole;
use Filament\Facades\Filament;

/**
 * The line across the top of a company's panel while it has an overdue
 * invoice from Rihla (billing automation). Owners only: an invoice is the
 * business's account with Rihla, not the team's business, and an Owner is
 * the one who can pay it. An e-mail may never arrive; this always does.
 */
final class OverdueInvoiceBanner
{
    public static function render(): string
    {
        $company = Filament::getTenant();
        $user = auth()->user();

        if (! $company instanceof Partner || $user === null || $user->roleAtCompany($company) !== CompanyRole::OWNER) {
            return '';
        }

        $overdue = $company->companyInvoices()->outstanding()->whereDate('due_on', '<', now()->toDateString())->orderBy('due_on')->get();

        if ($overdue->isEmpty()) {
            return '';
        }

        return view('filament.company.overdue-invoice', [
            'heading' => $overdue->count() === 1 ? 'An invoice from Rihla is overdue' : $overdue->count().' invoices from Rihla are overdue',
            'description' => $overdue->map(fn (CompanyInvoice $invoice): string => $invoice->number.' — '.$invoice->money()->format().', due '.$invoice->due_on->format('j M Y'))->join('; ')
                .'. Pay by bank transfer quoting the number; the invoices are under Company details.',
            'url' => CompanySettings::getUrl(),
        ])->render();
    }
}
