# 0009 — One database, every Umrah record tagged with its company, walled twice

**Status:** Accepted
**Date:** 2026-11-18
**Context:** Phase 1 of [`UMRAH_COMPANIES_PLAN.md`](../UMRAH_COMPANIES_PLAN.md)
**Decided by:** the owner chose the outcome (decisions 1, 2 and 12 of the plan). The mechanism is recorded here.

## Context

Other Umrah companies will run their business inside Rihla: their own
admin panel, website and app. Each must be totally independent of rihla.mv
and of every other company (decision 2), while everything lives in one
application (decision 1) on cPanel (decision 10).

## Decision

**One database. Every Umrah record carries `company_id`, and two walls
keep it apart.**

1. **The column.** `company_id` is on the 57 Umrah tables listed in the
   plan's Phase 1 inventory.
   - It points at `partners`: an Umrah company is a partner, and Rihla's
     own is `is_rihla`.
   - It is a plain indexed column with no foreign key, because adding a
     constrained column makes SQLite rebuild the table and cascade-delete
     its children (AGENTS.md). A company leaves through an export, never a
     delete.
   - It is not `partner_id`, which on `packages` already means the
     guesthouse host who wrote a package.
2. **The company is copied, never chosen.**
   `App\Models\Concerns\BelongsToCompany` copies a new row's company from
   the records it hangs off. It throws `CompanyMismatch` when two of them
   disagree, or when a row's company would change. Only a root record
   takes the current company (`App\Support\CompanyContext`), which is
   Rihla's until a company has its own panel.
3. **First wall: a global scope.** Every query on those models is
   narrowed to the current company. That covers lists, a record reached
   by id or route binding, relations and subqueries. Laravel's unique
   rule bypasses Eloquent, so forms narrow it with
   `CompanyContext::uniqueWithin()`.
4. **Second wall: the gate.** A `Gate::before`, registered ahead of the
   Super Admin grant, refuses any stored record of another company,
   however it was reached.
5. **Unique columns are per company where a company names them:** a
   customer's login, a package's address, a staff profile's address.
   References stay globally unique, because a reference quoted to a bank
   must never collide.
6. **Files, scheduled work and the bot keep to one company.**
   - Another company's scans and slips go under `{base}/company-{id}/`;
     Rihla's stay where they always were.
   - `CompanyContext::eachCompany()` runs scheduled work once per company,
     each in its own context.
7. **Rihla's operator tools see everything, by design.**
   `CompanyContext::acrossEveryCompany()` runs `data:forget`,
   `data:anonymise`, the retention report and file encryption without a
   scope. A deletion request must reach a person wherever they booked.
   Nothing a company's staff can start runs this way.

**The release gate is a test.** `CompanyIsolationTest` signs in as a
Super Admin, the one role the gate otherwise lets through everywhere. It
tries a second company's staff lists, record pages, document downloads,
signed slip links, invoices, receipts, printable lists and portal links,
and must be refused each time. Each check first proves the same path
reaches Rihla's own record. With the scope removed, all seven of its
tests fail.

## Consequences

- **A new Umrah table** takes `company_id` in its own migration and its
  model uses the trait (AGENTS.md says how). A test fails if a model
  claims a company its table cannot hold.
- **Raw `DB::table()` reads bypass the scope.** Today the only ones on
  Umrah tables are in `data:forget`, which runs across every company
  anyway. A new one needs the same justification or a `where`.
- **What Phase 1 does not do:** decide which company a request works for
  from its host name, give a company its own panel, its own bot, or a
  reference prefix. Those are Phases 2 and 4. Until then, every surface
  works for Rihla, so Phase 1 changed nothing anybody sees.
- **The honest limit** in the plan stands: whoever runs the server can
  read the database. The walls are about the application, not the
  operator.

## Alternatives rejected

- **A database per company.** Each company would be strongly isolated,
  but cPanel gives one database per account in practice. Every migration
  and backup would also run N times, and Rihla's own operator tools would
  have to fan out.
- **A scope without the gate, or the gate without the scope.** Either
  alone leaves one way through: a forgotten `withoutGlobalScopes()`, or a
  list that never calls a policy.
- **Deriving the company through joins instead of storing it.** It saves
  a column, but every record reached by id needs a different join to
  check, and a single missed join is a leak.
