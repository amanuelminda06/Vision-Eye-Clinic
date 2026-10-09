import { getTranslations } from 'next-intl/server';

export default async function NotFound() {
  const t = await getTranslations('notFound');
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-2 px-4 text-center">
      <p className="text-6xl font-semibold text-slate-300">404</p>
      <p className="text-slate-600">{t('message')}</p>
    </main>
  );
}
