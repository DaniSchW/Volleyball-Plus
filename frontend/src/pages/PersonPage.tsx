import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router';
import {
  api, del, DETAIL_FIELDS, errorText, patch, post, POSITIONS, put,
  type Invitation, type MinorPermissions, type PersonDetail, type Position,
} from '../lib/api';
import { useTenantPath } from '../lib/queries';
import { Avatar, Button, Card, Field, Loading, Notice, PageTitle, Select, Toggle } from '../components/ui';
import { RefereeLicenseCard } from '../components/team/RefereeLicenseCard';
import { ShareLink } from './TeamPage';

export function PersonPage() {
  const { t } = useTranslation();
  const { personId = '' } = useParams();
  const tp = useTenantPath();

  const person = useQuery({
    queryKey: ['person', personId],
    queryFn: async () => (await api<{ data: PersonDetail }>(tp(`persons/${personId}`))).data,
  });

  if (person.isPending) return <Loading />;
  if (person.isError) return <Notice tone="error">{errorText(person.error, t('errors.generic'))}</Notice>;

  const p = person.data;
  const canEdit = p.editable_fields.length > 0;

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-4">
        <Avatar url={p.avatar_url} name={`${p.first_name} ${p.last_name}`} size={72} />
        <div>
          <PageTitle>{p.first_name} {p.last_name}</PageTitle>
          <p className="-mt-3 text-sm text-slate-500">{p.teams.map((team) => `${team.name} (${t(`roles.${team.role}`)})`).join(', ')}</p>
        </div>
      </div>

      {canEdit ? <ProfileForm person={p} /> : <ProfileView person={p} />}
      {(p.can_manage_profile || p.editable_fields.length > 0) && <AvatarUpload person={p} />}
      <RefereeLicenseCard person={p} />
      {p.can_manage_minor_permissions && p.minor_permissions && <ChildPermissions person={p} permissions={p.minor_permissions} />}
      {(p.can_invite_guardian || p.can_invite_own) && <PersonLinks person={p} />}
    </div>
  );
}

