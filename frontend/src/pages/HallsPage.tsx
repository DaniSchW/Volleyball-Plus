import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, patch, post, type Hall, type HallBooking, type HallSlot, type Team } from '../lib/api';
import { formatDate, weekdayName } from '../lib/dates';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, Select } from '../components/ui';

type PlanResponse = { data: Hall[]; booking: HallBooking | null };

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

/**
 * Halls with their available times and the teams' training times. Trainers
 * read, admins edit; overlapping bookings are marked, not blocked.
 */
export function HallsPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const halls = useQuery({ queryKey: ['halls', slug], queryFn: async () => (await api<PlanResponse>(tp('halls'))).data });

  if (halls.isPending || me.isPending) return <Loading />;
  if (halls.isError) return <Notice tone="error">{errorText(halls.error, t('errors.generic'))}</Notice>;

  const admin = !!me.data?.is_admin;
  const conflicts = halls.data.flatMap((h) => h.bookings).filter((b) => b.conflicts.length > 0).length;

  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.halls')}</PageTitle>
      <p className="text-sm text-slate-600">{admin ? t('halls.hintAdmin') : t('halls.hint')}</p>
      {conflicts > 0 && <Notice tone="error">{t('halls.conflictCount', { count: conflicts })}</Notice>}
      {halls.data.length === 0 && <Notice>{t('halls.none')}</Notice>}
      {halls.data.map((hall) => <HallCard key={hall.id} hall={hall} admin={admin} />)}
      {admin && <HallForm />}
    </div>
  );
}

function usePlanUpdate() {
  const slug = useTenantSlug();
  const queryClient = useQueryClient();
  return (result: PlanResponse) => {
    queryClient.setQueryData(['halls', slug], result.data);
    void queryClient.invalidateQueries({ queryKey: ['hall-times'] });
  };
}

/** "Teil 1 + 2 von 3" or "Ganze Halle" */
export const courtsLabel = (courts: number[] | null, total: number | null, t: (key: string, o?: Record<string, unknown>) => string) =>
  courts === null ? t('halls.wholeHall') : t(total ? 'halls.partsOf' : 'halls.parts', { count: courts.length, parts: courts.join(' + '), total });

const period = (from: string | null, until: string | null, t: (key: string, o?: Record<string, string>) => string) =>
  from && until ? t('halls.between', { from: formatDate(from), until: formatDate(until) })
    : from ? t('halls.since', { from: formatDate(from) })
      : until ? t('halls.until', { until: formatDate(until) }) : null;

