@props(['banners'])

@if($banners && $banners->count() > 0)
<div class="relative" role="region" aria-label="Hero banner carousel">
    <!-- Hero Banner Carousel -->
    <div class="relative overflow-hidden hero-banner-container" tabindex="0">
        @foreach($banners as $index => $banner)
        <div class="hero-slide {{ $index === 0 ? 'active' : '' }}" 
             data-index="{{ $index }}"
             style="display: {{ $index === 0 ? 'block' : 'none' }};"
             role="group"
             aria-label="Slide {{ $index + 1 }} of {{ $banners->count() }}">
            
            <!-- Responsive Aspect Ratio Stage -->
            <div class="hero-stage">
                @if($banner->image_path)
                <!-- Image Container with Background -->
                <div class="hero-image-container">
                    @php
                        $isCover = isset($banner->fit_mode) && $banner->fit_mode === 'cover';
                        $fx = isset($banner->focal_x) ? max(0, min(100, (int) $banner->focal_x)) : null;
                        $fy = isset($banner->focal_y) ? max(0, min(100, (int) $banner->focal_y)) : null;
                    @endphp
                    @unless($isCover)
                    <!-- Blurred Background for Contain Mode only -->
                    <div class="hero-bg" aria-hidden="true">
                        <img src="{{ $banner->image_url ?? asset($banner->image_path) }}" 
                             alt=""
                             class="hero-bg-image"
         loading="lazy"
         decoding="async">
                    </div>
                    @endunless
                    
                    <!-- Main Image -->
                    <img src="{{ $banner->image_url ?? asset($banner->image_path) }}" 
                         alt="{{ $banner->title ?? 'Banner image' }}"
                         class="hero-banner-image {{ $isCover ? 'hero-img--cover' : 'hero-img--contain' }}"
                         loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                         fetchpriority="{{ $index === 0 ? 'high' : 'auto' }}"
                         decoding="{{ $index === 0 ? 'sync' : 'async' }}"
                         draggable="false"
                         style="{{ $isCover && $fx !== null && $fy !== null ? 'object-position:' . $fx . '% ' . $fy . '%' : '' }}">
                </div>
                @else
                <!-- Gradient Fallback -->
                <div class="hero-gradient-bg"></div>
                @endif
                
                <!-- Dark Overlay for Text Readability -->
                <div class="hero-overlay" style="opacity: {{ $banner->overlay_opacity / 100 }};"></div>
            </div>
            
            <!-- Content Section -->
            <div class="hero-content">
                <div class="hero-content-inner">
                    <h1 dir="auto" class="hero-title {{ $banner->heading_size ?? 'text-2xl' }} {{ $banner->heading_weight ?? 'font-bold' }}"
                        style="color: {{ $banner->heading_color ?? \App\Support\Brand::WHITE }} !important;">
                        {{ $banner->title }}
                    </h1>
                    
                    @if($banner->subtitle)
                    <p dir="auto" class="hero-subtitle {{ $banner->subheading_size ?? 'text-lg' }} {{ $banner->subheading_weight ?? 'font-normal' }}"
                       style="color: {{ $banner->subheading_color ?? \App\Support\Brand::CREAM }} !important;">
                        {{ $banner->subtitle }}
                    </p>
                    @endif
                    
                    <!-- Call to Action Buttons -->
                    <div class="hero-cta-buttons">
                        @if($banner->primary_cta_text && $banner->primary_cta_url)
                        <a href="{{ $banner->primary_cta_url }}" 
                           class="hero-cta-primary {{ $banner->primary_cta_size ?? 'text-base' }} {{ $banner->primary_cta_radius ?? 'rounded-lg' }}"
                           style="background-color: {{ $banner->primary_cta_bg_color ?? \App\Support\Brand::WINE }} !important; color: {{ $banner->primary_cta_text_color ?? \App\Support\Brand::WHITE }} !important;">
                            {{ $banner->primary_cta_text }}
                        </a>
                        @endif
                        
                        @if($banner->secondary_cta_text && $banner->secondary_cta_url)
                        <a href="{{ $banner->secondary_cta_url }}" 
                           class="hero-cta-secondary {{ $banner->secondary_cta_size ?? 'text-base' }} {{ $banner->secondary_cta_radius ?? 'rounded-lg' }}"
                           style="background-color: {{ $banner->secondary_cta_bg_color ?? 'rgba(255,255,255,0.2)' }} !important; color: {{ $banner->secondary_cta_text_color ?? \App\Support\Brand::WHITE }} !important;">
                            {{ $banner->secondary_cta_text }}
                        </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endforeach
        
        <!-- Navigation Controls (Only show if multiple slides) -->
        @if($banners->count() > 1)
        <!-- Previous Button -->
        <button class="hero-nav-arrow hero-nav-left" 
                type="button"
                data-click="changeSlide" data-args="[-1]"
                aria-label="Go to previous slide">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
        </button>
        
        <!-- Next Button -->
        <button class="hero-nav-arrow hero-nav-right" 
                type="button"
                data-click="changeSlide" data-args="[1]"
                aria-label="Go to next slide">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </button>
        
        <!-- Dots Indicator -->
        <div class="hero-nav-dots" role="navigation" aria-label="Slide navigation">
            @foreach($banners as $index => $banner)
            <button class="hero-dot {{ $index === 0 ? 'active' : '' }}"
                    type="button"
                    data-click="goToSlide" data-args="{{ json_encode([$index]) }}"
                    aria-label="Go to slide {{ $index + 1 }}"
                    aria-current="{{ $index === 0 ? 'true' : 'false' }}"></button>
            @endforeach
        </div>
        @endif
    </div>