/** Read-only view with the fields the viewer may see. */
function ProfileView({ person: p }: { person: PersonDetail }) {
  const { t } = useTranslation();
  const rows: [string, string | number | null][] = [
    [t('profile.fields.jersey_number'), p.jersey_number],
    [t('profile.fields.primary_position'), p.primary_position && t(`positions.${p.primary_position}`)],
    [t('profile.fields.secondary_positions'), p.secondary_positions.map((pos) => t(`positions.${pos}`)).join(', ') || null],
    [t('profile.fields.height_cm'), p.height_cm && `${p.height_cm} cm`],
    [t('profile.fields.reach_height_cm'), p.reach_height_cm && `${p.reach_height_cm} cm`],
    [t('profile.fields.jump_reach_cm'), p.jump_reach_cm && `${p.jump_reach_cm} cm`],
    [t('profile.fields.phone'), p.phone],
    [t('profile.fields.email'), p.email],
    [t('profile.fields.address'), p.address && [p.address.street, [p.address.postal_code, p.address.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')],
    [t('profile.fields.birthday'), p.birthday && new Date(p.birthday).toLocaleDateString()],
  ];

  return (
    <Card>
      <dl className="divide-y divide-slate-100">
        {rows.filter(([, value]) => value).map(([label, value]) => (
          <div key={label} className="flex justify-between gap-4 py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="text-right">
              {label === t('profile.fields.phone') ? <a className="text-brand-700 underline" href={`tel:${value}`}>{value}</a> : value}
            </dd>
          </div>
        ))}
      </dl>
    </Card>
  );
}

type FormState = Record<string, string> & { secondary_positions?: never };

function toForm(p: PersonDetail): FormState {
  return {
    first_name: p.first_name,
    last_name: p.last_name,
    jersey_number: p.jersey_number?.toString() ?? '',
    birthday: p.birthday ?? '',
    gender: p.gender,
    phone: p.phone ?? '',
    email: p.email ?? '',
    street: p.address?.street ?? '',
    postal_code: p.address?.postal_code ?? '',
    city: p.address?.city ?? '',
    country: p.address?.country ?? 'DE',
    primary_position: p.primary_position ?? '',
    height_cm: p.height_cm?.toString() ?? '',
    reach_height_cm: p.reach_height_cm?.toString() ?? '',
    jump_reach_cm: p.jump_reach_cm?.toString() ?? '',
  } as FormState;
}

function ProfileForm({ person }: { person: PersonDetail }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [form, setForm] = useState<FormState>(() => toForm(person));
  const [secondary, setSecondary] = useState<Position[]>(person.secondary_positions);
  const [shared, setShared] = useState<string[]>(person.shared_fields ?? []);
  useEffect(() => setForm(toForm(person)), [person]);

  const can = (field: string) => person.editable_fields.includes(field);
  const set = (key: string) => (value: string) => setForm((f) => ({ ...f, [key]: value }));
  const num = (value: string) => (value === '' ? null : Number(value));

  const save = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {};
      for (const key of ['first_name', 'last_name', 'gender', 'phone', 'email', 'birthday', 'primary_position'] as const) {
        if (can(key)) body[key] = form[key] === '' ? null : form[key];
      }
      for (const key of ['jersey_number', 'height_cm', 'reach_height_cm', 'jump_reach_cm'] as const) {
        if (can(key)) body[key] = num(form[key]);
      }
      if (can('secondary_positions')) body.secondary_positions = secondary;
      if (can('address')) {
        body.address = { street: form.street || null, postal_code: form.postal_code || null, city: form.city || null, country: form.country || null };
      }
      if (person.can_manage_profile) body.shared_fields = shared;
      return patch<{ data: PersonDetail }>(tp(`persons/${person.id}`), body);
    },
    onSuccess: ({ data }) => queryClient.setQueryData(['person', person.id], data),
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  const field = (key: string, props: Partial<Parameters<typeof Field>[0]> = {}) =>
    can(key) && <Field label={t(`profile.fields.${key}`)} value={form[key]} onChange={(e) => set(key)(e.target.value)} {...props} />;

  return (
    <form onSubmit={submit} className="space-y-4">
      <Card title={t('profile.standard')}>
        <p className="mb-3 text-xs text-slate-500">{t('profile.standardHint')}</p>
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            {field('first_name', { required: true })}
            {field('last_name', { required: true })}
          </div>
          {can('gender') && (
            <Select label={t('profile.fields.gender')} value={form.gender} onChange={(e) => set('gender')(e.target.value)}>
              <option value="male">{t('genders.male')}</option>
              <option value="female">{t('genders.female')}</option>
            </Select>
          )}
          {field('jersey_number', { type: 'number', min: 0, max: 99, inputMode: 'numeric' })}
          {can('primary_position') && (
            <Select label={t('profile.fields.primary_position')} value={form.primary_position} onChange={(e) => set('primary_position')(e.target.value)}>
              <option value="">–</option>
              {POSITIONS.map((pos) => <option key={pos} value={pos}>{t(`positions.${pos}`)}</option>)}
            </Select>
          )}
          {can('secondary_positions') && (
            <fieldset>
              <legend className="mb-1 text-sm font-medium text-slate-700">{t('profile.fields.secondary_positions')}</legend>
              <div className="flex flex-wrap gap-2">
                {POSITIONS.map((pos) => (
                  <label key={pos} className={`flex min-h-10 items-center gap-2 rounded-full border px-3 text-sm ${secondary.includes(pos) ? 'border-brand-600 bg-brand-50' : 'border-slate-300'}`}>
                    <input
                      type="checkbox"
                      className="sr-only"
                      checked={secondary.includes(pos)}
                      onChange={(e) => setSecondary((s) => (e.target.checked ? [...s, pos] : s.filter((x) => x !== pos)))}
                    />
                    {t(`positions.${pos}`)}
                  </label>
                ))}
              </div>
            </fieldset>
          )}
          <div className="grid grid-cols-3 gap-3">
            {field('height_cm', { type: 'number', min: 100, max: 250, inputMode: 'numeric' })}
            {field('reach_height_cm', { type: 'number', min: 150, max: 350, inputMode: 'numeric' })}
            {field('jump_reach_cm', { type: 'number', min: 150, max: 400, inputMode: 'numeric' })}
          </div>
        </div>
      </Card>

      {DETAIL_FIELDS.some((key) => can(key)) && (
        <Card title={t('profile.details')}>
          <p className="mb-3 text-xs text-slate-500">{t('profile.detailsHint')}</p>
          <div className="space-y-3">
            {field('phone', { type: 'tel', autoComplete: 'tel' })}
            {field('email', { type: 'email', autoComplete: 'email' })}
            {can('address') && (
              <>
                <Field label={t('profile.fields.street')} value={form.street} autoComplete="street-address" onChange={(e) => set('street')(e.target.value)} />
                <div className="grid grid-cols-3 gap-3">
                  <Field label={t('profile.fields.postal_code')} value={form.postal_code} autoComplete="postal-code" onChange={(e) => set('postal_code')(e.target.value)} />
                  <div className="col-span-2">
                    <Field label={t('profile.fields.city')} value={form.city} autoComplete="address-level2" onChange={(e) => set('city')(e.target.value)} />
                  </div>
                </div>
              </>
            )}
            {field('birthday', { type: 'date' })}
          </div>
        </Card>
      )}

      {person.can_manage_profile && (
        <Card title={t('profile.sharing')}>
          <p className="mb-2 text-xs text-slate-500">{t('profile.sharingHint')}</p>
          {DETAIL_FIELDS.map((key) => (
            <Toggle
              key={key}
              label={t(`profile.fields.${key}`)}
              checked={shared.includes(key)}
              onChange={(on) => setShared((s) => (on ? [...s, key] : s.filter((x) => x !== key)))}
            />
          ))}
        </Card>
      )}

      {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
      {save.isSuccess && <Notice tone="success">{t('common.saved')}</Notice>}
      <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
    </form>
  );
}

function AvatarUpload({ person }: { person: PersonDetail }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();

  const upload = useMutation({
    mutationFn: (file: File) => {
      const body = new FormData();
      body.append('image', file);
      return post<{ data: PersonDetail }>(tp(`persons/${person.id}/avatar`), body);
    },
    onSuccess: ({ data }) => queryClient.setQueryData(['person', person.id], data),
  });
  const remove = useMutation({
    mutationFn: () => del<{ data: PersonDetail }>(tp(`persons/${person.id}/avatar`)),
    onSuccess: ({ data }) => queryClient.setQueryData(['person', person.id], data),
  });

  return (
    <Card title={t('profile.picture')}>
      <div className="flex flex-wrap items-center gap-3">
        <label className="inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-slate-300 px-4 text-sm font-medium hover:bg-slate-50">
          {t('profile.pictureUpload')}
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="sr-only"
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) upload.mutate(file);
            }}
          />
        </label>
        {person.avatar_url && <Button variant="danger" onClick={() => remove.mutate()}>{t('profile.pictureRemove')}</Button>}
      </div>
      {upload.isPending && <p className="mt-2 text-sm text-slate-500">{t('profile.pictureUploading')}</p>}
      {upload.isError && <div className="mt-2"><Notice tone="error">{errorText(upload.error, t('errors.generic'))}</Notice></div>}
    </Card>
  );
}

