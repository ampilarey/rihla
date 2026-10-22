# The books

Batch C from the Hijaz comparison, as the owner chose: **a cash book per
departure with a profit and loss, income from the payment ledger
automatically, expenses typed by Finance against a departure or the
company, and reports — month by month, what is still owed, what came in
— each as a screen and a CSV. Finance records; Finance, the Operations
Manager, Reporting and the Super Admin read.**

## Where

**Bookings → Books** (`books.view`), four screens on one page, each with a
**CSV** button. Every export is written to the audit trail.

| Screen | What it shows |
|---|---|
| **Cash book** | A departure's — or the company's — money that actually moved, in date order: payments Finance verified (with what arrived when it was not rufiyaa), refunds, and costs marked *paid*. Totals per currency; one figure where every rate is known. The journey's margin (booked, costs, margin, per traveller) sits above it. Filter to a month. |
| **Month by month** | The last 6, 12 or 24 months: came in, refunded, paid out, net — every departure and the company together, by the month the money moved. |
| **Still owed** | Every held, confirmed or completed booking with a balance, soonest departure first, with totals. |
| **What came in** | Every payment verified between two dates, how it came, what arrived, who verified it; totals by currency and by method. |

## What counts

- **In** is the payment ledger's *verified* rows — the one place money is
  ever recorded as received. A payment recorded but not yet verified is
  not in the books. A refund is money out, dated when it was made.
- **Out** is a cost marked **paid**, dated when it was incurred. A cost
  that is only *agreed* or an *estimate* is in the journey's margin
  (Travel → What each journey made) and not in the cash book, because the
  cash book is what happened, not what is expected to.
- A per-person cost is multiplied by the confirmed travellers on the day
  the book is read.
- Currencies are kept apart. They are added into one rufiyaa figure only
  where the exchange rate table knows every rate involved
  (`docs/CURRENCIES.md`); otherwise the screen says which currency has no
  rate, and shows no total rather than a made-up one.

## Costs of the company

**Travel → Journey costs → Add a cost** with the departure left empty is a
cost of the company's own — rent, software, the agent's annual fee —
shown in the company's cash book and in no journey's margin. Two
categories were added for it: *Agent fees* and *Office and running costs*.

## Settling closes the books

A departure is **settled** from the board or the package's departures
list (`docs/DEPARTURE_LIFECYCLE.md`) once it is home and its books are
done. Settling is refused while any of its costs is still an *estimate*:
mark each agreed or paid, or remove it, first. Once settled, its costs
cannot be edited or removed by anybody, and its cash book says *Settled —
books closed*.
