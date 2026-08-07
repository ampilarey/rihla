# 📡 Rihla Travels - API Specification

## 📋 Overview

The Rihla Travels platform provides both web-based interfaces and API endpoints for accessing travel information, guide content, and administrative functions. This specification documents the available API endpoints, request/response formats, and authentication requirements.

## 🔐 Authentication & Authorization

### Admin Panel Authentication
- **Method**: Laravel Breeze (Session-based authentication)
- **Access**: Admin users with `is_admin = true`
- **Session**: Standard Laravel session management
- **CSRF Protection**: Enabled for all admin operations

### Public API Access
- **Authentication**: No authentication required for public endpoints
- **Rate Limiting**: Standard Laravel rate limiting applies
- **CORS**: Configured for cross-origin requests if needed

## 🌐 API Endpoints

### Public Endpoints

#### 1. Guide Steps API
**Endpoint**: `GET /api/guide-steps`

**Description**: Retrieve published guide steps for Umrah/Hajj guidance in specified locale.

**Parameters**:
```json
{
  "locale": "string (optional, 'en' or 'dv', defaults to current locale)"
}
```

**Example Request**:
```bash
GET /api/guide-steps?locale=en
```

**Response Format**:
```json
{
  "locale": "en",
  "total_steps": 15,
  "steps": [
    {
      "step_number": 1,
      "title": "Intention (Niyyah)",
      "summary": "Setting the intention for Umrah pilgrimage",
      "details": "Before beginning Umrah, you must make a clear intention...",
      "dua_text": "Allahumma inni uridu al-umrah...",
      "fiqh_notes": {
        "hanafi": "According to Hanafi school...",
        "shafi": "According to Shafi school..."
      },
      "checklist": [
        "Make clear intention",
        "Recite Talbiyah",
        "Enter Ihram state"
      ],
      "image_url": "/storage/guide/step1.jpg",
      "video_url": "https://youtube.com/watch?v=example"
    }
  ]
}
```

**Response Codes**:
- `200 OK`: Success
- `400 Bad Request`: Invalid locale parameter

---

### Admin Endpoints

All admin endpoints require authentication and admin privileges.

#### 1. Trip Management

##### List Trips
**Endpoint**: `GET /admin/trips`
**Method**: `GET`
**Authentication**: Required (Admin)
**Description**: Retrieve paginated list of trips

**Response**: HTML view with trips list

##### Create Trip
**Endpoint**: `POST /admin/trips`
**Method**: `POST`
**Authentication**: Required (Admin)
**Description**: Create a new trip

**Request Body** (multipart/form-data):
```json
{
  "title": "string (required)",
  "title_dv": "string (optional)",
  "date_start": "date (required, YYYY-MM-DD)",
  "date_end": "date (required, YYYY-MM-DD)",
  "location": "string (optional)",
  "location_dv": "string (optional)",
  "summary": "text (optional)",
  "summary_dv": "text (optional)",
  "details": "longtext (optional)",
  "details_dv": "longtext (optional)",
  "price_from_mvr": "integer (optional)",
  "status": "enum (current|upcoming|past)",
  "cover_image": "file (optional, image)",
  "is_published": "boolean (optional)"
}
```

**Response**: Redirect to trips list with success message

##### Update Trip
**Endpoint**: `PUT/PATCH /admin/trips/{trip}`
**Method**: `PUT/PATCH`
**Authentication**: Required (Admin)
**Description**: Update existing trip

**Request Body**: Same as create trip

**Response**: Redirect to trips list with success message

##### Delete Trip
**Endpoint**: `DELETE /admin/trips/{trip}`
**Method**: `DELETE`
**Authentication**: Required (Admin)
**Description**: Delete trip and associated media

**Response**: Redirect to trips list with success message

#### 2. Media Management

##### List Media
**Endpoint**: `GET /admin/media`
**Method**: `GET`
**Authentication**: Required (Admin)
**Description**: Retrieve paginated list of media files

##### Create Media
**Endpoint**: `POST /admin/media`
**Method**: `POST`
**Authentication**: Required (Admin)

**Request Body** (multipart/form-data):
```json
{
  "trip_id": "integer (optional)",
  "type": "enum (required, photo|video)",
  "title": "string (optional)",
  "caption": "text (optional)",
  "file_path": "file (required for photos)",
  "video_url": "string (required for videos)",
  "sort_order": "integer (optional, default: 0)",
  "is_published": "boolean (optional, default: true)"
}
```

