import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, errorText, patch, type MemberRow } from '../lib/api';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { Card, Loading, Notice, PageTitle, Toggle } from '../components/ui';

/** Admins: who has a login and who is admin. */
export function MembersPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const members = useQuery({ queryKey: ['members', slug], queryFn: async () => (await api<{ data: MemberRow[] }>(tp('members'))).data });

  const update = useMutation({
    mutationFn: ({ id, ...body }: { id: string; is_admin?: boolean; status?: string }) => patch(tp(`members/${id}`), body),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['members', slug] }),
  });

  if (members.isPending) return <Loading />;
  if (members.isError) return <Notice tone="error">{errorText(members.error, t('errors.generic'))}</Notice>;

  return (
    <div className="space-y-4">
      <PageTitle>{t('menu.members')}</PageTitle>
      {update.isError && <Notice tone="error">{errorText(update.error, t('errors.generic'))}</Notice>}
      <Card>
        <ul className="divide-y divide-slate-100">
          {members.data.map((member) => (
            <li key={member.id} className="py-2">
              <p className="truncate text-sm font-medium">{member.email}</p>
              <p className="text-xs text-slate-500">
                {member.persons.map((p) => (p.relation === 'guardian' ? `${p.name} (${t('profile.child')})` : p.name)).join(', ') || '–'}
              </p>
              <Toggle label={t('members.admin')} checked={member.is_admin} onChange={(v) => update.mutate({ id: member.id, is_admin: v })} />
              <Toggle
                label={t('members.active')}
                checked={member.status === 'active'}
                onChange={(v) => update.mutate({ id: member.id, status: v ? 'active' : 'disabled' })}
              />
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}
