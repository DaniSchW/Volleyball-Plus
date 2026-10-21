import { useId, useState, type FormEvent } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { api, errorText, patch, post, type CashBookDetail, type CashEntry } from '../lib/api';
import { formatDate, todayDate } from '../lib/dates';
import { formatMoney, parseMoney } from '../lib/money';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, Select } from '../components/ui';
import { amountTone } from './CashPage';

type Period = 'all' | 'year' | 'lastYear' | 'custom';

function periodRange(period: Period, custom: { from: string; to: string }): { from?: string; to?: string } {
  const year = new Date().getFullYear();
  if (period === 'year') return { from: `${year}-01-01`, to: `${year}-12-31` };
  if (period === 'lastYear') return { from: `${year - 1}-01-01`, to: `${year - 1}-12-31` };
  if (period === 'custom') return { from: custom.from || undefined, to: custom.to || undefined };
  return {};
}

/** One cash book: balance, bookings with running balance, new booking, cancel, CSV. */
export function CashBookPage() {
  const { t } = useTranslation();
  const { bookId = '' } = useParams();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [period, setPeriod] = useState<Period>('all');
  const [custom, setCustom] = useState({ from: '', to: '' });
  const [category, setCategory] = useState('');

  const range = periodRange(period, custom);
  const search = new URLSearchParams({ ...(range.from ? { from: range.from } : {}), ...(range.to ? { to: range.to } : {}), ...(category ? { category } : {}) }).toString();
  const key = ['cash-book', bookId, search];
  const book = useQuery({
    queryKey: key,
    queryFn: async () => (await api<{ data: CashBookDetail }>(tp(`cash-books/${bookId}${search ? `?${search}` : ''}`))).data,
    placeholderData: keepPreviousData,
  });

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['cash-book', bookId] });
    void queryClient.invalidateQueries({ queryKey: ['cash', slug] });
  };
  const cancel = useMutation({ mutationFn: (id: string) => post(tp(`cash-entries/${id}/cancel`)), onSuccess: refresh });

  if (book.isPending) return <Loading />;
  if (book.isError) return <Notice tone="error">{errorText(book.error, t('errors.generic'))}</Notice>;

  const data = book.data;
  const filtered = period !== 'all' || category !== '';

  return (
    <div className="space-y-4">
      <div>
        <Link to={`/${slug}/cash`} className="text-sm text-brand-700">‹ {t('menu.cash')}</Link>
        <PageTitle>{data.name}</PageTitle>
      </div>

      <Card>
        <p className="text-sm text-slate-500">{t('cash.balance')}</p>
        <p className={`text-2xl font-semibold tabular-nums ${amountTone(data.summary.balance)}`}>{formatMoney(data.summary.balance)}</p>
        {filtered && (
          <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 border-t border-slate-100 pt-3 text-sm sm:grid-cols-4">
            <Sum label={t('cash.carried')} cents={data.summary.carried} />
            <Sum label={t('cash.income')} cents={data.summary.income} />
            <Sum label={t('cash.expenses')} cents={data.summary.expenses} />
            <Sum label={t('cash.closing')} cents={data.summary.closing} />
          </dl>
        )}
      </Card>

      <NewEntry book={data} onBooked={refresh} />

      <Card title={t('cash.entries')}>
        <div className="mb-3 grid grid-cols-2 gap-2">
          <Select label={t('cash.period')} value={period} onChange={(e) => setPeriod(e.target.value as Period)}>
            {(['all', 'year', 'lastYear', 'custom'] as const).map((p) => <option key={p} value={p}>{t(`cash.periods.${p}`)}</option>)}
          </Select>
          <Select label={t('cash.category')} value={category} onChange={(e) => setCategory(e.target.value)}>
            <option value="">{t('cash.allCategories')}</option>
            {data.categories.map((c) => <option key={c} value={c}>{c}</option>)}
          </Select>
          {period === 'custom' && (
            <>
              <Field label={t('cash.from')} type="date" value={custom.from} onChange={(e) => setCustom({ ...custom, from: e.target.value })} />
              <Field label={t('cash.to')} type="date" value={custom.to} min={custom.from || undefined} onChange={(e) => setCustom({ ...custom, to: e.target.value })} />
            </>
          )}
        </div>

        {data.entries.length === 0 ? (
          <p className="text-sm text-slate-500">{t('cash.noEntries')}</p>
        ) : (
          <ul className="-mx-4 divide-y divide-slate-100">
            {data.entries.map((entry) => (
              <EntryRow
                key={entry.id}
                entry={entry}
                onCancel={() => window.confirm(t('cash.cancelConfirm', { description: entry.description })) && cancel.mutate(entry.id)}
              />
            ))}
          </ul>
        )}
        {cancel.isError && <Notice tone="error">{errorText(cancel.error, t('errors.generic'))}</Notice>}

        <a
          href={`/api/v1${tp(`cash-books/${bookId}/export`)}${search ? `?${search}` : ''}`}
          className="mt-4 inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-surface px-4 text-sm font-medium"
          download
        >
          📄 {t('cash.export')}
        </a>
      </Card>

      <OpeningBalance book={data} onSaved={refresh} />
    </div>
  );
}

function Sum({ label, cents }: { label: string; cents: number }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className={`font-medium tabular-nums ${amountTone(cents)}`}>{formatMoney(cents)}</dd>
    </div>
  );
}