**Response**: Redirect to media list with success message

##### Update Media
**Endpoint**: `PUT/PATCH /admin/media/{medium}`
**Method**: `PUT/PATCH`
**Authentication**: Required (Admin)

**Request Body**: Same as create media

##### Delete Media
**Endpoint**: `DELETE /admin/media/{medium}`
**Method**: `DELETE`
**Authentication**: Required (Admin)
**Description**: Delete media file and remove from storage

#### 3. Settings Management

##### Get Settings
**Endpoint**: `GET /admin/settings`
**Method**: `GET`
**Authentication**: Required (Admin)
**Description**: Retrieve current social media settings

##### Update Settings
**Endpoint**: `POST /admin/settings`
**Method**: `POST`
**Authentication**: Required (Admin)

**Request Body** (application/x-www-form-urlencoded):
```json
{
  "facebook_url": "string (optional, URL)",
  "instagram_url": "string (optional, URL)",
  "tiktok_url": "string (optional, URL)",
  "whatsapp_number": "string (required, max:20)",
  "viber_url": "string (optional, URL)",
  "youtube_playlist_id": "string (optional, max:50)"
}
```

**Response**: Redirect to settings page with success message

#### 4. Guide Steps Management

##### List Guide Steps
**Endpoint**: `GET /admin/guide-steps`
**Method**: `GET`
**Authentication**: Required (Admin)

##### Create Guide Step
**Endpoint**: `POST /admin/guide-steps`
**Method**: `POST`
**Authentication**: Required (Admin)

**Request Body**:
```json
{
  "step_number": "integer (required)",
  "locale": "string (required, en|dv)",
  "title": "string (required)",
  "summary": "text (optional)",
  "details": "text (optional)",
  "dua_text": "text (optional)",
  "fiqh_notes": "json (optional)",
  "video_url": "string (optional)",
  "image_path": "file (optional)",
  "checklist": "json (optional)",
  "is_published": "boolean (optional, default: true)"
}
```

#### 5. Hero Banner Management

##### List Hero Banners
**Endpoint**: `GET /admin/hero-banners`
**Method**: `GET`
**Authentication**: Required (Admin)

##### Create Hero Banner
**Endpoint**: `POST /admin/hero-banners`
**Method**: `POST`
**Authentication**: Required (Admin)

**Request Body**:
```json
{
  "locale": "enum (required, en|dv)",
  "title": "string (required, max:120)",
  "subtitle": "string (optional, max:200)",
  "primary_cta_text": "string (optional, max:60)",
  "primary_cta_url": "string (optional)",
  "secondary_cta_text": "string (optional, max:60)",
  "secondary_cta_url": "string (optional)",
  "image_path": "file (optional)",
  "overlay_opacity": "integer (optional, default: 40)",
  "sort_order": "integer (optional, default: 0)",
  "is_active": "boolean (optional, default: true)",
  "start_at": "timestamp (optional)",
  "end_at": "timestamp (optional)"
}
```

#### 6. Why Section Management

##### List Why Sections
**Endpoint**: `GET /admin/why-sections`
**Method**: `GET`
**Authentication**: Required (Admin)

##### Update Why Section
**Endpoint**: `PUT /admin/why-sections/{section}`
**Method**: `PUT`
**Authentication**: Required (Admin)

**Request Body**:
```json
{
  "locale": "string (required)",
  "title": "string (required)",
  "subtitle": "text (optional)",
  "image_path": "file (optional)",
  "primary_cta_text": "string (optional, max:60)",
  "primary_cta_url": "string (optional)",
  "secondary_cta_text": "string (optional, max:60)",
  "secondary_cta_url": "string (optional)",
  "background_color": "string (optional, hex color)",
  "text_color": "string (optional, hex color)",
  "button_color": "string (optional, hex color)",
  "is_active": "boolean (optional, default: true)"
}
```

## 📄 Request/Response Formats

### Content Types
- **HTML Views**: Admin panel returns HTML views
- **JSON API**: Public API endpoints return JSON
- **Form Data**: File uploads use multipart/form-data
- **URL Encoded**: Settings updates use application/x-www-form-urlencoded

### Error Responses

