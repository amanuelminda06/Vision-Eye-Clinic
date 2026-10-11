import Image from 'next/image';

type Props = {
  doctorId: string;
  name: string;
  /** Whether the doctor has a stored photo (served via the public photo route). */
  hasPhoto: boolean;
  alt: string;
  size?: number;
  className?: string;
};

/**
 * Doctor avatar. Photos are served only through the public, allow-listed
 * `/api/public/doctors/[id]/photo` route (published doctors only); otherwise a
 * deterministic initials placeholder is shown (no layout shift).
 */
export default function DoctorAvatar({
  doctorId,
  name,
  hasPhoto,
  alt,
  size = 80,
  className = '',
}: Props) {
  if (hasPhoto) {
    return (
      <Image
        src={`/api/public/doctors/${doctorId}/photo`}
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
