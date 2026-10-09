/** Runs once when the Next.js server starts — validate env before anything else. */
export async function register(): Promise<void> {
  if (process.env.NEXT_RUNTIME === 'nodejs') {
    await import('./env');
  }
}
