import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { del, errorText, put, type PersonDetail } from '../../lib/api';
import { useTenantPath } from '../../lib/queries';
import { Button, Card, Field, Notice } from '../ui';
import { RefereeBadge } from './RefereeBadge';

/** Referee license with expiry date; trainers see it in the roster. */
export function RefereeLicenseCard({ person }: { person: PersonDetail }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const license = person.referee_license;
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({ level: license?.level ?? '', license_number: license?.license_number ?? '', valid_until: license?.valid_until ?? '' });

  const done = ({ data }: { data: PersonDetail }) => {
    queryClient.setQueryData(['person', person.id], data);
    setEditing(false);
  };
  const save = useMutation({
    mutationFn: () => put<{ data: PersonDetail }>(tp(`persons/${person.id}/referee-license`), { ...form, license_number: form.license_number || null }),
    onSuccess: done,
  });
  const remove = useMutation({ mutationFn: () => del<{ data: PersonDetail }>(tp(`persons/${person.id}/referee-license`)), onSuccess: done });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  if (!person.can_edit_referee_license && !license) return null;

  return (
    <Card title={t('referee.title')}>
      {!editing ? (
        <div className="space-y-3">
          {license ? (
            <div className="space-y-1 text-sm">
              <RefereeBadge license={license} />
              {license.license_number && <p className="text-slate-600">{t('referee.number')}: {license.license_number}</p>}
              {license.expires_soon && <p className="text-amber-800">{t('referee.expiresSoon')}</p>}
            </div>
          ) : (
            <p className="text-sm text-slate-500">{t('referee.none')}</p>
          )}
          {person.can_edit_referee_license && (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setEditing(true)}>{license ? t('referee.edit') : t('referee.add')}</Button>
              {license && <Button variant="danger" onClick={() => window.confirm(t('referee.deleteConfirm')) && remove.mutate()}>{t('referee.delete')}</Button>}
            </div>
          )}
        </div>
      ) : (
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('referee.level')} required maxLength={20} value={form.level} onChange={(e) => setForm({ ...form, level: e.target.value })} placeholder="D, C, BK …" />
            <Field label={t('referee.validUntil')} type="date" required value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} />
          </div>
          <Field label={t('referee.number')} maxLength={40} value={form.license_number} onChange={(e) => setForm({ ...form, license_number: e.target.value })} hint={t('invitations.optional')} />
          {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
          <div className="flex gap-2">
            <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
            <Button variant="secondary" onClick={() => setEditing(false)}>{t('common.cancel')}</Button>
          </div>
        </form>
      )}
    </Card>
  );
}
