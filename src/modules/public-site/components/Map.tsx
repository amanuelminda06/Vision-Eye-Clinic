'use client';

import { useEffect, useRef } from 'react';
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

const defaultIcon = L.icon({
  iconUrl: (markerIcon as unknown as string),
  iconRetinaUrl: (markerIcon2x as unknown as string),
  shadowUrl: (markerShadow as unknown as string),
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  tooltipAnchor: [16, -28],
  shadowSize: [41, 41],
});

L.Marker.prototype.options.icon = defaultIcon;

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
    if (!mapRef.current || typeof L === 'undefined') {
      return;
    }

    const map = L.map(mapRef.current, {
      center: [lat, lng],
      zoom,
      zoomControl: false,
      attributionControl: false,
      scrollWheelZoom: false,
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      dragging: !L.Browser.touch ? true : ('2fingers' as any),
    });

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
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
