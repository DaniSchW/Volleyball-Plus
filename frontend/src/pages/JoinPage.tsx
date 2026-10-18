import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';
import { api, errorText, post, type PublicInvitation } from '../lib/api';
import { useAccount, useTenantSlug } from '../lib/queries';
import { Button, Field, Loading, Notice, Select } from '../components/ui';

type Mode = 'self' | 'child';

/** Opening an invitation link: join a team yourself, register a child, or connect to a profile. */
export function JoinPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const { token = '' } = useParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const account = useAccount();
  const loggedIn = !!account.data;

  const invitation = useQuery({
    queryKey: ['invitation', slug, token],
    queryFn: async () => (await api<{ data: PublicInvitation }>(`/t/${slug}/invitations/${token}`)).data,
    retry: false,
  });

  const [mode, setMode] = useState<Mode>('self');
  const [form, setForm] = useState<Record<string, string>>({ gender: 'male', child_gender: 'female' });
  const set = (key: string) => (value: string) => setForm((f) => ({ ...f, [key]: value }));

  const accept = useMutation({
    mutationFn: () => {
      const inv = invitation.data!;
      const body: Record<string, unknown> = {};
      if (!loggedIn) body.email = form.email;
      if (inv.kind === 'team') {
        body.mode = mode;
        const keys = mode === 'child'
          ? ['child_first_name', 'child_last_name', 'child_gender', 'child_birthday']
          : ['first_name', 'last_name', 'gender', 'birthday'];
        for (const key of keys) if (form[key]) body[key] = form[key];
      }
      return post<{ data: { status: 'joined' | 'confirm' }; message: string }>(`/t/${slug}/invitations/${token}/accept`, body);
    },
    onSuccess: async ({ data }) => {
      if (data.status === 'joined') {
        await queryClient.invalidateQueries();
        navigate(`/${slug}`, { replace: true });
      }
    },
  });

  if (invitation.isPending || account.isPending) return <Loading />;
  if (invitation.isError) {
    return (
      <div className="mx-auto max-w-sm p-4 pt-16">
        <Notice tone="error">{errorText(invitation.error, t('join.invalid'))}</Notice>
      </div>
    );
  }

  const inv = invitation.data;
  const submit = (event: FormEvent) => {
    event.preventDefault();
    accept.mutate();
  };

  const heading = inv.kind === 'team'
    ? t('join.teamHeading', { team: inv.team, role: t(`roles.${inv.role}`) })
    : inv.relation === 'guardian'
      ? t('join.guardianHeading', { person: inv.person })
      : t('join.ownHeading', { person: inv.person });

  return (
    <div className="mx-auto max-w-md space-y-4 p-4 pt-8">
      <div>
        <p className="text-sm text-slate-500">{inv.tenant}</p>
        <h1 className="text-xl font-semibold">{heading}</h1>
      </div>

      {accept.isSuccess && accept.data.data.status === 'confirm' ? (
        <Notice tone="success">{accept.data.message}</Notice>
      ) : (
        <form onSubmit={submit} className="space-y-4 rounded-xl border border-slate-200 bg-white p-4">
          {inv.kind === 'team' && inv.allows_child && (
            <fieldset className="space-y-2">
              <legend className="mb-1 text-sm font-medium">{t('join.who')}</legend>
              {(['self', 'child'] as Mode[]).map((value) => (
                <label key={value} className="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 px-3">
                  <input type="radio" name="mode" value={value} checked={mode === value} onChange={() => setMode(value)} className="accent-brand-700" />
                  <span className="text-sm">{t(`join.mode.${value}`)}</span>
                </label>
              ))}
            </fieldset>
          )}

          {inv.kind === 'team' && mode === 'self' && (
            <>
              <p className="text-xs text-slate-500">{loggedIn ? t('join.profileIfMissing') : null}</p>
              <div className="grid grid-cols-2 gap-3">
                <Field label={t('profile.fields.first_name')} required={!loggedIn} value={form.first_name ?? ''} onChange={(e) => set('first_name')(e.target.value)} />
                <Field label={t('profile.fields.last_name')} required={!loggedIn} value={form.last_name ?? ''} onChange={(e) => set('last_name')(e.target.value)} />
              </div>
              <Select label={t('profile.fields.gender')} value={form.gender} onChange={(e) => set('gender')(e.target.value)}>
                <option value="male">{t('genders.male')}</option>
                <option value="female">{t('genders.female')}</option>
              </Select>
              <Field label={t('join.birthdayOptional')} type="date" value={form.birthday ?? ''} onChange={(e) => set('birthday')(e.target.value)} />
            </>
          )}

          {inv.kind === 'team' && mode === 'child' && (
            <>
              <p className="text-sm text-slate-600">{t('join.childIntro')}</p>
              <div className="grid grid-cols-2 gap-3">
                <Field label={t('join.childFirstName')} required value={form.child_first_name ?? ''} onChange={(e) => set('child_first_name')(e.target.value)} />
                <Field label={t('profile.fields.last_name')} required value={form.child_last_name ?? ''} onChange={(e) => set('child_last_name')(e.target.value)} />
              </div>
              <Select label={t('profile.fields.gender')} value={form.child_gender} onChange={(e) => set('child_gender')(e.target.value)}>
                <option value="male">{t('genders.male')}</option>
                <option value="female">{t('genders.female')}</option>
              </Select>
              <Field label={t('profile.fields.birthday')} type="date" required value={form.child_birthday ?? ''} onChange={(e) => set('child_birthday')(e.target.value)} />
            </>
          )}

          {!loggedIn && (
            <Field
              label={mode === 'child' ? t('join.parentEmail') : t('login.email')}
              hint={t('join.emailHint')}
              type="email"
              autoComplete="email"
              required
              value={form.email ?? ''}
              onChange={(e) => set('email')(e.target.value)}
            />
          )}

          {accept.isError && <Notice tone="error">{errorText(accept.error, t('errors.generic'))}</Notice>}
          <Button type="submit" className="w-full" disabled={accept.isPending}>
            {t('join.submit')}
          </Button>
        </form>
      )}
    </div>
  );
}
