'use client';

import { useEffect } from 'react';

/**
 * Polls the due-message count every minute and prefixes the browser tab title,
 * e.g. "(7) Vision Eye Clinic". Rendered once in the protected layout.
 */
export default function MessagesTicker({ baseTitle }: { baseTitle: string }) {
  useEffect(() => {
    let cancelled = false;

    async function poll() {
      try {
        const res = await fetch('/api/messages/count', { cache: 'no-store' });
        if (!res.ok) return;
        const data = (await res.json()) as { count?: number };
        if (cancelled) return;
        const count = data.count ?? 0;
        document.title = count > 0 ? `(${count}) ${baseTitle}` : baseTitle;
      } catch {
        // ignore transient errors
      }
    }

    void poll();
    const timer = window.setInterval(poll, 60_000);
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [baseTitle]);

  return null;
}