#### Standard Error Format
```json
{
  "error": "string",
  "message": "string",
  "code": "integer"
}
```

#### Validation Error Format
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name is required.",
      "The field name must be a string."
    ]
  }
}
```

### Success Responses

#### Admin Operations
- **Redirect**: Admin operations typically redirect to list views
- **Flash Messages**: Success/error messages stored in session
- **Status Codes**: 302 redirects for successful operations

#### API Operations
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    // Response data
  }
}
```

## 🔒 Security Considerations

### Authentication Requirements
1. **Admin Access**: All admin endpoints require authentication
2. **Role Verification**: Admin middleware checks `is_admin` flag
3. **CSRF Protection**: All state-changing operations require CSRF tokens
4. **Input Validation**: All inputs validated using Laravel Form Requests

### Data Validation Rules

#### Trip Validation
```php
'title' => 'required|string|max:255',
'title_dv' => 'nullable|string|max:255',
'date_start' => 'required|date|after_or_equal:today',
'date_end' => 'required|date|after:date_start',
'location' => 'nullable|string|max:255',
'summary' => 'nullable|string',
'details' => 'nullable|string',
'price_from_mvr' => 'nullable|integer|min:0',
'status' => 'required|in:current,upcoming,past',
'cover_image' => 'nullable|image|max:5120'
```

#### Media Validation
```php
'type' => 'required|in:photo,video',
'title' => 'nullable|string|max:255',
'caption' => 'nullable|string',
'file_path' => 'required_if:type,photo|image|max:10240',
'video_url' => 'required_if:type,video|url|max:500',
'sort_order' => 'nullable|integer|min:0'
```

#### Settings Validation
```php
'facebook_url' => 'nullable|url|max:255',
'instagram_url' => 'nullable|url|max:255',
'tiktok_url' => 'nullable|url|max:255',
'whatsapp_number' => 'required|string|max:20',
'viber_url' => 'nullable|url|max:255',
'youtube_playlist_id' => 'nullable|string|max:50'
```

## 📊 Rate Limiting

### Default Laravel Rate Limits
- **Authentication**: 60 requests per minute
- **API**: 60 requests per minute
- **File Uploads**: Limited by file size and processing time

### Custom Rate Limits
```php
// Example rate limiting in routes
Route::middleware(['throttle:admin'])->group(function () {
    // Admin routes with custom rate limiting
});
```

## 🔄 File Upload Handling

### Image Processing
- **Supported Formats**: JPEG, PNG, GIF, WebP
- **Maximum Size**: 5MB for trips, 10MB for media
- **Automatic Processing**: 
  - WebP conversion
  - Multiple sizes generation
  - Thumbnail creation

### Video Handling
- **Supported Platforms**: YouTube, Vimeo, Facebook, Instagram, TikTok
- **URL Validation**: Automatic platform detection
- **Thumbnail Generation**: Automatic thumbnail URLs for supported platforms

## 🌐 CORS Configuration

### Development
```php
// config/cors.php
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['*'],
'allowed_headers' => ['*'],
```

### Production
Should be configured with specific allowed origins for security.

## 📱 Mobile API Considerations

### Responsive Design
- **Mobile-First**: All endpoints return mobile-optimized responses
- **Image Optimization**: Automatic image resizing for mobile devices
- **Pagination**: Efficient pagination for large datasets

### WhatsApp Integration
- **WhatsApp Links**: Automatic generation of `wa.me` links
- **Number Validation**: Proper WhatsApp number formatting
- **Cross-Platform**: Works on mobile and desktop

## 🧪 Testing Endpoints

### Development Testing
```bash
# Test guide steps API
curl "http://localhost:8000/api/guide-steps?locale=en"

# Test admin authentication
curl -X POST "http://localhost:8000/admin/login" \
  -d "email=admin@example.com&password=password"

# Test trip creation (with authentication)
curl -X POST "http://localhost:8000/admin/trips" \
  -H "Authorization: Bearer token" \
  -F "title=Test Trip" \
  -F "date_start=2024-01-01" \
  -F "date_end=2024-01-10"
```

### Health Check
The application provides standard Laravel health check endpoints for monitoring.

---

**API Version**: 1.0  
**Last Updated**: December 2024  
**Base URL**: `https://rihlatravels.mv`  
**Laravel Version**: 11.x
