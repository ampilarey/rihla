# 🚀 Rihla Travels - Deployment Guide

## 📋 Overview

This comprehensive deployment guide provides step-by-step instructions for deploying the Rihla Travels website to production environments. The guide covers server requirements, configuration, optimization, and post-deployment testing procedures.

## 🏗️ Server Requirements

### Minimum Requirements
- **PHP**: 8.2 or higher
- **MySQL**: 5.7+ or PostgreSQL 10+
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Memory**: 512MB RAM
- **Storage**: 2GB available space
- **Extensions**: GD or Imagick, OpenSSL, PDO, Mbstring, XML, Ctype, JSON, Tokenizer

### Recommended Production Setup
- **PHP**: 8.3+
- **MySQL**: 8.0+
- **Memory**: 1GB+ RAM
- **Storage**: 5GB+ available space
- **Cache**: Redis or Memcached
- **CDN**: CloudFlare or similar service

### Required PHP Extensions
```ini
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
extension=zip
```

## 🔧 Pre-Deployment Preparation

### Local Development Completion Checklist
- [x] All routes working correctly
- [x] Admin panel fully functional
- [x] Image processing and WebP conversion working
- [x] WhatsApp integration tested across devices
- [x] Language switching (EN/DV) working properly
- [x] Assets built and optimized for production
- [x] Database migrations tested
- [x] All features tested on mobile and desktop

### Code Preparation
```bash
# Ensure all changes are committed
git add .
git commit -m "Pre-deployment commit"
git push origin main

# Create production build
npm run build
composer install --no-dev --optimize-autoloader
```

> **After any `composer update` that moves Filament**, run
> `php artisan filament:upgrade` and commit what it republishes into
> `public/js/filament`, `public/css/filament` and `public/fonts/filament`.
> There is no build step on the server, so those files ship from git exactly
> like `public/build`. Stale assets are silent — last version's JavaScript
> against this version's markup — so a test fails when they drift. See
> [ADR 0003](adr/0003-filament-for-new-admin-modules.md).

## 📤 File Upload and Initial Setup

### 1. Server Access and File Transfer
```bash
# Upload via FTP/SFTP (recommended: use rsync for better reliability)
rsync -avz --exclude='node_modules' --exclude='.git' ./ user@server:/path/to/website/

# Or use traditional FTP/SFTP client
# Upload entire project folder excluding: node_modules, .git, storage/logs
```

### 2. Directory Structure on Server
```
/var/www/html/rihlatravels.mv/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/          # Document root
├── resources/
├── routes/
├── storage/         # Writable directory
├── vendor/
├── .env             # Environment configuration
├── artisan
└── composer.json
```

### 3. Document Root Configuration
**Apache (.htaccess)**:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

**Nginx Configuration**:
```nginx
server {
    listen 80;
    server_name rihlatravels.mv;
    root /var/www/html/rihlatravels.mv/public;
    
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## ⚙️ Environment Configuration

### 1. Environment File Setup
```bash
# Copy example environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 2. Production Environment Variables
```env
# Application Settings
APP_NAME="Rihla Travels"
APP_ENV=production
APP_KEY=base64:your-generated-key-here
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://rihlatravels.mv
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

# Database Configuration
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=rihla_travels_prod
DB_USERNAME=rihla_user
DB_PASSWORD=secure_password_here

# Cache Configuration (if using Redis)
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Session Configuration
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=mail.rihlatravels.mv
MAIL_PORT=587
MAIL_USERNAME=info@rihlatravels.mv
MAIL_PASSWORD=secure_email_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=info@rihlatravels.mv
MAIL_FROM_NAME="Rihla Travels"

# File Storage
FILESYSTEM_DISK=public
```

### 3. Security Configuration
```env
# Security Headers (add to .env if not present)
SANCTUM_STATEFUL_DOMAINS=rihlatravels.mv,www.rihlatravels.mv

# Session Security
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

## 📦 Dependencies and Asset Building

### 1. PHP Dependencies
```bash
# Install production dependencies (no dev packages)
composer install --no-dev --optimize-autoloader --no-interaction

# Verify installation
composer show
```

### 2. Node.js Dependencies and Asset Building
```bash
# Install Node dependencies
npm ci --production

# Build production assets
npm run build

# Verify build
ls -la public/build/
```

### 3. Laravel Optimization
```bash
# Generate optimized autoloader
composer dump-autoload --optimize --no-dev

# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# General optimization
php artisan optimize
```

## 🗄️ Database Setup

### 1. Database Creation and User Setup
```sql
-- Create database
CREATE DATABASE rihla_travels_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create user with appropriate permissions
CREATE USER 'rihla_user'@'localhost' IDENTIFIED BY 'secure_password_here';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX ON rihla_travels_prod.* TO 'rihla_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Laravel Database Migration
```bash
# Run migrations
php artisan migrate --force

# Seed database with initial data
php artisan db:seed --force
```

