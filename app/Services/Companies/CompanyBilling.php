<?php

namespace App\Services\Companies;

use App\Exceptions\DeskRefusal;
use App\Models\CompanyInvoice;
use App\Models\CompanyMembership;
use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyRole;
use App\Support\Contact;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Billing Umrah companies — the plan's "Later": invoices, reminders and
 * suspension, run once a day by `companies:bill`.
 *
 * ## What is billed
 *
 * - **The registration fee**, once, while it is set on the company and not
 *   yet recorded as paid. Recording the invoice paid records the fee paid,
 *   which is what lets Rihla's admin switch the website on (decision 13).
 * - **The monthly fee**, one invoice per calendar month from the month
 *   billing starts, while both are set. A whole month, never a part of one:
 *   no proration rule was agreed, so none is invented. A run that missed a
 *   month (a cron line lost for a week) issues it on the next run.
 *
 * Rihla's own company, a closed company and a suspended one get no new
 * monthly invoice — a suspended company cannot use what it would be billed
 * for — but invoices already issued keep their reminders.
 *
 * ## Paid by transfer, recorded by a person
 *
 * Decision 14: no card payments through Rihla's BML. Every invoice tells
 * the company where to send the money, and Rihla's admin records it once it
 * has arrived. Nothing here touches a bank.
 *
 * ## Telling the company
 *
 * Its Owners are e-mailed when an invoice is issued, a few days before it
 * is due and once when it falls overdue — best effort: a mail server that
 * refuses is logged and never stops the run, because the company's panel
 * shows every unpaid invoice whether or not a message arrived.
 */
final class CompanyBilling
{
    public function __construct(private readonly CompanyStanding $standing) {}

    /**
     * The daily run: issue what is due for every company, then send the
     * reminders and apply any suspension the configuration asks for.
     *
     * @return array{issued: int, reminded: int, overdue: int, suspended: int}
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $issued = 0;

        Partner::query()
            ->where('is_umrah_company', true)
            ->where('is_rihla', false)
            ->where('status', '!=', Partner::STATUS_CLOSED)
            ->each(function (Partner $company) use ($today, &$issued): void {
                $issued += count($this->issueDue($company, $today));
            });

        return ['issued' => $issued] + $this->chase($today);
    }

    /**
     * Every invoice this company is owed today and does not have yet.
     *
     * @return list<CompanyInvoice>
     */
    public function issueDue(Partner $company, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        if (! $company->is_umrah_company || $company->is_rihla || $company->isClosed()) {
            return [];
        }

        $new = [];

        if ($company->registration_fee_minor !== null && $company->registration_fee_minor > 0
            && $company->registration_paid_at === null
            && ! $company->companyInvoices()->where('kind', CompanyInvoice::REGISTRATION)->whereNull('cancelled_at')->exists()) {
            $new[] = $this->issue($company, CompanyInvoice::REGISTRATION, null, (int) $company->registration_fee_minor, (string) ($company->registration_fee_currency ?? 'MVR'), $today);
        }

        if ($company->monthly_fee_minor !== null && $company->monthly_fee_minor > 0
            && $company->billing_starts_on !== null && ! $company->isSuspended()) {
            $billed = $company->companyInvoices()
                ->where('kind', CompanyInvoice::MONTHLY)
                ->pluck('period_start')
                ->map(fn ($date): string => CarbonImmutable::parse($date)->format('Y-m'))
                ->all();

            for ($month = CarbonImmutable::parse($company->billing_starts_on)->startOfMonth(); $month->lte($today); $month = $month->addMonthNoOverflow()) {
                if (! in_array($month->format('Y-m'), $billed, true)) {
                    $new[] = $this->issue($company, CompanyInvoice::MONTHLY, $month, (int) $company->monthly_fee_minor, (string) ($company->monthly_fee_currency ?? 'MVR'), $today);
                }
            }
        }

        foreach ($new as $invoice) {
            $this->tell($invoice, 'Invoice '.$invoice->number.' from Rihla', $invoice->description().': '.$invoice->money()->format().', due '.$invoice->due_on->format('j M Y').'.');
        }

        return $new;
    }

    /**
     * Reminders before the due date, one notice once overdue, and the
     * configured suspension.
     *
     * @return array{reminded: int, overdue: int, suspended: int}
     */
    public function chase(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['reminded' => 0, 'overdue' => 0, 'suspended' => 0];
        $remindFrom = $today->addDays((int) config('companies.billing.remind_days_before', 3));
        $suspendAfter = config('companies.billing.suspend_after_days');

        CompanyInvoice::query()->outstanding()->with('company')->orderBy('due_on')->each(
            function (CompanyInvoice $invoice) use ($today, $remindFrom, $suspendAfter, &$counts): void {
                $due = CarbonImmutable::parse($invoice->due_on);

                if ($due->lt($today)) {
                    if ($invoice->overdue_notified_at === null) {
                        $this->tell($invoice, 'Invoice '.$invoice->number.' is overdue', $invoice->description().': '.$invoice->money()->format().' was due on '.$due->format('j M Y').' and has not reached Rihla.');
                        $invoice->forceFill(['overdue_notified_at' => now()])->save();
                        $counts['overdue']++;
                    }

                    $company = $invoice->company;

                    if ($suspendAfter !== null && $company !== null && ! $company->isSuspended()
                        && $due->addDays((int) $suspendAfter)->lt($today)) {
                        $this->standing->suspend($company, 'Invoice '.$invoice->number.' ('.$invoice->money()->format().') unpaid since '.$due->format('j M Y').'. Suspended automatically by billing.');
                        $counts['suspended']++;
                    }

                    return;
                }

                if ($invoice->reminded_at === null && $due->lte($remindFrom) && ! $invoice->issued_on->isSameDay($today)) {
                    $this->tell($invoice, 'Reminder: invoice '.$invoice->number.' is due '.$due->format('j M Y'), $invoice->description().': '.$invoice->money()->format().'.');
                    $invoice->forceFill(['reminded_at' => now()])->save();
                    $counts['reminded']++;
                }
            },
        );

        return $counts;
    }

