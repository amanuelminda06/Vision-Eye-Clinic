'use client';

import dynamic from 'next/dynamic';

/**
 * Leaflet reads `window` while being imported, so rendering it during SSR throws
 * `ReferenceError: window is not defined`. Load it client-side only.
 */
const Map = dynamic(() => import('./Map'), { ssr: false });

export default Map;
