import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

/**
 * The public site's client-side behaviour.
 *
 * The map is the only piece that needs a library; everything else (the mobile
 * menu, the booking form, the feedback form) is a few lines of inline script
 * in the view that owns it.
 */

// Leaflet resolves its default marker images from the CSS, which breaks under
// a bundler — point it at the bundled assets instead.
const defaultIcon = L.icon({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    tooltipAnchor: [16, -28],
    shadowSize: [41, 41],
});

L.Marker.prototype.options.icon = defaultIcon;

/** True on a touch-first device (a phone or a tablet). */
const isTouch = window.matchMedia('(pointer: coarse)').matches;

/**
 * The OpenStreetMap map on the contact page.
 *
 * - One tile host only: https://tile.openstreetmap.org (no a/b/c subdomains).
 * - The scroll wheel does NOT zoom — the page keeps scrolling.
 * - On a touch device a ONE finger scrolls the page; the map is panned and
 *   zoomed with TWO fingers (Leaflet's touch-zoom handler pans around the
 *   midpoint of the two fingers).
 * - The attribution is on the tile layer, never in the page chrome.
 */
function initMap() {
    const element = document.getElementById('site-map');

    if (!element) {
        return;
    }

    const lat = Number.parseFloat(element.dataset.lat || '');
    const lng = Number.parseFloat(element.dataset.lng || '');
    const zoom = Number.parseInt(element.dataset.zoom || '15', 10);

    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return;
    }

    const map = L.map(element, {
        center: [lat, lng],
        zoom: Number.isFinite(zoom) ? zoom : 15,
        // The attribution control stays ON: it renders the tile layer's
        // "© OpenStreetMap contributors" string, which the tile provider
        // requires. (Disabling the control would hide the attribution
        // entirely — Leaflet only adds it to the control.)
        attributionControl: true,
        scrollWheelZoom: false,
        dragging: !isTouch,
        touchZoom: true,
        tap: false,
    });

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    L.marker([lat, lng]).addTo(map);

    // The container is hidden until the layout settles; Leaflet needs a size.
    window.setTimeout(() => map.invalidateSize(), 0);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMap);
} else {
    initMap();
}

/**
 * A missing image must never leave a broken box.
 *
 * An <img> that carries `data-fallback` is replaced when it fails to load:
 *
 *   data-fallback="initials"  data-fallback-text="A"  -> the person's initial
 *   data-fallback="placeholder"                        -> a neutral grey block
 *   data-fallback="hide"                              -> the element is removed
 */
function initImageFallbacks() {
    document.querySelectorAll('img[data-fallback]').forEach((image) => {
        image.addEventListener(
            'error',
            () => {
                const mode = image.dataset.fallback;

                if (mode === 'hide') {
                    image.remove();

                    return;
                }

                const replacement = document.createElement('span');

                replacement.className = mode === 'initials'
                    ? 'flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700'
                    : 'flex h-40 w-full items-center justify-center bg-slate-100 text-sm font-medium text-slate-500';

                if (mode === 'initials') {
                    replacement.setAttribute('aria-hidden', 'true');
                }

                replacement.textContent = image.dataset.fallbackText || '';
                image.replaceWith(replacement);
            },
            { once: true }
        );
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initImageFallbacks);
} else {
    initImageFallbacks();
}
