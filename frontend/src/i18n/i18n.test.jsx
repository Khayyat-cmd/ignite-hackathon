import { describe, expect, it } from 'vitest';
import { renderToStaticMarkup } from 'react-dom/server';
import App from '../App';
import OperatorPanel from '../components/OperatorPanel';
import { I18nContext, LOCALES, createTranslator } from './index';
import { ar, en } from './strings';

const PLACEHOLDER = /\{(\w+)\}/g;

function placeholders(value) {
  return [...String(value).matchAll(PLACEHOLDER)].map((match) => match[1]).sort();
}

const zone = { id: 'east', name: 'East Entrance', risk_level: 'critical', area_sqm: 900, warning_density: 1, critical_density: 2, latest_reading: { deviceCount: 2100, densityPerSquareMeter: 2.33 }, boundary: [{ latitude: 33.9, longitude: 35.5 }, { latitude: 33.9, longitude: 35.501 }, { latitude: 33.901, longitude: 35.501 }, { latitude: 33.901, longitude: 35.5 }] };
const base = { attendeeCount: 3000, quality: { located: 2100, outside: 900 }, zones: [zone], responders: [], incidents: [], status: 'running', stale: false };

function withLocale(locale, node) {
  const value = { ...createTranslator(locale), setLocale: () => {} };
  return renderToStaticMarkup(<I18nContext.Provider value={value}>{node}</I18nContext.Provider>);
}

function render(locale, data) {
  return withLocale(locale, <OperatorPanel data={data} />);
}

describe('translation catalogue', () => {
  it('translates every English key into Arabic', () => {
    const missing = Object.keys(en).filter((key) => !(key in ar));
    expect(missing).toEqual([]);
  });

  it('keeps the same placeholders in both languages', () => {
    const mismatched = Object.keys(en)
      .filter((key) => placeholders(en[key]).join() !== placeholders(ar[key]).join());
    expect(mismatched).toEqual([]);
  });

  it('leaves no Arabic string still written in Latin script', () => {
    // A key copied across untranslated is the failure this catches. Placeholder
    // names are Latin by definition, so they come out before the check.
    const untranslated = Object.keys(en)
      .filter((key) => /[A-Za-z]/.test(String(ar[key]).replace(PLACEHOLDER, '')));
    expect(untranslated).toEqual([]);
  });
});

describe('translator', () => {
  it('reports the writing direction for each locale', () => {
    expect(LOCALES).toEqual(['en', 'ar']);
    expect(createTranslator('en').dir).toBe('ltr');
    expect(createTranslator('ar').dir).toBe('rtl');
  });

  it('selects the Arabic plural form the count actually calls for', () => {
    const t = createTranslator('ar').t;
    expect(t('attention.count', { count: 1 })).toBe('حادثة واحدة بانتظار موافقة الإرسال');
    expect(t('attention.count', { count: 2 })).toBe('حادثتان بانتظار موافقة الإرسال');
    expect(t('attention.count', { count: 5 })).toBe('5 حوادث بانتظار موافقة الإرسال');
    expect(t('attention.count', { count: 30 })).toBe('30 حادثة بانتظار موافقة الإرسال');
    expect(createTranslator('en').t('attention.count', { count: 2 })).toBe('2 incidents awaiting dispatch approval');
  });

  it('keeps Latin digits in Arabic so figures read the same in both languages', () => {
    expect(createTranslator('ar').n(2100)).toBe('2,100');
    expect(createTranslator('ar').time('2026-09-05T12:00:00Z')).toMatch(/^\d{2}:\d{2}:\d{2}$/);
  });

  it('humanises backend vocabulary this catalogue has never seen', () => {
    const { term, kind, role } = createTranslator('ar');
    expect(term('critical')).toBe('حرج');
    expect(term('some_new_status')).toBe('some new status');
    expect(kind('en_route')).toBe('في الطريق');
    expect(role('crowd_marshal')).toBe('منظّم حشود');
  });
});

describe('console in Arabic', () => {
  it('renders the workspace in Arabic without leaking raw keys', () => {
    const incident = { id: 'i1', zone_id: 'east', active_zone_id: 'east', status: 'awaiting_approval' };
    const html = render('ar', { ...base, incidents: [incident] });
    expect(html).toContain('أحوال المناطق');
    expect(html).toContain('فريق الاستجابة');
    expect(html).toContain('حادثة واحدة بانتظار موافقة الإرسال');
    expect(html).toContain('بانتظار الموافقة');
    expect(html).not.toContain('Zone conditions');
    expect(html).not.toMatch(/>[a-z]+\.[a-z]+</i);
  });

  it('translates the shell an operator sees before any event exists', () => {
    // The first paint has no snapshot, so this is the top bar, the status
    // strip and the setup card — surfaces the workspace test never reaches.
    const html = withLocale('ar', <App />);
    expect(html).toContain('منصة العمليات');
    expect(html).toContain('بيانات تدريبية');
    expect(html).toContain('العربية');
    expect(html).toContain('English');
    expect(html).not.toContain('Operations console');
    expect(html).not.toContain('Rehearsal data');
    expect(html).not.toMatch(/>[a-z]+\.[a-z.]+</i);
  });

  it('keeps the risk classes off the translated text', () => {
    const html = render('ar', base);
    expect(html).toContain('badge-critical');
    expect(html).toContain('حرج');
    expect(html).toContain('2,100');
  });
});
