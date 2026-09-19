# Getting the Dhivehi site translated

## Why this exists

Four audits of this project found Dhivehi that had been generated rather than
translated. The signature was always the same — many unrelated English strings
collapsing to one identical Dhivehi string:

| Where | What was found |
|---|---|
| `resources/lang/dv/guide.php` | 19 keys, **3** distinct values. One word appeared **31 times** on the live Dhivehi guide page, labelling Step, Checklist, Fiqh Notes, Du'a, Print, Previous Step and ten more |
| `resources/lang/dv/messages.php` | **91** keys sharing a value with an unrelated key — a file-upload instruction shared one with "Edit" — plus seven keys defined twice |
| Umrah guide steps (database) | All ten Dhivehi steps carried the **same** `dua_text` and the same `reference_text`, where the ten English steps carry ten real supplications and citations |
| Homepage "Why Choose Rihla" | Three feature titles sharing a 19-character suffix; two of three bodies sharing **72 of 77 characters** |

None of it was paraphrased or guessed at. It was removed, and the site falls
back to English wherever Dhivehi is absent.

**So the Dhivehi site currently reads almost entirely in English.** That is
correct — correct English beats invented Dhivehi, and on Umrah instructions it
is not a close call — but it is not finished.

## What a translator needs to do

Everything is in one spreadsheet. Nobody needs to open a PHP file.

```bash
php artisan rihla:translations:export
```

That writes a CSV to `storage/app/translations/`. It opens in Excel (it carries
a byte-order mark, so Thaana renders correctly rather than as mojibake). One row
per string:

| column | meaning |
|---|---|
| `english` | what the site says today |
| `current_dv` | the Dhivehi that is there now, if any |
| `status` | `MISSING` or `please check` |
| `new_dv` | **the only column to fill in** |

Leave `new_dv` empty wherever the existing Dhivehi is already correct.

As of the last export: **281 strings — 114 with no Dhivehi at all, and 167
carrying a value no speaker has ever checked.** Please treat all 167 as
unverified. The check that found the fabrication catches many keys sharing one
value; it cannot catch a translation that is simply wrong.

Then:

```bash
php artisan rihla:translations:import storage/app/translations/dv-translations-<date>.csv --dry-run
php artisan rihla:translations:import storage/app/translations/dv-translations-<date>.csv
php artisan test --filter=TranslationQualityTest
```

The import **refuses** a file in which two unrelated strings would render
identically, and writes nothing at all when it does. It compares the English
meaning behind each key, not the keys — `en/messages.php` deliberately gives
some strings two keys (`'Manage Trips'` and `'manage_trips'`), and one Dhivehi
value covering such a pair is correct. A blunter rule flagged those too, and
would have deleted eight real translations.

## The other half: 147 strings that are already translated and never shown

Views call `__('Trips')`. A key with no dot is a *JSON* lookup, against a
`lang/dv.json` that does not exist — so Laravel returns the key itself and the
visitor sees English. The Dhivehi is sitting in `messages.php`, reachable only
as `__('messages.Trips')`.

Rewiring those 147 call sites was written and measured: the Dhivehi trips page
went from **86 to 701** Thaana characters and the navigation turned Dhivehi. It
was then withdrawn, because the audit had just proved the file supplying those
strings was partly fabricated — wiring 147 more of them sight-unseen would have
replaced readable English with nonsense.

**Once a speaker has confirmed `messages.php`, that rewiring is half an hour's
work and is the single biggest improvement available to the Dhivehi site.**

## Page content is separate, and some of it needs a scholar

The spreadsheet covers interface strings only. These are entered in the admin
panel instead:

- **Umrah guide steps** — Admin → Guide Steps, locale `dv`. Ten steps, each with
  a title, summary, checklist, **du'a**, reference and fiqh notes. The du'as and
  the references are religious instruction a pilgrim acts on; they need someone
  qualified to supply them, not a translator working from English. Until they
  exist the Dhivehi guide shows the English steps, which are correct.
- **Homepage "Why Choose Rihla"** — Admin → Why Section. One section plus three
  features. The English copy is real and is what `/dv` shows today.

Anything entered in the admin panel wins over the English fallback immediately.

## The guard stays on

`tests/Feature/TranslationQualityTest.php` fails if unrelated keys ever share a
Dhivehi value again, if two guide steps share a du'a or a reference, or if two
features in one section share more than half their characters. It was checked
against the original data before being trusted: 72 of 77 shared characters,
against a threshold of 38.5.
