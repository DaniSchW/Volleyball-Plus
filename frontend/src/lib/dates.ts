import i18n from '../i18n';

const locale = () => (i18n.resolvedLanguage === 'en' ? 'en-GB' : 'de-DE');

/** "Di., 20.10." */
export function formatDay(iso: string): string {
  return new Intl.DateTimeFormat(locale(), { weekday: 'short', day: '2-digit', month: '2-digit' }).format(new Date(iso));
}

/** "Dienstag, 20. Oktober 2026" */
export function formatLongDay(iso: string): string {
  return new Intl.DateTimeFormat(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso));
}

/** "19:30" */
export function formatTime(iso: string): string {
  return new Intl.DateTimeFormat(locale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

/** "20.10., 17:30" */
export function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat(locale(), { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

/** Calendar date "2026-12-01" → "01.12.2026" (no time zone shift). */
export function formatDate(date: string): string {
  const [y, m, d] = date.split('-').map(Number);
  return new Intl.DateTimeFormat(locale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(y, m - 1, d));
}

const pad = (n: number) => String(n).padStart(2, '0');

/** ISO string → value for <input type="datetime-local"> in the browser's time zone. */
export function toLocalInput(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Adds minutes to a datetime-local value. */
export function addMinutes(local: string, minutes: number): string {
  const d = new Date(local);
  d.setMinutes(d.getMinutes() + minutes);
  return toLocalInput(d.toISOString());
}
