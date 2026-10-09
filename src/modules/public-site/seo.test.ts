import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { pageMetadata, siteBaseUrl } from './seo';

const original = process.env.PUBLIC_BASE_URL;

beforeAll(() => {
  process.env.PUBLIC_BASE_URL = 'https://vision.example.com/';
});

afterAll(() => {
  if (original === undefined) delete process.env.PUBLIC_BASE_URL;
  else process.env.PUBLIC_BASE_URL = original;
});

describe('siteBaseUrl', () => {
  it('strips a trailing slash from the configured origin', () => {
    expect(siteBaseUrl()).toBe('https://vision.example.com');
  });

  it('falls back to localhost when unset', () => {
    const saved = process.env.PUBLIC_BASE_URL;
    delete process.env.PUBLIC_BASE_URL;
    expect(siteBaseUrl()).toBe('http://localhost:3000');
    process.env.PUBLIC_BASE_URL = saved;
  });
});

describe('pageMetadata', () => {
  it('builds canonical + en/am hreflang for a sub-page', () => {
    const meta = pageMetadata({
      title: 'About us',
      description: 'About the clinic.',
      path: '/about',
    });
    expect(meta.alternates?.canonical).toBe('/about');
    expect(meta.alternates?.languages).toEqual({
      en: '/about?lang=en',
      am: '/about?lang=am',
      'x-default': '/about',
    });
    expect(meta.openGraph?.url).toBe('https://vision.example.com/about');
    expect(meta.twitter && 'card' in meta.twitter ? meta.twitter.card : undefined).toBe(
      'summary_large_image',
    );
    expect(meta.title).toBe('About us');
  });

  it('uses an absolute title on the home page', () => {
    const meta = pageMetadata({
      title: 'Vision Eye Clinic — Eye care',
      description: 'Home.',
      path: '/',
      isHome: true,
    });
    expect(meta.title).toEqual({ absolute: 'Vision Eye Clinic — Eye care' });
    expect(meta.openGraph?.url).toBe('https://vision.example.com');
  });
});
