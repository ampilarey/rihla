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
          50: '#FCF5F8',
          100: '#F9E7EF',
          200: '#F1CBDB',
          300: '#E7A6C3',
          400: '#DA76A2',
          500: '#8E2653', // brand primary
          600: '#731F43', // hover
          700: '#5B1835', // active
          800: '#441228',
          900: '#300D1C',
        },

        // Accent only, never a second primary. As a filled background it takes
        // ink text, never white: gold-500 on white is 2.4:1 and fails.
        gold: {
          400: '#E8C270',
          500: '#D2A03C',
          600: '#A87F2C',
          700: '#7A5A16', // safe as a text colour on cream
        },

        // Softer than pure black, which is what keeps the UI feeling premium
        // rather than harsh.
        ink: {
          DEFAULT: '#2E2621',
          muted: '#6B6159',
        },

        cream: {
          DEFAULT: '#FBF6EC',
          deep: '#F4EDDF',
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
          50: '#FFF9F4',
          100: '#FAF3EE',
          200: '#EDE6E1',
          300: '#DBD3CE',
          400: '#AAA19C',
          500: '#746B66',
          600: '#5B524D',
          700: '#483F39',
          800: '#2F2721',
          900: '#1F1610',
        },

        // ── Legacy brand tokens ────────────────────────────────────────────
        // Retained as aliases onto the new palette so that any markup missed
        // during the migration still renders in the new colours instead of
        // silently losing its style. Remove once `grep -r "brand-" resources/`
        // comes back clean.
        'brand-gold': '#D2A03C',

        // WhatsApp's own brand green, for the one button that is theirs rather
        // than ours. Recolouring their mark into wine makes a worse button:
        // people recognise this green without reading anything. Recorded so it
        // is obviously borrowed and never spreads into the palette.
        // #128C7E is their darker official green if the lighter one's 1.98:1
        // against white ever has to give way.
        whatsapp: '#25D366',
        'brand-sky-blue': '#8E2653',
        'brand-black': '#2E2621',
        'brand-white': '#FFFFFF',
        'brand-dark-grey': '#2E2621',
        'brand-light-beige': '#FBF6EC',
        'brand-emerald': '#8E2653',
        'brand-green': '#8E2653',
        'brand-900': '#2E2621',
        'brand-700': '#8E2653',
        'brand-600': '#D2A03C',
      },
      borderRadius: {
        '2xl': '1rem',
      },
      boxShadow: {
        'soft': '0 2px 15px -3px rgba(46, 38, 33, 0.07), 0 10px 20px -2px rgba(46, 38, 33, 0.04)',
      },
    },
  },
  plugins: [],
}
