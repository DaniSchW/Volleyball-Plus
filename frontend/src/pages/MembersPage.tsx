import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, patch, post, type Invitation, type MemberRow } from '../lib/api';
import { useTenant, useTenantPath, useTenantSlug } from '../lib/queries';
import { LogoUpload } from '../components/LogoUpload';
import { Button, Card, Loading, Notice, PageTitle, Toggle } from '../components/ui';
import { ShareLink } from './TeamPage';

/** Admins: who has a login and who is admin. */
export function MembersPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const tenant = useTenant();
  const [logoVersion, setLogoVersion] = useState(0);
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
      <LogoUpload
        title={t('members.tenantLogo')}
        path={tp('logo')}
        currentUrl={tenant.data?.logo_url ? `${tenant.data.logo_url}?v=${logoVersion}` : null}
        onUploaded={() => {
          setLogoVersion((v) => v + 1);
          void queryClient.invalidateQueries({ queryKey: ['tenant', slug] });
        }}
      />
      <DepartmentInvitations />
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

/** Links into the club without a team – for further admins or helpers. */
function DepartmentInvitations() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const key = ['department-invitations', slug];
  const links = useQuery({ queryKey: key, queryFn: async () => (await api<{ data: Invitation[] }>(tp('department-invitations'))).data });
  const [admin, setAdmin] = useState(true);
  const refresh = () => void queryClient.invalidateQueries({ queryKey: key });
  const create = useMutation({ mutationFn: () => post(tp('department-invitations'), { admin, max_uses: admin ? 1 : null, expires_in_days: 14 }), onSuccess: refresh });
  const revoke = useMutation({ mutationFn: (id: string) => del(tp(`invitations/${id}`)), onSuccess: refresh });

  return (
    <Card title={t('members.invite')}>
      <div className="space-y-3">
        <p className="text-sm text-slate-600">{t('members.inviteHint')}</p>
        <Toggle label={t('members.inviteAsAdmin')} checked={admin} onChange={setAdmin} />
        <Button onClick={() => create.mutate()} disabled={create.isPending}>{t('invitations.createButton')}</Button>
        {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
        <ul className="divide-y divide-slate-100">
          {links.data?.map((link) => (
            <li key={link.id} className="py-3">
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium">{link.grants_admin ? t('members.linkAdmin') : t('members.linkMember')}</span>
                <button type="button" className="text-sm text-red-700" onClick={() => revoke.mutate(link.id)}>{t('invitations.revoke')}</button>
              </div>
              <p className="text-xs text-slate-500">
                {link.max_uses ? t('members.usesOf', { used: link.use_count, max: link.max_uses }) : t('members.usesOpen', { used: link.use_count })}
              </p>
              <ShareLink url={link.url} title={link.grants_admin ? t('members.linkAdmin') : t('members.linkMember')} />
            </li>
          ))}
        </ul>
      </div>
    </Card>
  );
}