### 3. Storage Link Creation
```bash
# Create symbolic link for storage
php artisan storage:link

# Verify link creation
ls -la public/storage
```

## 🔐 File Permissions and Security

### 1. Directory Permissions
```bash
# Set proper permissions for Laravel directories
sudo chown -R www-data:www-data /var/www/html/rihlatravels.mv
sudo chmod -R 755 /var/www/html/rihlatravels.mv

# Set write permissions for storage and cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 644 storage/logs/*.log 2>/dev/null || true

# Secure sensitive files
sudo chmod 600 .env
sudo chmod 600 storage/app/*
```

### 2. SELinux Configuration (if applicable)
```bash
# Set SELinux contexts
sudo setsebool -P httpd_can_network_connect 1
sudo setsebool -P httpd_can_network_connect_db 1
sudo chcon -R -t httpd_exec_t /var/www/html/rihlatravels.mv
sudo restorecon -R /var/www/html/rihlatravels.mv
```

## 🌐 Web Server Configuration

### Apache Virtual Host Configuration
```apache
<VirtualHost *:80>
    ServerName rihlatravels.mv
    ServerAlias www.rihlatravels.mv
    DocumentRoot /var/www/html/rihlatravels.mv/public
    
    <Directory /var/www/html/rihlatravels.mv/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/rihlatravels_error.log
    CustomLog ${APACHE_LOG_DIR}/rihlatravels_access.log combined
</VirtualHost>

# Redirect HTTP to HTTPS
<VirtualHost *:80>
    ServerName rihlatravels.mv
    ServerAlias www.rihlatravels.mv
    Redirect permanent / https://rihlatravels.mv/
</VirtualHost>

<VirtualHost *:443>
    ServerName rihlatravels.mv
    ServerAlias www.rihlatravels.mv
    DocumentRoot /var/www/html/rihlatravels.mv/public
    
    SSLEngine on
    SSLCertificateFile /path/to/certificate.crt
    SSLCertificateKeyFile /path/to/private.key
    SSLCertificateChainFile /path/to/chain.crt
    
    <Directory /var/www/html/rihlatravels.mv/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx Configuration with SSL
```nginx
# HTTP to HTTPS redirect
server {
    listen 80;
    server_name rihlatravels.mv www.rihlatravels.mv;
    return 301 https://rihlatravels.mv$request_uri;
}

# HTTPS configuration
server {
    listen 443 ssl http2;
    server_name rihlatravels.mv www.rihlatravels.mv;
    root /var/www/html/rihlatravels.mv/public;
    
    # SSL Configuration
    ssl_certificate /path/to/certificate.crt;
    ssl_certificate_key /path/to/private.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    add_header Content-Security-Policy "default-src 'self'" always;
    
    index index.php;
    
    # Main location block
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }
    
    # Static file caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|webp)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

## 🔐 SSL Certificate Setup

### Let's Encrypt (Recommended)
```bash
# Install Certbot
sudo apt update
sudo apt install certbot python3-certbot-apache

# Obtain certificate
sudo certbot --apache -d rihlatravels.mv -d www.rihlatravels.mv

# Auto-renewal setup
sudo crontab -e
# Add: 0 12 * * * /usr/bin/certbot renew --quiet
```

### Manual SSL Certificate
```bash
# Upload certificate files to secure location
# Update web server configuration with certificate paths
# Test SSL configuration
openssl s_client -connect rihlatravels.mv:443
```

## 🚀 Performance Optimization

### 1. PHP-FPM Optimization
```ini
# /etc/php/8.3/fpm/pool.d/www.conf
[www]
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 1000
```

### 2. Laravel Caching Setup
```bash
# Clear existing caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Create production caches
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### 3. Database Optimization
```bash
# Optimize database tables
php artisan db:optimize

# Check database performance
php artisan db:show

