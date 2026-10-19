import Link from 'next/link';
import { getTranslations } from 'next-intl/server';
import { formatInTz } from '@/lib/datetime';
import { requirePageRole } from '@/lib/require-role';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import {
  DeleteFeedbackButton,
  FEEDBACK_ROLES,
  FeedbackWebsiteToggle,
  listFeedback,
} from '@/modules/feedback';

type SearchParams = Promise<{ q?: string; website?: string }>;

export default async function FeedbackPage({ searchParams }: { searchParams: SearchParams }) {
  await requirePageRole(FEEDBACK_ROLES);
  const sp = await searchParams;
  const t = await getTranslations('feedback');
  const tc = await getTranslations('common');
  const settings = await getSettingsMap();
  const tz = timezoneFrom(settings);

  const q = (sp.q ?? '').trim().slice(0, 100);
  const website = sp.website === 'true' || sp.website === 'false' ? sp.website : '';
  const rows = await listFeedback({ q, website });
  const hasFilters = !!(q || website);

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">{t('title')}</h1>
        <p className="mt-1 max-w-3xl text-sm text-slate-600">{t('intro')}</p>
      </div>

      <form method="get" className="card grid grid-cols-1 sm:grid-cols-3 gap-2 p-3">
        <div className="col-span-1 sm:col-span-2">
          <label className="label text-xs" htmlFor="q">
            {tc('search')}
          </label>
          <input
            className="input-field text-sm"
            id="q"
            name="q"
            type="search"
            defaultValue={q}
            placeholder={t('searchPlaceholder')}
          />
        </div>
        <div>
          <label className="label text-xs" htmlFor="website">
            {t('websiteFilter')}
          </label>
          <select
            className="input-field text-sm"
            id="website"
            name="website"
            defaultValue={website}
          >
            <option value="">{tc('all')}</option>
            <option value="true">{t('websitePublished')}</option>
            <option value="false">{t('websiteHidden')}</option>
          </select>
        </div>
        <div className="flex items-end sm:col-span-3">
          <button type="submit" className="btn-secondary text-sm px-3 py-1.5">
            {tc('filter')}
          </button>
          <Link
            href="/dashboard/feedback"
            className="ml-2 pb-1 text-xs text-slate-500 hover:text-slate-800"
          >
            {tc('clear')}
          </Link>
        </div>
      </form>

      <div className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">{tc('name')}</th>
              <th className="px-4 py-3 font-medium">{t('rating')}</th>
              <th className="px-4 py-3 font-medium">{t('message')}</th>
              <th className="px-4 py-3 font-medium">{t('submitted')}</th>
              <th className="px-4 py-3 font-medium">{t('onWebsite')}</th>
              <th className="px-4 py-3 font-medium">{tc('actions')}</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                  {hasFilters ? tc('noResults') : t('empty')}
                </td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr
                  key={row.id}
                  className="border-b border-slate-100 align-top last:border-0 hover:bg-slate-50"
                >
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-900">{row.name}</div>
                    {row.email ? <div className="text-xs text-slate-500">{row.email}</div> : null}
                  </td>
                  <td className="px-4 py-3">
                    {row.rating ? (
                      <span
                        role="img"
                        aria-label={t('ratingValue', { rating: row.rating })}
                        className="inline-block text-sm"
                      >
                        <span aria-hidden="true" className="text-amber-500">
                          {'★'.repeat(row.rating)}
                        </span>
                        <span aria-hidden="true" className="text-slate-300">
                          {'★'.repeat(5 - row.rating)}
                        </span>
                      </span>
                    ) : (
                      <span className="text-slate-400" aria-hidden="true">
                        —
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-700">
                    <p className="max-w-md whitespace-pre-wrap line-clamp-4" title={row.text}>
                      {row.text}
                    </p>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {formatInTz(row.createdAt, tz)}
                  </td>
                  <td className="px-4 py-3">
                    <FeedbackWebsiteToggle
                      feedbackId={row.id}
                      name={row.name}
                      showOnWebsite={row.showOnWebsite}
                    />
                  </td>
                  <td className="px-4 py-3">
                    <DeleteFeedbackButton feedbackId={row.id} name={row.name} />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
