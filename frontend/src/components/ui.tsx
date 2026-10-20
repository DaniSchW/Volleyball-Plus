import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';

/** Small set of shared building blocks so every page looks the same. */

export function Card({ title, children, actions }: { title?: ReactNode; children: ReactNode; actions?: ReactNode }) {
  return (
    <section className="vo-rise rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_1px_2px_rgb(15_23_42/0.04),0_6px_20px_-8px_rgb(15_23_42/0.08)]">
      {(title || actions) && (
        <div className="mb-3 flex items-center justify-between gap-3">
          {title && <h2 className="text-base font-semibold tracking-tight text-slate-900">{title}</h2>}
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

export function PageTitle({ children }: { children: ReactNode }) {
  return (
    <h1 className="mb-4 flex items-center gap-2.5 text-2xl font-bold tracking-tight text-slate-900">
      <span aria-hidden="true" className="h-7 w-1.5 shrink-0 rounded-full bg-gradient-to-b from-ball-300 to-ball-500" />
      {children}
    </h1>
  );
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' };

export function Button({ variant = 'primary', className = '', ...props }: ButtonProps) {
  const styles = {
    primary: 'bg-gradient-to-b from-brand-600 to-brand-700 text-white shadow-sm shadow-brand-700/30 hover:from-brand-500 hover:to-brand-600',
    secondary: 'border border-slate-300 bg-white text-slate-800 shadow-sm hover:border-slate-400 hover:bg-slate-50',
    danger: 'border border-red-200 bg-white text-red-700 hover:bg-red-50',
  }[variant];

  return (
    <button
      type="button"
      className={`inline-flex min-h-11 items-center justify-center gap-1.5 rounded-xl px-4 text-sm font-semibold transition active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50 ${styles} ${className}`}
      {...props}
    />
  );
}

export function Field({ label, hint, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string; hint?: string }) {
  const id = useId();
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-slate-700">
        {label}
      </label>
      <input
        id={id}
        className="block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base shadow-[inset_0_1px_1px_rgb(15_23_42/0.03)] transition placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 focus:outline-none disabled:bg-slate-100"
        {...props}
      />
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </div>
  );
}

export function Select({ label, children, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { label: string }) {
  const id = useId();
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-slate-700">
        {label}
      </label>
      <select id={id} className="block min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 text-base transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 focus:outline-none" {...props}>
        {children}
      </select>
    </div>
  );
}

export function Toggle({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (value: boolean) => void; disabled?: boolean }) {
  return (
    <label className={`flex min-h-11 items-center justify-between gap-3 text-sm ${disabled ? 'opacity-60' : 'cursor-pointer'}`}>
      <span>{label}</span>
      {/* A switch: the real checkbox stays for screen readers and keyboards. */}
      <input type="checkbox" className="peer sr-only" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
      <span
        aria-hidden="true"
        className="relative h-7 w-12 shrink-0 rounded-full bg-slate-300 transition peer-checked:bg-brand-600 peer-focus-visible:ring-4 peer-focus-visible:ring-brand-500/30 after:absolute after:left-0.5 after:top-0.5 after:size-6 after:rounded-full after:bg-white after:shadow after:transition peer-checked:after:translate-x-5"
      />
    </label>
  );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'success' | 'error'; children: ReactNode }) {
  const styles = {
    info: 'border-brand-100 bg-brand-50/60 text-slate-700',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-red-200 bg-red-50 text-red-800',
  }[tone];
  return (
    <p role={tone === 'error' ? 'alert' : 'status'} className={`rounded-xl border p-3 text-sm ${styles}`}>
      {children}
    </p>
  );
}

export function Avatar({ url, name, size = 40 }: { url: string | null; name: string; size?: number }) {
  const initials = name
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();

  return url ? (
    <img src={url} alt="" width={size} height={size} className="shrink-0 rounded-full object-cover" style={{ width: size, height: size }} />
  ) : (
    <span
      aria-hidden="true"
      className="inline-flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-100 to-brand-50 text-sm font-semibold text-brand-700 ring-1 ring-brand-100"
      style={{ width: size, height: size }}
    >
      {initials}
    </span>
  );
}

export function Loading() {
  const { t } = useTranslation();
  return <p className="p-6 text-center text-slate-500">{t('tenant.loading')}</p>;
}
