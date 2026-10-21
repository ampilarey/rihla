# The staff Telegram bot

Batch A4 of the Hijaz comparison. Linked staff get told what is waiting,
ask the bot for people, bookings, departures and lists, and act on a few
things with a confirmation tap. Everything the bot does is done **as the
linked person**, with that person's permissions, and written to the audit
trail with `channel = telegram`. Nothing changes for anybody who has not
linked a chat.

## Setting it up (once)

1. In Telegram, open **@BotFather**, send `/newbot`, and follow it. Keep the
   token it gives you; it is a password.
2. Put the token in the server's `.env` **on one site only**:

   ```
   TELEGRAM_BOT_TOKEN=123456789:AAH...
   TELEGRAM_BOT_USERNAME=rihla_staff_bot
   ```

   Telegram allows one webhook per bot, and every deploy points the bot at
   the site it ran on. Production and test therefore need **two bots**, or
   the test server's deploy steals the production bot.
3. Deploy. The deploy script runs `php artisan telegram:webhook`, which
   registers `https://<site>/api/telegram/webhook` with a secret derived
   from `APP_KEY` (or `TELEGRAM_WEBHOOK_SECRET` if set). `php artisan
   telegram:webhook --show` prints what Telegram currently holds.
4. Each member of staff opens **Telegram** in the staff panel menu, taps
   **Get a link code**, and opens the link on their phone (or sends
   `/start CODE` to the bot). The code lasts ten minutes and works once.

The morning summary goes out at `TELEGRAM_DAILY_SUMMARY_AT` (08:00) through
the scheduler that `scripts/install-scheduler-cron.sh` installs.

## What it does

| | Who | |
|---|---|---|
| `/today` | everybody | what is waiting for you, and the departures ahead |
| `/departures`, `/departure 12` | `departure.viewAny` | the departures ahead; one in detail |
| `/find Aminath` | `booking.viewAny` | a traveller by name, passport or booker's phone; a booking by reference |
| `/balance RIH-B-2026-0001` | `payment.viewAny` | what a booking owes |
| `/slips` | `payment.viewAny` | payments waiting to be verified, with Verify / Not received buttons for `payment.reconcile` |
| `/ready 12` | `departure.board` | what blocks a departure |
| `/checklist 12` | `checklist.viewAny` | open items, ticked by tap with `checklist.tick` |
| `/bus 1 12`, `/rooms makkah 12`, `/manifest airline 12` | `list.view` | the lists as PDF files |
| `/invoice RIH-B-…`, `/receipt RIH-P-…` | `booking.view`, `payment.download` | PDFs to forward |
| a photo captioned `RIH-B-2026-0001 10000` | `payment.create` | records a bank slip, waiting for Finance |

A departure has no code, so commands take its number from `/departures`
and default to the next one to leave.

**Told, without asking:** when a slip arrives (a payment starts waiting),
everyone linked who holds `payment.reconcile` gets a message with the two
buttons, except whoever recorded it.

Every money action takes two taps: the first says exactly what is about to
happen, the second does it. A refusal asks for the reason and reads the
next message as it.

## What it refuses

- An unlinked chat is told how to link, once a day, and nothing else.
- A code that is expired, used, or for an account without `telegram.link`
  does not link.
- Linking a chat revokes any earlier link for that account or that chat.
- `/unlink` (or the panel's Unlink button) stops the chat acting as the
  account at once.
- The webhook answers 404 without a token and 403 without the secret.

## On the test server

`data:anonymise` empties the three Telegram tables, so a restored copy of
production starts with nobody linked and a test bot cannot message real
staff.
