export const PAYMENT_METHODS = ['CASH', 'MOBILE_MONEY', 'BANK', 'CARD', 'OTHER'] as const;
export type PaymentMethodValue = (typeof PAYMENT_METHODS)[number];

/** `t()` key inside the `sales` namespace for each payment method. */
export const PAYMENT_LABEL_KEYS: Record<PaymentMethodValue, string> = {
  CASH: 'payCash',
  MOBILE_MONEY: 'payMobileMoney',
  BANK: 'payBank',
  CARD: 'payCard',
  OTHER: 'payOther',
};

export const SALE_STATUSES = ['DRAFT', 'CONFIRMED', 'VOIDED'] as const;
export type SaleStatusValue = (typeof SALE_STATUSES)[number];

/** `t()` key inside the `sales` namespace for each status. */
export const STATUS_LABEL_KEYS: Record<SaleStatusValue, string> = {
  DRAFT: 'statusDraft',
  CONFIRMED: 'statusConfirmed',
  VOIDED: 'statusVoided',
};

export function isPaymentMethod(value: unknown): value is PaymentMethodValue {
  return typeof value === 'string' && (PAYMENT_METHODS as readonly string[]).includes(value);
}

export function isSaleStatus(value: unknown): value is SaleStatusValue {
  return typeof value === 'string' && (SALE_STATUSES as readonly string[]).includes(value);
}

export const SALES_PAGE_SIZE = 20;
export const SALES_EXPORT_CAP = 5000;
export const MAX_SALE_LINES = 50;
export const MAX_QTY = 999;
export const MAX_REASON = 300;
export const MAX_PATIENT_QUERY = 100;
