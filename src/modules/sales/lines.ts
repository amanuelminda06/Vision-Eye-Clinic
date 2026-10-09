import { multiplyMoney, sumLineTotals, toDecimalString } from '@/lib/money';
import { MAX_QTY } from './constants';

/**
 * Sales line computation (pure — unit tested).
 *
 * The client only sends `serviceId` + `qty`. Prices, names and totals are
 * ALWAYS taken from the database rows here, so:
 *  - a tampered client price is impossible (it is never read),
 *  - the line keeps the price that was current when the sale was made,
 *  - later service price changes never touch existing sales.
 */

export type ServiceSnapshot = {
  id: string;
  name: string;
  currentPrice: string;
  active: boolean;
};

export type RequestedLine = { serviceId: string; qty: number };

export type ComputedLine = {
  serviceId: string;
  serviceNameSnapshot: string;
  qty: number;
  unitPrice: string;
  lineTotal: string;
};

export type ComputeError = 'serviceMissing' | 'serviceInactive' | 'quantityTooHigh';

export type ComputeSaleLinesResult =
  | { ok: true; lines: ComputedLine[]; total: string }
  | { ok: false; error: ComputeError; serviceNames: string[] };

/** Merges duplicate service rows (same service added twice → summed qty). */
function mergeRequested(requested: RequestedLine[]): RequestedLine[] {
  const merged = new Map<string, number>();
  for (const line of requested) {
    merged.set(line.serviceId, (merged.get(line.serviceId) ?? 0) + line.qty);
  }
  return [...merged].map(([serviceId, qty]) => ({ serviceId, qty }));
}

export function computeSaleLines(
  services: ServiceSnapshot[],
  requested: RequestedLine[],
): ComputeSaleLinesResult {
  const byId = new Map(services.map((service) => [service.id, service]));
  const merged = mergeRequested(requested);

  const missing: string[] = [];
  const inactive: string[] = [];
  const lines: ComputedLine[] = [];

  for (const requestedLine of merged) {
    const service = byId.get(requestedLine.serviceId);
    if (!service) {
      missing.push(requestedLine.serviceId);
      continue;
    }
    if (!service.active) {
      inactive.push(service.name);
      continue;
    }
    if (requestedLine.qty < 1 || requestedLine.qty > MAX_QTY) {
      return { ok: false, error: 'quantityTooHigh', serviceNames: [service.name] };
    }
    lines.push({
      serviceId: service.id,
      serviceNameSnapshot: service.name,
      qty: requestedLine.qty,
      unitPrice: toDecimalString(multiplyMoney(service.currentPrice, 1)),
      lineTotal: toDecimalString(multiplyMoney(service.currentPrice, requestedLine.qty)),
    });
  }

  if (inactive.length > 0) return { ok: false, error: 'serviceInactive', serviceNames: inactive };
  if (missing.length > 0) return { ok: false, error: 'serviceMissing', serviceNames: missing };
  if (lines.length === 0) return { ok: false, error: 'serviceMissing', serviceNames: [] };

  const total = sumLineTotals(
    lines.map((line) => ({ unitPrice: line.unitPrice, quantity: line.qty })),
  );

  return { ok: true, lines, total: toDecimalString(total) };
}
