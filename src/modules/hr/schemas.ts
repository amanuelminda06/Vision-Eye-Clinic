import { z } from 'zod';
import {
  LEAVE_TYPES,
  MAX_DECISION_NOTE,
  MAX_NOTE,
  MAX_RANGE_DAYS,
  MAX_REASON,
  TIME_RE,
  DATE_RE,
} from './constants';

const dateKey = z.string().regex(DATE_RE, 'invalidDate');
const timeKey = z.string().regex(TIME_RE, 'invalidTime');
const checkbox = z
  .union([z.boolean(), z.enum(['true', 'false'])])
  .transform((value) => value === true || value === 'true');

export const shiftFormSchema = z
  .object({
    id: z.string().max(64, 'tooLong').optional(),
    staffId: z.string().min(1, 'required').max(64, 'tooLong'),
    dateKey: dateKey,
    startTime: timeKey,
    endTime: timeKey,
    note: z.string().trim().max(MAX_NOTE, 'tooLong').optional(),
  })
  .refine((values) => values.startTime !== values.endTime, {
    message: 'invalidTime',
    path: ['endTime'],
  });

export type ShiftFormValues = z.infer<typeof shiftFormSchema>;

export const templateFormSchema = z
  .object({
    staffId: z.string().min(1, 'required').max(64, 'tooLong'),
    weekday: z.coerce.number().int('invalid').min(0, 'invalid').max(6, 'invalid'),
    startTime: timeKey,
    endTime: timeKey,
    validFrom: dateKey,
    validTo: z.union([dateKey, z.literal('')]).optional(),
  })
  .refine((values) => values.startTime !== values.endTime, {
    message: 'invalidTime',
    path: ['endTime'],
  })
  .refine((values) => !values.validTo || values.validTo >= values.validFrom, {
    message: 'endBeforeStart',
    path: ['validTo'],
  });

export type TemplateFormValues = z.infer<typeof templateFormSchema>;

export const generateFromTemplateSchema = z
  .object({
    staffId: z.string().max(64, 'tooLong').optional(),
    fromKey: dateKey,
    toKey: dateKey,
  })
  .refine(
    (values) => {
      const from = Date.parse(`${values.fromKey}T00:00:00Z`);
      const to = Date.parse(`${values.toKey}T00:00:00Z`);
      if (!Number.isFinite(from) || !Number.isFinite(to) || to < from) return false;
      return (to - from) / 86_400_000 + 1 <= MAX_RANGE_DAYS;
    },
    { message: 'rangeTooLong', path: ['toKey'] },
  );

export type GenerateFromTemplateValues = z.infer<typeof generateFromTemplateSchema>;

export const leaveRequestSchema = z
  .object({
    staffId: z.string().max(64, 'tooLong').optional(),
    type: z.enum(LEAVE_TYPES, 'invalidLeaveType'),
    startDate: dateKey,
    endDate: dateKey,
    halfDay: checkbox,
    reason: z.string().trim().max(MAX_REASON, 'tooLong').optional(),
  })
  .refine((values) => values.endDate >= values.startDate, {
    message: 'endBeforeStart',
    path: ['endDate'],
  });

export type LeaveRequestValues = z.infer<typeof leaveRequestSchema>;

export const leaveDecisionSchema = z.object({
  leaveId: z.string().min(1, 'required').max(64, 'tooLong'),
  note: z.string().trim().max(MAX_DECISION_NOTE, 'tooLong').optional(),
});

export type LeaveDecisionValues = z.infer<typeof leaveDecisionSchema>;

export const leaveCancelSchema = z.object({
  leaveId: z.string().min(1, 'required').max(64, 'tooLong'),
  note: z.string().trim().max(MAX_DECISION_NOTE, 'tooLong').optional(),
});
