# 0007 — Two brand colours that cannot share a background

**Status:** Accepted
**Date:** 2026-09-21
**Supersedes:** the wine-and-gold palette recorded in `BRAND.md` 1.0

## Context

The owner adopted a new palette: violet `#5F498A` and lemon chiffon `#FEF9CD`.
Both are good choices — violet on chiffon measures **7.00:1**, exactly the AAA
threshold for body text, and purple and yellow are true complements.

The trouble is that 7.00:1 is also the whole distance between them.

For two shapes to each clear 3:1 against one shared background, they must be at
least 3 × 3 = **9:1 apart**. They are 7:1 apart. So there is **no background,
anywhere, on which both are visible** — not white, not cream, not ink, not
black. On cream the chiffon measures 1.05:1. The best ground that exists for the
pair puts both at 2.65:1, still short, and looks muddy.

This is not a choice we made and could unmake. It is arithmetic that follows
from the two hexes, and every awkward thing in the system below follows from it.

## Decision

**Gold carries two values, one per ground.** `gold-500` `#EFD34D` is 9.85:1 on
ink and 1.46:1 on cream. `gold-600` `#A88C1F` is the reverse. They are the same
colour doing the same job on different backgrounds, not a ramp step.

**The logo ships as two files with different sails.** `rihla-mark.svg` uses the
dark gold and the mid violet; `rihla-mark-inverse.svg` uses the bright chiffon
and a lighter violet. Recolouring one and copying it over the other is the
specific mistake this ADR exists to prevent — the previous marks did exactly
that and their sails sat at 1.8:1 on the footer for as long as the inverse mark
existed, invisible to a test that compared the two files to each other instead
of to their backgrounds.

**A third colour carries the booking action.** Chiffon cannot be a button on a
pale page — 1.46:1 means the button has no findable edge, whatever its label
does. Violet could, but violet is already the navigation, the headings and every
other button. `teal-600` `#0A5754` is the strongest hue left that no status
colour has claimed (31° from the success green), at 8.21:1 against the page.
Light grounds only: on a dark section it is 1.75:1 and `.btn-gold` takes over.

**Gold never appears on anything badge-shaped.** It sits 14° in hue from the
`warning` amber, so a gold "Featured" badge and an amber "Payment pending" badge
read as the same kind of thing. The semantic colours are not moved — they are
conventional and correct — so the rule is on the brand side instead.

**The light ground may not go warmer than `#FEF9CD`.** The gold sail reads
3.19:1 on `#FFFDF0` and 3.05:1 on `#FEF9CD`; one shade warmer is 2.84 and the
sail dissolves. This binds the bag cloth as much as the page.

## Consequences

Anyone reaching for "the brand gold" has to know which ground they are on. That
is a real cost, and the reason it is written down here rather than left to be
rediscovered.

In exchange the system is honest: every pair that touches has been measured, the
weakest is recorded, and `BrandColourTest` fails on a retired hex or a logo fill
that cannot be seen against its own background.

## What was rejected

**Using both exact hexes as the two logo sails**, which the owner asked for
directly. It cannot be done — see the arithmetic above. Each variant carries the
one its own background can show instead, so both brand colours do appear in the
logo, just never in the same file.

**Moving `warning` away from gold.** It would touch every payment and warning
state on the site for a collision that only shows up if gold is used on badges,
which the rule above forbids anyway.

**Renaming the Tailwind tokens.** `wine` holds the violet and `gold` holds the
chiffon, which reads oddly. Renaming would have touched every view in the
codebase to change nothing a visitor can see.
