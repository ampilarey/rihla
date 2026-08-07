// Video Player Functions - Enhanced with Debugging
console.log('Video player script loading...');

// Make sure functions are globally available
window.loadVideo = function(mediaId, videoUrl) {
    console.log('=== LOAD VIDEO CALLED ===');
    console.log('Media ID:', mediaId);
    console.log('Video URL:', videoUrl);
    console.log('Function called from:', new Error().stack);
    
    const thumbnail = document.getElementById(`video-thumbnail-${mediaId}`);
    const player = document.getElementById(`video-player-${mediaId}`);
    const iframe = document.getElementById(`video-iframe-${mediaId}`);
    
    console.log('Elements found:', {
        thumbnail: !!thumbnail,
        player: !!player,
        iframe: !!iframe
    });
    
    if (thumbnail && player && iframe) {
        // Convert video URL to embed format
        const embedUrl = convertToEmbedUrl(videoUrl);
        console.log('Embed URL generated:', embedUrl);
        
        if (embedUrl) {
            // Set iframe source
            iframe.src = embedUrl;
            console.log('Iframe source set to:', embedUrl);
            
            // Hide thumbnail and show player
            thumbnail.classList.add('hidden');
            player.classList.remove('hidden');
            console.log('Thumbnail hidden, player shown');
            
            // Add close button to player
            addCloseButton(mediaId);
        } else {
            // If we can't embed, open in new tab
            console.log('Cannot embed video, opening in new tab');
            window.open(videoUrl, '_blank');
        }
    } else {
        console.error('Video elements not found:', { 
            thumbnail: thumbnail, 
            player: player, 
            iframe: iframe 
        });
        
        // Try to find elements by different selectors
        console.log('Trying alternative selectors...');
        const altThumbnail = document.querySelector(`[id*="video-thumbnail-${mediaId}"]`);
        const altPlayer = document.querySelector(`[id*="video-player-${mediaId}"]`);
        const altIframe = document.querySelector(`[id*="video-iframe-${mediaId}"]`);
        
        console.log('Alternative elements found:', {
            thumbnail: altThumbnail,
            player: altPlayer,
            iframe: altIframe
        });
    }
};

function convertToEmbedUrl(url) {
    try {
        console.log('Converting URL to embed format:', url);
        const urlObj = new URL(url);
        console.log('URL parsed successfully:', urlObj.hostname);
        
        // YouTube
        if (urlObj.hostname.includes('youtube.com') || urlObj.hostname.includes('youtu.be')) {
            let videoId = '';
            
            if (urlObj.hostname.includes('youtu.be')) {
                videoId = urlObj.pathname.substring(1);
            } else if (urlObj.searchParams.has('v')) {
                videoId = urlObj.searchParams.get('v');
            } else if (urlObj.pathname.includes('/watch/')) {
                videoId = urlObj.pathname.split('/watch/')[1];
            }
            
            console.log('YouTube video ID extracted:', videoId);
            
            if (videoId) {
                const embedUrl = `https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0&modestbranding=1`;
                console.log('YouTube embed URL generated:', embedUrl);
                return embedUrl;
            }
        }
        
        // Vimeo
        if (urlObj.hostname.includes('vimeo.com')) {
            const videoId = urlObj.pathname.substring(1);
            if (videoId) {
                const embedUrl = `https://player.vimeo.com/video/${videoId}?autoplay=1&title=0&byline=0&portrait=0`;
                console.log('Vimeo embed URL generated:', embedUrl);
                return embedUrl;
            }
        }
        
        // Facebook
        if (urlObj.hostname.includes('facebook.com')) {
            const videoId = urlObj.pathname.split('/videos/')[1];
            if (videoId) {
                const embedUrl = `https://www.facebook.com/plugins/video.php?href=${encodeURIComponent(url)}&show_text=0&width=560&height=315&appId`;
                console.log('Facebook embed URL generated:', embedUrl);
                return embedUrl;
            }
        }
        
        // Instagram
        if (urlObj.hostname.includes('instagram.com')) {
            const postId = urlObj.pathname.split('/p/')[1]?.split('/')[0];
            if (postId) {
                const embedUrl = `https://www.instagram.com/p/${postId}/embed/`;
                console.log('Instagram embed URL generated:', embedUrl);
                return embedUrl;
            }
        }
        
        // TikTok
        if (urlObj.hostname.includes('tiktok.com')) {
            const videoId = urlObj.pathname.split('/video/')[1];
            if (videoId) {
                const embedUrl = `https://www.tiktok.com/embed/${videoId}`;
                console.log('TikTok embed URL generated:', embedUrl);
                return embedUrl;
            }
        }
        
        console.log('No embed URL found for:', url);
        return null;
    } catch (e) {
        console.error('Error converting URL:', e);
        return null;
    }
}

