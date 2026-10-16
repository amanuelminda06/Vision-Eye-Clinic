import { isValidPhone } from '@/lib/phone';
import type { BlockedReason } from './constants';

/**
 * Pure eligibility check, run both when a task is created and again when the
 * user opens it. A failure never drops the task — it marks it BLOCKED with a
 * visible reason.
 */

export type EligibilityPatient = {
  active: boolean;
  doNotMessage: boolean;
  phoneE164: string;
};

export type EligibilityInput = {
  patient: EligibilityPatient;
  /** Status of the appointment/follow-up that produced the task, if any. */
  sourceStatus?: string | null;
};

export function blockedReason({ patient, sourceStatus }: EligibilityInput): BlockedReason | null {
  if (!patient.active) return 'inactive';
  if (patient.doNotMessage) return 'doNotMessage';
  if (!isValidPhone(patient.phoneE164)) return 'invalidPhone';
  if (sourceStatus === 'CANCELLED') return 'sourceCancelled';
  return null;
}

export function isEligible(input: EligibilityInput): boolean {
  return blockedReason(input) === null;
}
