import { z } from 'zod';
import {
  APPT_TIME_RE,
  DATE_RE,
  DURATIONS,
  FOLLOW_UP_PRESETS,
  MAX_APPT_NOTES,
  MAX_CANCEL_REASON,
  MAX_FOLLOW_UP_REASON,
  MAX_REQUEST_MESSAGE,
} from './constants';

const dateKey = z.string().regex(DATE_RE, 'invalidDate');
const timeKey = z.string().regex(APPT_TIME_RE, 'invalidTime');
const checkbox = z
  .union([z.boolean(), z.enum(['true', 'false'])])
  .transform((value) => value === true || value === 'true');

export const appointmentFormSchema = z
  .object({
    id: z.string().max(64, 'tooLong').optional(),
    patientId: z.string().max(64, 'tooLong').optional(),
    quickName: z.string().trim().max(100, 'tooLong').optional(),
    quickPhone: z.string().trim().max(32, 'tooLong').optional(),
    doctorId: z.string().min(1, 'doctorRequired').max(64, 'tooLong'),
    dateKey,
    startTime: timeKey,
    durationMin: z.coerce
      .number('invalidDuration')
      .int('invalidDuration')
      .min(DURATIONS[0], 'invalidDuration')
      .max(180, 'invalidDuration'),
    serviceId: z.string().max(64, 'tooLong').optional(),
    notes: z.string().trim().max(MAX_APPT_NOTES, 'tooLong').optional(),
    overrideDoubleBooking: checkbox,
  })
  .refine(
    (values) =>
      Boolean(values.patientId) || (Boolean(values.quickName) && Boolean(values.quickPhone)),
    {
      message: 'patientRequired',
      path: ['patientId'],
    },
  );

export type AppointmentFormValues = z.infer<typeof appointmentFormSchema>;

export const appointmentStatusSchema = z.object({
  id: z.string().min(1, 'required').max(64, 'tooLong'),
  status: z.enum(['SCHEDULED', 'CONFIRMED', 'COMPLETED', 'NO_SHOW', 'CANCELLED'], 'invalidStatus'),
  cancelReason: z.string().trim().max(MAX_CANCEL_REASON, 'tooLong').optional(),
});

export type AppointmentStatusValues = z.infer<typeof appointmentStatusSchema>;

export const followUpFormSchema = z
  .object({
    patientId: z.string().min(1, 'required').max(64, 'tooLong'),
    appointmentId: z.string().max(64, 'tooLong').optional(),
    preset: z.enum(FOLLOW_UP_PRESETS, 'invalidPreset'),
    dueDate: z.union([dateKey, z.literal('')]).optional(),
    reason: z.string().trim().max(MAX_FOLLOW_UP_REASON, 'tooLong').optional(),
  })
  .refine(
    (values) => values.preset !== 'custom' || Boolean(values.dueDate && values.dueDate.length),
    { message: 'invalidDate', path: ['dueDate'] },
  );

export type FollowUpFormValues = z.infer<typeof followUpFormSchema>;

export const followUpStatusSchema = z.object({
  id: z.string().min(1, 'required').max(64, 'tooLong'),
  status: z.enum(['DONE', 'CANCELLED'], 'invalidStatus'),
});

export const requestStatusSchema = z
  .object({
    id: z.string().min(1, 'required').max(64, 'tooLong'),
    status: z.enum(['NEW', 'CONTACTED', 'CONVERTED', 'DECLINED', 'SPAM'], 'invalidStatus'),
  })
  .refine((values) => values.status !== 'CONVERTED', {
    message: 'convertViaForm',
    path: ['status'],
  });

export type RequestStatusValues = z.infer<typeof requestStatusSchema>;

export const convertRequestSchema = z.object({
  requestId: z.string().min(1, 'required').max(64, 'tooLong'),
  doctorId: z.string().min(1, 'doctorRequired').max(64, 'tooLong'),
  serviceId: z.string().max(64, 'tooLong').optional(),
  patientId: z.string().max(64, 'tooLong').optional(),
  notes: z.string().trim().max(MAX_APPT_NOTES, 'tooLong').optional(),
});

export type ConvertRequestValues = z.infer<typeof convertRequestSchema>;

export const publicRequestSchema = z.object({
  name: z.string().trim().min(1, 'required').max(100, 'tooLong'),
  phone: z.string().trim().min(1, 'required').max(32, 'tooLong'),
  preferredDate: dateKey,
  preferredTimeWindow: z
    .string()
    .trim()
    .max(40, 'tooLong')
    .optional()
    .transform((value) => value || null),
  serviceInterest: z
    .string()
    .trim()
    .max(200, 'tooLong')
    .optional()
    .transform((value) => value || null),
  message: z
    .string()
    .trim()
    .max(MAX_REQUEST_MESSAGE, 'tooLong')
    .optional()
    .transform((value) => value || null),
  language: z.enum(['en', 'am'], 'invalidLanguage').default('en'),
});

export type PublicRequestValues = z.infer<typeof publicRequestSchema>;
