import i18n from '../i18n';

const locale = () => (i18n.resolvedLanguage === 'en' ? 'en-GB' : 'de-DE');

/** 123456 → "1.234,56 €" */
export function formatMoney(cents: number): string {
  return new Intl.NumberFormat(locale(), { style: 'currency', currency: 'EUR' }).format(cents / 100);
}

/** "12,50" or "12.50" → 1250; null when it is not an amount. */
export function parseMoney(input: string): number | null {
  const value = input.trim().replace(/\s|€/g, '');
  if (!/^\d{1,9}([.,]\d{1,2})?$/.test(value)) return null;
  return Math.round(Number(value.replace(',', '.')) * 100);
}
