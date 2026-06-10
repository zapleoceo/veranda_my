/* Google Places autocomplete on the address field — with graceful
 * degradation: when no browser key is configured the input stays a
 * plain text field and the server geocodes the typed address instead
 * (Nominatim/Google server-side). The checkout never depends on this
 * module succeeding. */

let loadPromise = null;

function loadGoogleMaps(key, lang) {
    if (loadPromise) return loadPromise;
    loadPromise = new Promise((resolve, reject) => {
        const cb = '__ooMapsReady';
        window[cb] = () => resolve(window.google);
        const s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key)
              + '&libraries=places&language=' + encodeURIComponent(lang)
              + '&region=VN&callback=' + cb;
        s.async = true;
        s.onerror = () => reject(new Error('maps load failed'));
        document.head.appendChild(s);
    });
    return loadPromise;
}

/**
 * @param {HTMLInputElement} input
 * @param {(p:{lat:number,lng:number,address:string}) => void} onPlace
 * @returns {Promise<boolean>} true when autocomplete is active
 */
export async function attachAutocomplete(input, onPlace) {
    const cfg = window.__oo?.cfg || {};
    const key = cfg.google_maps_key || '';
    if (!key || !input) return false;

    try {
        const google = await loadGoogleMaps(key, window.__oo?.lang || 'en');
        const center = new google.maps.LatLng(cfg.restaurant.lat, cfg.restaurant.lng);
        const radiusM = (cfg.max_radius_km || 15) * 1000;

        const ac = new google.maps.places.Autocomplete(input, {
            fields: ['geometry', 'formatted_address', 'name'],
            bounds: new google.maps.Circle({ center, radius: radiusM }).getBounds(),
            strictBounds: false,
            componentRestrictions: { country: 'vn' },
        });
        ac.addListener('place_changed', () => {
            const place = ac.getPlace();
            const loc = place?.geometry?.location;
            if (!loc) return;
            onPlace({
                lat: loc.lat(),
                lng: loc.lng(),
                address: place.formatted_address || place.name || input.value,
            });
        });
        return true;
    } catch (e) {
        console.warn('[onlineorder] Places unavailable, falling back to plain input', e);
        return false;
    }
}
