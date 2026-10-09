import type { MetadataRoute } from 'next';
import { siteBaseUrl } from '@/modules/public-site';

const PATHS = ['/', '/about', '/services', '/doctors', '/book', '/contact', '/faq', '/privacy'];

export default function sitemap(): MetadataRoute.Sitemap {
  const base = siteBaseUrl();
  const lastModified = new Date();
  return PATHS.map((path) => ({
    url: `${base}${path === '/' ? '' : path}`,
    lastModified,
    changeFrequency: 'monthly',
    priority: path === '/' ? 1 : 0.7,
    alternates: {
      languages: {
        en: `${base}${path}?lang=en`,
        am: `${base}${path}?lang=am`,
      },
    },
  }));
}
