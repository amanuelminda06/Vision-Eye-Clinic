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
  const googleEmbedUrl = `https://www.google.com/maps?q=${lat},${lng}&output=embed`;

  return (
    <div className={className}>
      <div className="overflow-hidden rounded-2xl border border-slate-200 aspect-video bg-slate-50">
        <iframe
          src={googleEmbedUrl}
          width="100%"
          height="100%"
          style={{ border: 0 }}
          allowFullScreen
          loading="lazy"
          title={title}
          referrerPolicy="no-referrer-when-downgrade"
        />
      </div>
    </div>
  );
}
