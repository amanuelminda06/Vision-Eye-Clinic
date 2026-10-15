import { z } from 'zod';

const MAX_REASON = 200;
const MAX_NOTE = 2000;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

export const patientSickLeaveSchema = z
  .object({
    patientId: z.string().min(1, 'required').max(64, 'tooLong'),
    startDate: z.string().trim().regex(DATE_RE, 'invalidDate'),
    endDate: z.string().trim().regex(DATE_RE, 'invalidDate'),
    reason: z.string().trim().max(MAX_REASON, 'tooLong').optional(),
    note: z.string().trim().max(MAX_NOTE, 'tooLong').optional(),
  })
  .refine((values) => values.endDate >= values.startDate, {
    message: 'endBeforeStart',
    path: ['endDate'],
  });

export type PatientSickLeaveValues = z.infer<typeof patientSickLeaveSchema>;
