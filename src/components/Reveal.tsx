'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';

type RevealProps = {
  /** Milliseconds to stagger the transition, e.g. 100. */
  delay?: number;
  className?: string;
  children: ReactNode;
};

/**
 * Restrained scroll-reveal wrapper. The visual transition lives in
 * globals.css (.reveal / .reveal-visible) and no-ops for users who prefer
 * reduced motion.
 */
export default function Reveal({ delay = 0, className = '', children }: RevealProps) {
  const ref = useRef<HTMLDivElement>(null);
  const [visible, setVisible] = useState(
    () => typeof IntersectionObserver === 'undefined',
  );

  useEffect(() => {
    const node = ref.current;
    if (!node) return;
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            setVisible(true);
            observer.disconnect();
          }
        }
      },
      { threshold: 0.12 },
    );
    observer.observe(node);
    return () => observer.disconnect();
  }, []);

  return (
    <div
      ref={ref}
      className={`reveal ${visible ? 'reveal-visible' : ''} ${className}`.trim()}
      style={delay ? { transitionDelay: `${delay}ms` } : undefined}
    >
      {children}
    </div>
  );
}