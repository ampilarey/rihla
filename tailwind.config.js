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
        // Dhivehi Font Options - Faruma Primary
        'dhivehi': ['Faruma', 'MV Waheed', 'sans-serif'], // Beautiful Dhivehi font from Maldives
        'dhivehi-faruma': ['Faruma', 'sans-serif'], // Primary Faruma font
        'dhivehi-waheed': ['MV Waheed', 'sans-serif'], // Traditional Maldivian font
        'dhivehi-cairo': ['Cairo', 'sans-serif'], // Modern Arabic support
        'dhivehi-elegant': ['Tajawal', 'sans-serif'], // Professional Arabic
        'inter': ['Inter', 'sans-serif'],
      },
      colors: {
        // Rihla Travels Brand Colors
        'brand-gold': '#C39A3A',
        'brand-sky-blue': '#1C9FE2',
        'brand-black': '#000000',
        'brand-white': '#FFFFFF',
        'brand-dark-grey': '#2E2E2E',
        'brand-light-beige': '#F5F0E6',
        'brand-emerald': '#009975',
        
        // Legacy colors for backward compatibility
        'brand-green': '#009975', // Same as emerald
        'brand-900': '#2E2E2E', // Same as dark grey
        'brand-700': '#1C9FE2', // Same as sky blue
        'brand-600': '#C39A3A', // Same as gold
      },
      borderRadius: {
        '2xl': '1rem',
      },
      boxShadow: {
        'soft': '0 2px 15px -3px rgba(0, 0, 0, 0.07), 0 10px 20px -2px rgba(0, 0, 0, 0.04)',
      },
    },
  },
  plugins: [],
}
