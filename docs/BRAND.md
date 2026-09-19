# Rihla Brand & Design System

**Version:** 1.0
**Date:** 2026-09-18
**Status:** Colour system **implemented** (`488ffee`). Logo decision **open** (§4).
**Implemented in:** `tailwind.config.js`, `resources/css/app.css`

This is the reference for Rihla's visual system. The roadmap that depends on it is
[`WEBSITE_UPGRADE_PLAN.md`](WEBSITE_UPGRADE_PLAN.md); this document is the spec.

---

## 1. The palette

Rihla's previous palette — gold `#C39A3A` with sky blue `#1C9FE2` — failed WCAG AA at the two
jobs it was used for most: white text on a button, and coloured text on white. Sky blue scored
**2.95:1** and gold **2.62:1**, against a 4.5:1 requirement. That, not taste, is why it changed.

The replacement keeps a warm, premium character and passes at every interactive combination.

### Primary — Wine

| Token | Hex | Use |
|---|---|---|
| `wine-50` | `#FCF5F8` | Section tints, secondary-button hover, progress track |
| `wine-100` | `#F9E7EF` | Subtle fills |
| `wine-200` | `#F1CBDB` | Progress tracks, dividers |
| `wine-300` | `#E7A6C3` | Borders on tinted grounds |
| `wine-400` | `#DA76A2` | Illustration, dark-mode accent |
| **`wine-500`** | **`#8E2653`** | **Brand primary.** CTAs, active nav, links, selected states |
| `wine-600` | `#731F43` | Button hover, link hover |
| `wine-700` | `#5B1835` | Button active |
| `wine-800` | `#441228` | Deep grounds |
| `wine-900` | `#300D1C` | Deepest ground |

Hover and active states move **down the wine ramp**, never sideways into another hue.

**Why this wine and not a brighter red.** Maroon is not a hue — it is a red with chroma held
down. An earlier attempt raised vividness from 37 to 56 while leaving the hue alone and the
result read as crimson. The lever that keeps it wine is the **blue lean** (`b*` in CIE L\*a\*b\*):
the old maroon sat at `+6.5` and the crimson at `+8.7`, both leaning orange. `#8E2653` sits at
`−1.7`, on the purple side of pure red, which is why it holds its character at a vividness of 47.

### Accent — Gold

| Token | Hex | Use |
|---|---|---|
| `gold-400` | `#E8C270` | On dark grounds |
| **`gold-500`** | **`#D2A03C`** | **Accent.** Rules, dividers, kiswah band, icons on dark |
| `gold-600` | `#A87F2C` | Gold-fill hover, focus rings |
| `gold-700` | `#7A5A16` | Gold as a **text** colour on white or cream |

**Gold is an accent, never a second primary.** Two hard rules:

- **Gold fills take ink text, never white.** `gold-500` against white is **2.38:1**. Against ink
  it is **6.24:1**.
- **Gold text on a light ground uses `gold-700`**, which reaches 6.36:1 on white. `gold-500` as
  body or link text on white fails.

### Ink & Cream

| Token | Hex | Use |
|---|---|---|
| `ink` | `#2E2621` | All body text, headings, dark UI. Softer than black, which is what keeps it premium |
| `ink-muted` | `#6B6159` | Secondary text, captions |
| `cream` | `#FBF6EC` | Main warm background |
| `cream-deep` | `#F4EDDF` | Alternating sections, sunken surfaces |

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

Tailwind's default `gray` scale is cool and fights the cream ground, so it is **overridden** with
a warm ramp in `tailwind.config.js`. Each step is matched to the lightness of the Tailwind step
it replaces, so every existing `gray-*` class keeps its contrast ratio.

