# Travel agency: flights, hotels abroad, visas for other countries

**Asked for by the owner, November 2026** ("Plan both, start with launch").
This is the fifth main part. It is planned now and built after Transport. It
becomes the `modules/Travel` module.

## The decisions, taken as defaults

| # | Question | Default | Why |
|---|---|---|---|
| 1 | Book online, or enquire? | **Enquire and quote**, for flights, hotels and visas alike. The customer says where, when and who. Staff quote one or more options, the customer accepts one and pays, and staff issue the ticket or voucher and upload it. Online booking waits for a supplier account (see below). | Live flight or hotel booking needs a paid supplier account (an airline consolidator, or a hotel bed bank), and none exists. A quote flow works from day one and stays useful afterwards. |
| 2 | Which visas? | **A list staff keep**: country, visa type, the documents needed, the usual processing days and the fee. Each comes with a checklist the customer works through. Anything else is "on request". | The list is what customers ask about. A fixed list typed in by a developer would go stale. |
| 3 | Licence | **Built and switched to "coming soon"** until the owner confirms the travel agency licence, as Stays guesthouses were. | The service switch is the safeguard. |
| 4 | Payment | **Bank transfer or cash**, recorded through the payment ledger. Card payment comes with BML. | As every other part. |
| 5 | Documents | **Reuse the document wallet:** passports, uploaded tickets and visas, encrypted and audited. | It already holds passports safely. |

## Phases

| Phase | What |
|---|---|
| **A1** ✅ | **Requests and quotes.** A "plan my trip" form (flight, hotel, visa or a mix). A staff board of requests. Quotes with several options and an expiry. The customer accepts one online. Payment against the accepted quote. |
| **A2** ✅ | **Fulfilment.** Staff upload the issued e-ticket, hotel voucher or visa. The customer sees everything for the trip on one page, in the portal. |
| **A3** ✅ | **Visa desk for other countries.** The country and visa-type list, the checklists, document collection through the wallet, and status (submitted, approved, refused). *Built with the wallet's encryption and disk rather than the Umrah wallet itself, which is tied to Umrah travellers and companies.* |
| **A4** ⏸ | **Online booking**, only when the owner signs up with a supplier (not built: no supplier account exists yet): search and book flights or hotels from that supplier's API, with Rihla's margin added. |

## What the owner must supply
- The travel agency licence, to switch it from "coming soon" to on.
- For A4 only, a flight or hotel supplier account and its API credentials.
