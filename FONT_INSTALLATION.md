# A_faruma Font Installation

## Overview
The A_faruma font has been configured in the CSS for better Dhivehi typography. This font provides improved readability and aesthetics for Thaana script.

## Font Files Required
You need to add the following font files to the `public/fonts/` directory:

1. **A_faruma.ttf** - Regular weight (400)

## Installation Steps

### 1. Create Fonts Directory
```bash
mkdir -p public/fonts
```

### 2. Add Font Files
Place your A_faruma font files in the `public/fonts/` directory:
- `public/fonts/A_faruma.ttf`

### 3. Verify Installation
After adding the font files, the CSS will automatically:
- Load A_Fruama as the primary font for Dhivehi content
- Fall back to Faruma, MV Waheed, and other fonts if A_Fruama is not available
- Apply proper typography optimizations for Thaana script

## Font Features
- **Primary Font**: A_faruma for all Dhivehi content
- **Fallbacks**: Faruma → MV Waheed → Cairo → Tajawal → sans-serif
- **Optimizations**: Ligatures, kerning, and proper letter spacing
- **Responsive**: Works on all devices and screen sizes

## Testing
To test if the font is working:
1. Switch to Dhivehi locale (`/lang/dv`)
2. Check if text appears in A_Fruama font
3. Use browser developer tools to verify font loading

## CSS Classes Available
- `.font-afruama` - Force A_faruma font
- `.font-afruama-regular` - A_faruma regular weight
- `.font-afruama-bold` - A_faruma bold weight
- `.force-afruama` - Force A_faruma with fallbacks
- `.afruama-text` - A_faruma with optimizations

## Troubleshooting
If the font doesn't load:
1. Check if font files are in the correct directory
2. Verify file permissions (should be readable)
3. Check browser console for font loading errors
4. Ensure CSS is properly compiled and loaded
