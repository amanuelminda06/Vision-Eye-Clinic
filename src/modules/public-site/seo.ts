import type { Metadata } from 'next';

/**
 * Public website SEO helpers. `PUBLIC_BASE_URL` is the canonical origin — it is
 * optional so local builds work, but MUST be set in production so that
 * canonical URLs, hreflang, the sitemap and JSON-LD are absolute. (The value is
 * validated at server startup by `src/env.ts`.)
 */

export function siteBaseUrl(): string {
  const configured = process.env.PUBLIC_BASE_URL?.trim();
  return (configured || 'http://localhost:3000').replace(/\/$/, '');
}

export type PageMetaInput = {
  title: string;
  description: string;
  /** Site-relative path starting with `/`. */
  path: string;
  /** Home uses the full title instead of the `%s · Clinic` template. */
  isHome?: boolean;
};

/**
 * Per-page metadata: title, description, canonical, hreflang (en/am) and Open
 * Graph/Twitter tags. The Open Graph image comes from the segment's
 * `opengraph-image` file convention.
 */
export function pageMetadata({ title, description, path, isHome }: PageMetaInput): Metadata {
  const url = `${siteBaseUrl()}${path === '/' ? '' : path}`;
  return {
    title: isHome ? { absolute: title } : title,
    description,
    alternates: {
      canonical: path,
      languages: {
        en: `${path}?lang=en`,
        am: `${path}?lang=am`,
        'x-default': path,
      },
    },
    openGraph: {
      type: 'website',
      siteName: 'Vision Eye Clinic',
      title,
      description,
      url,
      locale: 'en_ET',
      alternateLocale: ['am_ET'],
    },
    twitter: {
      card: 'summary_large_image',
      title,
      description,
    },
  };
}
