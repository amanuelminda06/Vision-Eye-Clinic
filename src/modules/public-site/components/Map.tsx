'use client';

import { useEffect, useRef } from 'react';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
declare const L: any;

type Props = {
  lat: number;
  lng: number;
  zoom?: number;
  height?: number | string;
  className?: string;
  title?: string;
};

export default function Map({
  lat,
  lng,
  zoom = 15,
  height = 200,
  className = '',
  title = 'Map',
}: Props) {
  const mapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!mapRef.current || typeof window === 'undefined' || typeof L === 'undefined') {
      return;
    }

    const map = L.map(mapRef.current, {
      center: [lat, lng],
      zoom,
      zoomControl: false,
      attributionControl: false,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors',
      maxZoom: 19,
    }).addTo(map);

    L.marker([lat, lng]).addTo(map);

    map.invalidateSize();

    return () => {
      map.remove();
    };
  }, [lat, lng, zoom]);

  return (
    <div className={className}>
      <div
        ref={mapRef}
        className="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50"
        style={{ height: typeof height === 'number' ? `${height}px` : height }}
        aria-label={title}
      />
    </div>
  );
}
