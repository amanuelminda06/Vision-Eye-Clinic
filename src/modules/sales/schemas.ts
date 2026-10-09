import { z } from 'zod';
import { MAX_QTY, MAX_REASON, MAX_SALE_LINES, PAYMENT_METHODS } from './constants';

export const saleLineSchema = z.object({
  serviceId: z.string().min(1, 'required').max(64, 'tooLong'),
  qty: z.number().int('invalidQuantity').min(1, 'minQuantity').max(MAX_QTY, 'tooMany'),
});

export const saleFormSchema = z.object({
  patientId: z.string().max(64, 'tooLong'),
  doctorId: z.string().max(64, 'tooLong'),
  paymentMethod: z.enum(PAYMENT_METHODS, 'invalidPaymentMethod'),
  lines: z.array(saleLineSchema).min(1, 'noLines').max(MAX_SALE_LINES, 'tooManyLines'),
  /** Walk-in marker — no patient record is attached. */
  walkIn: z.boolean(),
});

export type SaleLineInput = z.infer<typeof saleLineSchema>;

export const voidReasonSchema = z.object({
  reason: z.string().trim().min(3, 'required').max(MAX_REASON, 'tooLong'),
});

export type VoidReasonValues = z.infer<typeof voidReasonSchema>;

export const saleFilterSchema = z.object({
  from: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'invalidDate')
    .optional(),
  to: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'invalidDate')
    .optional(),
  patient: z.string().trim().max(100, 'tooLong').optional(),
  serviceId: z.string().max(64, 'tooLong').optional(),
  doctorId: z.string().max(64, 'tooLong').optional(),
  status: z.enum(['DRAFT', 'CONFIRMED', 'VOIDED'], 'invalid').optional(),
  paymentMethod: z.enum(PAYMENT_METHODS, 'invalid').optional(),
});

export type SaleFilterValues = z.infer<typeof saleFilterSchema>;
