'use client';

import { useRef } from 'react';
import { useTranslations } from 'next-intl';

type Props = { targetId: string };

/**
 * A5 and 80mm thermal print modes. The `@page` rule has to be swapped at
 * print time, so we inject it into a dedicated <style> and then print.
 */
export default function PrintButtons({ targetId }: Props) {
  const t = useTranslations('sales');
  const styleRef = useRef<HTMLStyleElement | null>(null);

  function print(mode: 'a5' | 'thermal') {
    const root = document.getElementById(targetId);
    if (!root) return;
    root.dataset.printMode = mode;
    if (!styleRef.current) {
      const style = document.createElement('style');
      styleRef.current = style;
      document.head.appendChild(style);
    }
    styleRef.current.textContent =
      mode === 'thermal'
        ? '@page { size: 80mm auto; margin: 3mm; }'
        : '@page { size: A5; margin: 10mm; }';
    window.print();
  }

  return (
    <div className="no-print flex flex-wrap items-center gap-2">
      <button type="button" className="btn-primary" onClick={() => print('a5')}>
        {t('printA5')}
      </button>
      <button type="button" className="btn-secondary" onClick={() => print('thermal')}>
        {t('printThermal')}
      </button>
    </div>
  );
}
