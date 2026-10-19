import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, errorText, post, type PersonSearchResult, type Team, type TeamRole } from '../../lib/api';
import { useTenantMe, useTenantPath } from '../../lib/queries';
import { Avatar, Button, Card, Notice, Select } from '../ui';

const ROLES: TeamRole[] = ['player', 'trainer', 'organizer'];

/**
 * Trainers take people of the whole department into their team; they
 * appear in the team right away (no invitation to accept).
 */
export function AddFromDepartment({ team, memberIds }: { team: Team; memberIds: Set<string> }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [q, setQ] = useState('');
  const [role, setRole] = useState<TeamRole>('player');
  const term = q.trim();
  const me = useTenantMe();
  // Admins and trainers often play or coach in a team themselves.
  const ownId = me.data?.persons.find((p) => p.relation === 'self')?.id;
  const ownRoles = me.data?.persons.find((p) => p.relation === 'self')?.teams.filter((m) => m.id === team.id).map((m) => m.role) ?? [];

  const results = useQuery({
    queryKey: ['person-search', term],
    queryFn: async () => (await api<{ data: PersonSearchResult[] }>(tp(`persons?q=${encodeURIComponent(term)}`))).data,
    enabled: term.length >= 2,
  });
  const add = useMutation({
    mutationFn: (personId: string) => post(tp(`teams/${team.id}/members`), { person_id: personId, role }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['roster', team.id] });
      void queryClient.invalidateQueries({ queryKey: ['person-search'] });
    },
  });

  return (
    <Card title={t('team.addFromDepartment')}>
      <div className="space-y-3">
        {ownId && !ownRoles.includes(role) && (
          <div className="flex items-center justify-between gap-3 rounded-lg bg-brand-50 p-3">
            <span className="text-sm">{t('team.addMeHint')}</span>
            <Button
              disabled={add.isPending}
              onClick={() => add.mutate(ownId, { onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['tenant-me'] }) })}
            >
              {t('team.addMe')} ({t(`roles.${role}`)})
            </Button>
          </div>
        )}
        <p className="text-sm text-slate-600">{t('team.addFromDepartmentHint')}</p>
        <div className="grid grid-cols-[1fr_8rem] items-end gap-2">
          <input
            type="search"
            className="min-h-11 min-w-0 rounded-lg border border-slate-300 px-3 text-base"
            placeholder={t('team.searchPlaceholder')}
            aria-label={t('team.search')}
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
          <Select label={t('team.role')} value={role} onChange={(e) => setRole(e.target.value as TeamRole)}>
            {ROLES.map((value) => <option key={value} value={value}>{t(`roles.${value}`)}</option>)}
          </Select>
        </div>
        {results.data?.length === 0 && <p className="text-sm text-slate-500">{t('team.searchNone')}</p>}
        <ul className="divide-y divide-slate-100">
          {results.data?.map((person) => {
            const name = `${person.first_name} ${person.last_name}`;
            const already = memberIds.has(person.id) && person.teams.some((m) => m.id === team.id && m.role === role);
            return (
              <li key={person.id} className="flex items-center gap-3 py-2">
                <Avatar url={person.avatar_url} name={name} size={32} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{name}</span>
                  <span className="block truncate text-xs text-slate-500">
                    {person.teams.length > 0 ? person.teams.map((m) => `${m.name} (${t(`roles.${m.role}`)})`).join(', ') : t('team.noTeam')}
                  </span>
                </span>
                <Button variant="secondary" disabled={already || add.isPending} onClick={() => add.mutate(person.id)}>
                  {already ? t('team.alreadyIn') : t('common.add')}
                </Button>
              </li>
            );
          })}
        </ul>
        {add.isError && <Notice tone="error">{errorText(add.error, t('errors.generic'))}</Notice>}
      </div>
    </Card>
  );
}
