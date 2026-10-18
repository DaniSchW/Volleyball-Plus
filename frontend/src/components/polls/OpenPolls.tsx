import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api, type Poll } from '../../lib/api';
import { useTenantPath, useTenantSlug } from '../../lib/queries';

/** Start page hint: open polls the member (or a child) has not answered yet. */
export function OpenPolls() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const polls = useQuery({ queryKey: ['polls', slug], queryFn: async () => (await api<{ data: Poll[] }>(tp('polls'))).data });

  const pending = (polls.data ?? []).filter((p) => !p.is_closed && p.my.some((m) => m.can_vote && m.option_ids.length === 0 && !m.free_text));
  if (pending.length === 0) return null;

  return (
    <Link to={`/${slug}/polls`} className="block rounded-xl border border-blue-200 bg-brand-50 p-4">
      <span className="block text-sm font-semibold text-brand-700">{t('polls.pending', { count: pending.length })}</span>
      <span className="block truncate text-sm text-slate-700">{pending.map((p) => p.title).join(' · ')}</span>
    </Link>
  );
}
