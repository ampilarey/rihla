const CACHE_NAME = 'rihla-guide-v1';
const urlsToCache = [
    '/guide',
    '/css/app.css',
    '/js/app.js',
    '/images/guide/',
    '/offline.html'
];

// Install event - cache resources
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Opened cache');
                return cache.addAll(urlsToCache);
            })
            .catch(error => {
                console.log('Cache failed:', error);
            })
    );
});

// Fetch event - serve from cache when offline
self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // Return cached version or fetch from network
                if (response) {
                    return response;
                }
                
                return fetch(event.request)
                    .then(response => {
                        // Check if we received a valid response
                        if (!response || response.status !== 200 || response.type !== 'basic') {
                            return response;
                        }
                        
                        // Clone the response
                        const responseToCache = response.clone();
                        
                        // Cache the response for future use
                        caches.open(CACHE_NAME)
                            .then(cache => {
                                // Only cache guide-related requests
                                if (event.request.url.includes('/guide') || 
                                    event.request.url.includes('/css/') || 
                                    event.request.url.includes('/js/') ||
                                    event.request.url.includes('/images/guide/')) {
                                    cache.put(event.request, responseToCache);
                                }
                            });
                        
                        return response;
                    })
                    .catch(() => {
                        // If offline and requesting guide page, show offline page
                        if (event.request.url.includes('/guide')) {
                            return caches.match('/offline.html');
                        }
                    });
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});
