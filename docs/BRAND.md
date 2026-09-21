# Rihla Brand & Design System

**Version:** 2.0
**Date:** 2026-09-21
**Status:** Violet palette **implemented**. Logo **settled** (§4).
**Implemented in:** `tailwind.config.js`, `app/Support/Brand.php`, `resources/css/app.css`

This is the reference for Rihla's visual system. The roadmap that depends on it is
[`WEBSITE_UPGRADE_PLAN.md`](WEBSITE_UPGRADE_PLAN.md); this document is the spec.

---

## 1. The palette

The palette is violet `#5F498A` and lemon chiffon `#FEF9CD`. It replaced a wine-and-gold scheme,
which had itself replaced a gold-and-sky-blue one that failed AA at the two jobs it was used for
most.

**The one thing to understand before using it.** The two brand colours are **7.0:1 apart**. For
two shapes to each clear 3:1 against one shared background they must be at least 9:1 apart, so
**no background shows both**. On cream the chiffon is 1.46:1 — not faint, absent. On ink the
violet is 1.96:1. Everything awkward below follows from that single fact: gold needs two values,
one per ground; the logo needs two variants; and the button that means "book" is neither of them.

The token *names* are historical — `wine` holds the violet and `gold` holds the chiffon. Renaming
them would have touched every view for no gain; the values are what changed.

### Primary — Wine

| Token | Hex | Use |
|---|---|---|
| `wine-50` | `#F7F5FA` | Tints, hover washes |
| `wine-100` | `#EDE8F5` | |
| `wine-200` | `#D9CFEA` | |
| `wine-300` | `#BBACD6` | |
| `wine-400` | `#9481BA` | The sail on the dark logo — 4.27:1 on ink |
| **`wine-500`** | **`#5F498A`** | **Primary. Buttons, links, active states** |
| `wine-600` | `#4C3A70` | Hover |
| `wine-700` | `#3C2E59` | Active |
| `wine-800` | `#2E2245` | Dark sections, the footer, the logo hull |
| `wine-900` | `#1E162E` | |

Hover and active states move **down the wine ramp**, never sideways into another hue.

**Why the violet is not more vivid.** It measures 31% saturation, which is far quieter than the
colours it sits beside in travel. That is not a missed opportunity — violet at this lightness
cannot be loud, and the pair does not need it to be. Chiffon is 83–90% saturated and does the
shouting; purple and yellow are complementary, the same relationship as blue and orange, so the
contrast is there. It just comes from the yellow.

### Accent — Gold

| Token | Hex | Use |
|---|---|---|
| `gold-400` | `#F8E57A` | |
| **`gold-500`** | **`#EFD34D`** | **Highlight — dark grounds only. 9.85:1 on ink, 1.46:1 on cream** |
| `gold-600` | `#A88C1F` | The same gold where the ground is light. 3.26:1 on white |
| `gold-700` | `#7A6413` | Gold as text on cream — 5.62:1 |

**Gold is an accent, never a second primary.** Two hard rules:

- **Gold fills take ink text, never white.** `gold-500` against white is **2.38:1**. Against ink
  it is **6.24:1**.
- **Gold text on a light ground uses `gold-700`**, which reaches 6.36:1 on white. `gold-500` as
  body or link text on white fails.

### Ink & Cream

| Token | Hex | Use |
|---|---|---|
| `ink` | `#2E2245` | All body text, headings, dark UI. Softer than black, which is what keeps it premium |
| `ink-muted` | `#6B6080` | Secondary text, captions |
| `cream` | `#FFFDF0` | Main warm background |
| `cream-deep` | `#FEF9CD` | Alternating sections, sunken surfaces |

The page should **not** become uniformly cream. Hierarchy runs **cream → wine → gold → ink**,
with white surfaces carrying content and wine marking the things that matter.

### Semantic

| Token | Hex | `dark` step | Use |
|---|---|---|---|
| `success` | `#0F7A54` | `#0B5B3E` | Paid, verified, permit issued |
| `warning` | `#9E6A0D` | `#7A5209` | Awaiting payment, passport expiring |
| `error` | `#D92D20` | `#A3231A` | Visa rejected, payment failed, validation |