</div>

<!-- Screen Reader Announcements -->
<div class="sr-only" aria-live="polite" id="hero-announcer"></div>

<style>
/* ===== HERO BANNER STYLES ===== */
/* Mobile-first approach with responsive aspect ratios */

/* Container Structure */
.hero-banner-container {
    position: relative;
    overflow: hidden;
}

.hero-slide {
    position: relative;
    width: 100%;
}

/* Responsive Aspect Ratio Stage */
.hero-stage {
    position: relative;
    width: 100%;
    /* Mobile: 16:9 aspect ratio, Desktop: 16/9 */
    aspect-ratio: 16/9;
    background-color: #000; /* hide any tiny gaps while loading */
}

/* Image Container */
.hero-image-container {
    position: relative;
    width: 100%;
    height: 100%;
    overflow: hidden;
}

/* Blurred Background for Contain Mode */
.hero-bg {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    overflow: hidden;
    z-index: 1;
}

.hero-bg-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: blur(20px) brightness(0.3);
    transform: scale(1.1);
    opacity: 0.4;
}

/* Main Image Styling */
.hero-banner-image {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 2;
}

/* Contain Mode: Show full image with letterbox/pillarbox */
.hero-img--contain {
    object-fit: contain;
    object-position: center;
}

/* Cover Mode: Fill stage with cropping */
.hero-img--cover {
    object-fit: cover;
    object-position: center;
}

/* Mobile: force the main image to fill the stage */
@media (max-width: 639px) {
    /* --- MOBILE ≤639px: show the entire 1920x1080 image with no crop --- */
    .hero-stage {
        aspect-ratio: 16/9 !important;     /* container is exactly 16:9 */
        height: auto !important;           /* let aspect-ratio drive height */
        background-color: #000 !important; /* hide tiny gaps during load */
    }

    /* Always show full image on mobile */
    .hero-banner-image {
        object-fit: contain !important;
        object-position: center !important;
    }

    /* No need for blurred BG when using contain and fixed 16:9 stage */
    .hero-bg { 
        display: none !important; 
    }

    /* Keep content overlay so it doesn't add height */
    .hero-content {
        position: absolute !important;   /* was: static */
        bottom: 19px !important;        /* Move up by 5mm (19px) from bottom */
        left: 0;
        right: 0;
        padding: 0.75rem !important;
    }
}

