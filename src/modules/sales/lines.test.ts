import { describe, expect, it } from 'vitest';
import { computeSaleLines, type ServiceSnapshot } from './lines';

const consultation: ServiceSnapshot = {
  id: 'svc-1',
  name: 'Eye Consultation',
  currentPrice: '300.00',
  active: true,
};
const oct: ServiceSnapshot = {
  id: 'svc-2',
  name: 'OCT Scan',
  currentPrice: '1500.50',
  active: true,
};

describe('computeSaleLines', () => {
  it('uses the server price snapshot, never a client value', () => {
    const result = computeSaleLines(
      [consultation, oct],
      [
        { serviceId: 'svc-1', qty: 2 },
        { serviceId: 'svc-2', qty: 1 },
      ],
    );

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.lines[0]).toMatchObject({
      serviceNameSnapshot: 'Eye Consultation',
      unitPrice: '300.00',
      qty: 2,
      lineTotal: '600.00',
    });
    expect(result.total).toBe('2100.50');
  });

  it('merges duplicate service rows by summing quantities', () => {
    const result = computeSaleLines(
      [consultation],
      [
        { serviceId: 'svc-1', qty: 1 },
        { serviceId: 'svc-1', qty: 2 },
      ],
    );

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.lines).toHaveLength(1);
    expect(result.lines[0].qty).toBe(3);
    expect(result.lines[0].lineTotal).toBe('900.00');
  });

  it('keeps the price frozen at sale time (later price changes do not apply)', () => {
    const first = computeSaleLines([consultation], [{ serviceId: 'svc-1', qty: 1 }]);
    expect(first.ok).toBe(true);
    if (!first.ok) return;
    expect(first.lines[0].unitPrice).toBe('300.00');

    const raised: ServiceSnapshot = { ...consultation, currentPrice: '400.00' };
    const second = computeSaleLines([raised], [{ serviceId: 'svc-1', qty: 1 }]);
    expect(second.ok).toBe(true);
    if (!second.ok) return;
    expect(second.lines[0].unitPrice).toBe('400.00');
    expect(first.lines[0].unitPrice).toBe('300.00');
  });

  it('rejects inactive services', () => {
    const inactive: ServiceSnapshot = { ...consultation, active: false };
    const result = computeSaleLines([inactive], [{ serviceId: 'svc-1', qty: 1 }]);
    expect(result).toMatchObject({ ok: false, error: 'serviceInactive' });
  });

  it('rejects services that no longer exist', () => {
    const result = computeSaleLines([consultation], [{ serviceId: 'missing', qty: 1 }]);
    expect(result).toMatchObject({ ok: false, error: 'serviceMissing' });
  });

  it('rejects quantities below 1 or above the cap', () => {
    expect(computeSaleLines([consultation], [{ serviceId: 'svc-1', qty: 0 }])).toMatchObject({
      ok: false,
      error: 'quantityTooHigh',
    });
    expect(computeSaleLines([consultation], [{ serviceId: 'svc-1', qty: 1000 }])).toMatchObject({
      ok: false,
      error: 'quantityTooHigh',
    });
  });
});
