# ✨ Rihla Travels - Feature Specifications

## 📋 Overview

This document provides comprehensive specifications for all features available in the Rihla Travels platform. Features are organized by user roles and functionality areas to provide clear understanding of capabilities and requirements.

## 🌐 Public Website Features

### 🏠 Homepage Features

#### Hero Banner System
- **Purpose**: Dynamic homepage banners with call-to-action buttons
- **Multilingual**: English and Dhivehi banner versions
- **Scheduling**: Start/end date scheduling for promotional banners
- **Customization**: 
  - Custom title and subtitle text
  - Primary and secondary CTA buttons
  - Overlay opacity control
  - Custom background images
- **Sorting**: Drag-and-drop ordering system
- **Status Control**: Active/inactive banner management

#### Why Choose Us Sections
- **Purpose**: Feature highlight sections showcasing company advantages
- **Multilingual Content**: Full English and Dhivehi support
- **Custom Styling**: 
  - Background colors
  - Text colors
  - Button colors
- **Feature Management**: Multiple features per section with:
  - Icons and images
  - Titles and descriptions
  - Optional links and buttons
- **Visual Hierarchy**: Sortable feature ordering

#### Social Media Integration
- **Supported Platforms**: Facebook, Instagram, TikTok, YouTube, Viber
- **WhatsApp Integration**: 
  - Floating action button (mobile)
  - CTA buttons throughout site
  - Direct messaging from all pages
- **YouTube Playlist**: Embedded video playlists
- **Dynamic Links**: Admin-configurable social media URLs

### 🗺️ Trip Management Features

#### Trip Listing Page (`/trips`)
- **Status-based Tabs**: 
  - Current trips (ongoing)
  - Upcoming trips (future bookings)
  - Past trips (completed journeys)
- **Trip Cards**: Visual trip cards with:
  - Cover images
  - Title and location
  - Date range
  - Starting price
  - "View Details" CTA
- **Pagination**: Efficient loading for large trip collections
- **Multilingual**: Full English/Dhivehi content display

#### Individual Trip Pages (`/trips/{slug}`)
- **Detailed Information**:
  - Title and description (multilingual)
  - Location and dates
  - Detailed itinerary
  - Pricing information
- **Media Gallery**: 
  - Photo and video collections
  - Responsive image display
  - YouTube video embedding
  - Sortable media ordering
- **WhatsApp Integration**: Direct inquiry buttons
- **SEO Optimization**: Clean URLs and meta descriptions

### 🖼️ Media Gallery Features

#### Gallery Page (`/gallery`)
- **Filtering System**: 
  - All media
  - Photos only
  - Videos only
- **Media Sources**:
  - Trip-specific galleries (past trips)
  - General site media
- **Responsive Grid**: Adaptive layout for different screen sizes
- **Media Types**:
  - **Photos**: High-quality image display with WebP optimization
  - **Videos**: YouTube, Vimeo, Facebook, Instagram, TikTok support
- **Lazy Loading**: Performance optimization for large galleries

### 📖 Islamic Travel Guide Features

#### Guide Page (`/guide`)
- **Multilingual Support**: English and Dhivehi versions
- **Step-by-Step Guidance**: Comprehensive Umrah/Hajj instructions
- **Content Types**:
  - **Title and Summary**: Brief overview of each step
  - **Detailed Instructions**: Comprehensive guidance
  - **Dua Text**: Specific prayers for each step
  - **Fiqh Notes**: Different schools of thought (Hanafi, Shafi, etc.)
  - **Checklist Items**: Actionable checklist for each step
  - **Visual Content**: Images and instructional videos
- **PDF Generation**: Downloadable guide in PDF format
- **Progress Tracking**: Visual step progression

#### Guide API (`/api/guide-steps`)
- **JSON Response**: Structured data for integration
- **Locale Support**: Language-specific content retrieval
- **Complete Data**: All guide information in API format
- **Error Handling**: Proper error responses for invalid requests

### 🌐 Social Hub Features

#### Social Media Hub (`/social`)
- **Platform Integration**: 
  - **YouTube**: Embedded video playlists
  - **Facebook**: Page integration links
  - **Instagram**: Photo gallery links
  - **TikTok**: Video content links
- **Dynamic Content**: Admin-configurable social media settings
- **WhatsApp Integration**: Direct messaging capabilities

#### Contact Page (`/contact`)
- **Company Information**: Contact details and location
- **WhatsApp Integration**: Direct messaging buttons
- **Social Media Links**: Quick access to all platforms
- **Business Hours**: Operational information

### 🌍 Multilingual Features

#### Language Switching
- **Supported Languages**: English (en) and Dhivehi (dv)
- **RTL Support**: Proper right-to-left text display for Dhivehi
- **Session Persistence**: Language preference maintained across pages
- **Content Localization**: All text content available in both languages
- **URL Structure**: Language switching without page reload

## 🔐 Admin Panel Features

### 📊 Dashboard
- **Statistics Overview**:
  - Total trips count
  - Total media files count
  - Total guide steps count
- **Quick Access**: Navigation to all management sections
- **Activity Overview**: Recent admin actions and changes

### 🗺️ Trip Management

#### Trip CRUD Operations
- **Create Trip**:
  - Basic information (title, dates, location)
  - Multilingual content (English + Dhivehi)
  - Pricing information
  - Status assignment (current/upcoming/past)
  - Cover image upload with automatic optimization
  - Publication control
- **Update Trip**: Full editing capabilities for all trip aspects
- **Delete Trip**: Safe deletion with media file cleanup
- **List View**: Paginated trip listing with search and filter

#### Media Association
- **Trip-Media Linking**: Associate photos/videos with specific trips
- **Bulk Operations**: Efficient media management for large collections

