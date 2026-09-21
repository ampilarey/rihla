/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
  ],
  theme: {
    extend: {
      fontFamily: {
        // One family per script, and nothing that is not loaded.
        //
        // The names dropped from here — 'dhivehi-faruma', 'dhivehi-waheed',
        // 'dhivehi-cairo', 'dhivehi-elegant' — pointed at a Google "Faruma"
        // that does not exist and at Arabic families that cannot render
        // Thaana at all. No view used any of them.
        'sans': ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        // A_Faruma is self-hosted and limited to the Thaana range, so Latin
        // inside Dhivehi text falls through to Inter.
        'dhivehi': ['A_Faruma', 'MV Waheed', 'Inter', 'sans-serif'],
        // Arabic, for du'a and Qur'anic text.
        'arabic': ['Cairo', 'Segoe UI', 'sans-serif'],
        'inter': ['Inter', 'sans-serif'],
        // The lockup only. Subset to nine letters, so it must never be used
        // for running text — anything outside RIHLA TRAVELS falls straight
        // through to Inter and the two faces sit side by side.
        'wordmark': ['Montserrat', 'Inter', 'sans-serif'],
      },

      // Display sizes, added rather than redefining Tailwind's text-* scale:
      // overriding those keys would silently resize every heading on the site.
      // Each pairs a size with the line height and tracking it needs, so a
      // hero heading cannot be set at body line height by accident.
      fontSize: {
        'display-sm': ['2rem', { lineHeight: '1.2', letterSpacing: '-0.01em' }],
        'display-md': ['2.5rem', { lineHeight: '1.15', letterSpacing: '-0.015em' }],
        'display-lg': ['3.25rem', { lineHeight: '1.1', letterSpacing: '-0.02em' }],
        'display-xl': ['4rem', { lineHeight: '1.05', letterSpacing: '-0.025em' }],
      },
      colors: {
        // ── Rihla colour system ────────────────────────────────────────────
        // Primary. Wine carries the brand: CTAs, active nav, links, selected
        // states, hero accents. Hover/active go darker within the ramp rather
        // than reaching for an unrelated colour.
        wine: {
          // Bare `bg-wine` / `border-s-wine` resolve to this, as bare
          // `text-ink` and `bg-cream` already did. Without it those classes
          // compile to nothing at all: `bg-wine px-3 py-1 text-white` was
          // rendering white text on no background — an invisible badge on
          // the tour leader's head count and the family portal, which no
          // test can see because the markup is there and only the colour
          // is missing.
          //
          // DEMO: the ramp now carries the violet from the 2026 colour
          // guide (#5F498A). The token name stays `wine` so no view has to
          // change; only the values moved.
          DEFAULT: '#5F498A',
          50: '#F7F5FA',
          100: '#EDE8F5',
          200: '#D9CFEA',
          300: '#BBACD6',
          400: '#9481BA',
          500: '#5F498A', // brand primary — white on this is 7.48:1
          600: '#4C3A70', // hover
          700: '#3C2E59', // active
          800: '#2E2245',
          900: '#1E162E',
        },

        // Accent only, never a second primary. As a filled background it takes
        // ink text, never white: gold-500 on white is 2.4:1 and fails.
        gold: {
          // As for wine: `border-s-gold` was doing nothing on the notices
          // banner and the leader's offline banner.
          //
          // DEMO: lemon chiffon from the 2026 guide. It is a HIGHLIGHT, not
          // an action colour — chiffon-500 against the cream ground is
          // 1.46:1, so a button filled with it has no findable edge. Use it
          // on dark grounds, where it reads 9.85:1 against ink.
          DEFAULT: '#EFD34D',
          400: '#F8E57A',
          500: '#EFD34D',
          600: '#A88C1F', // the darkest that still reads as gold on cream
          700: '#7A6413', // safe as a text colour on cream (5.62:1)
        },

        // The dark accent the violet/chiffon pair cannot supply. No single
        // colour can clear 3:1 against BOTH violet-500 and the cream ground
        // — they are only 7:1 apart — so marks on light grounds need their
        // own colour. Teal is the strongest hue left that no status colour
        // has claimed (31° from the success green).
        teal: {
          DEFAULT: '#0A5754',
          100: '#D6EBEA',
          400: '#2A9D96',
          500: '#0E6E6B',
          600: '#0A5754', // white on this is 8.39:1
          700: '#084B49',
        },

        // Softer than pure black, which is what keeps the UI feeling premium
        // rather than harsh.
        ink: {
          DEFAULT: '#2E2245',
          muted: '#6B6080',
        },

        cream: {
          DEFAULT: '#FFFDF0',
          deep: '#FEF9CD',
        },

        // Semantic only. Never stand in for the brand just because an element
        // is important. `text-success` etc. resolve to DEFAULT, so the values
        // are exactly as specified; the `dark` step exists so hover states and
        // text on cream have a legal option (base warning/error land at 4.3–4.5
        // on cream, which is below AA).
        success: { DEFAULT: '#0F7A54', dark: '#0B5B3E' },
        warning: { DEFAULT: '#9E6A0D', dark: '#7A5209' },
        error: { DEFAULT: '#D92D20', dark: '#A3231A' },

        // Warm neutrals replacing Tailwind's cool default gray. Each step is
        // matched to the lightness of the Tailwind step it replaces, so every
        // existing gray-* class keeps its contrast ratio (verified within 0.05)
        // while no longer fighting the cream ground.
        gray: {
          50: '#FBF9FD',
          100: '#F4F1F8',
          200: '#E8E3EF',
          300: '#D4CDE0',
          400: '#A49CB4',
          500: '#6E6680',
          600: '#564F66',
          700: '#433C52',
          800: '#2B2437',
          900: '#1B1526',
        },

        // ── Legacy brand tokens ────────────────────────────────────────────
        // Retained as aliases onto the new palette so that any markup missed
        // during the migration still renders in the new colours instead of
        // silently losing its style. Remove once `grep -r "brand-" resources/`
        // comes back clean.
        'brand-gold': '#EFD34D',

        // WhatsApp's own brand green, for the one button that is theirs rather
        // than ours. Recolouring their mark into wine makes a worse button:
        // people recognise this green without reading anything. Recorded so it
        // is obviously borrowed and never spreads into the palette.
        // #128C7E is their darker official green if the lighter one's 1.98:1
        // against white ever has to give way.
        whatsapp: '#25D366',
        'brand-sky-blue': '#5F498A',
        'brand-black': '#2E2245',
        'brand-white': '#FFFFFF',
        'brand-dark-grey': '#2E2245',
        'brand-light-beige': '#FFFDF0',
        'brand-emerald': '#5F498A',
        'brand-green': '#5F498A',
        'brand-900': '#2E2245',
        'brand-700': '#5F498A',
        'brand-600': '#EFD34D',
      },
      borderRadius: {
        '2xl': '1rem',
      },
      boxShadow: {
        'soft': '0 2px 15px -3px rgba(30, 22, 46, 0.07), 0 10px 20px -2px rgba(30, 22, 46, 0.04)',
      },
    },
  },
  plugins: [],
}
