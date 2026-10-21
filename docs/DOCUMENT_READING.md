# Document reading

Batch B from the Hijaz comparison. A passport, an ID card, a visa or a bank
slip uploaded to the wallet is **read**, the reading is **proposed**, and a
member of Visa Staff (anybody with `document.update`) **accepts** it field by
field. Nothing reaches a traveller's record unread by a person: the reader
proposes, the person decides, and the decision is written down with the
person's name on it.

## What reads

`DOCUMENTS_READER` in `.env`:

| Value | What happens |
|---|---|
| `auto` (default) | Claude's vision API when `ANTHROPIC_API_KEY` (or `DOCUMENTS_ANTHROPIC_KEY`) is set — the drafting assistant's key will do; otherwise Tesseract when its binary is on the host; otherwise nothing is proposed. |
| `anthropic` | The vision API only. Without a key, nothing is proposed. |
| `tesseract` | OCR on the server only. No image leaves the host. Needs `tesseract` on the PATH (`TESSERACT_BIN` to point elsewhere; `TESSDATA_PREFIX` and `TESSERACT_LANGS` as the install needs). cPanel hosts rarely have it. |
| `manual` | Nothing is read. Staff type what the scan says, exactly as before this existed. |

A PDF with a text layer (an e-visa, a bank statement) is read from its text
by `pdftotext` when poppler is on the host, and needs no OCR and no API.

**The vision API sends the scan to Anthropic.** A passport is personal data;
decide with that in mind. Tesseract keeps everything on the server. Either
way the scan itself stays encrypted in the wallet, and only the reading —
the fields and the raw text — is stored beside it, in `document_extractions`.

## What a reading is

A row in `document_extractions` per attempt, append-only: which reader, the
fields it proposed with a confidence per field, the raw text, whether the
passport's machine-readable zone passed its check digits, and later who
accepted or set it aside and what they accepted.

A passport reading is **check-digited**. The two lines at the bottom of a
passport carry a check digit for the number, the date of birth and the
expiry; a reading that passes all three is almost certainly right, and one
that fails is marked **FAIL** in the accept screen before anybody looks at
the boxes. OCR's usual confusions (O for 0, I for 1, S for 5) are repaired
before checking.

## Accepting

On **Documents**, the Reading column says *To accept* and the row's
**Accept reading** opens the fields with what was read, each with *Now: …*
showing what the record holds. Blank a box to leave that field alone. The
screen also says:

- whether the MRZ passed,
- which fields the reader was unsure of,
- whether the passport or ID number is **already on file** for another
  traveller — accepting files it twice, which is usually a duplicate
  traveller record,
- and for a visa, whether to mark the traveller's visa application
  **issued** with this document as the evidence.

Tick **Verify** to mark the document verified at the same time. Accepting
writes only the fields that type of document may write (a passport never
writes medical notes), normalises them (upper-case numbers, two-letter
countries, `male`/`female`), and sets the document's expiry. **Ignore
reading** sets the proposal aside and writes nothing. **Read again** proposes
afresh — after switching readers, say.

## Visas in bulk

The agent sends the departure's visas as one PDF with a page per pilgrim,
or as a folder of files. **Travel → Visas in bulk** takes the departure and
the files, takes the PDF apart with poppler (`pdfseparate`, which comes
with `pdftotext`), reads every page — the text layer when it has one, the
configured reader when it is a scan or a photo — and offers each page to
the traveller on that departure whose **passport number** it carries, or
failing that the one whose **name** it nearly is (four words in five, and
no other traveller as close). A page matched by name is marked as a guess;
a page nobody matched has nobody chosen.

The person changes or clears any match, then **File the visas**. Each page
left matched goes into that traveller's documents as their visa, verified,
with the reading accepted; the traveller's visa application on that
departure is marked **issued** with the document as evidence, opened first
if none existed. A page left out files nothing. A visa never renames
anybody or writes a passport number: the match was the person's decision.

Until it is filed or thrown away the batch sits on the documents disk,
encrypted like everything else there, under its own token; a batch nobody
came back to is swept after a day. Without poppler the page still takes
photos and one PDF per visa, matched by hand.

## The cross-check and the board

When both an ID card and a passport have been accepted for the same
traveller, the two are compared on date of birth, gender and name. A
disagreement is stored on the traveller and the departure board says *An ID
card and a passport disagree* until somebody corrects a reading or explains
it on the traveller (a misprinted card, a changed name).

## Photos

A passport-size photo is checked on upload — size, aspect, file weight — and
the result is said on the row (*Photo looks right* / *Photo: check*), never
blocked: a visa agent's rejection costs more than a warning, and the owner
confirms the exact numbers their agent wants in `config/documents.php`.

## Bank slips

A slip attached to a payment is read the same way, and the amount, reference
and date it carries are shown to Finance beside the payment and in the
**The money is in** confirmation, so the person reconciling sees what the
slip says next to what the booking expects. The reading never reconciles
anything by itself.

## Privacy

`data:anonymise` blanks every reading (fields, raw text, what was accepted)
along with the traveller's ID number, the cross-check notes and the slip
readings. `data:forget` reaches readings through the document version they
belong to, so a right-to-erasure request takes them with the scan.