function EntryRow({ entry, onCancel }: { entry: CashEntry; onCancel: () => void }) {
  const { t } = useTranslation();
  const cancelled = entry.cancelled_at !== null;
  const meta = [formatDate(entry.booked_on), entry.category, entry.person?.name].filter(Boolean).join(' · ');

  return (
    <li className={`flex items-start gap-3 px-4 py-3 ${cancelled ? 'opacity-60' : ''}`}>
      <div className="min-w-0 flex-1">
        <p className={`text-sm font-medium ${cancelled ? 'line-through' : ''}`}>{entry.description}</p>
        <p className="text-xs text-slate-500">{meta}</p>
        <p className="text-xs text-slate-400">
          {cancelled ? t('cash.cancelledBy', { name: entry.cancelled_by ?? '–' }) : entry.created_by ? t('cash.bookedBy', { name: entry.created_by }) : null}
        </p>
      </div>
      <div className="shrink-0 text-right">
        <p className={`text-sm font-semibold tabular-nums ${cancelled ? 'line-through' : amountTone(entry.amount_cents)}`}>
          {entry.amount_cents > 0 ? '+' : ''}{formatMoney(entry.amount_cents)}
        </p>
        {entry.balance_after !== null && <p className="text-xs tabular-nums text-slate-500">{formatMoney(entry.balance_after)}</p>}
        {!cancelled && (
          <button type="button" className="mt-1 text-xs text-red-700" onClick={onCancel} aria-label={t('cash.cancelEntry', { description: entry.description })}>
            {t('cash.cancel')}
          </button>
        )}
      </div>
    </li>
  );
}

function NewEntry({ book, onBooked }: { book: CashBookDetail; onBooked: () => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const listId = useId();
  const empty = { direction: 'expense' as 'income' | 'expense', amount: '', booked_on: todayDate(), description: '', category: '', person_id: '' };
  const [form, setForm] = useState(empty);
  const [invalid, setInvalid] = useState(false);

  const save = useMutation({
    mutationFn: (amount: number) => post(tp(`cash-books/${book.id}/entries`), {
      booked_on: form.booked_on,
      amount_cents: form.direction === 'income' ? amount : -amount,
      description: form.description,
      category: form.category || null,
      person_id: form.person_id || null,
    }),
    onSuccess: () => {
      setForm({ ...empty, direction: form.direction, booked_on: form.booked_on });
      onBooked();
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const amount = parseMoney(form.amount);
    setInvalid(amount === null || amount === 0);
    if (amount !== null && amount !== 0) save.mutate(amount);
  };

  return (
    <form onSubmit={submit}>
      <Card title={t('cash.newEntry')}>
        <div className="space-y-3">
          <div role="radiogroup" aria-label={t('cash.direction')} className="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1">
            {(['income', 'expense'] as const).map((d) => (
              <button
                key={d}
                type="button"
                role="radio"
                aria-checked={form.direction === d}
                onClick={() => setForm({ ...form, direction: d })}
                className={`min-h-10 rounded-md text-sm ${form.direction === d ? `bg-surface font-medium shadow-sm ${d === 'income' ? 'text-emerald-700' : 'text-red-700'}` : 'text-slate-600'}`}
              >
                {t(`cash.${d}`)}
              </button>
            ))}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('cash.amount')} required inputMode="decimal" placeholder="0,00" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            <Field label={t('cash.date')} type="date" required value={form.booked_on} onChange={(e) => setForm({ ...form, booked_on: e.target.value })} />
          </div>
          <Field label={t('cash.description')} required maxLength={300} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('cash.category')} list={listId} maxLength={60} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} />
            <datalist id={listId}>
              {book.categories.map((c) => <option key={c} value={c} />)}
            </datalist>
            <Select label={t('cash.person')} value={form.person_id} onChange={(e) => setForm({ ...form, person_id: e.target.value })}>
              <option value="">{t('cash.noPerson')}</option>
              {book.persons.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </Select>
          </div>
          {invalid && <Notice tone="error">{t('cash.amountInvalid')}</Notice>}
          {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
          <Button type="submit" disabled={save.isPending}>{t('cash.book')}</Button>
        </div>
      </Card>
    </form>
  );
}

function OpeningBalance({ book, onSaved }: { book: CashBookDetail; onSaved: () => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [value, setValue] = useState((Math.abs(book.opening_balance_cents) / 100).toFixed(2).replace('.', ','));
  const [negative, setNegative] = useState(book.opening_balance_cents < 0);

  const save = useMutation({
    mutationFn: (cents: number) => patch(tp(`cash-books/${book.id}`), { opening_balance_cents: cents }),
    onSuccess: onSaved,
  });
  const parsed = parseMoney(value.replace('-', ''));

  return (
    <details className="rounded-xl border border-slate-200 bg-surface p-4">
      <summary className="cursor-pointer text-sm font-medium">{t('cash.opening')}: {formatMoney(book.opening_balance_cents)}</summary>
      <form
        className="mt-3 space-y-3"
        onSubmit={(e) => {
          e.preventDefault();
          if (parsed !== null) save.mutate(negative ? -parsed : parsed);
        }}
      >
        <p className="text-xs text-slate-500">{t('cash.openingHint')}</p>
        <div className="grid grid-cols-[1fr_auto] items-end gap-3">
          <Field label={t('cash.amount')} inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} />
          <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" className="size-5 accent-brand-solid" checked={negative} onChange={(e) => setNegative(e.target.checked)} />
            {t('cash.negative')}
          </label>
        </div>
        {parsed === null && <Notice tone="error">{t('cash.amountInvalid')}</Notice>}
        {save.isSuccess && <Notice tone="success">{t('common.saved')}</Notice>}
        <Button type="submit" variant="secondary" disabled={parsed === null || save.isPending}>{t('common.save')}</Button>
      </form>
    </details>
  );
}
