# Transport: launches, speedboats and cargo

**Asked for by the owner, November 2026** ("Plan both, start with launch").
This is the fourth main part of the site, beside Rihla's Umrah business, the
Umrah companies and Stays. It sells seats on scheduled boats between islands,
takes charter and cargo requests, and checks passengers on board.

It is the `modules/Transport` module (see `docs/MODULES_PLAN.md`), and it
reuses what is already built: islands, customers, the payment ledger, the
service switch, the Telegram bot and the PDF and QR stack.

## The decisions, taken as defaults

The owner was asked these questions and left them open. Each default can
be changed from the admin or a config value, without a rebuild.

| # | Question | Default | Why |
|---|---|---|---|
| 1 | Whose boats? | **Both.** Every boat belongs to an operator, which is a `partners` row of kind `operator`. Rihla's own boats belong to Rihla's own partner. Other operators get their own panel in T5. | It is the Stays pattern (a host is a partner), so commission, statements and the panel all follow it. |
| 2 | Timetable or charter? | **Scheduled sailings first**: a route (island A to island B), a boat, a departure time and a number of seats. **Charters are an enquiry** that staff quote (T4). | Scheduled seats are what people book every day. A charter is a conversation. |
| 3 | Cargo pricing | **Quoted each time** (T4): the sender describes the cargo, staff quote, and the sender pays. A per-kilo fare table can come later. | No rate card exists, and an invented one would be wrong. |
| 4 | Tickets | **Yes.** Each passenger gets a ticket with a QR code. The crew scans it at boarding, using the existing Scan pattern (T3). | A manifest of who actually boarded is what the coast guard and the operator ask for. |
| 5 | Payment | **Bank transfer or cash**, recorded through the existing payment ledger. Card payment arrives with BML for the whole site. | It is how every other part of the site takes money today. |
| 6 | Price | **One adult fare and an optional child fare per route**, in MVR. | That is the simplest honest price. Tourist/local and return fares come later if asked for. |
| 7 | Holding seats | **15 minutes** while the booker fills in passenger names. Then the booking is **confirmed by staff**, or by payment, and an unpaid hold lapses. | The seat counter has the same lock and lapse rules as Umrah seats. |

## The data (T1)

- **`vessels`:** an operator's boat. Name, kind (speedboat, launch, dhoni, cargo), seats, cargo capacity in kg, registration number, active.
- **`boat_routes`:** an operator's route, from one island to another. Duration, adult fare, child fare, currency, active.
- **`sailings`:** one departure of a route on a boat at a time. It carries:
  - seats on sale, and seats taken, a counter kept under a row lock as `departures` does;
  - a status: scheduled, boarding, departed, or cancelled with a reason.
- **`boat_bookings`:** one booker's seats on one sailing. Reference, customer, contact phone, seats, total, paid, currency, held until, and status (held, confirmed, cancelled, lapsed). It takes payments (`TakesPayments`).
- **`boat_tickets`:** one passenger on a booking. Full name, child or adult, a random token for the QR code, and boarded-at.

How each table is classified for privacy:
- `boat_tickets` (names) and `boat_bookings` (contact phone) are scrubbed by `data:anonymise`, and `data:forget` reaches them through the booking's customer.
- `vessels`, `boat_routes` and `sailings` are kept.

**Permissions:** a new `transport` area:
- `transport.viewAny` and `transport.view` let staff see transport records;
- `transport.manage` covers boats, routes and sailings;
- `transport.book` covers taking a booking for somebody;
- `transport.board` covers scanning passengers on board.

Operations Manager holds all of them. Booking Staff hold the read and book permissions.

## Phases

| Phase | What | Done when |
|---|---|---|
| **T1** ✅ | **Foundations.** The module, the tables, the models, and the staff screens for boats, routes, sailings (one at a time, or a week of them from a timetable), and bookings. `TransportDesk` holds seats under a lock, confirms, cancels and lapses. Permissions. Privacy lists. (The public on/off switch arrives with the public pages in T2.) | Staff can set up a route and a week of sailings and book seats for a caller, and a test proves two bookers can never take the last seat twice. |
| **T2** ✅ | **Booking online.** Search by from, to and date. The booker picks a sailing and gives the passengers' names and a phone number. Seats are held, then the booker sees payment instructions and a "my tickets" page with a QR code per passenger. A Telegram alert goes to staff. | A visitor books two seats without phoning, and their QR tickets open on a phone. |
| **T3** ✅ | **Boarding.** A manifest PDF per sailing, and a QR scan that marks each passenger boarded. Cancelling a sailing tells every booker. | The crew checks a full boat on board from a phone, and the manifest shows who boarded. |
| **T4** ✅ | **Cargo and charters.** Request forms, a staff quote, a payment against the quote, and status updates (received, loaded, delivered). | A cargo request goes from form to delivered with a paid quote. |
| **T5** | **Other operators.** An operator panel, as hosts have one: their boats, routes, sailings, bookings and manifests. Rihla's commission on online seats, and monthly statements, as for hosts. | An outside operator runs their timetable from their own panel. |

## Not in this plan
- Live boat tracking.
- Weather cancellations decided automatically.
- Dynamic pricing.
- Seat maps.
- Connections across several boats.
- Integration with any other booking system.
