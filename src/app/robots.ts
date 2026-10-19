import type { MetadataRoute } from 'next';
import { siteBaseUrl } from '@/modules/public-site';

export default function robots(): MetadataRoute.Robots {
  const base = siteBaseUrl();
  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        disallow: ['/login', '/api/', '/settings'],
      },
    ],
    sitemap: `${base}/sitemap.xml`,
    host: base,
  };
}