Semantic colours **never** stand in for the brand just because something is important.

Two notes that matter in practice:

- **Base `warning` and `error` fail on cream** (4.31 and 4.49, just under AA). On cream
  surfaces use `text-warning-dark` / `text-error-dark`. On white the base values pass.
- **Green cannot be brightened.** Every livelier green tested fails white text —
  `#12916A` at 3.97, `#15A078` at 3.32. Solid success fills stay at `#0F7A54`; status chips use
  a pale tint with dark text, which reads better anyway.

### Warm neutrals

Tailwind's default `gray` is cool and fights the ground, so it is **overridden**. The ramp was
warm under the wine palette and is violet-tinted under this one, matched step for step to the
lightness it replaces. Every step measures equal or better contrast than the warm step it
replaced; none regressed.

| Step | Violet-tinted | Step | Violet-tinted |
|---|---|---|---|
| 50 | `#FBF9FD` | 500 | `#6E6680` |
| 100 | `#F4F1F8` | 600 | `#564F66` |
| 200 | `#E8E3EF` | 700 | `#433C52` |
| 300 | `#D4CDE0` | 800 | `#2B2437` |
| 400 | `#A49CB4` | 900 | `#1B1526` |

### Teal — the action colour

Neither brand colour can be the button that means *book*: chiffon is invisible on the page, and
violet is already the colour of the navigation, the headings and every other button. Teal is the
strongest hue left that no status colour has claimed — 31° from the success green.

| Token | Hex | Use |
|---|---|---|
| `teal-500` | `#0E6E6B` | |
| **`teal-600`** | **`#0A5754`** | **`.btn-action` — the booking call to action. Light grounds only** |
| `teal-700` | `#084B49` | Hover |

`teal-600` is 8.21:1 against the cream page, so its edge is findable, and white on it is 8.39:1.
On a dark section it is **1.75:1** and disappears — use `.btn-gold` there.

---

## 2. Components

Defined once each in `resources/css/app.css`, inside `@layer components`.

| Class | Composition |
|---|---|
| `.btn-primary` | `wine-500` fill, white text, hover `wine-600`, active `wine-700` |
| `.btn-secondary` | Cream surface, `wine-500` border, `wine-600` text, hover `wine-50` |
| `.btn-gold` | `gold-500` fill, **ink text**, hover `gold-600` |
| `.btn-outline` | Ink border and text, hover ink fill with cream text |
| `.btn-sky` | Legacy alias, folded onto `.btn-primary` |
| `.btn-action` | `teal-600` fill, white text, hover `teal-700`. The booking call to action. **Light grounds only** |
| `.card` | White, warm border, soft shadow |
| `.section-y` | Section rhythm: `py-10 md:py-14 lg:py-16` |
| `.section-y-tight` | Section rhythm for pages without a hero: `py-8 md:py-10 lg:py-12` |
| `.section-light` / `.section-dark` / `.section-wine` | Cream / ink / wine grounds |
| `.section-gradient` | Single-hue `wine-600 → wine-500` |
| `.badge-gold` | Gold fill, **ink text** |
| `.badge-wine` | Wine fill, white text |
| `.badge-success` / `.badge-warning` / `.badge-error` | Pale tint, dark semantic text |
| `.badge-sky` / `.badge-emerald` | Legacy aliases, folded onto `.badge-wine` |
| `.card-hover` | `.card` plus a shadow lift on hover |
| `.section-title` | Centred `text-3xl` ink heading with the gold rule beneath it |
| `.text-brand-heading` / `.text-brand-body` | Ink and ink-muted text |
| `.divider-gold` | Hairline rule, `gold-500` at 40% |
| `.divider-sky` | Legacy alias, folded onto a wine hairline |
| `.aspect-video` / `.aspect-square` / `.aspect-4-3` | 16:9, 1:1, 4:3 boxes |
| `.font-dhivehi` | A_Faruma, for Thaana |
| `.font-arabic` | Cairo at line-height 2, for du'a text |

