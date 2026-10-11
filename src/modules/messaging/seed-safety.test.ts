import { describe, expect, it } from 'vitest';
import { REAL_SERVICES } from '../../../prisma/seed-services';
import { TEMPLATES } from '../../../prisma/seed-messages';
import { LIBRARY_PAIRS } from '../../../prisma/seed-library';

const BANNED_PHRASES = ['diabetes', 'blood pressure', 'የስኳር', 'ደም ግፊት'];

function smsBodies(): string[] {
  const bodies: string[] = [];
  for (const tpl of Object.values(TEMPLATES)) {
    bodies.push(tpl.en, tpl.am);
  }
  for (const item of LIBRARY_PAIRS) {
    bodies.push(item.bodyEn, item.bodyAm);
  }
  return bodies;
}

describe('SMS seed contentment', () => {
  const bodies = smsBodies();

  it('never mentions a service name (English or Amharic)', () => {
    const forbidden = REAL_SERVICES.flatMap((s) => [s.nameEn, s.nameAm ?? '']).filter(Boolean);
    for (const body of bodies) {
      const lowered = body.toLowerCase();
      for (const name of forbidden) {
        expect(lowered, `body must not contain service name "${name}"`).not.toContain(
          name.toLowerCase(),
        );
      }
    }
  });

  it('never mentions diabetes or high blood pressure (English or Amharic)', () => {
    for (const body of bodies) {
      const lowered = body.toLowerCase();
      for (const phrase of BANNED_PHRASES) {
        expect(lowered, `body must not contain "${phrase}"`).not.toContain(phrase.toLowerCase());
      }
    }
  });
});