import Image from 'next/image';

type SiteLogoProps = {
  /** Rendered height in px; width follows the intrinsic ~1.92:1 ratio. */
  height?: number;
  priority?: boolean;
  className?: string;
};

/** Official Vision Eye Clinic logo (light-background lockup). Background removed so it works on any surface. */
export default function SiteLogo({
  height = 36,
  priority = false,
  className = '',
}: SiteLogoProps) {
  return (
    <Image
      src="/vision-logo.png"
      alt="Vision Eye Clinic"
      width={Math.round(height * 1.924)}
      height={height}
      priority={priority}
      className={className}
    />
  );
}

type SiteLogoDarkProps = {
  /** Rendered height in px; width follows the intrinsic ~1.38:1 ratio. */
  height?: number;
  className?: string;
};

/** Official Vision Eye Clinic logo, cream on transparent (for dark surfaces). */
export function SiteLogoDark({ height = 40, className = '' }: SiteLogoDarkProps) {
  return (
    <Image
      src="/vision-logo-dark.png"
      alt="Vision Eye Clinic"
      width={Math.round(height * 1.376)}
      height={height}
      className={className}
    />
  );
}