/* Gradient Fallback */
.hero-gradient-bg {
    width: 100%;
    height: 100%;
    background: linear-gradient(to bottom right, #731F43, #8E2653);
}

/* Dark Overlay for Text Readability */
.hero-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(to bottom, transparent 0%, rgba(0,0,0,0.3) 40%, rgba(0,0,0,0.7) 100%);
    z-index: 3;
    pointer-events: none;
}

/* Content Section */
.hero-content {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 4;
    padding: 1rem 0.5rem;
    width: 100%;
    box-sizing: border-box;
}

.hero-content-inner {
    text-align: center;
    color: white;
    max-width: 100%;
    margin: 0 auto;
    padding: 0 0.5rem;
}

/* Typography */
.hero-title {
    margin-bottom: 0.75rem !important;  /* Reverted to original mobile spacing */
    line-height: 1.2 !important;
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
}

.hero-subtitle {
    margin-bottom: 1rem !important;     /* Reverted to original mobile spacing */
    opacity: 0.9 !important;
    line-height: 1.4 !important;
    word-wrap: break-word !important;
    overflow-wrap: break-word !important;
}

/* Call to Action Buttons */
.hero-cta-buttons {
    display: flex !important;
    flex-direction: column !important;
    gap: 0.5rem !important;             /* Reverted to original mobile spacing */
    align-items: center !important;
    margin-bottom: 1rem !important;
}

.hero-cta-primary,
.hero-cta-secondary {
    padding: 0.5rem 1rem !important;
    font-weight: 500 !important;
    text-decoration: none !important;
    transition: all 0.3s !important;
}

.hero-cta-primary:hover {
    opacity: 0.9 !important;
    transform: translateY(-1px) !important;
}

.hero-cta-secondary:hover {
    opacity: 0.9 !important;
    transform: translateY(-1px) !important;
}

/* Navigation Arrows */
.hero-nav-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255, 255, 255, 0.2);
    color: white;
    padding: 0.5rem;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    z-index: 20;
    min-width: 44px;
    min-height: 44px;
}

.hero-nav-arrow:hover {
    background: rgba(255, 255, 255, 0.3);
}

.hero-nav-arrow:focus {
    outline: 2px solid white;
    outline-offset: 2px;
}

.hero-nav-left {
    left: 1rem;
}

.hero-nav-right {
    right: 1rem;
}

/* Dots Navigation */
.hero-nav-dots {
    position: absolute;
    bottom: 1rem;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 0.5rem;
    z-index: 20;
    align-items: center;
    justify-content: center;
    flex-wrap: nowrap;
}

.hero-dot {
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    background: rgba(255, 255, 255, 0.5);
    flex-shrink: 0;
    min-width: 0.75rem;
    min-height: 0.75rem;
    padding: 0;
    margin: 0;
}

.hero-dot.active {
    background: white;
}

.hero-dot:hover {
    background: rgba(255, 255, 255, 0.8);
}

.hero-dot:focus {
    outline: 2px solid white;
    outline-offset: 2px;
}

/* ===== DESKTOP STYLES (≥640px) ===== */
@media (min-width: 640px) {
    .hero-stage {
        aspect-ratio: 16/9;
    }
    
    .hero-content {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: 0;
        background: none;
        max-height: none;
        overflow: visible;
        padding: 2rem 1rem;
    }
    
    .hero-content-inner {
        padding: 0;
    }
    
    .hero-title {
        font-size: clamp(2.5rem, 5vw, 4rem);
        margin-bottom: 3rem !important;  /* Increased from 1.5rem to 3rem for much more space */
    }
    
    .hero-subtitle {
        font-size: clamp(1.125rem, 2.5vw, 1.5rem);
        margin-bottom: 3.5rem !important;    /* Increased from 2rem to 3.5rem for much more space */
    }
    
    .hero-cta-buttons {
        flex-direction: row;
        gap: 2rem !important;              /* Increased from 1rem to 2rem for much more space */
        margin-bottom: 2rem;
    }
    
    .hero-cta-primary,
    .hero-cta-secondary {
        padding: 1rem 2rem;
        font-size: 1.125rem;
    }
}

