import { cookies, headers } from 'next/headers';
import { getRequestConfig } from 'next-intl/server';
import { defaultLocale, isLocale, LOCALE_COOKIE } from './config';

export default getRequestConfig(async () => {
  // `?lang=` (public site) is forwarded by the proxy as `x-locale`; otherwise
  // fall back to the persisted cookie, then the default.
  const requestHeaders = await headers();
  const headerLocale = requestHeaders.get('x-locale');
  const store = await cookies();
  const cookieLocale = store.get(LOCALE_COOKIE)?.value;

  const locale = isLocale(headerLocale ?? undefined)
    ? (headerLocale as string)
    : isLocale(cookieLocale)
      ? cookieLocale
      : defaultLocale;

  return {
    locale,
    messages: (await import(`../../messages/${locale}.json`)).default,
  };
});
