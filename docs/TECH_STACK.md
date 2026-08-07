# 🛠️ Rihla Travels - Tech Stack Documentation

## 📋 Overview

The Rihla Travels platform is built using modern web technologies with a focus on performance, security, and maintainability. This document provides a comprehensive overview of the technology stack, dependencies, and architectural decisions.

## 🏗️ Architecture Overview

### Backend Architecture
- **Framework**: Laravel 11 (PHP 8.2+)
- **Pattern**: Model-View-Controller (MVC)
- **Database**: MySQL 8.0+ / PostgreSQL 10+
- **Authentication**: Laravel Breeze
- **File Storage**: Local with symbolic links

### Frontend Architecture
- **CSS Framework**: TailwindCSS 3.1+
- **JavaScript**: Alpine.js 3.4+
- **Build Tool**: Vite 7.0+
- **Responsive**: Mobile-first design approach

## 🔧 Backend Technologies

### Core Framework
```json
{
  "laravel/framework": "^12.0",
  "php": "^8.2"
}
```

**Laravel 11 Features Used:**
- MVC Architecture
- Eloquent ORM
- Route Model Binding
- Middleware
- Service Providers
- Artisan Commands
- Database Migrations & Seeders

### Key Dependencies

#### Image Processing
```json
{
  "intervention/image": "^2.7"
}
```
- **Purpose**: Image manipulation, resizing, and WebP conversion
- **Features**: Automatic thumbnail generation, format conversion
- **Usage**: Trip cover images, gallery photos, hero banners

#### PDF Generation
```json
{
  "barryvdh/laravel-dompdf": "^3.1"
}
```
- **Purpose**: PDF generation for travel guides and documents
- **Features**: HTML to PDF conversion, custom styling
- **Usage**: Umrah/Hajj guide PDF downloads

### Development Dependencies
```json
{
  "laravel/breeze": "^2.3",
  "larastan/larastan": "^3.6",
  "laravel/pail": "^1.2.2",
  "laravel/pint": "^1.24",
  "laravel/sail": "^1.41"
}
```