**One rhythm only.** `.section-y` and `.section-y-tight` are the vertical spacing of every
public page section. Five pages had four different rhythms between them before this, and only
one of them changed with the viewport — so a phone was given the spacing chosen for a desktop.
The numbers are a judgement call and can be argued with; the point is that arguing with them is
now a two-line edit here rather than a sweep through the views. They set vertical padding only,
because a section often carries a full-bleed background while its content stays in a container.

**One primary only.** Before this pass there were three competing primaries — an emerald
`.btn-primary`, a sky `.btn-sky` and a gold-filled `.btn-secondary`. Do not add a fourth.

> `.btn-gold` and `.badge-wine` are tree-shaken out of the build until a view uses them. That is
> correct Tailwind behaviour, not a missing style.

**This table is complete, and a test says so.** `SectionRhythmTest` fails if `app.css` defines a
component class this file does not name. Two classes — `.section` and `.container-fluid` — sat in
the stylesheet unused and undocumented long enough that `.section` read as the base of the
`.section-light` / `.section-dark` / `.section-wine` family, which it never was. Both are gone.

---

## 3. Contrast reference

Measured to the WCAG 2.2 relative-luminance formula. AA needs 4.5:1 for text and UI, 3:1 for
large text and meaningful graphics.

| Combination | Ratio | |
|---|---|---|
| White on `wine-500` (primary button) | 7.48 | AAA |
| White on `wine-600` (hover) | 9.78 | AAA |
| White on `wine-700` (active) | 12.16 | AAA |
| `wine-500` text on white (links) | 7.48 | AAA |
| `wine-500` text on cream | 7.33 | AAA |
| `wine-600` text on cream | 9.58 | AAA |
| White on `teal-600` (`.btn-action`) | 8.39 | AAA |
| `teal-600` fill against cream — its own edge | 8.21 | AAA |
| **`teal-600` fill against a dark section** | **1.75** | **Fails — use `.btn-gold` there** |
| Ink on `gold-500` (gold button) | 9.85 | AAA |
| `gold-500` on ink (footer, highlights) | 9.85 | AAA |
| `gold-700` text on cream | 5.62 | AA |
| `gold-600` as a shape on white (the logo sail) | 3.26 | Graphics only |
| **`gold-500` fill against cream — its own edge** | **1.46** | **Fails — a button with no findable edge** |
| **`gold-500` text on white** | **1.49** | **Fails — never do this** |
| Ink on white | 14.68 | AAA |
| Ink on cream | 14.37 | AAA |
| Ink on `cream-deep` | 13.73 | AAA |
| `ink-muted` on cream | 5.70 | AA |
| **`wine-500` and `cream-deep`, the two brand colours** | **7.00** | **Why no ground shows both at 3:1** |

---

## 4. Logo — the dhoni

**The two-sail dhoni is the Rihla logo**, with the company name set beneath it, justified to the
mark's own width. It is used everywhere a logo appears: the site header and footer, the login page,
the browser tab, the phone home screen, the PWA, and the preview card that WhatsApp and Facebook
show when a link is shared. The icons are the mark alone — there is no room for type at 16 pixels.

The name is **real text, not artwork**. It is indexed by search engines, read aloud by screen
readers, selectable, and sharp at any pixel density without a second file. The wrapper carries the
width and the mark fills it, so mark and name are the same width by construction rather than by a
number someone has to keep in step. Sizes are in `cqw` — a percentage of the lockup's own width —
so the whole thing scales from one class: `w-20` in the header, `w-16` in the footer.

`RIHLA` and `TRAVELS` carry different tracking because they are different lengths — five letters
and seven, each spread to the same span. The two lines are `aria-hidden`; one `sr-only` span
supplies "Rihla Travels" so a screen reader says the name rather than spelling out two
letter-spaced fragments.