    /** The money has arrived. A registration invoice also records the fee paid. */
    public function recordPayment(CompanyInvoice $invoice, User $by, string $paidOn, ?string $reference): void
    {
        if (! $invoice->isOutstanding()) {
            throw new DeskRefusal('That invoice is already '.($invoice->paid_on !== null ? 'paid' : 'cancelled').'.');
        }

        DB::transaction(function () use ($invoice, $by, $paidOn, $reference): void {
            $invoice->forceFill([
                'paid_on' => $paidOn,
                'payment_reference' => filled($reference) ? trim((string) $reference) : null,
                'recorded_by' => $by->getKey(),
            ])->save();

            $company = $invoice->company;

            if ($invoice->kind === CompanyInvoice::REGISTRATION && $company !== null && $company->registration_paid_at === null) {
                $company->forceFill(['registration_paid_at' => $paidOn])->save();
            }
        });
    }

    /** Not owed after all. Kept, with the reason, and never reissued for its month. */
    public function cancel(CompanyInvoice $invoice, User $by, string $reason): void
    {
        $reason = trim($reason);

        if (! $invoice->isOutstanding()) {
            throw new DeskRefusal('Only an unpaid invoice can be cancelled.');
        }

        if ($reason === '') {
            throw new DeskRefusal('Say why: the company will ask.');
        }

        $invoice->forceFill([
            'cancelled_at' => now(),
            'cancelled_reason' => $reason,
            'recorded_by' => $by->getKey(),
        ])->save();
    }

    /** The invoice as a PDF, in Rihla's name: this is Rihla's own document. */
    public function pdf(CompanyInvoice $invoice): string
    {
        $currency = $invoice->currency;

        return Pdf::loadView('pdf.company-invoice', [
            'invoice' => $invoice,
            'company' => $invoice->company,
            'issuer' => [
                'name' => (string) config('invoices.issuer.name', 'Rihla Travels'),
                'address' => config('invoices.issuer.address'),
                'registration' => config('invoices.issuer.registration'),
                'phone' => Contact::displayNumber(),
                'email' => config('invoices.issuer.email'),
            ],
            'bank' => [
                'name' => config('payments.bank.name'),
                'account_name' => config('payments.bank.account_name'),
                'account' => config('payments.bank.accounts.'.$currency),
            ],
        ])->output();
    }

    private function issue(Partner $company, string $kind, ?CarbonImmutable $month, int $amountMinor, string $currency, CarbonImmutable $today): CompanyInvoice
    {
        return DB::transaction(function () use ($company, $kind, $month, $amountMinor, $currency, $today): CompanyInvoice {
            $invoice = new CompanyInvoice;
            $invoice->forceFill([
                'partner_id' => $company->getKey(),
                // Unique and temporary: the real number needs the id.
                'number' => 'pending-'.bin2hex(random_bytes(8)),
                'kind' => $kind,
                'period_start' => $month?->toDateString(),
                'period_end' => $month?->endOfMonth()->toDateString(),
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'issued_on' => $today->toDateString(),
                'due_on' => $today->addDays((int) config('companies.billing.due_days', 14))->toDateString(),
            ])->save();

            $invoice->forceFill(['number' => sprintf('RIH-CI-%s-%05d', $today->format('Y'), $invoice->getKey())])->save();

            return $invoice->setRelation('company', $company);
        });
    }

    /** E-mail the company's Owners. Best effort: the panel shows it anyway. */
    private function tell(CompanyInvoice $invoice, string $subject, string $line): void
    {
        $addresses = CompanyMembership::query()
            ->where('company_id', $invoice->partner_id)
            ->where('role', CompanyRole::OWNER)
            ->whereNotNull('accepted_at')
            ->with('user')
            ->get()
            ->map(fn (CompanyMembership $membership): ?string => $membership->user?->email)
            ->filter()
            ->unique()
            ->values();

        $body = $line."\n\n"
            .'Pay by bank transfer and quote '.$invoice->number.' as the reference. '
            .'The invoice, with Rihla\'s bank details, is under Company details in your panel.';

        foreach ($addresses as $address) {
            try {
                Mail::raw($body, fn ($message) => $message->to($address)->subject($subject));
            } catch (Throwable $e) {
                Log::warning('Company invoice e-mail not sent', ['invoice' => $invoice->number, 'error' => $e->getMessage()]);
            }
        }
    }
}
