# Restoring production data onto the test server

`test.rihla.mv` auto-deploys from `main` and **is reachable on the public
internet**. A production backup restored onto it without scrubbing puts real
passport numbers, medical notes and telephone numbers on a public host.

§10.4 of the upgrade plan asked for this before Phase 3 shipped. It was not
written then; it is written now, and this is how to use it.

## The procedure

Run every step **on the test server**. Nothing here touches production, and
step 3 cannot: `data:anonymise` refuses outright when `APP_ENV` is
`production`, with no override flag and no environment variable that unlocks
it.

```bash
# 1. On the test server, take the production backup you were given and
#    restore it into the TEST database. Never the other way round.
mysql -u <test_user> -p <test_database> < production-backup.sql

# 2. Look at what would change before changing it.
cd /home/rihla/test.rihla.mv && php artisan data:anonymise --dry-run

# 3. Scrub it.
php artisan data:anonymise

# 4. Nothing on the scrubbed database can be signed in to — the password
#    hashes are left alone on purpose rather than set to something known.
#    Make an account.
php artisan admin:create you@example.com <a password you choose>
```

## What it does

| | |
|---|---|
| **Scrubbed** | Names, e-mail addresses, telephone numbers, national IDs, passport numbers, addresses, medical notes, free-text notes on bookings, payments, enquiries, incidents, questions and tasks — replaced with stand-ins keyed to the row, so "Placeholder Person 41" is the same person everywhere and obviously not a real one. |
| **Regenerated** | Portal and family-portal tokens, so a link somebody was sent for a real booking does not open a scrubbed one on a public host. |
| **Emptied** | The audit log, sessions, password-reset tokens, queues, import/export records, Pulse's tables, broadcast deliveries and seat holds. A scrubbed audit log reads as evidence, and a session row is a live credential. |
| **Kept** | Packages, departures, prices, itineraries, rooms, content and roles — nothing in them is about a person, and a scrub that empties them produces a test server nobody can test on. |

E-mail addresses become `person41@example.invalid`. `.invalid` is reserved by
RFC 2606 and can never be delivered to, so a stray send from the test server
reaches nobody. Telephone numbers start `3`, which the Maldives does not
issue for mobiles.

## Why it refuses when the schema has moved

`App\Support\Anonymisation` classifies **every** table in the database —
scrub, empty, or keep — and the command compares that list against the live
schema before it does anything. An unclassified table stops the whole run.

This is the point of the design. A scrubber built from a list of tables
somebody remembered is a scrubber that misses the table added last Tuesday,
and it misses it in the direction where real data survives on a public
server. `AnonymiseTest` asserts the same thing in CI, so a new table fails a
test rather than a pilgrim's privacy.

**When it stops on an unclassified table, add it to one of the three lists in
`App\Support\Anonymisation`.** Do not work around it.
