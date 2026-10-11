import Image from 'next/image';

type Props = {
  doctorId: string;
  name: string;
  /**
   * Stored photo URL. Uploaded staff photos (`/api/uploads/…`) are served
   * through the public, allow-listed `/api/public/doctors/[id]/photo` route;
   * site-relative paths (e.g. `/doctors/…png`) are used as-is.
   */
  photoUrl: string | null;
  alt: string;
  size?: number;
  className?: string;
};

const UPLOAD_PREFIX = '/api/uploads/';

/**
 * Doctor avatar. Uploaded photos are served only through the public,
 * allow-listed route (published doctors only); a site-relative path is used
 * directly; otherwise a deterministic initials placeholder is shown (no layout
 * shift).
 */
export default function DoctorAvatar({
  doctorId,
  name,
  photoUrl,
  alt,
  size = 80,
  className = '',
}: Props) {
  const src = photoUrl
    ? photoUrl.startsWith(UPLOAD_PREFIX)
      ? `/api/public/doctors/${doctorId}/photo`
      : photoUrl
    : null;

  if (src) {
    return (
      <Image
        src={src}
        alt={alt}
        width={size}
        height={size}
        className={`rounded-full object-cover ${className}`}
      />
    );
  }

  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('');

  return (
    <span
      aria-hidden="true"
      className={`flex items-center justify-center rounded-full bg-orange-100 font-semibold text-orange-800 ${className}`}
      style={{ width: size, height: size, fontSize: Math.round(size / 3) }}
    >
      {initials || '?'}
    </span>
  );
}
