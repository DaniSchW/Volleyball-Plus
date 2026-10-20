import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { errorText, put, type LegalDetails } from '../../lib/api';
import { useLegal } from '../../pages/LegalPage';
import { Button, Card, Field, Loading, Notice } from '../ui';

const FIELDS = ['name', 'company', 'street', 'postal_code', 'city', 'country', 'email', 'phone', 'vat_id'] as const;
const REQUIRED = new Set(['name', 'street', 'postal_code', 'city', 'email']);

/** Superadmins: operator details used by imprint, privacy policy and terms. */
export function LegalForm() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const legal = useLegal();
  const [form, setForm] = useState<Record<(typeof FIELDS)[number], string> | null>(null);
  useEffect(() => {
    if (legal.data && !form) setForm(Object.fromEntries(FIELDS.map((f) => [f, legal.data[f] ?? ''])) as Record<(typeof FIELDS)[number], string>);
  }, [legal.data, form]);

  const save = useMutation({
    mutationFn: () => put<{ data: LegalDetails }>('/platform/legal', Object.fromEntries(FIELDS.map((f) => [f, form![f] || null]))),
    onSuccess: ({ data }) => queryClient.setQueryData(['legal'], data),
  });

  if (!form) return <Loading />;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <Card title={t('platform.legal.title')}>
      <form onSubmit={submit} className="space-y-3">
        <p className="text-sm text-slate-600">{t('platform.legal.hint')}</p>
        {FIELDS.map((field) => (
          <Field
            key={field}
            label={t(`platform.legal.fields.${field}`)}
            required={REQUIRED.has(field)}
            type={field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text'}
            value={form[field]}
            onChange={(e) => setForm({ ...form, [field]: e.target.value })}
          />
        ))}
        {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
        {save.isSuccess && <Notice tone="success">{t('common.saved')}</Notice>}
        <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
        <p className="text-xs text-slate-500">
          <Link className="text-brand-700 underline" to="/impressum">{t('legal.imprint')}</Link> · <Link className="text-brand-700 underline" to="/datenschutz">{t('legal.privacy')}</Link> · <Link className="text-brand-700 underline" to="/agb">{t('legal.terms')}</Link>
        </p>
        <Notice>{t('platform.legal.disclaimer')}</Notice>
      </form>
    </Card>
  );
}
