import { useState, type FormEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, post, type Hall, type HallBooking, type HallClosure, type HallClosureEvent } from '../../lib/api';
import { formatDate, formatDateTime, todayDate } from '../../lib/dates';
import { useTenantPath } from '../../lib/queries';
import { TypeIcon } from '../events/EventCard';
import { Button, Field, Notice, SaveBar } from '../ui';

type PlanResponse = { data: Hall[]; booking: HallBooking | null };

/** Current and coming closures of a hall; admins can lift them. */
export function ClosureList({ hall, admin, onUpdate }: { hall: Hall; admin: boolean; onUpdate: (r: PlanResponse) => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const lift = useMutation({ mutationFn: (id: string) => del<PlanResponse>(tp(`hall-closures/${id}`)), onSuccess: onUpdate });
  if (hall.closures.length === 0) return null;

  return (
    <ul className="space-y-1">
      {hall.closures.map((c: HallClosure) => (
        <li key={c.id} className="flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 ring-1 ring-red-200">
          <span aria-hidden="true">🚫</span>
          <span className="min-w-0 flex-1">
            <span className="font-medium">{t('halls.closure.entry', { from: formatDate(c.starts_on), until: formatDate(c.ends_on) })}</span>
            {c.reason && <> · {c.reason}</>}
            <span className="block text-xs text-red-700">{t(c.mode === 'delete' ? 'halls.closure.modeShortDelete' : 'halls.closure.modeShortCancel')}</span>
          </span>
          {admin && (
            <button
              type="button"
              className="shrink-0 text-xs font-medium underline"
              onClick={() => window.confirm(t('halls.closure.liftConfirm')) && lift.mutate(c.id)}
            >
              {t('halls.closure.lift')}
            </button>
          )}
        </li>
      ))}
    </ul>
  );
}

/**
 * Close a hall for some days: shows the events there in that period (trainings
 * preselected) and cancels or deletes the chosen ones.
 */
export function ClosureForm({ hall, onDone, onUpdate }: { hall: Hall; onDone: () => void; onUpdate: (r: PlanResponse) => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [from, setFrom] = useState(todayDate());
  const [until, setUntil] = useState(todayDate());
  const [reason, setReason] = useState('');
  const [mode, setMode] = useState<'cancel' | 'delete'>('cancel');
  // Ticked by hand; untouched events follow the suggestion (trainings that still take place).
  const [picked, setPicked] = useState<Record<string, boolean>>({});
  const valid = /^\d{4}-\d{2}-\d{2}$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(until) && from <= until;

  const affected = useQuery({
    queryKey: ['hall-closure-preview', hall.id, from, until],
    queryFn: async () => (await api<{ data: HallClosureEvent[] }>(tp(`halls/${hall.id}/closures/preview`), { method: 'POST', body: { starts_on: from, ends_on: until } })).data,
    enabled: valid,
  });
  const chosen = (affected.data ?? []).filter((e) => picked[e.id] ?? e.suggested);

  const save = useMutation({
    mutationFn: () => post<PlanResponse>(tp(`halls/${hall.id}/closures`), { starts_on: from, ends_on: until, reason: reason || null, mode, event_ids: chosen.map((e) => e.id) }),
    onSuccess: (r) => {
      onUpdate(r);
      onDone();
    },
  });

  const submit = (e: FormEvent) => {
    e.preventDefault();
    save.mutate();
  };

  return (
    <form onSubmit={submit} className="space-y-3 rounded-xl border border-red-200 p-3">
      <h3 className="font-semibold">🚫 {t('halls.closure.close')}</h3>
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('halls.closure.from')} type="date" required value={from} onChange={(e) => { setFrom(e.target.value); if (e.target.value > until) setUntil(e.target.value); }} />
        <Field label={t('halls.closure.until')} type="date" required min={from} value={until} onChange={(e) => setUntil(e.target.value)} />
      </div>
      <Field label={t('halls.closure.reason')} maxLength={200} placeholder={t('halls.closure.reasonPlaceholder')} value={reason} onChange={(e) => setReason(e.target.value)} />

      <fieldset className="min-w-0 space-y-1">
        <legend className="mb-1 text-sm font-medium text-slate-700">{t('halls.closure.mode')}</legend>
        {(['cancel', 'delete'] as const).map((value) => (
          <label key={value} className={`flex min-h-11 items-start gap-2 rounded-lg border px-3 py-2 text-sm ${mode === value ? 'border-brand-500 bg-brand-50' : 'border-slate-200'}`}>
            <input type="radio" name={`closure-mode-${hall.id}`} className="mt-1 accent-brand-solid" checked={mode === value} onChange={() => setMode(value)} />
            <span>{t(value === 'cancel' ? 'halls.closure.modeCancel' : 'halls.closure.modeDelete')}</span>
          </label>
        ))}
        <p className="text-xs text-slate-500">{t('halls.closure.seriesHint')}</p>
      </fieldset>

      <fieldset className="min-w-0 space-y-1">
        <legend className="mb-1 text-sm font-medium text-slate-700">{t('halls.closure.affected')}</legend>
        <p className="text-xs text-slate-500">{t('halls.closure.affectedHint')}</p>
        {affected.isPending && valid && <p className="text-sm text-slate-500">…</p>}
        {affected.data?.length === 0 && <p className="text-sm text-slate-500">{t('halls.closure.none')}</p>}
        {affected.data?.map((e) => (
          <label key={e.id} className="flex min-h-11 items-center gap-2 border-b border-slate-100 py-1 text-sm last:border-0">
            <input type="checkbox" className="size-5 accent-brand-solid" checked={picked[e.id] ?? e.suggested} onChange={(ev) => setPicked({ ...picked, [e.id]: ev.target.checked })} />
            <TypeIcon type={e.type} />
            <span className="min-w-0 flex-1">
              <span className="font-medium">{formatDateTime(e.starts_at)}</span> · {e.title}
              <span className="block text-xs text-slate-500">
                {e.teams.join(', ')}
                {e.status === 'cancelled' && <> · {t('events.cancelled')}</>}
              </span>
            </span>
          </label>
        ))}
      </fieldset>

      {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
      <SaveBar>
        <Button type="submit" variant="danger" disabled={!valid || save.isPending || affected.isFetching}>
          {chosen.length === 0
            ? t('halls.closure.submitNone')
            : t(mode === 'delete' ? 'halls.closure.submitDelete' : 'halls.closure.submitCancel', { count: chosen.length })}
        </Button>
        <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
      </SaveBar>
    </form>
  );
}

/** Closure covering the day, if any (for hall suggestions in the event form). */
export const closureOn = (hall: Hall, date: string) => hall.closures.find((c) => c.starts_on <= date && c.ends_on >= date) ?? null;
