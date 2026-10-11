/**
 * SMS delivery seam. This build ships only the manual `sms:`-link provider —
 * the receptionist sends from the clinic phone. A gateway can be added later
 * by implementing `SmsProvider` and selecting it with the `sms_mode` setting,
 * without touching callers.
 */

export type SmsMode = 'MANUAL_LINK' | 'GATEWAY';

export type Platform = 'android' | 'ios' | 'other';

/** A prepared message handed to the provider. `body` is already rendered. */
export type SmsMessage = {
  phoneE164: string;
  body: string;
};

export type SmsDispatch =
  | { mode: 'MANUAL_LINK'; kind: 'link'; href: string }
  | { mode: 'GATEWAY'; kind: 'sent'; providerId: string };

export interface SmsProvider {
  readonly mode: SmsMode;
  /**
   * Whether the provider can prepare messages for a whole list at once
   * (bulk/individual). The manual `sms:` link provider cannot — it returns one
   * link per recipient.
   */
  readonly supportsBulkIndividual: boolean;
  /** Prepares delivery for one message. Manual mode returns an `sms:` link. */
  prepare(message: SmsMessage, platform?: Platform): SmsDispatch;
}

/** Detects the handset platform from a user agent; falls back to Android. */
export function detectPlatform(userAgent: string | null | undefined): Platform {
  const ua = (userAgent ?? '').toLowerCase();
  if (/iphone|ipad|ipod/.test(ua)) return 'ios';
  if (/android/.test(ua)) return 'android';
  return 'other';
}

/**
 * Builds an `sms:` link. Android uses `?body=`, iOS uses `&body=`; `other`
 * falls back to the Android form. The body is percent-encoded (Amharic and
 * line breaks are safe). Throws on an empty body, which would otherwise open
 * the Messages app with a blank message.
 */
export function buildSmsLink(
  phoneE164: string,
  body: string,
  platform: Platform = 'android',
): string {
  if (!body.trim()) throw new Error('Cannot build an SMS link with an empty body');
  const separator = platform === 'ios' ? '&' : '?';
  return `sms:${phoneE164}${separator}body=${encodeURIComponent(body)}`;
}

/**
 * Builds a group `sms:` link addressed to several numbers. Handsets accept a
 * comma-separated recipient list; the body is shared. Throws when there are no
 * recipients or the body is empty.
 */
export function buildGroupSmsLink(
  phones: string[],
  body: string,
  platform: Platform = 'android',
): string {
  const recipients = phones.map((phone) => phone.trim()).filter(Boolean);
  if (recipients.length === 0) throw new Error('Cannot build a group SMS link with no recipients');
  if (!body.trim()) throw new Error('Cannot build an SMS link with an empty body');
  const separator = platform === 'ios' ? '&' : '?';
  return `sms:${recipients.join(',')}${separator}body=${encodeURIComponent(body)}`;
}

export class ManualLinkProvider implements SmsProvider {
  readonly mode = 'MANUAL_LINK' as const;
  readonly supportsBulkIndividual = false;

  prepare(message: SmsMessage, platform: Platform = 'android'): SmsDispatch {
    return {
      mode: 'MANUAL_LINK',
      kind: 'link',
      href: buildSmsLink(message.phoneE164, message.body, platform),
    };
  }
}

/** Placeholder so callers can be wired before a real gateway exists. */
export class GatewayProvider implements SmsProvider {
  readonly mode = 'GATEWAY' as const;
  readonly supportsBulkIndividual = true;

  prepare(): never {
    throw new Error('SMS gateway provider is not implemented');
  }
}

const MANUAL = new ManualLinkProvider();

/** Resolves the provider for a mode. Unknown/blank modes fall back to manual. */
export function getSmsProvider(mode: string | null | undefined): SmsProvider {
  if (mode === 'GATEWAY') return new GatewayProvider();
  return MANUAL;
}
