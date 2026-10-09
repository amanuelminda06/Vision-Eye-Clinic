'use server';

import { Prisma } from '@prisma/client';
import { prisma } from '@/lib/prisma';
import { writeAudit } from '@/lib/audit';
import { ok, fail, withAuth, type ActionResult } from '@/lib/action';
import { validate } from '@/lib/errors';
import { formatMoney, toDecimalString } from '@/lib/money';
import { phoneSearchVariants } from '@/lib/phone';
import { getSettingsMap, timezoneFrom } from '@/modules/settings';
import { SALES_ROLES, SALES_OWNER_ROLES } from './roles';
import { saleFormSchema, voidReasonSchema, type SaleLineInput } from './schemas';
import { MAX_PATIENT_QUERY } from './constants';
import { computeSaleLines, type ServiceSnapshot } from './lines';
import { nextReceiptNumber } from './receipt';

function parseLines(raw: string): SaleLineInput[] | null {
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as SaleLineInput[]) : null;
  } catch {
    return null;
  }
}

function readSaleForm(formData: FormData) {
  return {
    patientId: String(formData.get('patientId') ?? ''),
    doctorId: String(formData.get('doctorId') ?? ''),
    paymentMethod: String(formData.get('paymentMethod') ?? ''),
    walkIn: String(formData.get('walkIn') ?? '') === 'true',
    lines: parseLines(String(formData.get('lines') ?? '[]')) ?? [],
  };
}

/**
 * Fast entry: validates, recomputes all money server-side, claims the next
 * gap-free receipt number and stores the sale as CONFIRMED in one transaction.
 */
export async function createSaleAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult<{ id: string; receiptNumber: string }>> {
  return withAuth<{ id: string; receiptNumber: string }>(SALES_ROLES, async (actor) => {
    const values = validate(saleFormSchema, readSaleForm(formData));

    let patientId: string | null = null;
    if (!values.walkIn) {
      if (!values.patientId) return fail('validationError', { patientId: 'required' });
      const patient = await prisma.patient.findUnique({
        where: { id: values.patientId },
        select: { id: true, active: true },
      });
      if (!patient || !patient.active) return fail('validationError', { patientId: 'invalid' });
      patientId = patient.id;
    }

    let doctorId: string | null = null;
    if (values.doctorId) {
      const doctor = await prisma.staff.findUnique({
        where: { id: values.doctorId },
        select: { id: true, active: true },
      });
      if (!doctor || !doctor.active) return fail('validationError', { doctorId: 'invalid' });
      doctorId = doctor.id;
    }

    const serviceIds = [...new Set(values.lines.map((line) => line.serviceId))];
    const services = await prisma.service.findMany({
      where: { id: { in: serviceIds } },
      select: { id: true, name: true, currentPrice: true, active: true },
    });
    const snapshots: ServiceSnapshot[] = services.map((service) => ({
      id: service.id,
      name: service.name,
      currentPrice: toDecimalString(service.currentPrice),
      active: service.active,
    }));

    const computed = computeSaleLines(snapshots, values.lines);
    if (!computed.ok) return fail('validationError', { lines: computed.error });

    const settings = await getSettingsMap();
    const tz = timezoneFrom(settings);
    const now = new Date();

    const sale = await prisma.$transaction(async (tx) => {
      const receiptNumber = await nextReceiptNumber(tx, now, tz);
      return tx.sale.create({
        data: {
          receiptNumber,
          patientId,
          doctorId,
          saleDate: now,
          status: 'CONFIRMED',
          paymentMethod: values.paymentMethod,
          total: computed.total,
          createdBy: actor.id,
          confirmedAt: now,
          lines: {
            create: computed.lines.map((line) => ({
              serviceId: line.serviceId,
              serviceNameSnapshot: line.serviceNameSnapshot,
              qty: line.qty,
              unitPrice: line.unitPrice,
              lineTotal: line.lineTotal,
            })),
          },
        },
      });
    });

    await writeAudit({
      user: actor,
      entity: 'Sale',
      entityId: sale.id,
      action: 'SALE_CREATED',
      new: {
        receiptNumber: sale.receiptNumber,
        patientId,
        doctorId,
        paymentMethod: sale.paymentMethod,
        status: sale.status,
        total: toDecimalString(sale.total),
        lines: computed.lines.map((line) => ({
          service: line.serviceNameSnapshot,
          qty: line.qty,
          unitPrice: formatMoney(line.unitPrice),
          lineTotal: formatMoney(line.lineTotal),
        })),
      },
    });

    return ok({ id: sale.id, receiptNumber: sale.receiptNumber });
  });
}

