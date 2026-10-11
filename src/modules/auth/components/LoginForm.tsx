'use client';

import { useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import { getSession, signIn } from 'next-auth/react';
import { useTranslations } from 'next-intl';
import LocaleSwitcher from '@/components/LocaleSwitcher';

export default function LoginForm() {
  const router = useRouter();
  const t = useTranslations('auth');
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    setError(null);

    const form = new FormData(event.currentTarget);
    const result = await signIn('credentials', {
      email: String(form.get('email') ?? ''),
      password: String(form.get('password') ?? ''),
      redirect: false,
    });

    if (result?.error || !result?.ok) {
      setError(t('invalidCredentials'));
      setPending(false);
      return;
    }

    // First-login forced change: the JWT still says mustChangePassword, so
    // send the user straight to /change-password instead of the app. Otherwise
    // land on the dashboard (the app root `/` is the public marketing site).
    const session = await getSession();
    router.push(session?.user?.mustChangePassword ? '/change-password' : '/dashboard');
    router.refresh();
  }

  return (
    <main className="flex min-h-screen items-center justify-center px-4">
      <div className="w-full max-w-sm">
        <div className="card p-6">
          <h1 className="text-xl font-semibold text-slate-900">{t('loginTitle')}</h1>
          <p className="mt-1 text-sm text-slate-500">{t('loginSubtitle')}</p>

          <form onSubmit={onSubmit} className="mt-6 space-y-4">
            <div>
              <label className="label" htmlFor="email">
                {t('email')}
              </label>
              <input
                className="input-field"
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                autoFocus
                required
                disabled={pending}
                aria-invalid={!!error}
              />
            </div>

            <div>
              <label className="label" htmlFor="password">
                {t('password')}
              </label>
              <input
                className="input-field"
                id="password"
                name="password"
                type="password"
                autoComplete="current-password"
                required
                disabled={pending}
                aria-invalid={!!error}
              />
            </div>

            {error ? (
              <p role="alert" className="text-sm font-medium text-red-600">
                {error}
              </p>
            ) : null}

            <button type="submit" className="btn-primary w-full" disabled={pending}>
              {pending ? t('signingIn') : t('signIn')}
            </button>
          </form>
        </div>

        <div className="mt-4 flex items-center justify-center gap-3">
          <LocaleSwitcher />
        </div>
      </div>
    </main>
  );
}
