import { FaInstagram, FaTiktok, FaTelegramPlane } from 'react-icons/fa';

type Platform = 'instagram' | 'telegram' | 'tiktok';

type Props = {
  platform: Platform;
  className?: string;
};

const ICONS: Record<Platform, React.ComponentType<React.SVGProps<SVGSVGElement>>> = {
  instagram: FaInstagram,
  telegram: FaTelegramPlane,
  tiktok: FaTiktok,
};

export function SocialIcon({ platform, className }: Props) {
  const Icon = ICONS[platform];
  return <Icon className={className ?? 'h-5 w-5'} aria-hidden="true" />;
}