`public/images/rihla-mark.svg` — three paths: hull `#2E2245`, fore sail `#A88C1F`, main sail
`#5F498A`. `rihla-mark-inverse.svg` carries hull `#FFFDF0`, fore sail `#FEF9CD`, main sail
`#9481BA`.

**The two variants are not one artwork recoloured, and this matters.** Each carries the gold and
the violet that *its own background* can show. Measured: the light mark's fore sail is 3.26:1 on
white; the dark mark's is 13.73:1 on ink. Recolour one and copy it over the other and the sails
drop to 1.8:1 — which is exactly what the previous marks did, invisibly, for as long as the
inverse mark existed. `BrandColourTest` now measures every fill against the surface its variant
is for.

The geometry was lifted from the vector paths inside
[`docs/brand/Rihla-Palette.pdf`](brand/Rihla-Palette.pdf): the geometry is the designer's own curve
data, extracted rather than traced.

Because it is vector, **one file covers every size** — the 16-pixel favicon and the header come
from the same 461 bytes, with no resampling and no blur on a high-density screen. The wordmark it
replaced needed six raster files, and before that a single 1.44 MB PNG.

### One thing to keep in mind

The dhoni carries no company name. A visitor arriving from search sees the boat and reads "Rihla
Travels" only in the browser tab title and the page copy, not in the header. That is a deliberate
choice, not an oversight. If it ever needs the name, the fix is to set "Rihla Travels" in type
beside the mark rather than to go back to the old lockup — `<x-brand-logo>` is the single place
that would change.

### The icon set — a second mark, and why

The site's inline mark and the app icon are **not the same artwork**, and the reason is the 7.0:1
in §1.

The inline mark sits directly on the page, so its two sails have to survive whatever ground the
page gives them — which is why each variant carries the gold *its own* background can show. That
works, but it means neither variant is the guide's pair: the light mark's fore sail is `#A88C1F`,
not chiffon.

An icon has a field of its own. So the icon set uses the two guide colours as **field and figure**
— a chiffon `#FEF9CD` disc on an ultra violet `#5F498A` ground, carrying the violet ship. Violet on
chiffon is 7.00:1 and the disc on the field is 7.00:1, on every browser chrome, light or dark.
That is the one arrangement in which both brand colours appear at full strength, and it is the
answer to "can the sails be the two brand colours": as two shapes on a third ground they cannot,
because that needs about 9:1; as field and figure they can.

The field is violet rather than cream. Cream was chosen when the icon was an ink-hulled dhoni that
would have vanished on a dark browser theme; a violet field solves the same problem and carries
the brand while doing it. The manifest's `background_color` stays cream — that paints the splash
screen behind the icon, not the icon.

| File | Size | Purpose |
|---|---|---|
| `public/images/rihla-mark.svg` | 461 B | The logo itself, in the header and footer |
| `public/images/rihla-icon.svg` | — | The icon master: every raster below is cut from it |
| `public/images/rihla-icon-small.svg` | — | 48px and under; see below |
| `public/favicon.svg` | — | Offered first; sharp at any pixel density |
| `public/favicon.ico` | 16–256 | Legacy browsers, Windows pins, bookmark bars |
| `public/favicon-16x16.png`, `-32x32.png` | 16, 32 | Browser tab |
| `public/apple-touch-icon.png` | 180 | iOS home screen |
| `public/images/icon-192.png`, `-512.png` | 192, 512 | PWA, `purpose: any`; 512 also serves the structured-data logo |
| `public/images/icon-maskable-192.png`, `-512.png` | 192, 512 | PWA, `purpose: maskable` |
| `public/images/rihla-social.png` | 1200×630 | `og:image` and `twitter:image` — scrapers cannot read SVG |

**There are two icon masters.** The standard one leaves a violet margin around the disc that is
thinner than a pixel at 16px, so the small sizes would lose their ground. `rihla-icon-small.svg`
fills more of the square and is what 16, 32 and 48 are cut from — including the first three
entries of the `.ico`.

