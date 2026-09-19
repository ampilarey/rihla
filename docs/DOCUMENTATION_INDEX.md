# 📚 Rihla Travels - Documentation Index

Welcome to the comprehensive documentation for the Rihla Travels website. This documentation provides detailed information about the project architecture, features, deployment, and maintenance.

## 📋 Documentation Structure

### 🎯 Core Documentation
- **[Website Upgrade Plan](WEBSITE_UPGRADE_PLAN.md)** - Audited current state, phased roadmap, and the full upgrade backlog
- **[Domain Model & Booking Engine](DOMAIN_MODEL_AND_BOOKING_ENGINE.md)** - Target domain model, booking lifecycle, capacity, payments, documents, visa and permits
- **[Brand & Design System](BRAND.md)** - Colour palette, component classes, contrast reference and the open logo decision
- **[Project Overview](PROJECT_OVERVIEW.md)** - Vision, goals, target audience, and key differentiators
- **[Tech Stack](TECH_STACK.md)** - Complete technology stack and dependencies
- **[Database Schema](DATABASE_SCHEMA.md)** - Database structure, relationships, and migrations
- **[Architecture decisions](adr/)** - One short record per significant decision, newest last: [how content is translated](adr/0001-how-content-is-translated.md), [staying on cPanel](adr/0002-stay-on-cpanel-shared-hosting.md), [Filament for new admin modules](adr/0003-filament-for-new-admin-modules.md), [keeping the media model for now](adr/0004-keep-the-media-model-until-there-is-more-than-one-owner.md)

### 🚀 Implementation Guides
- **[Feature Specifications](FEATURE_SPECIFICATIONS.md)** - Detailed feature descriptions and requirements
- **[API Specification](API_SPECIFICATION.md)** - REST API endpoints and data formats
- **[Deployment Guide](DEPLOYMENT_GUIDE.md)** - Step-by-step deployment instructions

### 🔧 Technical References
- **[Authentication Guide](AUTHENTICATION_GUIDE.md)** - User authentication and authorization
- **[Frontend Architecture](FRONTEND_ARCHITECTURE.md)** - UI/UX patterns and component structure
- **[Content Management](CONTENT_MANAGEMENT.md)** - Admin panel and content workflow

## 🏗️ Project Overview

**Rihla Travels** is a comprehensive travel website built with Laravel 13, featuring:

### ✨ Key Features
- **Multi-language Support** - English and Dhivehi with RTL support
- **Trip Management** - Complete CRUD for travel packages
- **Media Gallery** - Photo and video management with WebP conversion
- **Islamic Travel Guide** - Comprehensive Umrah/Hajj guidance
- **WhatsApp Integration** - Direct communication throughout the site
- **Admin Panel** - Full content management system

### 🎨 Design Features
- **Responsive Design** - Mobile-first approach with TailwindCSS
- **Hero Banners** - Customizable homepage banners
- **Why Sections** - Feature highlights with custom styling
- **Social Integration** - YouTube, Facebook, Instagram, TikTok support

### 🌐 Technology Stack
- **Backend**: Laravel 13, PHP 8.3+
- **Frontend**: TailwindCSS, Alpine.js, Vite
- **Database**: MySQL/PostgreSQL with optimized schema
- **Image Processing**: Intervention Image with WebP conversion
- **Deployment**: cPanel/StackCP ready

## 📁 Quick Navigation

### For Developers
1. Start with [Tech Stack](TECH_STACK.md) to understand the architecture
2. Review [Database Schema](DATABASE_SCHEMA.md) for data relationships
3. Check [API Specification](API_SPECIFICATION.md) for endpoints
4. Follow [Deployment Guide](DEPLOYMENT_GUIDE.md) for setup

### For Content Managers
1. Read [Content Management](CONTENT_MANAGEMENT.md) for admin workflow
2. Check [Feature Specifications](FEATURE_SPECIFICATIONS.md) for functionality
3. Review [Authentication Guide](AUTHENTICATION_GUIDE.md) for user roles

### For Stakeholders
1. Start with [Project Overview](PROJECT_OVERVIEW.md) for business context
2. Review [Feature Specifications](FEATURE_SPECIFICATIONS.md) for capabilities
3. Check [Deployment Guide](DEPLOYMENT_GUIDE.md) for technical requirements

## 🚀 Getting Started

### Local Development
```bash
# Clone repository
git clone <repository-url>
cd rihla

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Database setup
php artisan migrate --seed
php artisan storage:link

# Build assets and start server
npm run build
php artisan serve
```

### Admin Access
- **URL**: `/admin`
- Admin users are **not seeded**. Create one with `php artisan admin:create <email> <password>` (see `AGENTS.md`).

## 🔗 External Resources

- **Laravel Documentation**: https://laravel.com/docs/13.x
- **TailwindCSS Documentation**: https://tailwindcss.com/docs
- **Alpine.js Documentation**: https://alpinejs.dev/
- **Intervention Image**: https://image.intervention.io/

## 📞 Support & Contact

- **Technical Support**: info@rihlatravels.mv
- **WhatsApp**: +960 797 2434
- **Project Repository**: [Internal Repository]

## 📄 License

This project is proprietary software developed for Rihla Travels.

---

**Last Updated**: September 2026  
**Version**: 1.1.0  
**Laravel Version**: 13.x
