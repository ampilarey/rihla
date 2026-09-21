# Rihla Travels — logo and colour pack

Generated 21 September 2026. Two marks, both built from the same ship geometry
and the same two brand colours — lemon chiffon `#FEF9CD` and ultra violet `#5F498A`.

---

## Which mark to use

### A · Two sails  (`A-two-sails/`)
The two brand colours as the two sails. Chiffon fore sail, violet main sail.

**Use on:** ink `#2E2245`, cream `#FFFDF0`, white. **Never on violet.**

The two colours are 7.00:1 apart. For two shapes to each clear the 3:1 minimum
against one shared ground they would need to be about 9:1 apart, so no single
ground shows both well:

| ground | chiffon sail | violet sail |
|---|---|---|
| ink `#2E2245` | 13.73 ✓ | **1.96 ✗** |
| violet `#5F498A` | 7.00 ✓ | **1.00 ✗** — no violet lockup is supplied |
| cream `#FFFDF0` | **1.05 ✗** | 7.33 ✓ |
| white | **1.07 ✗** | 7.48 ✓ |

Anything under 3:1 is present in the file and not visible to the eye. On ink the
violet sail is a dark shape on a dark ground; on cream the chiffon sail is gone.
That is a property of the two colours, not of the drawing.

### B · Chiffon roundel  (`B-roundel/`)
The same two hexes, arranged as field and figure: a chiffon disc carrying a
violet ship. On pale grounds the disc has no edge (1.05), so it is drawn with a
violet hairline ring at 7.33:1.

**Use anywhere.** Violet on chiffon is 7.00:1 on every ground, because the violet
is measured against the disc it sits on rather than against the page.

This is the one to use for favicons, app icons, WhatsApp and anywhere the
background is not yours to choose.

---

## What is in the box

```
svg/    vector masters — use these wherever you can
          rihla-two-sails-on-dark.svg   mark A, for ink grounds
          rihla-two-sails-on-light.svg  mark A, for cream and white
          rihla-roundel.svg             mark B, for any ground
pdf/    the same three, 100 mm wide, for printers

colour-codes/
  colours.txt · colours.json · colours.css · colours.scss
  rihla.gpl           GIMP, Inkscape, Krita
  rihla.ase           Illustrator, Photoshop, InDesign, Affinity
  tailwind-colors.js  paste into theme.extend.colors

Rihla-brand-sheet.pdf   one page, both marks on four grounds, every code
which-mark-to-use.png   the measured comparison
```

Rasters are not committed — PNG, JPG, WebP and the icon set are all cut from
the vectors above, and the site's own copies live in `public/`. The app and
browser icons are a *third* arrangement of the same two colours, described in
[`docs/BRAND.md` §4](../../BRAND.md); their masters are `public/images/rihla-icon.svg`
and `rihla-icon-small.svg`.

---

## The two logo colours

| | hex | RGB | HSL |
|---|---|---|---|
| Lemon chiffon | `#FEF9CD` | 254, 249, 205 | 54°, 96%, 90% |
| Ultra violet | `#5F498A` | 95, 73, 138 | 260°, 31%, 41% |

The full site palette is in `colour-codes/`.

## For a printer

Give them the SVG or the PDF and the hex values. **No CMYK or Pantone numbers are
supplied here on purpose** — a conversion made without your printer's paper and
ink profile prints wrong, and a guessed Pantone is worse than none. Ask them to
convert from the hex against their own profile and to send a proof.

## Clear space and minimum size

Keep clear space around the mark equal to the height of the hull. Minimum
reproduction: mark A at 18 mm / 48 px wide; mark B at 12 mm / 32 px, below which
use `public/images/rihla-icon-small.svg`.

## Not in this pack

Rasters. Every PNG, JPG, WebP and icon on the site is cut from these vectors and
lives in `public/` — see `docs/BRAND.md` §4 for the file list and what each one
is for.