function HallCard({ hall, admin }: { hall: Hall; admin: boolean }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const update = usePlanUpdate();
  const [editing, setEditing] = useState<'hall' | 'slot' | 'booking' | null>(null);
  const [booking, setBooking] = useState<HallBooking | null>(null);
  const [warning, setWarning] = useState<HallBooking | null>(null);

  const removeHall = useMutation({ mutationFn: () => del<PlanResponse>(tp(`halls/${hall.id}`)), onSuccess: update });
  const removeSlot = useMutation({ mutationFn: (id: string) => del<PlanResponse>(tp(`hall-slots/${id}`)), onSuccess: update });
  const removeBooking = useMutation({ mutationFn: (id: string) => del<PlanResponse>(tp(`hall-bookings/${id}`)), onSuccess: update });

  const days = WEEKDAYS.map((day) => ({
    day,
    slots: hall.slots.filter((s) => s.weekday === day),
    bookings: hall.bookings.filter((b) => b.weekday === day),
  })).filter((d) => d.slots.length > 0 || d.bookings.length > 0);

  const close = () => {
    setEditing(null);
    setBooking(null);
  };

  return (
    <Card
      title={<>🏟️ {hall.name}</>}
      actions={admin && editing === null ? (
        <button type="button" className="rounded px-2 py-1 text-sm text-brand-700" onClick={() => setEditing('hall')}>{t('halls.edit')}</button>
      ) : undefined}
    >
      {editing === 'hall' ? (
        <div className="space-y-3">
          <HallForm hall={hall} onDone={close} />
          <Button variant="danger" onClick={() => window.confirm(t('halls.deleteConfirm', { name: hall.name })) && removeHall.mutate()}>{t('halls.delete')}</Button>
        </div>
      ) : (
        <div className="space-y-3">
          <p className="text-sm text-slate-600">
            {t('halls.courts', { count: hall.courts })}
            {hall.address && <> · <a className="text-brand-700 underline" target="_blank" rel="noreferrer" href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(hall.address)}`}>{hall.address}</a></>}
          </p>
          {hall.contact && (
            <p className="text-sm text-slate-600">
              {t('halls.contact')}:
              {hall.contact.name && <> {hall.contact.name}</>}
              {hall.contact.phone && <>{hall.contact.name ? ' ·' : ''} <a className="text-brand-700 underline" href={`tel:${hall.contact.phone.replace(/\s/g, '')}`}>{hall.contact.phone}</a></>}
              {hall.contact.email && <>{hall.contact.name || hall.contact.phone ? ' ·' : ''} <a className="text-brand-700 underline" href={`mailto:${hall.contact.email}`}>{hall.contact.email}</a></>}
            </p>
          )}
          {hall.notes && <p className="whitespace-pre-line text-sm text-slate-600">{hall.notes}</p>}

          {warning && <BookingWarning booking={warning} onClose={() => setWarning(null)} />}

          {days.length === 0 ? (
            <p className="text-sm text-slate-500">{t('halls.empty')}</p>
          ) : (
            <ul className="space-y-3">
              {days.map(({ day, slots, bookings }) => (
                <li key={day}>
                  <h3 className="text-sm font-semibold">{weekdayName(day)}</h3>
                  {slots.length > 0 && (
                    <p className="text-xs text-slate-500">
                      {t('halls.trainingTimes')}:{' '}
                      {slots.map((s, i) => (
                        <span key={s.id}>
                          {i > 0 && ', '}
                          {s.start_time}–{s.end_time}
                          {s.courts && ` · ${courtsLabel(s.courts, hall.courts, t)}`}
                          {period(s.valid_from, s.valid_until, t) && ` (${period(s.valid_from, s.valid_until, t)})`}
                          {admin && (
                            <button type="button" className="ml-1 text-red-700" aria-label={t('halls.deleteSlot', { time: `${weekdayName(day)} ${s.start_time}` })} onClick={() => removeSlot.mutate(s.id)}>✕</button>
                          )}
                        </span>
                      ))}
                    </p>
                  )}
                  <ul className="mt-1 space-y-1">
                    {bookings.map((b) => (
                      <li key={b.id} className={`rounded-lg border px-3 py-2 text-sm ${b.conflicts.length > 0 ? 'border-red-300 bg-red-50' : b.outside_slots ? 'border-amber-300 bg-amber-50' : 'border-slate-200'}`}>
                        <div className="flex items-start gap-2">
                          <span className="mt-1 size-3 shrink-0 rounded-full" style={{ backgroundColor: b.team.color ?? '#94a3b8' }} aria-hidden="true" />
                          <div className="min-w-0 flex-1">
                            <p>
                              <span className="font-medium tabular-nums">{b.start_time}–{b.end_time}</span> · {b.team.name}
                              {b.team.archived && <span className="text-xs text-slate-500"> ({t('cash.archived')})</span>}
                            </p>
                            <p className="text-xs text-slate-500">
                              {[courtsLabel(b.courts, hall.courts, t), period(b.valid_from, b.valid_until, t), b.notes].filter(Boolean).join(' · ')}
                            </p>
                            {b.conflicts.map((c) => (
                              <p key={c.id} className="text-xs font-medium text-red-700">⚠️ {t('halls.conflictWith', { team: c.team, time: `${c.start_time}–${c.end_time}` })}</p>
                            ))}
                            {b.outside_slots && <p className="text-xs font-medium text-amber-800">⚠️ {t('halls.outsideSlots')}</p>}
                          </div>
                          {admin && (
                            <div className="flex shrink-0 gap-1">
                              <button type="button" className="rounded px-1 text-brand-700" aria-label={t('halls.editBooking', { team: b.team.name })} onClick={() => { setBooking(b); setEditing('booking'); }}>✎</button>
                              <button
                                type="button"
                                className="rounded px-1 text-red-700"
                                aria-label={t('halls.deleteBooking', { team: b.team.name })}
                                onClick={() => window.confirm(t('halls.deleteBookingConfirm', { team: b.team.name })) && removeBooking.mutate(b.id)}
                              >
                                ✕
                              </button>
                            </div>
                          )}
                        </div>
                      </li>
                    ))}
                  </ul>
                </li>
              ))}
            </ul>
          )}

          {admin && editing === 'slot' && <TimeForm hall={hall} kind="slot" onDone={close} />}
          {admin && editing === 'booking' && <TimeForm hall={hall} kind="booking" booking={booking} onDone={close} onWarning={setWarning} />}
          {admin && editing === null && (
            <div className="flex flex-wrap gap-2">
              <Button variant="secondary" onClick={() => setEditing('booking')}>{t('halls.addBooking')}</Button>
              <Button variant="secondary" onClick={() => setEditing('slot')}>{t('halls.addSlot')}</Button>
            </div>
          )}
        </div>
      )}
    </Card>
  );
}

function BookingWarning({ booking, onClose }: { booking: HallBooking; onClose: () => void }) {
  const { t } = useTranslation();
  if (booking.conflicts.length === 0 && !booking.outside_slots) return null;

  return (
    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
      <p className="font-medium">{t('halls.savedWithWarning')}</p>
      <ul className="mt-1 list-disc pl-5">
        {booking.conflicts.map((c) => <li key={c.id}>{t('halls.conflictWith', { team: c.team, time: `${c.start_time}–${c.end_time}` })}</li>)}
        {booking.outside_slots && <li>{t('halls.outsideSlots')}</li>}
      </ul>
      <button type="button" className="mt-2 text-xs underline" onClick={onClose}>{t('halls.dismiss')}</button>
    </div>
  );
}

function HallForm({ hall, onDone }: { hall?: Hall; onDone?: () => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const update = usePlanUpdate();
  const empty = {
    name: '', street: '', house_number: '', postal_code: '', city: '', courts: '1', contact_name: '', contact_phone: '', contact_email: '', notes: '',
  };
  const [form, setForm] = useState(hall ? {
    name: hall.name, street: hall.street ?? '', house_number: hall.house_number ?? '', postal_code: hall.postal_code ?? '', city: hall.city ?? '',
    courts: String(hall.courts), notes: hall.notes ?? '',
    contact_name: hall.contact?.name ?? '', contact_phone: hall.contact?.phone ?? '', contact_email: hall.contact?.email ?? '',
  } : empty);
  const [hours, setHours] = useState<OpeningHour[]>(() => (hall?.slots ?? [])
    .filter((s) => !s.valid_from && !s.valid_until)
    .map((s) => ({ key: s.id, weekday: s.weekday, start_time: s.start_time, end_time: s.end_time, courts: s.courts ?? [] })));

  const save = useMutation({
    mutationFn: () => {
      const body = {
        ...form, courts: Number(form.courts) || 1, notes: form.notes || null,
        street: form.street || null, house_number: form.house_number || null, postal_code: form.postal_code || null, city: form.city || null,
        contact_name: form.contact_name || null, contact_phone: form.contact_phone || null, contact_email: form.contact_email || null,
        opening_hours: hours.map(({ weekday, start_time, end_time, courts }) => ({ weekday, start_time, end_time, courts })),
      };
      return hall ? patch<PlanResponse>(tp(`halls/${hall.id}`), body) : post<PlanResponse>(tp('halls'), body);
    },
    onSuccess: (result) => {
      update(result);
      if (!hall) {
        setForm(empty);
        setHours([]);
      }
      onDone?.();
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  const fields = (
    <form onSubmit={submit} className="space-y-3">
      <div className="grid grid-cols-[1fr_6rem] gap-3">
        <Field label={t('halls.name')} required maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <Field label={t('halls.courtsLabel')} type="number" min={1} max={12} required inputMode="numeric" value={form.courts} onChange={(e) => setForm({ ...form, courts: e.target.value })} />
      </div>
      <fieldset className="space-y-2">
        <legend className="mb-1 text-sm font-medium text-slate-700">{t('halls.address')}</legend>
        <div className="grid grid-cols-[1fr_6rem] gap-2">
          <Field label={t('halls.street')} maxLength={120} autoComplete="off" value={form.street} onChange={(e) => setForm({ ...form, street: e.target.value })} />
          <Field label={t('halls.houseNumber')} maxLength={20} autoComplete="off" value={form.house_number} onChange={(e) => setForm({ ...form, house_number: e.target.value })} />
        </div>
        <div className="grid grid-cols-[6rem_1fr] gap-2">
          <Field label={t('halls.postalCode')} maxLength={10} inputMode="numeric" autoComplete="off" value={form.postal_code} onChange={(e) => setForm({ ...form, postal_code: e.target.value })} />
          <Field label={t('halls.city')} maxLength={80} autoComplete="off" value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} />
        </div>
      </fieldset>
      <OpeningHours hours={hours} onChange={setHours} courts={Number(form.courts) || 1} />
      <fieldset className="space-y-2">
        <legend className="mb-1 text-sm font-medium text-slate-700">{t('halls.contact')}</legend>
        <p className="text-xs text-slate-500">{t('halls.contactHint')}</p>
        <Field label={t('halls.contactName')} maxLength={120} value={form.contact_name} onChange={(e) => setForm({ ...form, contact_name: e.target.value })} />
        <div className="grid grid-cols-2 gap-2">
          <Field label={t('halls.contactPhone')} type="tel" maxLength={40} value={form.contact_phone} onChange={(e) => setForm({ ...form, contact_phone: e.target.value })} />
          <Field label={t('halls.contactEmail')} type="email" maxLength={255} value={form.contact_email} onChange={(e) => setForm({ ...form, contact_email: e.target.value })} />
        </div>
      </fieldset>
      <Field label={t('halls.notes')} maxLength={1000} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
      {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
      <div className="flex gap-2">
        <Button type="submit" disabled={save.isPending}>{hall ? t('common.save') : t('halls.add')}</Button>
        {onDone && <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>}
      </div>
    </form>
  );

  return hall ? fields : <Card title={t('halls.new')}>{fields}</Card>;
}

type OpeningHour = { key: string; weekday: number; start_time: string; end_time: string; courts: number[] };

let nextKey = 0;

/**
 * Weekly training times of the hall, entered like the opening hours of a
 * shop: per weekday one or more times, optionally only some parts.
 */
function OpeningHours({ hours, onChange, courts }: { hours: OpeningHour[]; onChange: (hours: OpeningHour[]) => void; courts: number }) {
  const { t } = useTranslation();
  const change = (key: string, values: Partial<OpeningHour>) => onChange(hours.map((h) => (h.key === key ? { ...h, ...values } : h)));
  const add = (weekday: number) => {
    const last = hours.filter((h) => h.weekday === weekday).at(-1) ?? hours.at(-1);
    onChange([...hours, { key: `new-${nextKey++}`, weekday, start_time: last?.start_time ?? '18:00', end_time: last?.end_time ?? '20:00', courts: last?.courts ?? [] }]);
  };

  return (
    <fieldset className="min-w-0">
      <legend className="mb-1 text-sm font-medium text-slate-700">{t('halls.openingHours')}</legend>
      <p className="mb-2 text-xs text-slate-500">{t('halls.openingHoursHint')}</p>
      <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
        {WEEKDAYS.map((day) => {
          const rows = hours.filter((h) => h.weekday === day).sort((a, b) => a.start_time.localeCompare(b.start_time));
          return (
            <li key={day} className="flex items-start gap-2 p-2">
              <span className="w-10 shrink-0 pt-2 text-sm font-semibold">{weekdayName(day, 'short')}</span>
              <div className="min-w-0 flex-1 space-y-2">
                {rows.length === 0 && <p className="pt-2 text-sm text-slate-400">{t('halls.closed')}</p>}
                {rows.map((h) => (
                  <div key={h.key} className="space-y-1">
                    <div className="flex items-center gap-1">
                      <input
                        type="time"
                        required
                        aria-label={t('halls.openFrom', { day: weekdayName(day) })}
                        className="min-h-10 w-full min-w-0 rounded-lg border border-slate-300 px-2 text-sm"
                        value={h.start_time}
                        onChange={(e) => change(h.key, { start_time: e.target.value })}
                      />
                      <span className="text-slate-500">–</span>
                      <input
                        type="time"
                        required
                        aria-label={t('halls.openUntil', { day: weekdayName(day) })}
                        className="min-h-10 w-full min-w-0 rounded-lg border border-slate-300 px-2 text-sm"
                        value={h.end_time}
                        onChange={(e) => change(h.key, { end_time: e.target.value })}
                      />
                      <button
                        type="button"
                        className="min-h-10 shrink-0 rounded px-2 text-red-700"
                        aria-label={t('halls.removeTime', { time: `${weekdayName(day)} ${h.start_time}` })}
                        onClick={() => onChange(hours.filter((x) => x.key !== h.key))}
                      >
                        ✕
                      </button>
                    </div>
                    {courts > 1 && (
                      <div className="flex flex-wrap items-center gap-1 text-xs">
                        {Array.from({ length: courts }, (_, i) => i + 1).map((c) => {
                          const on = h.courts.length === 0 || h.courts.includes(c);
                          const all = Array.from({ length: courts }, (_, i) => i + 1);
                          return (
                            <button
                              key={c}
                              type="button"
                              aria-pressed={on}
                              className={`rounded-full px-2 py-1 ring-1 ${on ? 'bg-brand-50 text-brand-800 ring-brand-500' : 'text-slate-400 ring-slate-200'}`}
                              onClick={() => {
                                const current = h.courts.length === 0 ? all : h.courts;
                                const next = on ? current.filter((x) => x !== c) : [...current, c].sort((a, b) => a - b);
                                change(h.key, { courts: next.length === courts || next.length === 0 ? [] : next });
                              }}
                            >
                              {t('halls.court', { court: c })}
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </div>
                ))}
              </div>
              <button type="button" className="min-h-10 min-w-10 shrink-0 rounded-lg text-xl font-semibold leading-none text-brand-700 hover:bg-brand-50" aria-label={t('halls.addTime', { day: weekdayName(day) })} onClick={() => add(day)}>
                +
              </button>
            </li>
          );
        })}
      </ul>
    </fieldset>
  );
}

function TimeForm({ hall, kind, booking, onDone, onWarning }: {
  hall: Hall;
  kind: 'slot' | 'booking';
  booking?: HallBooking | null;
  onDone: () => void;
  onWarning?: (booking: HallBooking) => void;
}) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const update = usePlanUpdate();
  const teams = useQuery({
    queryKey: ['teams', slug],
    queryFn: async () => (await api<{ data: Team[] }>(tp('teams'))).data,
    enabled: kind === 'booking',
  });
  const source: Partial<HallBooking & HallSlot> = booking ?? {};
  const [form, setForm] = useState({
    team_id: source.team?.id ?? '',
    weekday: String(source.weekday ?? 1),
    start_time: source.start_time ?? '18:00',
    end_time: source.end_time ?? '20:00',
    courts: source.courts ?? [],
    valid_from: source.valid_from ?? '',
    valid_until: source.valid_until ?? '',
    notes: source.notes ?? '',
  });

  const save = useMutation({
    mutationFn: () => {
      const body = {
        weekday: Number(form.weekday), start_time: form.start_time, end_time: form.end_time,
        valid_from: form.valid_from || null, valid_until: form.valid_until || null,
        courts: form.courts,
        ...(kind === 'booking' ? { team_id: form.team_id, notes: form.notes || null } : {}),
      };
      if (kind === 'slot') return post<PlanResponse>(tp(`halls/${hall.id}/slots`), body);
      return booking ? patch<PlanResponse>(tp(`hall-bookings/${booking.id}`), body) : post<PlanResponse>(tp(`halls/${hall.id}/bookings`), body);
    },
    onSuccess: (result) => {
      update(result);
      if (result.booking) onWarning?.(result.booking);
      onDone();
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <form onSubmit={submit} className="space-y-3 rounded-lg border border-slate-200 p-3">
      <h3 className="text-sm font-semibold">{kind === 'slot' ? t('halls.addSlot') : booking ? t('halls.editBookingTitle') : t('halls.addBooking')}</h3>
      {kind === 'booking' && (
        <Select label={t('halls.team')} required value={form.team_id} onChange={(e) => setForm({ ...form, team_id: e.target.value })}>
          <option value="">{t('halls.chooseTeam')}</option>
          {teams.data?.map((team) => <option key={team.id} value={team.id}>{team.name}</option>)}
        </Select>
      )}
      <div className="grid grid-cols-3 gap-2">
        <Select label={t('halls.weekday')} value={form.weekday} onChange={(e) => setForm({ ...form, weekday: e.target.value })}>
          {WEEKDAYS.map((d) => <option key={d} value={d}>{weekdayName(d, 'short')}</option>)}
        </Select>
        <Field label={t('halls.start')} type="time" required value={form.start_time} onChange={(e) => setForm({ ...form, start_time: e.target.value })} />
        <Field label={t('halls.end')} type="time" required value={form.end_time} onChange={(e) => setForm({ ...form, end_time: e.target.value })} />
      </div>
      {hall.courts > 1 && (
        <fieldset>
          <legend className="mb-1 text-sm font-medium text-slate-700">{t('halls.courtLabel')}</legend>
          <p className="mb-1 text-xs text-slate-500">{kind === 'slot' ? t('halls.slotCourtHint') : t('halls.courtHint')}</p>
          <div className="flex flex-wrap gap-2">
            {Array.from({ length: hall.courts }, (_, i) => i + 1).map((c) => (
              <label key={c} className="flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 px-3 text-sm">
                <input
                  type="checkbox"
                  className="size-5 accent-brand-solid"
                  checked={form.courts.includes(c)}
                  onChange={(e) => setForm({ ...form, courts: e.target.checked ? [...form.courts, c].sort((a, b) => a - b) : form.courts.filter((x) => x !== c) })}
                />
                {t('halls.court', { court: c })}
              </label>
            ))}
          </div>
          <p className="mt-1 text-xs text-slate-500">
            {courtsLabel(form.courts.length === 0 || form.courts.length === hall.courts ? null : form.courts, hall.courts, t)}
          </p>
        </fieldset>
      )}
      <div className="grid grid-cols-2 gap-2">
        <Field label={t('halls.validFrom')} type="date" value={form.valid_from} onChange={(e) => setForm({ ...form, valid_from: e.target.value })} />
        <Field label={t('halls.validUntil')} type="date" min={form.valid_from || undefined} value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} />
      </div>
      {kind === 'booking' && <Field label={t('halls.notes')} maxLength={255} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />}
      {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
      <div className="flex gap-2">
        <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
        <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
      </div>
    </form>
  );
}