# Create database indexes if needed
mysql -u root -p -e "OPTIMIZE TABLE rihla_travels_prod.*;"
```

## 🔐 Admin Configuration

### 1. Admin User Setup
```bash
# Change default admin password
php artisan tinker
```

```php
// In tinker console
$user = App\Models\User::where('email', 'admin@rihlatravels.mv')->first();
$user->update(['password' => bcrypt('new_secure_password_here')]);
exit
```

### 2. Admin Settings Configuration
Access `/admin/settings` and configure:
- **WhatsApp Number**: Update with production number
- **Social Media URLs**: Configure all platform links
- **YouTube Playlist ID**: Set up video content

### 3. Content Migration
```bash
# Import content if migrating from another system
# Use Laravel seeders or custom migration scripts
php artisan db:seed --class=ProductionDataSeeder
```

## 🧪 Post-Deployment Testing

### 1. Basic Functionality Tests
- [ ] **Homepage**: Loads correctly with hero banners
- [ ] **Trip Pages**: All trip listings and individual pages working
- [ ] **Gallery**: Media gallery with filtering functional
- [ ] **Guide**: Islamic travel guide accessible
- [ ] **Contact**: Contact page and WhatsApp integration working
- [ ] **Language Switching**: EN/DV switching functional

### 2. Admin Panel Tests
- [ ] **Login**: Admin authentication working
- [ ] **Dashboard**: Statistics and navigation functional
- [ ] **Trip Management**: Create, edit, delete trips working
- [ ] **Media Upload**: Image upload and processing working
- [ ] **Settings**: Configuration updates working
- [ ] **Guide Management**: Guide step CRUD operations working

### 3. Performance Tests
```bash
# Test page load times
curl -w "@curl-format.txt" -o /dev/null -s "https://rihlatravels.mv"

# Test database queries
php artisan tinker
DB::enableQueryLog();
// Run some queries
DB::getQueryLog();

# Check image optimization
ls -la storage/app/public/
```

### 4. Security Tests
- [ ] **HTTPS**: SSL certificate working and redirecting HTTP
- [ ] **Admin Access**: Only authorized users can access admin
- [ ] **File Permissions**: Sensitive files properly protected
- [ ] **Input Validation**: Forms properly validated

### 5. Mobile Responsiveness Tests
- [ ] **Mobile Navigation**: Menu working on mobile devices
- [ ] **WhatsApp FAB**: Floating action button visible and functional
- [ ] **Touch Interface**: All interactive elements touch-friendly
- [ ] **Image Scaling**: Images properly sized for mobile screens

## 📊 Monitoring and Maintenance

### 1. Error Monitoring Setup
```bash
# Monitor Laravel logs
tail -f storage/logs/laravel.log

# Set up log rotation
sudo nano /etc/logrotate.d/laravel
```

### 2. Performance Monitoring
```bash
# Install monitoring tools (optional)
# New Relic, DataDog, or custom monitoring solutions

# Regular performance checks
php artisan optimize
php artisan queue:work --daemon  # if using queues in future
```

### 3. Backup Strategy
```bash
# Database backup script
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u rihla_user -p rihla_travels_prod > /backups/rihla_db_$DATE.sql

# File backup script
tar -czf /backups/rihla_files_$DATE.tar.gz /var/www/html/rihlatravels.mv/
```

## 🚨 Troubleshooting Common Issues

### 1. 500 Server Errors
```bash
# Check Laravel logs
tail -f storage/logs/laravel.log

# Check web server error logs
tail -f /var/log/apache2/error.log
# or
tail -f /var/log/nginx/error.log

# Verify file permissions
ls -la storage/
ls -la bootstrap/cache/
```

### 2. Image Upload Issues
```bash
# Check storage permissions
sudo chmod -R 775 storage/
php artisan storage:link

# Verify PHP extensions
php -m | grep -i gd
php -m | grep -i imagick

# Test image processing
php artisan tinker
```

### 3. Database Connection Issues
```bash
# Test database connection
php artisan tinker
DB::connection()->getPdo();

# Check .env file
grep DB_ .env
```

### 4. Performance Issues
```bash
# Clear all caches and re-optimize
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan optimize

# Check server resources
htop
df -h
free -m
```

## 🎯 Go-Live Checklist

### Final Pre-Launch Verification
- [ ] All functionality tested and working
- [ ] Admin password changed from default
- [ ] WhatsApp number updated for production
- [ ] Social media links configured
- [ ] SSL certificate active and working
- [ ] Domain properly pointing to server
- [ ] Backup system in place and tested
- [ ] Error monitoring configured
- [ ] Performance optimized
- [ ] Security measures implemented

### Post-Launch Monitoring
- [ ] Monitor error logs for first 24 hours
- [ ] Check page load times
- [ ] Verify all features working correctly
- [ ] Test on multiple devices and browsers
- [ ] Confirm backup processes working

---

**Deployment completed successfully!** 🎉

## 📞 Support and Maintenance

### Emergency Contacts
- **Technical Support**: info@rihlatravels.mv
- **WhatsApp Support**: +960 797 2434

### Regular Maintenance Tasks
1. **Weekly**: Check error logs and performance
2. **Monthly**: Update dependencies and security patches
3. **Quarterly**: Review and optimize database
4. **Annually**: Security audit and backup strategy review

---

**Deployment Guide Version**: 1.0  
**Last Updated**: December 2024  
**Laravel Version**: 11.x  
**PHP Version**: 8.2+
