import { notFound } from 'next/navigation';
import LoginForm from '@/modules/auth/components/LoginForm';

/**
 * The only entry point — `/login/<LOGIN_SECRET_PATH>`.
 * Wrong secret → real 404 (proxy already 404s other /login paths; this is the
 * final server-side check so the URL never renders without the exact secret).
 */
export default async function LoginPage({ params }: { params: Promise<{ secret: string }> }) {
  const { secret } = await params;
  if (secret !== process.env.LOGIN_SECRET_PATH) notFound();

  return <LoginForm />;
}
