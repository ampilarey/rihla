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

    // One place (a listing: data-lat, data-lng, data-label) or several (a
    // host's page — §16.8: data-points, a JSON list of {lat, lng, name}).
    let points = [];

    try {
        points = element.dataset.points
            ? JSON.parse(element.dataset.points)
            : [{ lat: element.dataset.lat, lng: element.dataset.lng, name: element.dataset.label || '' }];
    } catch {
        return;
    }

    points = points
        .map((point) => ({ lat: Number(point.lat), lng: Number(point.lng), name: String(point.name || '') }))
        .filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng));

    if (points.length === 0) {
        return;
    }

    const draw = async () => {
        const [{ default: L }] = await Promise.all([
            import('leaflet'),
            import('leaflet/dist/leaflet.css'),
        ]);

        element.textContent = '';

        const map = L.map(element, { scrollWheelZoom: false }).setView([points[0].lat, points[0].lng], 14);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        points.forEach((point) => {
            // An element, never a string: Leaflet writes a string tooltip
            // through innerHTML, and the name is whatever the host typed.
            const label = document.createElement('span');
            label.textContent = point.name;

            L.circleMarker([point.lat, point.lng], {
                radius: 10,
                color: '#2E2245',
                weight: 2,
                fillColor: '#5F498A',
                fillOpacity: 0.9,
            }).addTo(map).bindTooltip(label);
        });

        if (points.length > 1) {
            map.fitBounds(points.map((point) => [point.lat, point.lng]), { padding: [30, 30], maxZoom: 15 });
        }
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
