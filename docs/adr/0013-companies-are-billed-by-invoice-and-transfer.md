# 0013 — Companies are billed by invoice and bank transfer, recorded by a person

**Status:** Accepted
**Date:** 2026-11-19
**Context:** "Later: billing automation" in [`UMRAH_COMPANIES_PLAN.md`](../UMRAH_COMPANIES_PLAN.md), on top of [ADR 0012](0012-features-are-permission-areas-and-the-owner-decides-support.md)
**Decided by:** the owner asked for everything remaining to be built. Decision 14 (no card payments through Rihla's BML) fixes how a company pays. The amounts and the suspension rule stay the owner's.

## Context

Phase 5 stored a registration fee on each company and left recording it
paid to Rihla's admin, by hand. Nothing issued an invoice, nothing reminded
a company and nothing noticed an unpaid one, so with more than a couple of
companies Rihla's income depended on somebody remembering.

## Decision

1. **Two kinds of invoice, both from the company's record.** The
   registration fee, once, while it is set and not yet paid; and the
   monthly fee (`partners.monthly_fee_minor`, new), one invoice per whole
   calendar month from `billing_starts_on`. No proration, because no rule
   for it was agreed. Nobody types an invoice in, so an amount on an
   invoice is always the amount on the record when it was issued.
2. **One daily run, idempotent** (`companies:bill`, 06:10). It issues
   what is missing, including months a dead cron line skipped, and sends
   reminders: when issued, a few days before the due date, and once when
   overdue. Running it twice sends nothing twice. "Issue invoices now" on
   the company's record runs the issuing half at once.
3. **Paid by transfer, recorded by a person.** Decision 14 rules out
   cards, so the PDF carries Rihla's own bank account. This is the one
   document where a company is shown Rihla's account, because here Rihla
   is the one being paid. Rihla's admin records the payment once it has
   arrived. Recording a registration invoice as paid records the fee as
   paid, which is what lets the website be switched on (decision 13).
4. **No automatic suspension until the owner chooses it.**
   `companies.billing.suspend_after_days` is null. Once set, the run
   suspends a company whose invoice is that many days past due, names
   the invoice as the reason, and leaves reactivation to a person.
5. **Told twice, in different ways.** The company's Owners are e-mailed
   (best effort, since there may be no SMTP account yet) and see an
   overdue invoice across the top of their panel, with every invoice and
   its PDF under Company details. Rihla's admin sees overdue invoices on
   Needs attention. Other members of the company's team see none of it:
   an invoice is the business's account with Rihla.
6. **`company_invoices.partner_id`, not `company_id`.** `company_id`
   means "a record in the company's own books, scoped to it". These
   invoices are Rihla's books, so they use a plain indexed column with no
   foreign key, for the SQLite reasons in AGENTS.md. The table is in
   `Anonymisation::KEEP`: one business billing another, with nobody named.

## Consequences

- A cancelled invoice is kept with its reason, and its month is never
  invoiced again (the unique key is company, kind and month). This is
  deliberate: "first month free" is a cancelled invoice, not a missing one.
- A suspended company is not invoiced for months it cannot use. Its
  invoices that were already issued are still chased.
- Changing a fee changes only the invoices issued after the change.