**Maskable icons need no extra file.** Android crops to the middle 80%, and the disc's radius is
38 of 100 for exactly that reason, so the standard master is already inside the safe zone.
`BrandMarkTest` asserts every pixel of artwork sits inside it, and that everything outside is the
solid field.

**`favicon.ico` is checked by pixel, not by size.** For one palette change it was the only icon
still carrying the maroon brand, because the only assertion on it was `filesize() > 0` — which
passes for any bytes at all. `BrandMarkTest::test_the_ico_carries_the_current_brand` now walks the
container's directory, decodes each entry and scans it against the retired list.

### The print pack

[`docs/brand/logo-pack/`](brand/logo-pack/) holds the vector masters, a 100 mm PDF of each mark,
the palette in seven formats (`.txt`, `.json`, `.css`, `.scss`, `.gpl`, `.ase`, Tailwind) and a
one-page A4 sheet to hand a printer. **No CMYK or Pantone values are recorded anywhere**, on
purpose: a conversion made without the printer's paper and ink profile prints wrong, and a guessed
Pantone is the same class of mistake as an invented social link. The printer converts from the hex
against their own profile and sends a proof.

It also holds `rihla-two-sails-*.svg` — the mark with the two guide colours as the two sails, which
is what was asked for and what print can carry. It is **not used anywhere on the site**: on ink its
violet sail is 1.96:1 and on cream its chiffon sail is 1.05:1, and no ground exists that shows
both. `which-mark-to-use.png` is the measured comparison.

### The retired wordmark

The Kaaba-and-calligraphy wordmark is **retired, not deleted**. Both versions stay in the
repository and both are asserted unchanged by tests:

| File | What it is |
|---|---|
| `public/images/rihla-logo.png` | The untouched original, 6250 × 2976 |
| `public/images/rihla-logo-brand.png` | The same artwork recoloured into the palette |

Nothing serves them. They are kept for print, for the company seal, and for anyone who re-adopts
the lockup.

Worth recording why the recolour exists at all: sampling the original found `#097EDD` bright blue
and pure `#000000` black, neither in the palette, and the blue in nothing else on the site. The
colour work moved every other element to wine, gold and ink and deliberately left the wordmark
alone, so it sat at the top of every page in the pre-rebrand scheme while the page around it had
changed completely. That is why it kept looking unchanged — it was unchanged.

### The palette document

[`docs/brand/Rihla-Palette.pdf`](brand/Rihla-Palette.pdf) is the source of truth for the colour
system in §1–§3 above. Every token in it — the ten-step wine ramp, gold, ink, cream and the three
semantic colours — is implemented exactly in `tailwind.config.js` and `app/Support/Brand.php`,
verified value by value.

### Company seal

The registered seal (`RIHLA TRAVELS` / `REG NO: C11452023`) is a separate artefact. If it is
reissued around a new mark: 40 mm minimum diameter — at 38 mm the registration number drops
below the 1.5 mm legible limit — supplied as a 1-bit black-and-white file at 600 dpi or better,
with the stamp maker told explicitly not to add their own border. Keep the existing seal in use
until the replacement is physically in hand, and check whether the Ministry of Islamic Affairs
holds a specimen before changing it.

---

## 5. Rules

1. **Wine is the only primary.** Hover and active move down its ramp.
2. **Gold is an accent.** Never a large filled button with white text.
3. **Semantic colours are reserved.** Green means confirmed, amber means attention, red means
   problem — never decoration.
4. **Ink, not black.** Pure `#000` is not in the palette.
5. **Never colour alone.** Every status carries an icon or a label as well (WCAG 1.4.1), which is
   also what keeps wine and error red from being confusable.
6. **Use tokens, not hex.** Hard-coded colours in Blade are how the old palette survived in the
   PDF stylesheet and the hero banner for as long as it did.
7. **Third-party brands are exempt.** Instagram and Viber colours on the social and contact pages
   are deliberately retained as platform identities.
8. **Restraint.** No unnecessary gradients, no heavy shadows, no glassmorphism, no ornate
   decoration. The single gradient in the system is single-hue wine.
