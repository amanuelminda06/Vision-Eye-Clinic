/** Limits for the public feedback form (contact page). */
export const MAX_FEEDBACK_NAME = 120;
export const MAX_FEEDBACK_EMAIL = 254;
export const MAX_FEEDBACK_TEXT = 1000;

/** `createdBy` value for feedback that arrives through the public website. */
export const PUBLIC_FEEDBACK_ACTOR = 'public-website';

/** Spam guard: 3 submissions per hour per IP (in addition to the honeypot). */
export const PUBLIC_FEEDBACK_RATE_MAX = 3;
export const PUBLIC_FEEDBACK_RATE_WINDOW_MS = 60 * 60_000;

/** Newest feedback returned by the CMS list. */
export const FEEDBACK_LIST_LIMIT = 200;