const CHILD_FLAGS = ['own_login', 'can_rsvp', 'can_chat', 'can_take_tasks', 'can_vote_polls'] as const;
const CHILD_EDITABLE = ['avatar', 'phone', 'email', 'jersey_number', 'primary_position', 'secondary_positions', 'height_cm', 'reach_height_cm', 'jump_reach_cm'];

/** Parents decide what the child may do on its own. */
function ChildPermissions({ person, permissions }: { person: PersonDetail; permissions: MinorPermissions }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [form, setForm] = useState(permissions);

  const save = useMutation({
    mutationFn: () => put(tp(`persons/${person.id}/minor-permissions`), { ...form }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['person', person.id] }),
  });

  return (
    <Card title={t('child.title')}>
      <p className="mb-2 text-xs text-slate-500">{t('child.hint')}</p>
      {CHILD_FLAGS.map((flag) => (
        <Toggle key={flag} label={t(`child.flags.${flag}`)} checked={form[flag]} onChange={(v) => setForm((f) => ({ ...f, [flag]: v }))} />
      ))}
      <p className="mt-3 mb-1 text-sm font-medium">{t('child.editable')}</p>
      {CHILD_EDITABLE.map((key) => (
        <Toggle
          key={key}
          label={key === 'avatar' ? t('profile.picture') : t(`profile.fields.${key}`)}
          checked={form.editable_profile_fields.includes(key)}
          onChange={(on) => setForm((f) => ({
            ...f,
            editable_profile_fields: on ? [...f.editable_profile_fields, key] : f.editable_profile_fields.filter((x) => x !== key),
          }))}
        />
      ))}
      {save.isSuccess && <div className="mt-2"><Notice tone="success">{t('common.saved')}</Notice></div>}
      <Button className="mt-3" onClick={() => save.mutate()} disabled={save.isPending}>{t('common.save')}</Button>
    </Card>
  );
}

/**
 * Single-use links for this profile: invite a parent, or give the person
 * (a child: only by its parents) an own login.
 */
function PersonLinks({ person }: { person: PersonDetail }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [link, setLink] = useState<Invitation | null>(null);

  const create = useMutation({
    mutationFn: (relation: 'self' | 'guardian') => post<{ data: Invitation }>(tp(`persons/${person.id}/invitations`), { relation }),
    onSuccess: ({ data }) => setLink(data),
  });

  return (
    <Card title={t('personLinks.title')}>
      <p className="mb-3 text-xs text-slate-500">{t('personLinks.hint')}</p>
      <div className="flex flex-wrap gap-2">
        {person.can_invite_guardian && <Button variant="secondary" onClick={() => create.mutate('guardian')}>{t('personLinks.guardian')}</Button>}
        {person.can_invite_own && <Button variant="secondary" onClick={() => create.mutate('self')}>{t('personLinks.own')}</Button>}
      </div>
      {create.isError && <div className="mt-2"><Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice></div>}
      {link && (
        <div className="mt-3">
          <p className="text-sm">{link.relation === 'guardian' ? t('personLinks.createdGuardian') : t('personLinks.createdOwn')}</p>
          <ShareLink url={link.url} title={`${person.first_name} ${person.last_name}`} />
        </div>
      )}
    </Card>
  );
}
