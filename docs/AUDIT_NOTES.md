# Hero Slider

## Overview
A fully configurable hero slider component for the Rihla Travels homepage that supports multiple slides, autoplay, manual navigation, and locale-aware content.

## Features

### Core Functionality
- **Multiple Slides**: Support for unlimited slides with title, subtitle, CTA, and image
- **Auto-play**: Configurable delay (default 5000ms) with pause on hover
- **Manual Controls**: Navigation arrows, pagination dots, and swipe gestures on mobile
- **Infinite Loop**: Seamless transition between first and last slides
- **Responsive Design**: Mobile-first approach with stacked layout on small screens

### Admin Management
- **Full CRUD**: Create, read, update, delete hero slides
- **Drag & Drop Sorting**: Reorder slides with visual feedback
- **Publish Control**: Toggle slide visibility with immediate effect
- **Scheduling**: Set start/end dates for time-based publishing
- **Locale Support**: Separate content for English (en) and Dhivehi (dv)

### Technical Features
- **Image Processing**: Automatic resizing to 1600px max width and 400px thumbnails
- **WebP Conversion**: All images converted to WebP format for optimal performance
- **Lazy Loading**: Non-critical images loaded on demand
- **Accessibility**: ARIA labels, keyboard navigation, and screen reader support
- **RTL Support**: Automatic layout flipping for Dhivehi locale

## Database Schema

### Table: `hero_slides`
```sql
- id (primary key)
- locale (enum: 'en', 'dv')
- title (string, max 180 chars)
- subtitle (string, max 240 chars, nullable)
- cta_text (string, max 60 chars, nullable)
- cta_url (string, max 255 chars, nullable)
- image_path (string)
- thumb_path (string, nullable)
- sort_order (integer, default 0)
- is_active (boolean, default true)
- start_at (timestamp, nullable)
- end_at (timestamp, nullable)
- timestamps
```

### Indexes
- `is_active` - For filtering published slides
- `locale` - For language-specific queries
- `start_at`, `end_at` - For scheduling queries
- Composite: `['is_active', 'locale']`, `['locale', 'sort_order']`

## Model Features

### HeroSlide Model
- **Scopes**: `published()`, `forLocale()`, `ordered()`
- **Casts**: Boolean, integer, and datetime fields
- **Accessors**: `image_url`, `thumb_url` for full asset URLs
- **Methods**: `isCurrentlyPublished()` for schedule validation

### Validation Rules
- Title: Required, max 180 characters
- Subtitle: Optional, max 240 characters
- CTA Text: Optional, max 60 characters
- CTA URL: Optional, valid URL format
- Image: Required for new slides, optional for updates
- Locale: Required, must be 'en' or 'dv'
- Schedule: End date must be after start date

## Frontend Implementation

### Component: `x-hero-slider`
- **Alpine.js Integration**: Reactive state management
- **Touch Support**: Swipe gestures for mobile devices
- **Keyboard Navigation**: Arrow keys for slide control
- **Performance**: Lazy loading and optimized transitions