/** RECEPTION (or OWNER) asks the OWNER to void a confirmed sale. */
export async function requestVoidAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(SALES_ROLES, async (actor) => {
    const saleId = String(formData.get('saleId') ?? '');
    const values = validate(voidReasonSchema, { reason: String(formData.get('reason') ?? '') });

    const sale = await prisma.sale.findUnique({
      where: { id: saleId },
      select: { id: true, status: true, voidRequestedBy: true, total: true },
    });
    if (!sale) return fail('unknownError');
    if (sale.status !== 'CONFIRMED') return fail('notConfirmed');
    if (sale.voidRequestedBy) return fail('alreadyRequested');

    await prisma.sale.update({
      where: { id: saleId },
      data: { voidRequestedBy: actor.id, voidRequestReason: values.reason },
    });
    await writeAudit({
      user: actor,
      entity: 'Sale',
      entityId: saleId,
      action: 'SALE_VOID_REQUESTED',
      new: { voidRequestedBy: actor.id, voidRequestReason: values.reason },
    });

    return ok();
  });
}

/** OWNER voids a confirmed sale directly. */
export async function voidSaleAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(SALES_OWNER_ROLES, async (actor) => {
    const saleId = String(formData.get('saleId') ?? '');
    const values = validate(voidReasonSchema, { reason: String(formData.get('reason') ?? '') });

    const sale = await prisma.sale.findUnique({
      where: { id: saleId },
      select: { id: true, status: true, total: true },
    });
    if (!sale) return fail('unknownError');
    if (sale.status !== 'CONFIRMED') return fail('notConfirmed');

    await prisma.sale.update({
      where: { id: saleId },
      data: {
        status: 'VOIDED',
        voidedBy: actor.id,
        voidReason: values.reason,
        voidedAt: new Date(),
      },
    });
    await writeAudit({
      user: actor,
      entity: 'Sale',
      entityId: saleId,
      action: 'SALE_VOIDED',
      old: { status: 'CONFIRMED' },
      new: { status: 'VOIDED', voidedBy: actor.id, voidReason: values.reason },
    });

    return ok();
  });
}

/** OWNER approves a pending void request (uses the requester's reason). */
export async function approveVoidRequestAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(SALES_OWNER_ROLES, async (actor) => {
    const saleId = String(formData.get('saleId') ?? '');
    const sale = await prisma.sale.findUnique({
      where: { id: saleId },
      select: { id: true, status: true, voidRequestedBy: true, voidRequestReason: true },
    });
    if (!sale) return fail('unknownError');
    if (sale.status !== 'CONFIRMED') return fail('notConfirmed');
    if (!sale.voidRequestedBy) return fail('noRequest');

    await prisma.sale.update({
      where: { id: saleId },
      data: {
        status: 'VOIDED',
        voidedBy: actor.id,
        voidReason: sale.voidRequestReason,
        voidedAt: new Date(),
      },
    });
    await writeAudit({
      user: actor,
      entity: 'Sale',
      entityId: saleId,
      action: 'SALE_VOID_APPROVED',
      old: { status: 'CONFIRMED', voidRequestedBy: sale.voidRequestedBy },
      new: { status: 'VOIDED', voidedBy: actor.id, voidReason: sale.voidRequestReason },
    });

    return ok();
  });
}

/** OWNER rejects a pending void request — the sale stays confirmed. */
export async function rejectVoidRequestAction(
  _prev: ActionResult | null,
  formData: FormData,
): Promise<ActionResult> {
  return withAuth(SALES_OWNER_ROLES, async (actor) => {
    const saleId = String(formData.get('saleId') ?? '');
    const values = validate(voidReasonSchema, { reason: String(formData.get('reason') ?? '') });

    const sale = await prisma.sale.findUnique({
      where: { id: saleId },
      select: { id: true, status: true, voidRequestedBy: true, voidRequestReason: true },
    });
    if (!sale) return fail('unknownError');
    if (sale.status !== 'CONFIRMED') return fail('notConfirmed');
    if (!sale.voidRequestedBy) return fail('noRequest');

    await prisma.sale.update({
      where: { id: saleId },
      data: { voidRequestedBy: null, voidRequestReason: null },
    });
    await writeAudit({
      user: actor,
      entity: 'Sale',
      entityId: saleId,
      action: 'SALE_VOID_REQUEST_REJECTED',
      old: {
        voidRequestedBy: sale.voidRequestedBy,
        voidRequestReason: sale.voidRequestReason,
      },
      new: { rejectReason: values.reason },
    });

    return ok();
  });
}

export type PatientSearchResult = { id: string; fullName: string; phoneE164: string };

/** Type-ahead for the new-sale patient picker (name or phone fragment). */
export async function searchPatientsAction(
  query: string,
): Promise<ActionResult<PatientSearchResult[]>> {
  return withAuth<PatientSearchResult[]>(SALES_ROLES, async () => {
    const q = String(query).trim().slice(0, MAX_PATIENT_QUERY);
    if (q.length < 2) return ok([]);

    const or: Prisma.PatientWhereInput[] = [{ fullName: { contains: q, mode: 'insensitive' } }];
    for (const variant of phoneSearchVariants(q) ?? []) {
      or.push({ phoneE164: { contains: variant } });
    }

    const rows = await prisma.patient.findMany({
      where: { active: true, OR: or },
      select: { id: true, fullName: true, phoneE164: true },
      orderBy: { fullName: 'asc' },
      take: 8,
    });
    return ok(rows);
  });
}