#### Development Tools
- **Laravel Breeze**: Authentication scaffolding
- **Larastan**: Static analysis (PHPStan for Laravel)
- **Laravel Pail**: Real-time log viewing
- **Laravel Pint**: Code style fixing (Laravel's PHP CS Fixer)
- **Laravel Sail**: Docker development environment

## 🎨 Frontend Technologies

### CSS Framework
```json
{
  "tailwindcss": "^3.1.0",
  "@tailwindcss/forms": "^0.5.2",
  "@tailwindcss/vite": "^4.0.0"
}
```

**TailwindCSS Configuration:**
- Custom color palette for brand identity
- Responsive breakpoints
- Component utilities
- Form styling enhancements

### JavaScript Framework
```json
{
  "alpinejs": "^3.4.2"
}
```

**Alpine.js Features:**
- Lightweight reactive framework
- Component-based JavaScript
- Directives for DOM manipulation
- State management for interactive elements

### Build Tools
```json
{
  "vite": "^7.0.4",
  "laravel-vite-plugin": "^2.0.0",
  "autoprefixer": "^10.4.2",
  "postcss": "^8.4.31"
}
```

**Vite Configuration:**
- Fast hot module replacement (HMR)
- Asset optimization and minification
- CSS and JS bundling
- Development server with HTTPS support

### Additional Frontend Dependencies
```json
{
  "axios": "^1.11.0",
  "concurrently": "^9.0.1"
}
```

- **Axios**: HTTP client for API requests
- **Concurrently**: Run multiple npm scripts simultaneously

## 🗄️ Database Technologies

### Database System
- **Primary**: MySQL 8.0+ (Production)
- **Alternative**: PostgreSQL 10+ (Compatible)
- **Development**: SQLite (Local development)

### Database Features Used
- **Migrations**: Version-controlled database schema
- **Seeders**: Sample data population
- **Eloquent ORM**: Object-relational mapping
- **Query Builder**: Fluent database interface
- **Pagination**: Built-in Laravel pagination
- **Soft Deletes**: For data retention

### Key Database Extensions
- **Foreign Key Constraints**: Data integrity
- **Indexes**: Query optimization
- **JSON Columns**: Flexible data storage
- **Timestamps**: Automatic created_at/updated_at

## 🖼️ Image Processing & Media

### Intervention Image v2.7
```php
// Example usage in the application
use Intervention\Image\Facades\Image;

// Image resizing and WebP conversion
$image = Image::make($uploadedFile)
    ->resize(1600, null, function ($constraint) {
        $constraint->aspectRatio();
    })
    ->encode('webp', 90);
```

**Image Processing Features:**
- **Automatic WebP Conversion**: Modern image format for better compression
- **Multiple Image Sizes**: Original, large, thumbnail variants
- **Aspect Ratio Preservation**: Proper image resizing without distortion
- **Quality Optimization**: Balanced file size and image quality

### Media Storage Strategy
- **Local Storage**: Files stored in `storage/app/public/`
- **Symbolic Links**: Public access via `storage:link`
- **Organized Structure**: Date-based folder organization
- **Backup Strategy**: Regular file system backups

## 🌐 Web Server Requirements

### PHP Configuration
```ini
; Required PHP settings
php_version >= 8.2
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 50M
post_max_size = 50M

; Required Extensions
extension=gd          ; or imagick for image processing
extension=pdo_mysql   ; or pdo_pgsql for PostgreSQL
extension=mbstring
extension=openssl
extension=tokenizer
extension=xml
extension=ctype
extension=json
extension=fileinfo
extension=curl
```

### Web Server Options
1. **Apache 2.4+**
   - mod_rewrite enabled
   - .htaccess support
   - SSL/TLS support

2. **Nginx 1.18+**
   - PHP-FPM configuration
   - URL rewriting
   - SSL/TLS termination

## 🔐 Security Stack

### Laravel Security Features
- **CSRF Protection**: Built-in CSRF token validation
- **XSS Protection**: Automatic output escaping
- **SQL Injection Prevention**: Eloquent ORM and query builder
- **Authentication**: Secure session management
- **Authorization**: Role-based access control

### Authentication System
```php
// Laravel Breeze implementation
Route::middleware(['auth', 'can:admin'])->group(function () {
    // Admin-only routes
});
```

**Features:**
- **User Registration/Login**: Email and password authentication
- **Admin Role**: Special admin privileges for content management
- **Session Management**: Secure session handling
- **Password Hashing**: bcrypt encryption

## 📱 Progressive Web App Features

### Service Worker
- **Offline Support**: Basic offline functionality
- **Caching Strategy**: Asset and API response caching
- **Background Sync**: Offline action queuing

### Manifest Configuration
```json
{
  "name": "Rihla Travels",
  "short_name": "Rihla",
  "description": "Travel and pilgrimage services",
  "start_url": "/",
  "display": "standalone",
  "theme_color": "#0e7a57",
  "background_color": "#ffffff"
}
```

## 🚀 Performance Optimizations

### Frontend Optimizations
- **Asset Minification**: CSS and JS compression
- **Image Optimization**: WebP format and proper sizing
- **Lazy Loading**: Images loaded on demand
- **Code Splitting**: Modular JavaScript loading

### Backend Optimizations
- **Query Optimization**: Efficient database queries
- **Caching**: Laravel's built-in caching mechanisms
- **Database Indexing**: Proper index strategy
- **Asset Versioning**: Cache-busting for updated assets

### Server Optimizations
- **Gzip Compression**: Text asset compression
- **Browser Caching**: Appropriate cache headers
- **CDN Ready**: Optimized for content delivery networks
- **Database Connection Pooling**: Efficient database connections

## 🔄 Development Workflow

### Build Process
```bash
# Development
npm run dev          # Start Vite dev server
php artisan serve    # Start Laravel server

# Production
npm run build        # Build optimized assets
composer install --no-dev --optimize-autoloader
```

### Code Quality Tools
- **Laravel Pint**: Code style enforcement
- **Larastan**: Static analysis
- **Laravel Testing**: PHPUnit integration
- **ESLint**: JavaScript code quality (if configured)

## 📦 Package Management

### Composer Dependencies
```bash
# Production dependencies
composer install --no-dev --optimize-autoloader

# Development dependencies
composer install
```

### NPM Dependencies
```bash
# Development
npm install

# Production build
npm run build
```

## 🏗️ Deployment Architecture

### Production Environment
- **Web Server**: Apache/Nginx with PHP-FPM
- **Database**: MySQL 8.0+ with replication support
- **File Storage**: Local filesystem with backup strategy
- **SSL/TLS**: HTTPS enforcement
- **Monitoring**: Error logging and performance monitoring

### Development Environment
- **Local Development**: Laravel Sail (Docker)
- **Database**: SQLite for quick setup
- **Asset Compilation**: Vite dev server with HMR
- **Debugging**: Laravel Telescope (if installed)

## 🔧 Configuration Management

### Environment Variables
```env
# Core Laravel settings
APP_NAME="Rihla Travels"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://rihlatravels.mv

# Database configuration
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=rihla_travels
DB_USERNAME=username
DB_PASSWORD=password

# Image processing
FILESYSTEM_DISK=public
```

### Service Providers
- **AppServiceProvider**: Global application configuration
- **AuthServiceProvider**: Authentication configuration
- **ViewServiceProvider**: View configuration and localization

## 📊 Monitoring & Analytics

### Error Tracking
- **Laravel Logging**: Comprehensive error logging
- **Laravel Pail**: Real-time log monitoring
- **Custom Error Handling**: User-friendly error pages

### Performance Monitoring
- **Query Logging**: Database query optimization
- **Asset Monitoring**: Loading time optimization
- **Server Monitoring**: Resource usage tracking

---

**Document Version**: 1.0  
**Last Updated**: December 2024  
**Laravel Version**: 11.x  
**PHP Version**: 8.2+
