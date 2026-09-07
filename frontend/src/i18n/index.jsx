import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { catalogues, en } from './strings';

export const LOCALES = ['en', 'ar'];
export const STORAGE_KEY = 'aman.locale';

// Arabic keeps Latin digits: a control room reads counts and distances against
// the tabular figures on the wall, and mixing numeral systems between the two
// languages makes the same reading look like a different number.
const NUMBER_LOCALE = { en: 'en-US', ar: 'ar-u-nu-latn' };

const RTL = new Set(['ar']);

function fill(template, vars) {
  if (!vars) return template;
  return template.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match));
}

// Humanised fallback for a value the backend introduced after this catalogue
// was written: better a readable English word than a raw key on screen.
function humanise(value) {
  return String(value).replaceAll('_', ' ');
}

export function createTranslator(locale) {
  const table = catalogues[locale] || en;
  const plural = new Intl.PluralRules(locale);
  const number = new Intl.NumberFormat(NUMBER_LOCALE[locale] || locale);

  function lookup(key, vars) {
    if (vars && typeof vars.count === 'number') {
      const category = plural.select(vars.count);
      const form = table[`${key}_${category}`] ?? table[`${key}_other`];
      if (form !== undefined) return form;
    }
    return table[key] ?? en[key];
  }

  function vocab(prefix, value) {
    return table[`${prefix}.${value}`] ?? en[`${prefix}.${value}`] ?? humanise(value);
  }

  function t(key, vars) {
    const template = lookup(key, vars);
    if (template === undefined) return key;
    return fill(template, vars);
  }

  return {
    locale,
    dir: RTL.has(locale) ? 'rtl' : 'ltr',
    t,
    // Formatted count. Callers pass raw numbers; nothing calls toLocaleString
    // directly, so a locale change moves every figure at once.
    n: (value) => (Number.isFinite(Number(value)) ? number.format(Number(value)) : '—'),
    // Backend vocabulary — statuses, risk levels, roles, message kinds —
    // translated by value with a humanised fallback, so a value added to the
    // API after this catalogue was written still reads as words on screen.
    term: (value) => (value == null ? t('term.unknown') : vocab('term', value)),
    role: (value) => (value == null ? '' : vocab('role', value)),
    kind: (value) => (value == null ? '' : vocab('kind', value)),
    time: (value) => (value
      ? new Date(value).toLocaleTimeString(NUMBER_LOCALE[locale] || locale, { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })
      : t('time.noReading')),
  };
}

export function readStoredLocale() {
  if (typeof window === 'undefined') return 'en';
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);
    return LOCALES.includes(saved) ? saved : 'en';
  } catch {
    return 'en';
  }
}

// English outside a provider, so server-rendered tests and the crash screen
// still produce readable copy instead of raw keys.
const I18nContext = createContext({ ...createTranslator('en'), setLocale: () => {} });

export function I18nProvider({ children }) {
  const [locale, setLocale] = useState(readStoredLocale);
  const value = useMemo(() => ({ ...createTranslator(locale), setLocale }), [locale]);

  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = value.dir;
    try { window.localStorage.setItem(STORAGE_KEY, locale); } catch { /* preference only */ }
  }, [locale, value.dir]);

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n() {
  return useContext(I18nContext);
}

// Both languages stay visible and clickable — a single toggle hides which one
// you are about to get. Each is written in its own script, the convention that
// lets a reader find their language without reading the other.
export const LOCALE_NAMES = { en: 'English', ar: 'العربية' };

export function LanguageToggle() {
  const { locale, setLocale, t } = useI18n();
  return <div className="lang-switch" role="group" aria-label={t('lang.aria')}>
    {LOCALES.map((code) => <button
      key={code}
      type="button"
      lang={code}
      className={code === locale ? 'active' : ''}
      aria-pressed={code === locale}
      onClick={() => setLocale(code)}
    >{LOCALE_NAMES[code]}</button>)}
  </div>;
}

export { I18nContext };
