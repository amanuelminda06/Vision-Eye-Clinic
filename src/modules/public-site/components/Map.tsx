'use client';

type Props = {
  lat: number;
  lng: number;
  zoom?: number;
  height?: number | string;
  className?: string;
  title?: string;
  openInMapsLabel?: string;
};

export default function Map({
  lat,
  lng,
  zoom = 15,
  height = 280,
  className = '',
  title = 'Map',
  openInMapsLabel = 'Open in Maps',
}: Props) {
  const osmUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=${zoom}/${lat}/${lng}`;
  const googleUrl = `https://maps.google.com/?q=${lat},${lng}`;

  return (
    <div className={className}>
      <div className="overflow-hidden rounded-2xl border border-slate-200">
        <iframe
          src={`https://www.openstreetmap.org/export/embed.html?bbox=${lng - 0.01},${lat - 0.01},${lng + 0.01},${lat + 0.01}&layer=mapnik&marker=${lat},${lng}`}
          width="100%"
          height={height}
          style={{ border: 0 }}
          allowFullScreen
          loading="lazy"
          title={title}
        />
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        <a
          href={osmUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
        >
          {openInMapsLabel}
        </a>
        <a
          href={googleUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"
        >
          Google Maps
        </a>
      </div>
    </div>
  );
}
