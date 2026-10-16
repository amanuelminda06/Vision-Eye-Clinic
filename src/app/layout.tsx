import type { Metadata } from 'next';
import { NextIntlClientProvider } from 'next-intl';
import Script from 'next/script';
import { getLocale, getTranslations } from 'next-intl/server';
import './globals.css';

// Every route in this ERP is session- and locale-dependent (dynamic by
// design) — opt out of the static-shell requirement for the whole app.
export const instant = false;

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('app');
  return {
    title: { default: t('name'), template: `%s · ${t('name')}` },
  };
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const locale = await getLocale();
  return (
    <html lang={locale}>
      <body className="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <link
          rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          crossOrigin=""
        />
        <Script
          strategy="beforeInteractive"
          src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
          id="leaflet-js"
        />
        <NextIntlClientProvider>{children}</NextIntlClientProvider>
      </body>
    </html>
  );
}