### 📷 Media Management

#### File Upload and Processing
- **Image Handling**:
  - **Automatic WebP Conversion**: Modern format optimization
  - **Multiple Sizes**: Original, large, and thumbnail variants
  - **Format Support**: JPEG, PNG, GIF to WebP conversion
  - **Size Limits**: Appropriate file size restrictions
- **Video Integration**:
  - **Platform Support**: YouTube, Vimeo, Facebook, Instagram, TikTok
  - **URL Validation**: Automatic platform detection
  - **Thumbnail Generation**: Automatic video thumbnails

#### Media Organization
- **Sorting System**: Drag-and-drop ordering for media
- **Categorization**: Photo/video type classification
- **Trip Association**: Link media to specific trips
- **Publication Control**: Show/hide media from public gallery

### 📖 Guide Step Management

#### Step Creation and Management
- **Step Information**:
  - Step number and ordering
  - Multilingual titles and descriptions
  - Detailed instructions
- **Religious Content**:
  - Dua text for each step
  - Fiqh notes for different schools of thought
  - Checklist items for verification
- **Media Integration**:
  - Step images
  - Instructional videos
- **Publication Control**: Individual step visibility management

#### Bulk Operations
- **Status Updates**: Bulk enable/disable steps
- **Order Management**: Drag-and-drop step reordering
- **Locale Management**: Separate English and Dhivehi content

### 🖼️ Hero Banner Management

#### Banner Creation
- **Content Setup**:
  - Multilingual titles and subtitles
  - Primary and secondary CTA buttons
  - Custom background images
- **Display Settings**:
  - Overlay opacity control
  - Scheduled display (start/end dates)
  - Sort order management
- **Status Control**: Active/inactive banner management

#### Banner Organization
- **Drag-and-Drop Ordering**: Visual banner arrangement
- **Scheduling System**: Time-based banner display
- **Locale Management**: Separate banners per language

### ⚙️ Settings Management

#### Social Media Configuration
- **Platform URLs**: 
  - Facebook page link
  - Instagram profile link
  - TikTok channel link
  - YouTube playlist ID
  - Viber contact link
- **WhatsApp Integration**: 
  - Phone number configuration
  - Automatic link generation
- **Validation**: URL format validation and sanitization

### 🎨 Why Section Management

#### Section Configuration
- **Content Management**:
  - Multilingual titles and subtitles
  - Custom background images
  - CTA button configuration
- **Styling Options**:
  - Background color customization
  - Text color control
  - Button color theming

#### Feature Management
- **Feature Items**:
  - Icon upload and selection
  - Title and description text
  - Optional feature images
  - Link and button configuration
- **Organization**: Sortable feature ordering within sections

## 🔧 Technical Features

### 🖼️ Image Processing
- **Automatic Optimization**:
  - WebP format conversion for modern browsers
  - Multiple image sizes generation
  - Quality optimization without visible loss
- **Storage Management**: 
  - Organized file structure
  - Automatic cleanup on deletion
  - Symbolic link management

### 📱 Responsive Design
- **Mobile-First Approach**: Optimized for mobile devices
- **Adaptive Layouts**: Seamless experience across screen sizes
- **Touch-Optimized**: Mobile-friendly interaction elements

### 🚀 Performance Optimization
- **Asset Optimization**: Minified CSS and JavaScript
- **Lazy Loading**: Images loaded on demand
- **Caching**: Laravel's built-in caching mechanisms
- **Database Optimization**: Efficient queries and indexing

### 🔒 Security Features
- **Authentication**: Secure admin login system
- **Authorization**: Role-based access control
- **Input Validation**: Comprehensive form validation
- **File Upload Security**: Safe file handling and processing

## 🌟 User Experience Features

### 📱 Mobile Experience
- **WhatsApp FAB**: Floating action button for mobile users
- **Touch Navigation**: Mobile-optimized navigation menus
- **Responsive Media**: Proper media scaling for all devices

### 🌐 Accessibility Features
- **Semantic HTML**: Proper HTML structure for screen readers
- **Keyboard Navigation**: Full keyboard accessibility
- **Color Contrast**: WCAG compliant color combinations
- **Text Scaling**: Support for user font size preferences

### 📊 SEO Features
- **Clean URLs**: SEO-friendly URL structure
- **Meta Tags**: Proper meta descriptions and titles
- **Structured Data**: Schema markup for search engines
- **Sitemap Generation**: Automatic sitemap updates

## 🔄 Integration Features

### 📞 WhatsApp Integration
- **Direct Messaging**: One-click WhatsApp contact
- **Number Management**: Admin-configurable phone numbers
- **Cross-Platform**: Works on mobile and desktop
- **Message Templates**: Pre-filled message content

### 🎥 Video Platform Integration
- **YouTube**: Playlist and individual video support
- **Vimeo**: Video embedding with thumbnail generation
- **Social Platforms**: Facebook, Instagram, TikTok support
- **Automatic Thumbnails**: Platform-specific thumbnail generation

### 📄 PDF Generation
- **Guide Export**: Downloadable Umrah/Hajj guides
- **Custom Styling**: Branded PDF templates
- **Multilingual**: Language-specific PDF generation
- **Dynamic Content**: Real-time PDF generation from database

## 🎯 Feature Access Levels

### 👤 Public Users
- View all published trips and media
- Access Islamic travel guide
- Use WhatsApp contact features
- Browse social media integrations

### 👨‍💼 Admin Users
- Full content management access
- Media upload and processing
- Settings configuration
- User management (if implemented)

### 🔧 System Features
- Automatic image optimization
- Multilingual content management
- SEO optimization
- Performance monitoring

---

**Feature Version**: 1.0  
**Last Updated**: December 2024  
**Target Audience**: Travel website users and content managers
