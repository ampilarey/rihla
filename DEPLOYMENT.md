# 🚀 Rihla Travels - Deployment Checklist

## Pre-Deployment Checklist

### ✅ Local Development Complete
- [x] All routes working correctly
- [x] Admin panel functional
- [x] Image processing working
- [x] WhatsApp integration tested
- [x] Language switching working
- [x] Assets built and optimized

## 📋 Server Requirements

### Minimum Requirements
- **PHP**: 8.2 or higher
- **MySQL**: 5.7+ or PostgreSQL 10+
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Extensions**: GD or Imagick, OpenSSL, PDO, Mbstring, XML, Ctype, JSON, Tokenizer

### Recommended
- **PHP**: 8.3+
- **MySQL**: 8.0+
- **Memory**: 512MB+ RAM
- **Storage**: 2GB+ available space

## 🔧 Deployment Steps

### 1. File Upload
```bash
# Upload all project files to your hosting
# Set document root to /public folder
# Ensure .env file is in root directory
```

### 2. Environment Configuration
```bash
# Copy and edit environment file
cp .env.example .env

# Update these values:
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_NAME="Rihla Travels"

# Database settings
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

# Mail settings (if using)
MAIL_MAILER=smtp
MAIL_HOST=your_smtp_host
MAIL_PORT=587
MAIL_USERNAME=your_email
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=info@yourdomain.com
MAIL_FROM_NAME="Rihla Travels"
```

### 3. Install Dependencies
```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node dependencies and build assets
npm install
npm run build
```

### 4. Database Setup
```bash
# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate --force

# Seed database with demo data
php artisan db:seed --force

# Create storage link
php artisan storage:link
```

### 5. File Permissions
```bash
# Set correct permissions
chmod -R 755 storage
chmod -R 755 bootstrap/cache
chmod -R 644 storage/logs/*.log
chmod -R 644 bootstrap/cache/*.php

# Set ownership (if needed)
chown -R www-data:www-data storage
chown -R www-data:www-data bootstrap/cache
```

### 6. Optimization
```bash
# Clear and cache configuration
php artisan config:clear
php artisan config:cache

# Clear and cache routes
php artisan route:clear
php artisan route:cache

# Clear and cache views
php artisan view:clear
php artisan view:cache

# Optimize for production
php artisan optimize
```

## 🔐 Admin Access

### Default Credentials
- **Email**: admin@rihlatravels.mv
- **Password**: password

### ⚠️ IMPORTANT: Change After Deployment
```bash
# Option 1: Use admin panel to change password
# Option 2: Use tinker
php artisan tinker
$user = App\Models\User::where('email', 'admin@rihlatravels.mv')->first();
$user->update(['password' => bcrypt('new_secure_password')]);
```

## 📱 WhatsApp Configuration

### Update WhatsApp Number
1. Go to `/admin/settings`
2. Update WhatsApp number (without + or country code)
3. Save settings

### Test WhatsApp Integration
- Test floating button on mobile
- Test CTA buttons on all pages
- Verify wa.me links work correctly

## 🌐 Domain Configuration

### SSL Certificate
- Ensure HTTPS is enabled
- Update APP_URL in .env to https://yourdomain.com

### DNS Settings
- Point domain to hosting server
- Set up subdomain if needed (e.g., admin.yourdomain.com)

## 📊 Performance Optimization

### Caching
```bash
# Enable Redis/Memcached if available
# Update cache configuration in config/cache.php
```

### Image Optimization
- Ensure GD or Imagick extension is enabled
- Test image upload and WebP conversion
- Verify storage permissions

### Database Optimization
```bash
# Optimize database tables
php artisan db:optimize

# Check database performance
php artisan db:show
```

## 🧪 Post-Deployment Testing

### Public Pages
- [ ] Home page loads correctly
- [ ] Trips page with tabs working
- [ ] Individual trip pages working
- [ ] Gallery with filters working
- [ ] Social page with YouTube embed
- [ ] Contact page with WhatsApp CTA
- [ ] Language switching (EN/DV) working

### Admin Panel
- [ ] Login working with admin credentials
- [ ] Dashboard showing correct counts
- [ ] Trip CRUD operations working
- [ ] Media upload and management working
- [ ] Settings update working
- [ ] Image processing (WebP conversion) working

### Mobile Responsiveness
- [ ] All pages responsive on mobile
- [ ] WhatsApp floating button visible
- [ ] Navigation menu working on mobile
- [ ] Images and videos displaying correctly

### Performance
- [ ] Page load times under 3 seconds
- [ ] Images loading with lazy loading
- [ ] CSS and JS files minified
- [ ] Database queries optimized

## 🚨 Troubleshooting

### Common Issues

#### 500 Server Error
```bash
# Check error logs
tail -f storage/logs/laravel.log

# Verify file permissions
ls -la storage/
ls -la bootstrap/cache/
```

#### Image Upload Issues
```bash
# Check storage permissions
chmod -R 755 storage

# Verify storage link
php artisan storage:link

# Check PHP extensions
php -m | grep -i gd
php -m | grep -i imagick
```

#### Database Connection Issues
```bash
# Test database connection
php artisan tinker
DB::connection()->getPdo();

# Check .env file
cat .env | grep DB_
```

#### Slow Performance
```bash
# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Re-optimize
php artisan optimize
```

## 📞 Support

### Technical Issues
- Check Laravel logs: `storage/logs/laravel.log`
- Check server error logs
- Verify file permissions and ownership

### Contact Information
- **Email**: info@rihlatravels.mv
- **WhatsApp**: +960 797 2434

## 🎯 Final Checklist

### Before Going Live
- [ ] All functionality tested
- [ ] Admin password changed
- [ ] WhatsApp number updated
- [ ] Social media links configured
- [ ] YouTube playlist ID set (if using)
- [ ] SSL certificate active
- [ ] Domain pointing correctly
- [ ] Backup system in place

### Monitoring
- [ ] Set up error monitoring
- [ ] Configure backup schedule
- [ ] Set up performance monitoring
- [ ] Plan maintenance schedule

---

**🎉 Congratulations! Your Rihla Travels website is now live and ready to serve customers!**

Remember to:
- Regularly backup your database and files
- Monitor performance and security
- Keep Laravel and dependencies updated
- Test new features before deploying to production