### Styling
- **Brand Colors**: Sky-blue primary (#1C9FE2), gold accent (#C39A3A)
- **Gradient Background**: Sky-blue to gold overlay
- **Responsive Typography**: Fluid text sizing from mobile to desktop
- **Smooth Transitions**: CSS transitions for slide changes

### Layout
- **Desktop**: Two-column layout (text left, image right)
- **Mobile**: Stacked layout with full-width image
- **RTL Support**: Automatic layout flipping for Dhivehi

## Admin Interface

### Routes
- `GET /admin/hero-slides` - Index view
- `GET /admin/hero-slides/create` - Create form
- `POST /admin/hero-slides` - Store new slide
- `GET /admin/hero-slides/{id}/edit` - Edit form
- `PUT /admin/hero-slides/{id}` - Update slide
- `DELETE /admin/hero-slides/{id}` - Delete slide
- `POST /admin/hero-slides/{id}/toggle-status` - Toggle visibility
- `POST /admin/hero-slides/update-order` - Update sort order

### Views
- **Index**: Table with drag-and-drop sorting, status toggles
- **Create/Edit**: Form with image upload, validation, and scheduling
- **Show**: Detailed view with image previews and metadata

### Features
- **Image Upload**: Drag-and-drop file upload with preview
- **Real-time Validation**: Client-side and server-side validation
- **Sortable Interface**: Visual drag-and-drop reordering
- **Status Management**: Quick toggle for slide visibility

## Localization

### Content Management
- **Separate Slides**: Each locale has independent slide sets
- **RTL Layout**: Automatic direction and alignment for Dhivehi
- **Translation Keys**: All UI text supports both languages

### Seeded Content
- **English**: 3 slides with Umrah-focused messaging
- **Dhivehi**: 3 slides with equivalent content in Dhivehi script

## Performance Optimizations

### Image Processing
- **WebP Format**: 80% quality for optimal size/quality balance
- **Multiple Sizes**: Large (1600px) and thumbnail (400px) versions
- **Storage Organization**: Structured folder hierarchy in public storage

### Frontend Performance
- **Lazy Loading**: Non-critical images loaded on demand
- **Efficient Transitions**: CSS-based animations for smooth performance
- **Touch Optimization**: Responsive touch handling for mobile devices

## Accessibility Features

### Navigation
- **Keyboard Support**: Arrow keys and tab navigation
- **Screen Reader**: ARIA labels and semantic markup
- **Focus Management**: Visible focus indicators for all interactive elements

### Content
- **Alt Text**: Automatic alt text from slide titles
- **Semantic Structure**: Proper heading hierarchy (H1 for first slide, H2 for others)
- **Color Contrast**: High contrast text on dark overlays

## Usage Examples

### Basic Implementation
```blade
<x-hero-slider :slides="$heroSlides" />
```

### Conditional Display
```blade
@if($heroSlides->count() > 0)
    <x-hero-slider :slides="$heroSlides" />
@else
    <!-- Fallback content -->
@endif
```

### Admin Management
```php
// Get published slides for current locale
$slides = HeroSlide::published()
    ->forLocale(app()->getLocale())
    ->ordered()
    ->get();
```

## Future Enhancements

### Planned Features
- **Video Support**: MP4 and WebM video slides
- **Advanced Scheduling**: Recurring slides and time zones
- **Analytics**: Slide performance tracking and A/B testing
- **API Endpoints**: RESTful API for mobile app integration

### Performance Improvements
- **Image CDN**: Cloud-based image delivery
- **Caching**: Redis-based slide caching
- **Compression**: Advanced image optimization algorithms

## Troubleshooting

### Common Issues
1. **Images Not Loading**: Check storage link and file permissions
2. **Slides Not Displaying**: Verify slide status and schedule dates
3. **RTL Layout Issues**: Ensure proper locale detection
4. **Touch Not Working**: Check mobile device compatibility

### Debug Commands
```bash
# Clear caches
php artisan view:clear
php artisan config:clear

# Check slide data
php artisan tinker
>>> App\Models\HeroSlide::all()->pluck('title', 'locale')
```

## Security Considerations

### File Upload
- **File Validation**: Strict MIME type and size limits
- **Image Processing**: Server-side image manipulation only
- **Storage Isolation**: Public storage with proper permissions

### Admin Access
- **Authentication Required**: All admin routes protected
- **Admin Gate**: Additional authorization check
- **CSRF Protection**: All forms include CSRF tokens

## Testing

### Unit Tests
- Model scopes and methods
- Validation rules and constraints
- Image processing functionality

### Feature Tests
- Admin CRUD operations
- Frontend slide rendering
- Locale switching behavior

### Browser Tests
- Touch gesture handling
- Keyboard navigation
- Responsive layout behavior

# Umrah Guide (Stepper)

## Overview
The Umrah Guide has been redesigned from a carousel to a **Guided Stepper** with clear, numbered steps, sticky Table of Contents (TOC), progress indicators, and comprehensive content management.

## Key Features Implemented

### 1. Frontend Guide Page (`/guide`)
- **Sticky TOC**: Left sidebar (desktop) with step navigation and current step highlighting
- **Progress Bar**: Top progress indicator showing "Step X of N" and percentage
- **Step Content**: Large numbered headings, images, descriptions, checklists, and Fiqh notes
- **Mobile Responsive**: Collapsible TOC dropdown for mobile devices
- **Navigation**: Previous/Next step buttons with smooth scrolling
- **Print Support**: CSS print styles for clean printing
- **WhatsApp FAB**: Fixed action button for quick help

### 2. Admin Management (`/admin/guide-steps`)
- **CRUD Operations**: Create, read, update, delete guide steps
- **Locale Support**: English (en) and Dhivehi (dv) content management
- **Drag & Drop Reordering**: Up/down arrows to reorder steps
- **Bulk Actions**: Publish/unpublish multiple steps at once
- **Rich Content**: Support for images, checklists, Fiqh notes, Du'a text, and references
- **Status Management**: Toggle publish status for individual steps

### 3. Data Model
- **Extended Guide Steps Table**: Added locale, dua_text, reference_text, checklist (JSON), fiqh_notes (JSON)
- **Indexing**: Optimized queries with `locale, is_published, step_number` index
- **Casting**: Proper boolean and array casting for JSON fields

### 4. Localization & RTL
- **Bilingual Content**: Separate content for English and Dhivehi
- **RTL Support**: Automatic RTL layout for Dhivehi locale
- **Language Files**: Complete translations in `resources/lang/en/guide.php` and `resources/lang/dv/guide.php`

## How to Use

### Adding New Guide Steps
1. Navigate to `/admin/guide-steps`
2. Click "Add New Step"
3. Fill in required fields:
   - **Language**: Select English or Dhivehi
   - **Step Number**: Sequential numbering (1, 2, 3...)
   - **Title**: Step title (max 120 characters)
   - **Description**: Detailed step explanation
   - **Image**: Optional step image (auto-resized to 1600px)
   - **Du'a Text**: Optional supplication text
   - **Reference**: Optional Quran/Hadith reference
   - **Checklist**: Add/remove checklist items
   - **Fiqh Notes**: Add Fiqh differences (e.g., Hanafi • Shafi'i)
4. Set publish status and save

### Reordering Steps
1. In the admin list, use up/down arrows next to step numbers
2. Steps automatically reorder and save to database
3. Frontend immediately reflects new order

### Translating Content
1. Create steps for both locales (en and dv)
2. Use appropriate language for titles and descriptions
3. Dhivehi content automatically displays in RTL layout
4. Locale switching via `/lang/dv` or `/lang/en`

### Publishing/Unpublishing
- **Individual**: Click status button in admin list
- **Bulk**: Select multiple steps and use bulk action dropdown
- **Draft Mode**: Uncheck "Publish" when creating/editing

## Content Structure

### English Steps (10 steps)
1. Intention (Niyyah)
2. Ihram & Talbiyah
3. Entering Masjid al-Haram
4. Tawaf (7 circuits)
5. Pray 2 Rak'ah at Maqam Ibrahim
6. Drink Zamzam
7. Sa'i between Safa and Marwah
8. Halq/Taqsir (shave/trim)
9. Leave Ihram
10. Du'a & Etiquette Guide

### Dhivehi Steps (10 steps)
- Complete Dhivehi translations with proper RTL support
- Same step structure, localized content

## Technical Implementation

### Routes
- `GET /guide` - Public guide page
- `GET /admin/guide-steps` - Admin management
- `POST /admin/guide-steps/update-order` - Reorder steps
- `POST /admin/guide-steps/{id}/toggle-status` - Toggle publish status

### Controllers
- `PageController@guide` - Public guide display
- `Admin\GuideStepController` - Admin CRUD operations

### Views
- `resources/views/pages/guide.blade.php` - Public guide page
- `resources/views/admin/guide-steps/*.blade.php` - Admin management views

### Database
- Migration: `2025_08_27_151226_extend_guide_steps_table.php`
- Model: `App\Models\GuideStep` with scopes and accessors

## Testing
- **Feature Tests**: `tests/Feature/UmrahGuideTest.php`
- **Coverage**: Step display, locale switching, ordering
- **All Tests Pass**: ✅ 3 tests, 12 assertions

## Future Enhancements
- **PDF Generation**: Server-side PDF creation for offline use
- **PWA Offline**: Service worker caching for offline guide access
- **API Endpoints**: RESTful API for mobile app integration
- **Advanced Search**: Filter steps by content, tags, or difficulty

## Brand Consistency
- **Primary Colors**: Sky-blue for CTAs and progress highlights
- **Accent Colors**: Gold for dividers and badges
- **Typography**: Dark-grey headings with proper hierarchy
- **Spacing**: Consistent `max-w-screen-xl` container with responsive padding

## Accessibility Features
- **Semantic HTML**: Proper heading hierarchy (H1 page, H2 steps)
- **Keyboard Navigation**: Full TOC navigation via keyboard
- **Focus Management**: Clear focus rings and visible focus states
- **Screen Reader**: Proper ARIA labels and semantic structure
- **Mobile**: Touch-friendly tap targets (≥44px)

This implementation provides a comprehensive, user-friendly Umrah guide that replaces the carousel with a more appropriate stepper interface, making it easier for pilgrims to follow step-by-step instructions and reference specific parts of the ritual.

---

# Umrah Guide (Card Grid Layout)

## Overview
A comprehensive, scrollable guide for performing Umrah with expandable details, bilingual support (EN/DV), and full admin management. The guide features a modern card grid layout that provides excellent visual hierarchy and improved user experience across all devices.

## Features

### Core Functionality
- **Card Grid Layout**: Responsive grid (1-3 columns) with hover effects and modern design
- **Expandable Content**: Optional details, dua text, fiqh notes, and checklists per step
- **Progress Tracking**: Top progress bar with "Step X of N" indicator
- **Bilingual Support**: Full English and Dhivehi content with RTL layout
- **Admin Management**: Complete CRUD with drag-drop reordering and publish controls

### Content Management
- **Step Details**: Summary (required) + optional expanded details
- **Media Support**: Images with WebP variants and optional video URLs
- **Religious Content**: Dua text, fiqh notes, and interactive checklists
- **Locale Control**: Separate content management for EN/DV
- **Publish Control**: Individual and bulk publish/unpublish

### Technical Features
- **PDF Export**: Server-side PDF generation with proper formatting
- **PWA Offline**: Service worker caching for offline access
- **API Endpoints**: RESTful API for mobile app integration
- **Print Styles**: Optimized CSS for printing
- **Performance**: Lazy image loading and WebP optimization

## Database Schema

### Table: `guide_steps` (Updated)
```sql
- id (primary key)
- locale (enum: 'en', 'dv') - required
- step_number (int) - required, unique per locale
- title (string, max 120) - required
- summary (text) - required, short description
- details (longtext) - nullable, expanded content
- dua_text (longtext) - nullable, supplications
- fiqh_notes (longtext) - nullable, religious notes
- video_url (string) - nullable, YouTube/Vimeo URLs
- image_path (string) - nullable, public disk path
- checklist (json) - nullable, array of items
- is_published (bool) - default true
- timestamps
```

### Indexes
- `locale, is_published, step_number` - Composite index for efficient queries
- `locale` - For language-specific filtering
- `is_published` - For published content queries

## Model Features

### GuideStep Model
- **Scopes**: `published()`, `forLocale($locale)`, `ordered()`
- **Casts**: `is_published:boolean`, `step_number:int`, `checklist:array`
- **Accessors**: `image_url`, `thumbnail_url`, `large_image_url`
- **Helper Methods**: `hasDetails()`, `hasVideo()`, `hasImage()`

### Validation Rules
- `locale`: required|in:en,dv
- `step_number`: required|integer|min:1
- `title`: required|string|max:120
- `summary`: required|string
- `details, dua_text, fiqh_notes`: nullable|string
- `video_url`: nullable|url|max:255
- `image`: nullable|image|mimes:jpeg,png,webp|max:6144
- `checklist`: nullable|array

## Frontend Implementation

### Public Page (`/guide`)
- **Layout**: Responsive container with max-w-screen-xl
- **Header**: Sticky progress bar with step counter and action buttons
- **Card Grid**: Responsive grid layout (1 column mobile, 2 columns tablet, 3 columns desktop)
- **Actions**: Print and Download PDF buttons
- **WhatsApp FAB**: Fixed bottom-right for quick help

### Responsive Design
- **Desktop**: Three-column grid layout with sticky TOC
- **Tablet**: Two-column grid layout
- **Mobile**: Single-column grid layout with collapsible TOC
- **RTL Support**: Automatic layout flipping for Dhivehi
- **Touch Friendly**: Minimum 44px tap targets

### Print & PDF
- **Print Styles**: Hide navigation, show all content, optimize layout
- **PDF Export**: Server-generated PDF with cover, TOC, and formatted content
- **Branding**: Rihla logo and consistent styling

## Admin Interface

### Routes
- `GET /admin/guide-steps` - Index with drag-drop reordering
- `GET /admin/guide-steps/create` - Create new step
- `GET /admin/guide-steps/{id}/edit` - Edit existing step
- `POST /admin/guide-steps/update-order` - Update step ordering
- `POST /admin/guide-steps/{id}/toggle-status` - Toggle publish status
- `POST /admin/guide-steps/bulk-update-status` - Bulk publish/unpublish

### Features
- **Drag & Drop**: Visual reordering with immediate database updates
- **Image Management**: Upload, resize, WebP conversion, thumbnail generation
- **Preview**: Live preview of step content
- **Bulk Actions**: Select multiple steps for batch operations

## API Endpoints

### `GET /api/guide-steps`
- **Parameters**: `locale` (optional, defaults to current)
- **Response**: JSON with locale, total_steps, and steps array
- **Rate Limiting**: 60 requests per minute
- **CORS**: Configured for web/app origins

### Response Format
```json
{
  "locale": "en",
  "total_steps": 10,
  "steps": [
    {
      "step_number": 1,
      "title": "Intention (Niyyah)",
      "summary": "Make sincere intention...",
      "details": "Detailed explanation...",
      "dua_text": "Supplication text...",
      "fiqh_notes": "Religious notes...",
      "checklist": ["Item 1", "Item 2"],
      "image_url": "https://...",
      "video_url": "https://..."
    }
  ]
}
```

## PWA Offline Support

### Service Worker (`/public/sw.js`)
- **Cache Strategy**: Stale-while-revalidate for HTML, cache-first for images
- **Offline Page**: Custom offline.html with retry functionality
- **Cache Management**: Automatic cleanup of old caches
- **Scope**: `/guide` and related assets

### Manifest (`/public/manifest.json`)
- **App Name**: "Rihla Travels - Umrah Guide"
- **Display Mode**: Standalone
- **Theme Colors**: Brand sky-blue (#1C9FE2)
- **Icons**: 192x192 and 512x512 PNG variants

## Testing

### Feature Tests (`tests/Feature/GuideTest.php`)
- **Coverage**: 6 tests, 16 assertions
- **Test Cases**:
  - Guide page loads with steps ✅
  - PDF download functionality ✅
  - API returns steps correctly ✅
  - Locale validation ✅
  - Step ordering ✅
  - Unpublished steps hidden ✅

### Test Database
- **Factory**: `GuideStepFactory` with realistic test data
- **States**: `unpublished()`, `dhivehi()` for different scenarios
- **Cleanup**: `RefreshDatabase` trait for isolated tests

## Seed Content

### English Steps (10 steps)
1. **Intention (Niyyah)**: Make sincere intention in heart
2. **Ihram & Talbiyah**: Enter sacred state with prescribed clothing
3. **Entering Masjid al-Haram**: Enter with right foot, recite entrance dua
4. **Tawaf (7 circuits)**: Complete circuits around Kaaba
5. **Pray 2 Rak'ah**: At Maqam Ibrahim if space permits
6. **Drink Zamzam**: Blessed water while facing Kaaba
7. **Sa'i (7 times)**: Walk between Safa and Marwah
8. **Halq/Taqsir**: Shave head or trim hair
9. **Leave Ihram**: Exit sacred state after completion
10. **Du'a & Etiquette**: Maintain proper manners throughout

### Dhivehi Steps
- Complete translations with proper RTL layout
- Same step structure, localized content and terminology

## Performance Optimizations

### Image Handling
- **WebP Conversion**: Automatic conversion for modern browsers
- **Thumbnail Generation**: 400px thumbnails for faster loading
- **Lazy Loading**: Images load only when needed
- **Storage**: Public disk with organized folder structure

### Caching Strategy
- **Service Worker**: Cache guide page and assets
- **Database**: Efficient queries with proper indexing
- **Frontend**: Alpine.js for reactive updates without full page reloads

## Brand Guidelines

### Color Scheme
- **Primary**: Sky-blue (#1C9FE2) for CTAs and progress
- **Accent**: Gold (#C39A3A) for badges and dividers
- **Text**: Dark grey (#2E2E2E) for headings and body text

### Typography
- **English**: Inter font family for modern readability
- **Dhivehi**: A_faruma primary, Faruma fallback, MV Waheed secondary
- **Hierarchy**: Clear H1 (page), H2 (steps), H3 (sections)
- **Font Features**: Ligatures, kerning, and optimized letter spacing for Thaana script

### Spacing
- **Container**: `max-w-screen-xl mx-auto px-4 md:px-6 lg:px-8`
- **Sections**: `py-6` mobile, `py-10` desktop
- **Components**: Consistent gap spacing with `gap-4 md:gap-6 lg:gap-8`

## Accessibility Features

### Semantic Structure
- **Landmarks**: `<header>`, `<main>`, `<footer>` for navigation
- **Headings**: Proper H1-H2 hierarchy for screen readers
- **ARIA**: `aria-expanded` for expandable content

### Keyboard Navigation
- **Focus Management**: Clear focus rings and visible states
- **Tab Order**: Logical navigation through interactive elements
- **Shortcuts**: Enter/Space for expanding content

### Mobile Accessibility
- **Touch Targets**: Minimum 44px for all interactive elements
- **Gesture Support**: Swipe and tap gestures for mobile users
- **Responsive**: Adapts to different screen sizes and orientations

## Future Enhancements

### Planned Features
- **Advanced Search**: Filter steps by content, difficulty, or tags
- **User Progress**: Save user's progress through the guide
- **Social Sharing**: Share specific steps or entire guide
- **Audio Support**: Text-to-speech for hands-free use
- **Offline Maps**: Location-based guidance for physical sites

### Technical Improvements
- **CDN Integration**: Global content delivery for better performance
- **Analytics**: Track user engagement and step completion
- **A/B Testing**: Test different content formats and layouts
- **Performance Monitoring**: Real-time performance metrics

## Implementation Status

### Completed ✅
- Database schema and migrations
- Model with scopes and accessors
- Admin CRUD interface
- Public guide page with card grid layout
- PDF generation and export
- PWA offline support
- API endpoints
- Comprehensive testing
- Bilingual support (EN/DV)
- Print styles and optimization
- **Umrah Hero Slider system** (basic implementation)

### In Progress 🔄
- **Umrah Hero Slider**: Basic functionality implemented, needs admin views and full interactive features

### Ready for Production
The Card Grid Layout Guide is fully implemented and ready for production use. All core features are functional, tested, and optimized for both desktop and mobile users.

**Note**: The "How to Perform Umrah" section has been removed from the main homepage (`/`) and is now available as a dedicated page at `/guide` and as a hero slider at `/umrah-hero`.

**Font Update**: A_faruma font has been configured for improved Dhivehi typography. See `FONT_INSTALLATION.md` for installation instructions.

---

## Umrah Hero Slider

### Overview
A dedicated hero slider system for displaying Umrah steps in an engaging, visual format. Each slide represents a step with large hero images, impactful text, and clear call-to-action buttons.

### Features Implemented ✅
- **Database Schema**: `umrah_slides` table with all required fields
- **Model**: `UmrahSlide` with scopes, accessors, and helper methods
- **Admin Controller**: Full CRUD functionality with image processing
- **Public Routes**: `/umrah-hero` and `/api/umrah-slides`
- **Basic View**: Static hero slider with step content
- **API Endpoint**: JSON response for slides by locale
- **Seeder**: Basic English and Dhivehi content

### Features Pending 🔄
- **Admin Views**: Create, edit, index, and show forms
- **Interactive Slider**: Alpine.js-powered slider with navigation
- **Image Upload**: Admin interface for slide images
- **Drag & Drop**: Reordering functionality in admin
- **Advanced Features**: Autoplay, scheduling, custom CTAs

### Technical Implementation
- **Table**: `umrah_slides` with proper indexes and constraints
- **Image Processing**: Multiple sizes (1920w, 1280w, 768w) + WebP
- **Responsive Design**: Mobile-first approach with brand colors
- **Localization**: EN/DV support with RTL for Dhivehi
- **Performance**: Lazy loading and optimized image variants

### Current Status
- **Database**: ✅ Created and seeded
- **API**: ✅ Working and tested
- **Public Page**: ✅ Basic view functional
- **Admin**: 🔄 Controller ready, views pending
- **Interactive**: 🔄 Basic structure, needs Alpine.js

### Next Steps
1. Create admin views (index, create, edit, show)
2. Implement interactive slider with Alpine.js
3. Add image upload functionality
4. Implement drag & drop reordering
5. Add advanced features (autoplay, scheduling)
6. Complete testing and optimization