function addCloseButton(mediaId) {
    console.log('Adding close button for media ID:', mediaId);
    const player = document.getElementById(`video-player-${mediaId}`);
    
    // Check if close button already exists
    if (player.querySelector('.video-close-btn')) {
        console.log('Close button already exists');
        return;
    }
    
    // Create close button
    const closeBtn = document.createElement('button');
    closeBtn.className = 'video-close-btn absolute top-2 right-2 bg-black bg-opacity-70 text-white rounded-full p-2 hover:bg-opacity-90 transition-all z-10';
    closeBtn.innerHTML = `
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
        </svg>
    `;
    
    closeBtn.onclick = function() {
        console.log('Close button clicked for media ID:', mediaId);
        closeVideo(mediaId);
    };
    
    player.appendChild(closeBtn);
    console.log('Close button added successfully');
}

function closeVideo(mediaId) {
    console.log('Closing video for media ID:', mediaId);
    const thumbnail = document.getElementById(`video-thumbnail-${mediaId}`);
    const player = document.getElementById(`video-player-${mediaId}`);
    const iframe = document.getElementById(`video-iframe-${mediaId}`);
    
    if (thumbnail && player && iframe) {
        // Clear iframe source
        iframe.src = '';
        console.log('Iframe source cleared');
        
        // Show thumbnail and hide player
        thumbnail.classList.remove('hidden');
        player.classList.add('hidden');
        console.log('Thumbnail shown, player hidden');
        
        // Remove close button
        const closeBtn = player.querySelector('.video-close-btn');
        if (closeBtn) {
            closeBtn.remove();
            console.log('Close button removed');
        }
    }
}

// Debug function to check if video player is loaded
window.checkVideoPlayer = function() {
    console.log('=== VIDEO PLAYER STATUS CHECK ===');
    console.log('Global functions available:', {
        loadVideo: typeof window.loadVideo,
        convertToEmbedUrl: typeof convertToEmbedUrl,
        addCloseButton: typeof addCloseButton,
        closeVideo: typeof closeVideo
    });
    
    // Check if we can find any video elements on the page
    const videoContainers = document.querySelectorAll('[id*="video-container"]');
    const videoThumbnails = document.querySelectorAll('[id*="video-thumbnail"]');
    const videoPlayers = document.querySelectorAll('[id*="video-player"]');
    
    console.log('Video elements found on page:', {
        containers: videoContainers.length,
        thumbnails: videoThumbnails.length,
        players: videoPlayers.length
    });
    
    // Log details of each video element
    videoContainers.forEach((container, index) => {
        console.log(`Video container ${index}:`, container.id);
    });
    
    return {
        functionsLoaded: typeof window.loadVideo === 'function',
        elementsFound: videoContainers.length > 0
    };
};

// Test function to manually test video loading
window.testVideoPlayer = function(mediaId = 2, videoUrl = 'https://www.youtube.com/watch?v=YcFRPn5Xca8') {
    console.log('=== TESTING VIDEO PLAYER ===');
    console.log('Testing with:', { mediaId, videoUrl });
    
    const result = window.checkVideoPlayer();
    console.log('Player status:', result);
    
    if (result.functionsLoaded) {
        console.log('Calling loadVideo function...');
        window.loadVideo(mediaId, videoUrl);
    } else {
        console.error('Video player functions not loaded!');
    }
};

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('=== DOM LOADED - INITIALIZING VIDEO PLAYER ===');
    
    // Wait a bit for other scripts to load
    setTimeout(() => {
        console.log('Checking video player status...');
        const status = window.checkVideoPlayer();
        
        if (status.functionsLoaded) {
            console.log('✅ Video player initialized successfully!');
            console.log('You can test it by running: testVideoPlayer() in the console');
        } else {
            console.error('❌ Video player failed to initialize!');
        }
    }, 1000);
});

// Also initialize immediately if DOM is already loaded
if (document.readyState === 'loading') {
    console.log('DOM still loading, waiting...');
} else {
    console.log('DOM already loaded, initializing immediately...');
    const status = window.checkVideoPlayer();
    console.log('Immediate status check:', status);
}

console.log('Video player script loaded completely');
