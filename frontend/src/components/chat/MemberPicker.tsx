import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, type MemberRow } from '../../lib/api';
import { useTenantPath, useTenantSlug } from '../../lib/queries';

/** Choose department members for a custom chat (admins). */
export function MemberPicker({ chosen, onChange }: { chosen: string[]; onChange: (ids: string[]) => void }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const members = useQuery({ queryKey: ['members', slug], queryFn: async () => (await api<{ data: MemberRow[] }>(tp('members'))).data });
  const [filter, setFilter] = useState('');

  const label = (m: MemberRow) => m.persons.find((p) => p.relation === 'self')?.name ?? m.persons[0]?.name ?? m.email;
  const list = (members.data ?? [])
    .filter((m) => m.status === 'active')
    .filter((m) => !filter || label(m).toLowerCase().includes(filter.toLowerCase()))
    .sort((a, b) => label(a).localeCompare(label(b)));

  return (
    <fieldset>
      <legend className="mb-1 text-sm font-medium text-slate-700">{t('chat.members', { count: chosen.length })}</legend>
      <input
        type="search"
        className="mb-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base"
        placeholder={t('team.search')}
        aria-label={t('team.search')}
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
      />
      <ul className="max-h-64 overflow-y-auto rounded-lg border border-slate-100">
        {list.map((m) => (
          <li key={m.id}>
            <label className="flex min-h-11 items-center gap-3 px-3 text-sm">
              <input
                type="checkbox"
                className="size-5 accent-brand-700"
                checked={chosen.includes(m.id)}
                onChange={(e) => onChange(e.target.checked ? [...chosen, m.id] : chosen.filter((x) => x !== m.id))}
              />
              {label(m)}
            </label>
          </li>
        ))}
      </ul>
    </fieldset>
  );
}
