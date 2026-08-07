# Rihla Travels - Laravel 13 Travel Website

A beautiful, responsive travel website built with Laravel 13, TailwindCSS, and modern web technologies. Features include trip management, media gallery, social media integration, and WhatsApp communication.

## 🌟 Features

- **Public Pages**: Home, Trips, Gallery, Social Hub, Contact
- **Admin Panel**: Full CRUD for trips and media management
- **Multi-language**: English and Dhivehi (RTL support)
- **Image Processing**: Automatic WebP conversion and resizing
- **WhatsApp Integration**: Direct messaging throughout the site
- **Responsive Design**: Mobile-first approach with TailwindCSS
- **YouTube Integration**: Video embedding and playlist support

## 🚀 Tech Stack

- **Backend**: Laravel 13, PHP 8.3+
- **Frontend**: TailwindCSS, Vite, Alpine.js
- **Database**: MySQL/PostgreSQL
- **Image Processing**: Intervention Image v3
- **Authentication**: Laravel Breeze
- **Deployment**: cPanel/StackCP ready

## 📋 Requirements

- PHP 8.3 or higher
- Composer
- Node.js & NPM
- MySQL/PostgreSQL
- Web server (Apache/Nginx)

## 🛠️ Installation

### 1. Clone and Setup
```bash
git clone <repository-url>
cd rihla
cp .env.example .env
composer install
npm install
```

### 2. Environment Configuration
```bash
php artisan key:generate
# Edit .env file with your database and app settings
```

### 3. Database Setup
```bash
php artisan migrate --seed
php artisan storage:link
```

### 4. Build Assets
```bash
npm run build
```

### 5. Start Development Server
```bash
php artisan serve
```

## 🔐 Admin Access

Default admin credentials:
- **Email**: admin@rihlatravels.mv
- **Password**: password

**⚠️ Important**: Change these credentials after first login!

## 🌐 Public Routes

- `/` - Home page
- `/trips` - Trip listings
- `/trips/{slug}` - Individual trip details
- `/gallery` - Media gallery
- `/social` - Social media hub
- `/contact` - Contact information
- `/lang/{locale}` - Language switching

## 🔧 Admin Routes

- `/admin` - Dashboard
- `/admin/trips` - Trip management
- `/admin/media` - Media management
- `/admin/settings` - Social media settings

## 📱 WhatsApp Integration

The site includes WhatsApp integration throughout:
- Floating button on mobile
- CTA buttons on all pages
- Contact information
- Number: 9607972434 (configurable in admin)

## 🎨 Customization

### Brand Colors
- Primary Green: `#0e7a57`
- Accent Gold: `#d1a34a`

### TailwindCSS
Custom components and utilities are defined in `resources/css/app.css`

### Language Files
- English: `resources/lang/en/messages.php`
- Dhivehi: `resources/lang/dv/messages.php`

## 🚀 Deployment

### cPanel/StackCP Deployment

1. **Upload Files**
   - Upload all project files to your hosting
   - Set document root to `/public`

2. **Server Requirements**
   - PHP 8.3+
   - MySQL/PostgreSQL
   - Composer support

3. **Install Dependencies**
```bash
composer install --no-dev --optimize-autoloader
npm run build
```

4. **Environment Setup**
```bash
cp .env.example .env
# Edit .env with production settings
php artisan key:generate
```

5. **Database & Storage**
```bash
php artisan migrate --seed
php artisan storage:link
php artisan optimize
```

6. **File Permissions**
   - `storage/` - Writable (755)
   - `bootstrap/cache/` - Writable (755)

### Production Environment
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
```

## 📁 Project Structure

```
rihla/
├── app/
│   ├── Http/Controllers/
│   │   ├── Admin/          # Admin controllers
│   │   ├── HomeController.php
│   │   ├── TripController.php
│   │   ├── MediaController.php
│   │   └── PageController.php
│   ├── Models/
│   │   ├── Trip.php
│   │   ├── Media.php
│   │   └── Setting.php
│   └── Http/Middleware/
│       └── SetLocale.php   # Language switching
├── resources/
│   ├── views/
│   │   ├── admin/          # Admin views
│   │   ├── trips/          # Trip views
│   │   ├── media/          # Gallery views
│   │   ├── pages/          # Static pages
│   │   └── layouts/        # Layout templates
│   └── lang/               # Language files
├── database/
│   ├── migrations/         # Database structure
│   └── seeders/           # Sample data
└── routes/
    └── web.php            # All routes
```

## 🔧 Development

### Adding New Features
1. Create controller in appropriate namespace
2. Add routes to `routes/web.php`
3. Create views in `resources/views/`
4. Add translations to language files
5. Update admin panel if needed

### Image Processing
Photos are automatically:
- Converted to WebP format
- Resized to 1600px max width
- Thumbnails created at 400px width
- Stored in `storage/app/public/`

### YouTube Videos
- Accept full YouTube URLs
- Automatically extract video IDs
- Generate thumbnails from YouTube
- Embed with responsive iframes

## 🐛 Troubleshooting

### Common Issues

1. **Storage Link**
   ```bash
   php artisan storage:link
   ```

2. **Permissions**
   ```bash
   chmod -R 755 storage bootstrap/cache
   ```

3. **Cache Issues**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan view:clear
   ```

4. **Image Processing**
   - Ensure GD or Imagick extension is enabled
   - Check storage permissions

## 📞 Support

For technical support or questions:
- Email: info@rihlatravels.mv
- WhatsApp: +960 797 2434

## 📄 License

This project is proprietary software developed for Rihla Travels.

---

**Built with ❤️ using Laravel 13 and TailwindCSS**
