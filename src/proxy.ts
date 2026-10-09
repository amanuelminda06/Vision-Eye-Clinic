import { NextResponse, type NextRequest } from 'next/server';
import { isLocale, LOCALE_COOKIE } from '@/i18n/config';

/**
 * Route gate (Next 16: middleware was renamed proxy).
 *
 * 1. Public marketing pages (the clinic website) are open and support
 *    `?lang=en|am`, which we forward to the app as an `x-locale` request header
 *    (so the renderer picks it up) and persist as the `locale` cookie.
 * 2. `/login/<secret>` — the only auth entry point. Any other `/login…` URL
 *    gets a real 404 so scanners learn nothing.
 * 3. Everything else requires a session cookie (optimistic sniff — the real,
 *    signature-verified check runs in `requireRole()` / the app layout).
 *
 * Locale is cookie-based, so no locale prefix exists in URLs.
 */
const SESSION_COOKIES = ['authjs.session-token', '__Secure-authjs.session-token'];

const PUBLIC_PATHS = new Set([
  '/',
  '/about',
  '/services',
  '/doctors',
  '/book',
  '/contact',
  '/faq',
  '/privacy',
]);

function isPublicPath(pathname: string): boolean {
  const clean = pathname.length > 1 && pathname.endsWith('/') ? pathname.slice(0, -1) : pathname;
  if (PUBLIC_PATHS.has(clean)) return true;
  // Next.js metadata image routes (file conventions) for the public segment.
  return clean.startsWith('/opengraph-image') || clean.startsWith('/twitter-image');
}

function withLocale(req: NextRequest, lang: string | null): NextResponse {
  const headers = new Headers(req.headers);
  if (isLocale(lang ?? undefined)) headers.set('x-locale', lang as string);
  const response = NextResponse.next({ request: { headers } });
  if (isLocale(lang ?? undefined)) {
    response.cookies.set(LOCALE_COOKIE, lang as string, {
      path: '/',
      maxAge: 60 * 60 * 24 * 365,
      sameSite: 'lax',
    });
  }
  return response;
}

export function proxy(req: NextRequest) {
  const { pathname } = req.nextUrl;
  const lang = req.nextUrl.searchParams.get('lang');

  if (isPublicPath(pathname)) {
    return withLocale(req, lang);
  }

  const loginPath = `/login/${process.env.LOGIN_SECRET_PATH}`;

  if (pathname === loginPath || pathname === `${loginPath}/`) {
    return NextResponse.next();
  }
  if (pathname === '/login' || pathname.startsWith('/login/')) {
    return new NextResponse(null, { status: 404 });
  }

  const hasSession = SESSION_COOKIES.some((name) => req.cookies.has(name));
  if (!hasSession) {
    const url = req.nextUrl.clone();
    url.pathname = loginPath;
    url.search = '';
    return NextResponse.redirect(url);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ['/((?!_next|api|.*\\..*).*)'],
};
