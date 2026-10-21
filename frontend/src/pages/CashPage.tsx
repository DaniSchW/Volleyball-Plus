import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, errorText, type CashBookInfo, type CashOverview } from '../lib/api';
import { formatMoney } from '../lib/money';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { Card, Loading, Notice, PageTitle } from '../components/ui';

/**
 * Cash books the member keeps: own teams (trainers, organizers); admins see
 * the department book and all teams with totals.
 */
export function CashPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const overview = useQuery({ queryKey: ['cash', slug], queryFn: async () => (await api<{ data: CashOverview }>(tp('cash'))).data });

  if (overview.isPending) return <Loading />;
  if (overview.isError) return <Notice tone="error">{errorText(overview.error, t('errors.generic'))}</Notice>;

  const { books, total, teams_total } = overview.data;
  const department = books.find((b) => b.kind === 'department');
  const teams = books.filter((b) => b.kind === 'team');

  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.cash')}</PageTitle>
      <p className="text-sm text-slate-600">{t('cash.hint')}</p>
      {books.length === 0 && <Notice>{t('cash.none')}</Notice>}

      {total !== null && (
        <Card title={t('cash.overview')}>
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <div>
              <dt className="text-slate-500">{t('cash.total')}</dt>
              <dd className={`text-lg font-semibold ${amountTone(total)}`}>{formatMoney(total)}</dd>
            </div>
            <div>
              <dt className="text-slate-500">{t('cash.teamsTotal')}</dt>
              <dd className={`text-lg font-semibold ${amountTone(teams_total ?? 0)}`}>{formatMoney(teams_total ?? 0)}</dd>
            </div>
          </dl>
        </Card>
      )}

      {department && <BookList books={[department]} />}
      {teams.length > 0 && (
        <section className="space-y-2">
          {department && <h2 className="text-sm font-semibold text-slate-700">{t('cash.teamBooks')}</h2>}
          <BookList books={teams} />
        </section>
      )}
    </div>
  );
}

function BookList({ books }: { books: CashBookInfo[] }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();

  return (
    <ul className="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-surface">
      {books.map((book) => (
        <li key={book.id}>
          <Link to={`/${slug}/cash/${book.id}`} className="flex min-h-14 items-center gap-3 px-4 hover:bg-slate-50">
            <span className="min-w-0 flex-1 truncate font-medium">
              {book.kind === 'department' ? '🏛️ ' : ''}{book.name}
              {book.archived && <span className="ml-2 text-xs font-normal text-slate-500">{t('cash.archived')}</span>}
            </span>
            <span className={`shrink-0 font-semibold tabular-nums ${amountTone(book.summary.balance)}`}>{formatMoney(book.summary.balance)}</span>
            <span aria-hidden="true" className="text-slate-400">›</span>
          </Link>
        </li>
      ))}
    </ul>
  );
}

export function amountTone(cents: number): string {
  return cents < 0 ? 'text-red-700' : cents > 0 ? 'text-emerald-700' : 'text-slate-700';
}
