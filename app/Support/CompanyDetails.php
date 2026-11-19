<?php

namespace App\Support;

use App\Models\Partner;

/**
 * The business a document or instruction speaks for — companies plan,
 * Phase 2c.
 *
 * Rihla's own details live in configuration and settings, as they always
 * have. When the current company is another one, its own: its name,
 * address, registration number, telephone and e-mail on every invoice,
 * receipt and printed list; its bank account in every transfer instruction;
 * its letters in front of every reference. A company that has not filled
 * a detail in prints nothing there rather than borrowing Rihla's, which
 * would tell a pilgrim to call or pay somebody else (decision 2).
 */
final class CompanyDetails
{
    /** The current company when it is not Rihla, else null. */
    public static function company(): ?Partner
    {
        $context = app(CompanyContext::class);
        $id = $context->scopeId();

        if ($id === null || $id === $context->rihlaId()) {
            return null;
        }

        // Not memoised: `once()` keys on the call site, not on $id, so a
        // command working through companies in turn would be handed the
        // first one's details for all of them.
        return Partner::find($id);
    }

    /**
     * Who issues a document.
     *
     * @param  array<string, string|null>  $rihla  Rihla's own, as configured
     * @return array<string, string|null>
     */
    public static function issuer(array $rihla): array
    {
        $company = self::company();

        if ($company === null) {
            return $rihla;
        }

        return [
            'name' => $company->name,
            'registration' => $company->registration_number,
            'address' => $company->address,
            'email' => $company->email,
            'phone' => (string) $company->phone,
        ];
    }

    /** The line at the foot of a document, or Rihla's configured one. */
    public static function terms(): ?string
    {
        $company = self::company();

        return $company === null ? (string) config('invoices.terms.note') : $company->invoice_terms;
    }

    /**
     * The tax line under a document (security review): Rihla's for Rihla,
     * and nothing for a company, which has no tax line of its own stored —
     * Rihla's GST or TIN under a company's name would be a tax claim made
     * on the company's behalf.
     */
    public static function taxNote(): ?string
    {
        return self::company() === null ? (filled(config('invoices.tax.note')) ? (string) config('invoices.tax.note') : null) : null;
    }

    /**
     * Where a transfer goes: bank, account name and the account for one
     * currency. Null account when none is set, as Rihla's driver does.
     *
     * @return array{bank: ?string, account_name: ?string, accounts: array<string, string>}
     */
    public static function bank(): array
    {
        $company = self::company();

        if ($company === null) {
            return [
                'bank' => config('payments.bank.name'),
                'account_name' => config('payments.bank.account_name'),
                'accounts' => array_filter((array) config('payments.bank.accounts', [])),
            ];
        }

        return [
            'bank' => $company->bank_name,
            'account_name' => $company->bank_account_name,
            'accounts' => array_filter((array) ($company->bank_accounts ?? [])),
        ];
    }

    /**
     * The front of a reference: Rihla's (`RIH-B`) or the company's letters
     * with the same type letter (`CRL-B`). A company that has chosen no
     * letters uses Rihla's shape; the number after it is the row's own id,
     * which no other row shares, so the two can never collide.
     */
    public static function referencePrefix(?int $companyId, string $type, string $rihla): string
    {
        if ($companyId === null || $companyId === app(CompanyContext::class)->rihlaId()) {
            return $rihla;
        }

        $prefix = Partner::whereKey($companyId)->value('reference_prefix');

        return is_string($prefix) && $prefix !== '' ? $prefix.'-'.$type : $rihla;
    }
}
