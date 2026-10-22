# Currencies

Batch C from the Hijaz comparison, as the owner chose: **prices and
balances stay in rufiyaa; a payment may arrive in dollars or riyals and is
credited at the rate Finance records that day; the site can show what a
price comes to in the other two, from a rate table Finance keeps.**

## The rate table

**Bookings → Exchange rates** (`rate.manage`: Finance and the Operations
Manager). One line per day the rate was set: the currency, how many MVR one
unit is worth, the day it applies from, and a note saying where the figure
came from. Add a new line when the rate moves rather than editing the old
one, so a payment recorded in March still shows the rate March had. The
latest line whose day has come is the current rate.

Where the table has no line for a currency, `config/finance.php` is read
as before (`'USD' => 1542` meaning 15.42). Where neither names a rate,
**there is none**: the record-payment form asks for one, the site shows no
equivalent, and the profitability report keeps its currencies apart. A rate
is never invented.

## Recording a payment in another currency

On a booking, **Record a payment** has *Received in*. Choose USD or SAR and
the form asks for the rate (filled in from the table for the day, editable
to the rate the bank actually gave) and shows what the booking will be
credited: *= MVR 10,023 credited to the booking*. The payment keeps all
three — what arrived, the rate, and the credit — and every list, the
receipt, the pilgrim's portal and the Telegram alert say *received USD 650
at 15.4200* beside the MVR figure. The ledger, the paid total and the
balance are unchanged: they only ever see the credit.

Through the Telegram bot: caption the slip `RIH-B-2026-0001 650 USD` and it
is credited at the table's rate for the day; without one, the bot says so
and records nothing.

Refunds are made in the booking's currency, as they were.

## What the site shows

Where a rate exists, the package page's price list and the departure cards
show *≈ USD 1,848 · SAR 6,931* under each rufiyaa price. Where none does,
nothing is shown. A visitor price list (USD, Phase 15) is unchanged.

## Bank accounts

`PAYMENTS_BANK_ACCOUNT_SAR` joins the MVR and USD accounts in `.env`; the
transfer instructions show the account for the currency being paid.
