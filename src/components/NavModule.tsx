import Link from 'next/link';
import { useState } from 'react';
import { useTranslations } from 'next-intl';
import type { NavModule } from '@/modules/nav';

type Props = {
  module: NavModule;
  pathname: string;
  className: string;
  onNavigate: () => void;
};

/** Collapsible module group: header (acronym + full name) with expand/collapse. */
export default function NavModule({ module, pathname, className, onNavigate }: Props) {
  const t = useTranslations('nav');
  const active = module.items.some(
    (item) => pathname === item.href || pathname.startsWith(item.href + '/'),
  );
  const [open, setOpen] = useState(active);

  const heading = module.acronym ? (
    <span>
      <span className="font-semibold text-slate-900">{module.acronym}</span>
      <span className="ml-1.5 text-slate-400">—</span>
      <span className="ml-1.5 font-medium text-slate-700">{t(module.labelKey as 'pms')}</span>
    </span>
  ) : (
    <span className="font-semibold text-slate-900">{t(module.labelKey as 'dashboard')}</span>
  );

  return (
    <div className="group">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        aria-label={open ? t('collapse') : t('expand')}
        className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-600"
      >
        {heading}
        <span
          className={`ml-1.5 inline-flex h-4 w-4 items-center justify-center text-[10px] transition-transform ${open ? 'rotate-180' : ''}`}
          aria-hidden
        >
          ▾
        </span>
      </button>
      {open ? (
        <div className="mt-0.5 flex flex-col gap-0.5">
          {module.items.map((item) => (
            <Link
              key={item.href}
              href={item.href}
              onClick={onNavigate}
              aria-current={pathname === item.href ? 'page' : undefined}
              className={
                className +
                (pathname === item.href
                  ? ' bg-orange-100 text-orange-900'
                  : ' text-slate-600 hover:bg-orange-50 hover:text-orange-900')
              }
            >
              {t(item.labelKey as 'dashboard')}
              {item.badge != null && item.badge > 0 ? (
                <span className="ml-1.5 inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">
                  {item.badge}
                </span>
              ) : null}
            </Link>
          ))}
        </div>
      ) : null}
    </div>
  );
}
