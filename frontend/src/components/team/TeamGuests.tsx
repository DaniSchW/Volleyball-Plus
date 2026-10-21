import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, patch, post, type CrossCandidate, type CrossNomination, type Team } from '../../lib/api';
import { useTenantPath } from '../../lib/queries';
import { Avatar, Button, Card, Loading, Notice, Select } from '../ui';

/**
 * Cross-nomination: players of lower teams invited to this team's
 * trainings for good, switchable per player.
 */
export function TeamGuests({ team }: { team: Team }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const key = ['cross-nominations', team.id];
  const guests = useQuery({ queryKey: key, queryFn: async () => (await api<{ data: CrossNomination[] }>(tp(`teams/${team.id}/cross-nominations`))).data });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: key });
    void queryClient.invalidateQueries({ queryKey: ['cross-candidates', team.id] });
  };

  const toggle = useMutation({
    mutationFn: ({ id, active }: { id: string; active: boolean }) => patch(tp(`cross-nominations/${id}`), { active }),
    onSuccess: refresh,
  });
  const remove = useMutation({ mutationFn: (id: string) => del(tp(`cross-nominations/${id}`)), onSuccess: refresh });

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">{t('guests.hint')}</p>
      {team.in_hierarchy === false && <Notice>{t('guests.noLeague')}</Notice>}
      <Card title={t('guests.title')}>
        {guests.isPending && <Loading />}
        {guests.data?.length === 0 && <p className="text-sm text-slate-500">{t('guests.none')}</p>}
        <ul className="divide-y divide-slate-100">
          {guests.data?.map((guest) => {
            const name = `${guest.person.first_name} ${guest.person.last_name}`;
            return (
              <li key={guest.id} className="flex items-center gap-3 py-2">
                <Avatar url={guest.person.avatar_url} name={name} size={32} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{name}</span>
                  <span className="block truncate text-xs text-slate-500">
                    {[guest.home_team.name, guest.person.primary_position && t(`positions.${guest.person.primary_position}`)].filter(Boolean).join(' · ')}
                  </span>
                </span>
                {team.can_manage && (
                  <>
                    <label className="flex items-center gap-1 text-xs">
                      <input
                        type="checkbox"
                        className="size-5 accent-brand-solid"
                        checked={guest.active}
                        aria-label={t('guests.active', { name })}
                        onChange={(e) => toggle.mutate({ id: guest.id, active: e.target.checked })}
                      />
                      {t('guests.on')}
                    </label>
                    <button
                      type="button"
                      className="rounded p-2 text-sm text-red-700 hover:bg-red-50"
                      aria-label={t('guests.remove', { name })}
                      onClick={() => window.confirm(t('guests.removeConfirm', { name })) && remove.mutate(guest.id)}
                    >
                      ✕
                    </button>
                  </>
                )}
              </li>
            );
          })}
        </ul>
      </Card>
      {team.can_manage && <InviteGuest team={team} onAdded={refresh} />}
    </div>
  );
}

function InviteGuest({ team, onAdded }: { team: Team; onAdded: () => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [open, setOpen] = useState(false);
  const [choice, setChoice] = useState('');
  const candidates = useQuery({
    queryKey: ['cross-candidates', team.id],
    queryFn: async () => (await api<{ data: CrossCandidate[] }>(tp(`teams/${team.id}/cross-nomination-candidates`))).data,
    enabled: open,
  });
  const add = useMutation({
    mutationFn: (candidate: CrossCandidate) => post(tp(`teams/${team.id}/cross-nominations`), { person_id: candidate.person.id, home_team_id: candidate.home_team.id }),
    onSuccess: () => {
      setChoice('');
      onAdded();
    },
  });

  if (!open) {
    return <Button onClick={() => setOpen(true)}>{t('guests.invite')}</Button>;
  }

  const selected = candidates.data?.find((c) => c.person.id === choice);

  return (
    <Card title={t('guests.invite')}>
      {candidates.isPending && <Loading />}
      {candidates.data?.length === 0 && <p className="text-sm text-slate-500">{t('guests.noCandidates')}</p>}
      {candidates.data && candidates.data.length > 0 && (
        <div className="space-y-3">
          <Select label={t('guests.player')} value={choice} onChange={(e) => setChoice(e.target.value)}>
            <option value="">–</option>
            {candidates.data.map((c) => (
              <option key={c.person.id} value={c.person.id}>
                {c.person.first_name} {c.person.last_name} ({c.home_team.name}{c.person.primary_position ? `, ${t(`positions.${c.person.primary_position}`)}` : ''})
              </option>
            ))}
          </Select>
          <Button disabled={!selected || add.isPending} onClick={() => selected && add.mutate(selected)}>{t('guests.add')}</Button>
        </div>
      )}
      {add.isError && <Notice tone="error">{errorText(add.error, t('errors.generic'))}</Notice>}
    </Card>
  );
}

