/**
 * Email delivery seam for the daily "still unsent" digest. Only a console
 * logger ships in this version; swapping in SMTP later is a `getEmailProvider`
 * change, not a caller change.
 */

export type EmailMessage = {
  to: string;
  subject: string;
  body: string;
};

export interface EmailProvider {
  readonly mode: 'CONSOLE' | 'SMTP';
  send(message: EmailMessage): Promise<void>;
}

export class ConsoleEmailProvider implements EmailProvider {
  readonly mode = 'CONSOLE' as const;

  async send(message: EmailMessage): Promise<void> {
    if (process.env.NODE_ENV === 'test') return;
    console.log(`[messaging:email] → ${message.to}: ${message.subject}\n${message.body}`);
  }
}

const CONSOLE_PROVIDER = new ConsoleEmailProvider();

export function getEmailProvider(): EmailProvider {
  return CONSOLE_PROVIDER;
}
