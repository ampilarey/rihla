# The departure lifecycle

Batch C from the Hijaz comparison. A departure is moved along by hand,
from the package's departures list, by somebody allowed to; the stage
decides what the dates and the old publish switch used to decide, and the
dates only say when a move looks overdue.

## The stages

| Stage | What it means | Who moves it there |
|---|---|---|
| **Draft** | Being set up. Hidden from the site, nobody can book it. Every new departure, and every copy, starts here. | — |
| **Open for booking** | On the site, selling seats (while it has seats and has not left). The only stage that lists and sells. | Operations Manager (`departure.lifecycle`) |
| **Closed to booking** | No new bookings; the ones it has carry on. Off the site. Can be opened again. A reason is asked for. | Operations Manager |
| **Departed** | The party has flown. The leader's tools and the board follow this, not the date. | Operations Manager |
| **Returned** | The party is home. Finance can settle it. | Operations Manager |
| **Settled** | Its books are closed; nothing on it is anybody's work. It leaves the board. | Finance or Operations Manager (`departure.settle`) |
| **Cancelled** | It is not running. Possible only while no booking on it is held or confirmed. A reason is asked for. | Operations Manager |

Allowed moves: Draft → Open or Cancelled · Open → Closed, Departed or
Cancelled · Closed → Open, Departed or Cancelled · Departed → Returned ·
Returned → Settled. Settled and Cancelled are final.

Opening is refused for a departure with no seats, or one whose departure
date has passed. Cancelling is refused while a held or confirmed booking
stands: cancel or move those first.

Every move is a line in the departure's history — who, when, from what to
what, and the reason — shown on the departure's form as *"closed by
Aminath on 3 Nov: flights full"*.

## What follows the stage

- **The public site** lists and sells only open departures. A closed
  departure disappears from the package page and the comparison; a
  pilgrim's own booking pages are unaffected.
- **Every door to a seat** — the checkout, the desk, a waitlist offer —
  passes through the seat allocator, which refuses any departure that is
  not open, whatever its dates and seats say.
- **The departure board** shows what is somebody's work: open, closed,
  departed and returned departures, and a draft leaving within two months.
  It says when a stage looks overdue:
  - a draft leaving within 60 days — *Not open for booking yet*;
  - open after its departure date — *Still open for booking after leaving*
    (a blocker);
  - closed after its departure date — *not marked departed*;
  - departed after its return date — *not marked returned*;
  - returned for more than 14 days — *not settled*.
- **The tour leader's tools, the Nusuk gate, the lists and the Telegram
  summary** leave settled, cancelled and (for the leader) draft departures
  out.

## Content Managers

A Content Manager still edits a departure's prices, hotels and itinerary,
and no longer takes it on or off sale: that is an operations decision
(`departure.lifecycle`), and the publish toggle that used to sit on the
form is gone.

## The passport rule

A passport has to be valid for six months (`PASSPORT_VALIDITY_MONTHS`)
beyond the journey. It is now counted from the day the journey **comes
home** (`PASSPORT_VALIDITY_FROM=return`), as Hijaz does: a passport that
runs out between the outbound and the return flight is the one an airline
refuses. Set `PASSPORT_VALIDITY_FROM=departure` to count from the day it
leaves, as before.

## Existing departures

The migration placed every departure by its dates: one that had already
come home became *Settled* (its books did not exist while it ran), one on
the road became *Departed*, a future one became *Open* if it was published
and *Draft* if not.
