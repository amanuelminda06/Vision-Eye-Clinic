'use client';

import type { ReactNode } from 'react';

type Props = {
  name: string;
  defaultValue: string;
  ariaLabel: string;
  className?: string;
  children: ReactNode;
};

/** Native select that submits its surrounding form as soon as it changes. */
export default function AutoSubmitSelect({
  name,
  defaultValue,
  ariaLabel,
  className,
  children,
}: Props) {
  return (
    <select
      className={className ?? 'input-field w-auto'}
      name={name}
      defaultValue={defaultValue}
      aria-label={ariaLabel}
      onChange={(event) => event.currentTarget.form?.requestSubmit()}
    >
      {children}
    </select>
  );
}
