/**
 * The listing page's map — §16.7 (Phase 13.2).
 *
 * Leaflet is loaded only when the map scrolls into view, as its own chunk,
 * so the listing page does not pay ~150 KB of JavaScript and a stylesheet
 * for something most visitors never scroll to. On every other page this
 * returns before doing anything.
 *
 * A circle marker rather than Leaflet's default pin: the pin is two PNGs
 * resolved from a path Vite rewrites, which breaks silently — a map with
 * no marker, and nothing in the console that says why.
 */
export function startStayMap() {
    const element = document.getElementById('stay-map');

    if (!element) {
        return;
    }

    const lat = Number(element.dataset.lat);
    const lng = Number(element.dataset.lng);

    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return;
    }

    const draw = async () => {
        const [{ default: L }] = await Promise.all([
            import('leaflet'),
            import('leaflet/dist/leaflet.css'),
        ]);

        element.textContent = '';

        const map = L.map(element, { scrollWheelZoom: false }).setView([lat, lng], 14);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        L.circleMarker([lat, lng], {
            radius: 10,
            color: '#2E2245',
            weight: 2,
            fillColor: '#5F498A',
            fillOpacity: 0.9,
        }).addTo(map).bindTooltip(element.dataset.label || '');
    };

    if (!('IntersectionObserver' in window)) {
        draw();

        return;
    }

    const observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            observer.disconnect();
            draw();
        }
    }, { rootMargin: '200px' });

    observer.observe(element);
}
