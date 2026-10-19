import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';

/** Small set of shared building blocks so every page looks the same. */

export function Card({ title, children, actions }: { title?: ReactNode; children: ReactNode; actions?: ReactNode }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-4">
      {(title || actions) && (
        <div className="mb-3 flex items-center justify-between gap-3">
          {title && <h2 className="text-base font-semibold">{title}</h2>}
          {actions}
        </div>
      )}
      {children}
    </section>
  );
}

export function PageTitle({ children }: { children: ReactNode }) {
  return (
    <h1 className="mb-4 flex items-center gap-2 text-xl font-semibold">
      <span aria-hidden="true" className="h-6 w-1.5 shrink-0 rounded-full bg-ball-400" />
      {children}
    </h1>
  );
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'danger' };

export function Button({ variant = 'primary', className = '', ...props }: ButtonProps) {
  const styles = {
    primary: 'bg-brand-700 text-white hover:bg-brand-600',
    secondary: 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50',
    danger: 'border border-red-200 bg-white text-red-700 hover:bg-red-50',
  }[variant];

  return (
    <button
      type="button"
      className={`inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-sm font-medium disabled:opacity-50 ${styles} ${className}`}
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
        className="block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-base disabled:bg-slate-100"
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
      <select id={id} className="block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-base" {...props}>
        {children}
      </select>
    </div>
  );
}

export function Toggle({ label, checked, onChange, disabled }: { label: string; checked: boolean; onChange: (value: boolean) => void; disabled?: boolean }) {
  return (
    <label className="flex min-h-11 items-center justify-between gap-3 text-sm">
      <span>{label}</span>
      <input type="checkbox" className="size-5 accent-brand-700" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
    </label>
  );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'success' | 'error'; children: ReactNode }) {
  const styles = {
    info: 'border-slate-200 bg-slate-50 text-slate-700',
    success: 'border-green-200 bg-green-50 text-green-800',
    error: 'border-red-200 bg-red-50 text-red-800',
  }[tone];
  return (
    <p role={tone === 'error' ? 'alert' : 'status'} className={`rounded-lg border p-3 text-sm ${styles}`}>
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
      className="inline-flex shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700"
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