/* ===== ACCESSIBILITY & REDUCED MOTION ===== */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

@media (prefers-reduced-motion: reduce) {
    .hero-slide,
    .hero-dot,
    .hero-nav-arrow {
        transition: none;
    }
}
</style>

<script nonce="@cspNonce">
// ===== HERO BANNER CAROUSEL JAVASCRIPT =====
// Production-ready with proper error handling and accessibility

document.addEventListener('DOMContentLoaded', function() {
    // Cache DOM elements once for performance
    const container = document.querySelector('.hero-banner-container');
    const slides = document.querySelectorAll('.hero-slide');
    const dots = document.querySelectorAll('.hero-dot');
    const totalSlides = slides.length;
    
    // Early return if no slides
    if (totalSlides === 0) return;
    
    let currentSlide = 0;
    let autoAdvanceInterval = null;
    let restartTimeout = null;
    
    // ===== CORE FUNCTIONS =====
    
    /**
     * Show a specific slide and update all related states
     * @param {number} index - Slide index to show
     */
    function showSlide(index) {
        // Validate index
        if (index < 0 || index >= totalSlides) return;
        
        // Hide all slides
        slides.forEach(slide => {
            slide.style.display = 'none';
            slide.classList.remove('active');
        });
        
        // Show current slide
        if (slides[index]) {
            slides[index].style.display = 'block';
            slides[index].classList.add('active');
        }
        
        // Update dots state (only if dots exist)
        if (dots.length > 0) {
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
                dot.setAttribute('aria-current', i === index ? 'true' : 'false');
            });
        }
        
        // Update current slide index
        currentSlide = index;
        
        // Announce slide change for screen readers
        const slide = slides[index];
        if (slide) {
            const title = slide.querySelector('.hero-title')?.textContent || '';
            const slideNumber = index + 1;
            slide.setAttribute('aria-label', `Slide ${slideNumber} of ${totalSlides}: ${title}`);
            
            // Update live region for screen readers
            const announcer = document.getElementById('hero-announcer');
            if (announcer) {
                announcer.textContent = `Slide ${slideNumber} of ${totalSlides}: ${title}`;
            }
        }
    }
    
    /**
     * Change slide by direction (-1 for previous, 1 for next)
     * @param {number} direction - Direction to move (-1 or 1)
     */
    function changeSlide(direction) {
        const newIndex = (currentSlide + direction + totalSlides) % totalSlides;
        showSlide(newIndex);
    }
    
    /**
     * Go to a specific slide by index
     * @param {number} index - Target slide index
     */
    function goToSlide(index) {
        showSlide(index);
    }
    
    // ===== KEYBOARD NAVIGATION =====
    
    /**
     * Handle keyboard navigation
     * @param {KeyboardEvent} event - Keyboard event
     */
    function handleKeydown(event) {
        if (!container.contains(event.target)) return;
        
        switch (event.key) {
            case 'ArrowLeft':
                event.preventDefault();
                changeSlide(-1);
                break;
            case 'ArrowRight':
                event.preventDefault();
                changeSlide(1);
                break;
            case 'Home':
                event.preventDefault();
                goToSlide(0);
                break;
            case 'End':
                event.preventDefault();
                goToSlide(totalSlides - 1);
                break;
        }
    }
    
    // ===== AUTO-ADVANCE =====
    
    /**
     * Start auto-advance functionality
     */
    function startAutoAdvance() {
        if (totalSlides <= 1) return;
        if (autoAdvanceInterval) return; // prevent stacking
        
        // Respect reduced motion preference
        const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) return;
        
        autoAdvanceInterval = setInterval(() => {
            changeSlide(1);
        }, 5000);
    }
    
    /**
     * Stop auto-advance functionality
     */
    function stopAutoAdvance() {
        if (autoAdvanceInterval) {
            clearInterval(autoAdvanceInterval);
            autoAdvanceInterval = null;
        }
    }
    
    /**
     * Pause auto-advance on user interaction
     */
    function pauseAutoAdvance() {
        stopAutoAdvance();
        if (restartTimeout) {
            clearTimeout(restartTimeout);
            restartTimeout = null;
        }
        restartTimeout = setTimeout(() => {
            restartTimeout = null;
            startAutoAdvance();
        }, 10000);
    }
    
    // ===== EVENT LISTENERS =====
    
    // Keyboard navigation
    document.addEventListener('keydown', handleKeydown);
    
    // Pause auto-advance on user interaction
    container.addEventListener('mouseenter', pauseAutoAdvance);
    container.addEventListener('focusin', pauseAutoAdvance);
    
    // Resume auto-advance when leaving container (only if focus actually left)
    container.addEventListener('mouseleave', startAutoAdvance);
    container.addEventListener('focusout', (e) => {
        const next = e.relatedTarget;
        // Only resume if focus actually left the container
        if (!next || !container.contains(next)) startAutoAdvance();
    });
    
    // ===== INITIALIZATION =====
    
    // Show first slide initially
    showSlide(0);
    
    // Start auto-advance
    startAutoAdvance();
    
    // ===== GLOBAL FUNCTIONS (for inline onclick compatibility) =====
    
    // Expose functions globally for inline onclick handlers
    window.changeSlide = changeSlide;
    window.goToSlide = goToSlide;
    window.showSlide = showSlide;
    
    // ===== CLEANUP (same scope, correct targets) =====
    
    window.addEventListener('beforeunload', function() {
        document.removeEventListener('keydown', handleKeydown);
        container.removeEventListener('mouseenter', pauseAutoAdvance);
        container.removeEventListener('focusin', pauseAutoAdvance);
        container.removeEventListener('mouseleave', startAutoAdvance);
        container.removeEventListener('focusout', startAutoAdvance);
        stopAutoAdvance();
        if (restartTimeout) clearTimeout(restartTimeout);
    });
});
</script>
@else
<!-- Fallback Hero Section -->
<section class="relative bg-gradient-to-br from-wine-600 to-wine-500 text-white" style="height: 600px;">
    <div class="container mx-auto px-4 h-full flex items-center justify-center">
        <div class="max-w-4xl mx-auto text-center">
            <h1 class="text-3xl md:text-5xl lg:text-6xl font-bold mb-4 md:mb-6">
                {{ __('messages.hero_title') }}
            </h1>
            <p class="text-lg md:text-xl lg:text-2xl mb-6 md:mb-8 text-gray-100 max-w-3xl mx-auto">
                {{ __('messages.hero_sub') }}
            </p>
            <div class="flex flex-col sm:flex-row gap-3 md:gap-4 justify-center">
                {{-- Gold with ink text: the one fill the palette permits gold to be.
                     A wine button on the wine hero was the primary action drawn in
                     the colour of its own background, so the secondary outranked it. --}}
                <a href="{{ route('trips.index') }}" class="btn-gold text-base md:text-lg px-6 md:px-8 py-3 md:py-4">
                    {{ __('messages.cta_trips') }}
                </a>
                <a href="{{ \App\Support\Contact::whatsappUrl() }}" 
                   target="_blank" 
                   rel="noopener"
                   class="btn-secondary text-base md:text-lg px-6 md:px-8 py-3 md:py-4">
                    {{ __('messages.cta_whatsapp') }}
                </a>
            </div>
        </div>
    </div>
</section>
@endif