| Step | Warm | Step | Warm |
|---|---|---|---|
| 50 | `#FFF9F4` | 500 | `#746B66` |
| 100 | `#FAF3EE` | 600 | `#5B524D` |
| 200 | `#EDE6E1` | 700 | `#483F39` |
| 300 | `#DBD3CE` | 800 | `#2F2721` |
| 400 | `#AAA19C` | 900 | `#1F1610` |

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
| `.card` | White, warm border, soft shadow |
| `.section-light` / `.section-dark` / `.section-wine` | Cream / ink / wine grounds |
| `.section-gradient` | Single-hue `wine-600 → wine-500` |
| `.badge-gold` | Gold fill, **ink text** |
| `.badge-wine` | Wine fill, white text |
| `.badge-success` / `.badge-warning` / `.badge-error` | Pale tint, dark semantic text |
| `.badge-sky` / `.badge-emerald` | Legacy aliases, folded onto `.badge-wine` |

**One primary only.** Before this pass there were three competing primaries — an emerald
`.btn-primary`, a sky `.btn-sky` and a gold-filled `.btn-secondary`. Do not add a fourth.

> `.btn-gold` and `.badge-wine` are tree-shaken out of the build until a view uses them. That is
> correct Tailwind behaviour, not a missing style.

---

## 3. Contrast reference

Measured to the WCAG 2.2 relative-luminance formula. AA needs 4.5:1 for text and UI, 3:1 for
large text and meaningful graphics.

| Combination | Ratio | |
|---|---|---|
| White on `wine-500` (primary button) | 8.23 | AAA |
| White on `wine-600` (hover) | 10.47 | AAA |
| White on `wine-700` (active) | 12.91 | AAA |
| `wine-500` text on white (links) | 8.23 | AAA |
| `wine-500` text on cream | 7.64 | AAA |
| `wine-600` text on cream | 9.72 | AAA |
| Ink on `gold-500` (gold button) | 6.24 | AA |
| `gold-700` text on white | 6.36 | AA |
| `gold-700` text on cream | 5.90 | AA |
| `gold-500` on ink (footer) | 6.24 | AA |
| **`gold-500` text on white** | **2.38** | **Fails — never do this** |
| Ink on white | 14.84 | AAA |
| Ink on cream | 13.77 | AAA |
| Status chips (pale tint, dark text) | 6.09–7.11 | AA |
| Brand wine vs error red separation | ΔE 56 | Clearly distinct |

---

## 4. Logo — the dhoni

**The two-sail dhoni is the Rihla logo.** It is used everywhere a logo appears: the site header
and footer, the login page, the browser tab, the phone home screen, the PWA, and the preview card
that WhatsApp and Facebook show when a link is shared.

`public/images/rihla-mark.svg` — 461 bytes, three paths, wine `#8E2653`, gold `#D2A03C`,
ink `#2E2621`. Lifted from the vector paths inside
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

### The icon set

Every icon is cut from the same master, on a cream `#FBF6EC` field. Cream rather than transparency,
because a transparent icon loses its ink hull against a dark browser theme.

| File | Size | Purpose |
|---|---|---|
| `public/images/rihla-mark.svg` | 461 B | The logo itself, everywhere on the site |
| `public/favicon.svg` | 589 B | Offered first; sharp at any pixel density |
| `public/favicon.ico` | 16/32/48 | Legacy browsers, Windows pins |
| `public/favicon-16x16.png`, `-32x32.png` | 16, 32 | Browser tab |
| `public/apple-touch-icon.png` | 180 | iOS home screen |
| `public/images/icon-192.png`, `-512.png` | 192, 512 | PWA, `purpose: any`; 512 also serves the structured-data logo |
| `public/images/icon-maskable-192.png`, `-512.png` | 192, 512 | PWA, `purpose: maskable` |
| `public/images/rihla-social.png` | 1200×630 | `og:image` and `twitter:image` — scrapers cannot read SVG |

**Maskable icons carry extra padding on purpose.** Android crops them to a circle or squircle and
keeps only the middle 80%; at the inset the other icons use, the dhoni loses the tip of a sail.
`BrandMarkTest` asserts every pixel of artwork sits inside that safe zone.

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
