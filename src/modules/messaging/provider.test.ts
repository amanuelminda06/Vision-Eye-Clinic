import { describe, expect, it } from 'vitest';
import {
  buildGroupSmsLink,
  buildSmsLink,
  detectPlatform,
  getSmsProvider,
  ManualLinkProvider,
} from './provider';

describe('buildSmsLink', () => {
  it('builds an android link with ?body=', () => {
    expect(buildSmsLink('+251911111111', 'Hello')).toBe('sms:+251911111111?body=Hello');
  });

  it('uses &body= on iOS', () => {
    expect(buildSmsLink('+251911111111', 'Hi', 'ios')).toBe('sms:+251911111111&body=Hi');
  });

  it('encodes Amharic and spaces', () => {
    const href = buildSmsLink('+251911111111', 'መልካም ልደት', 'android');
    expect(href).toBe(`sms:+251911111111?body=${encodeURIComponent('መልካም ልደት')}`);
    expect(href).not.toContain(' ');
  });

  it('throws on an empty body', () => {
    expect(() => buildSmsLink('+251911111111', '   ')).toThrow();
  });
});

describe('buildGroupSmsLink', () => {
  it('joins recipients with commas and shares one body', () => {
    const href = buildGroupSmsLink(['+251911111111', '+251922222222'], 'Move to 3rd floor', 'android');
    expect(href).toBe('sms:+251911111111,+251922222222?body=Move%20to%203rd%20floor');
  });

  it('uses &body= on iOS', () => {
    const href = buildGroupSmsLink(['+1', '+2'], 'hi', 'ios');
    expect(href.startsWith('sms:+1,+2&body=hi')).toBe(true);
  });

  it('drops blank entries', () => {
    const href = buildGroupSmsLink([' +251911111111 ', '  ', '+251922222222'], 'hi');
    expect(href).toBe('sms:+251911111111,+251922222222?body=hi');
  });

  it('throws with no recipients or an empty body', () => {
    expect(() => buildGroupSmsLink([], 'hi')).toThrow();
    expect(() => buildGroupSmsLink(['  '], 'hi')).toThrow();
    expect(() => buildGroupSmsLink(['+1'], '  ')).toThrow();
  });
});

describe('detectPlatform', () => {
  it('detects iOS, Android, and defaults', () => {
    expect(detectPlatform('Mozilla/5.0 (iPhone; CPU iPhone OS 17)')).toBe('ios');
    expect(detectPlatform('Mozilla/5.0 (Linux; Android 14)')).toBe('android');
    expect(detectPlatform('')).toBe('other');
    expect(detectPlatform(null)).toBe('other');
  });
});

describe('providers', () => {
  it('manual provider cannot bulk individual', () => {
    expect(new ManualLinkProvider().supportsBulkIndividual).toBe(false);
  });

  it('getSmsProvider resolves manual by default and gateway on request', () => {
    expect(getSmsProvider('MANUAL_LINK').mode).toBe('MANUAL_LINK');
    expect(getSmsProvider(undefined).mode).toBe('MANUAL_LINK');
    expect(getSmsProvider('GATEWAY').mode).toBe('GATEWAY');
    expect(getSmsProvider('GATEWAY').supportsBulkIndividual).toBe(true);
  });
});