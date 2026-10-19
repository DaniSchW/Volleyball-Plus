import { useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ApiError, errorText, post } from '../lib/api';
import { Button, Field, Notice, Select } from '../components/ui';

/** Make a slug from the club name: "SSV Ulm 1846" → "ssv-ulm-1846". */
function slugify(name: string): string {
  return name.toLowerCase()
    .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 64);
}

/** A club registers its volleyball department; a platform admin approves it. */
export function RegisterPage() {
  const { t } = useTranslation();
  const [form, setForm] = useState({ club_name: '', slug: '', city: '', first_name: '', last_name: '', gender: 'male', email: '', note: '', accept: false, website: '' });
  const [slugTouched, setSlugTouched] = useState(false);
  const set = (key: keyof typeof form, value: string | boolean) => setForm((f) => ({ ...f, [key]: value }));

  const register = useMutation({ mutationFn: () => post<{ message: string }>('/clubs/register', { ...form, website: form.website || undefined }) });
  const fieldError = (field: string) => (register.error instanceof ApiError ? register.error.errors[field]?.[0] : undefined);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    register.mutate();
  };

  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col gap-6 p-6">
      <div>
        <Link to="/" className="text-sm text-brand-700">‹ {t('app.name')}</Link>
        <h1 className="mt-2 text-2xl font-semibold">{t('register.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('register.intro')}</p>
      </div>

      {register.isSuccess ? (
        <Notice tone="success">{register.data.message}</Notice>
      ) : (
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="font-semibold">{t('register.club')}</h2>
          <Field
            label={t('register.clubName')}
            placeholder={t('register.clubNamePlaceholder')}
            required
            minLength={3}
            maxLength={120}
            value={form.club_name}
            onChange={(e) => {
              set('club_name', e.target.value);
              if (!slugTouched) set('slug', slugify(e.target.value));
            }}
          />
          <Field
            label={t('register.slug')}
            hint={t('register.slugHint', { url: `${window.location.host}/${form.slug || 'kuerzel'}` })}
            required
            pattern="[a-z0-9]+(-[a-z0-9]+)*"
            maxLength={64}
            value={form.slug}
            onChange={(e) => {
              setSlugTouched(true);
              set('slug', e.target.value.toLowerCase());
            }}
          />
          {fieldError('slug') && <Notice tone="error">{fieldError('slug')}</Notice>}
          <Field label={t('register.city')} maxLength={80} value={form.city} onChange={(e) => set('city', e.target.value)} />

          <h2 className="pt-2 font-semibold">{t('register.you')}</h2>
          <p className="-mt-2 text-xs text-slate-500">{t('register.youHint')}</p>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('register.firstName')} required maxLength={80} value={form.first_name} onChange={(e) => set('first_name', e.target.value)} />
            <Field label={t('register.lastName')} required maxLength={80} value={form.last_name} onChange={(e) => set('last_name', e.target.value)} />
          </div>
          <Select label={t('register.gender')} value={form.gender} onChange={(e) => set('gender', e.target.value)}>
            <option value="male">{t('genders.male')}</option>
            <option value="female">{t('genders.female')}</option>
          </Select>
          <Field label={t('login.email')} type="email" autoComplete="email" required value={form.email} onChange={(e) => set('email', e.target.value)} />
          <div>
            <label htmlFor="register-note" className="mb-1 block text-sm font-medium text-slate-700">{t('register.note')}</label>
            <textarea id="register-note" rows={3} maxLength={1000} className="w-full rounded-lg border border-slate-300 p-3 text-base" value={form.note} onChange={(e) => set('note', e.target.value)} />
          </div>
          {/* Honeypot against bots – hidden from people. */}
          <input type="text" name="website" tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" value={form.website} onChange={(e) => set('website', e.target.value)} />
          <label className="flex items-start gap-3 text-sm">
            <input type="checkbox" required className="mt-0.5 size-5 shrink-0 accent-brand-700" checked={form.accept} onChange={(e) => set('accept', e.target.checked)} />
            <span>{t('register.accept')}</span>
          </label>
          {register.isError && !fieldError('slug') && <Notice tone="error">{errorText(register.error, t('errors.generic'))}</Notice>}
          <Button type="submit" className="w-full" disabled={register.isPending}>{t('register.submit')}</Button>
        </form>
      )}
    </div>
  );
}
