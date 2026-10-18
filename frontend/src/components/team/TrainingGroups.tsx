import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, patch, post, type RosterRow, type Team, type TrainingGroup } from '../../lib/api';
import { useTenantPath } from '../../lib/queries';
import { Button, Card, Field, Loading, Notice } from '../ui';

/**
 * Training groups within the team (e.g. setter training) with their own
 * trainings; trainers and organizers are always invited to them.
 */
export function TrainingGroups({ team }: { team: Team }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const key = ['training-groups', team.id];
  const groups = useQuery({ queryKey: key, queryFn: async () => (await api<{ data: TrainingGroup[] }>(tp(`teams/${team.id}/training-groups`))).data });
  const roster = useQuery({ queryKey: ['roster', team.id], queryFn: async () => (await api<{ data: RosterRow[] }>(tp(`teams/${team.id}/members`))).data });
  const [name, setName] = useState('');

  const create = useMutation({
    mutationFn: () => post(tp(`teams/${team.id}/training-groups`), { name }),
    onSuccess: () => {
      setName('');
      void queryClient.invalidateQueries({ queryKey: key });
    },
  });

  if (groups.isPending || roster.isPending) return <Loading />;

  const players = (roster.data ?? []).filter((row) => row.roles.some((r) => r.role === 'player'));

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">{t('groups.hint')}</p>
      {groups.data?.length === 0 && <Notice>{t('groups.none')}</Notice>}
      {groups.data?.map((group) => <GroupCard key={group.id} team={team} group={group} players={players} />)}
      {team.can_manage && (
        <Card title={t('groups.create')}>
          <form
            className="flex items-end gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              create.mutate();
            }}
          >
            <div className="min-w-0 flex-1">
              <Field label={t('groups.name')} required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} placeholder={t('groups.namePlaceholder')} />
            </div>
            <Button type="submit" disabled={create.isPending}>{t('common.add')}</Button>
          </form>
          {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
        </Card>
      )}
    </div>
  );
}

function GroupCard({ team, group, players }: { team: Team; group: TrainingGroup; players: RosterRow[] }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [selected, setSelected] = useState<string[]>(group.person_ids);
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['training-groups', team.id] });

  const save = useMutation({
    mutationFn: () => patch(tp(`training-groups/${group.id}`), { person_ids: selected }),
    onSuccess: () => {
      setEditing(false);
      refresh();
    },
  });
  const remove = useMutation({ mutationFn: () => del(tp(`training-groups/${group.id}`)), onSuccess: refresh });

  const members = players.filter((row) => group.person_ids.includes(row.person.id));
  const name = (row: RosterRow) => `${row.person.first_name} ${row.person.last_name}`;

  return (
    <Card title={`${group.name} (${group.person_ids.length})`}>
      {!editing ? (
        <div className="space-y-3">
          <p className="text-sm text-slate-700">{members.length > 0 ? members.map(name).join(', ') : t('groups.empty')}</p>
          {team.can_manage && (
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setEditing(true)}>{t('groups.chooseMembers')}</Button>
              <Button variant="danger" onClick={() => window.confirm(t('groups.deleteConfirm', { name: group.name })) && remove.mutate()}>{t('groups.delete')}</Button>
            </div>
          )}
        </div>
      ) : (
        <div className="space-y-3">
          <ul className="divide-y divide-slate-100">
            {players.map((row) => (
              <li key={row.person.id}>
                <label className="flex min-h-11 items-center gap-3 text-sm">
                  <input
                    type="checkbox"
                    className="size-5 accent-brand-700"
                    checked={selected.includes(row.person.id)}
                    onChange={(e) => setSelected((s) => (e.target.checked ? [...s, row.person.id] : s.filter((id) => id !== row.person.id)))}
                  />
                  {name(row)}
                  {row.person.primary_position && <span className="text-xs text-slate-500">{t(`positions.${row.person.primary_position}`)}</span>}
                </label>
              </li>
            ))}
          </ul>
          <div className="flex gap-2">
            <Button onClick={() => save.mutate()} disabled={save.isPending}>{t('common.save')}</Button>
            <Button variant="secondary" onClick={() => { setSelected(group.person_ids); setEditing(false); }}>{t('common.cancel')}</Button>
          </div>
          {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
        </div>
      )}
    </Card>
  );
}
