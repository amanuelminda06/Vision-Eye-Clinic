'use client';

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
  const osmUrl = `https://www.openstreetmap.org/export/embed.html?bbox=${lng - 0.01},${lat - 0.01},${lng + 0.01},${lat + 0.01}&layer=mapnik&marker=${lat},${lng}`;

  return (
    <div className={className}>
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50">
        <iframe
          src={osmUrl}
          width="100%"
          height={height}
          style={{ border: 0, display: 'block' }}
          allowFullScreen
          loading="eager"
          title={title}
        />
      </div>
    </div>
  );
}
