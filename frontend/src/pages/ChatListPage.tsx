import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { api, errorText, post, type ChatDetail, type ChatSummary, type MemberRow } from '../lib/api';
import { formatDay, formatTime } from '../lib/dates';
import { useTenantMe, useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Field, Loading, Notice, PageTitle, Select } from '../components/ui';

/** All chats of the member: team chats and the custom chats they belong to. */
export function ChatListPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const me = useTenantMe();
  const [creating, setCreating] = useState(false);
  const chats = useQuery({
    queryKey: ['chats', slug],
    queryFn: async () => (await api<{ data: ChatSummary[] }>(tp('chats'))).data,
    refetchInterval: 30_000,
  });

  if (chats.isPending) return <Loading />;
  if (chats.isError) return <Notice tone="error">{t('errors.generic')}</Notice>;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <PageTitle>{t('menu.chat')}</PageTitle>
        {me.data?.is_admin && !creating && <Button onClick={() => setCreating(true)}>{t('chat.create')}</Button>}
      </div>
      {creating && <CreateChat onDone={() => setCreating(false)} />}
      {chats.data.length === 0 && <Notice>{t('chat.none')}</Notice>}
      <ul className="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 bg-white">
        {chats.data.map((chat) => (
          <li key={chat.id}>
            <Link to={`/${slug}/chat/${chat.id}`} className="flex min-h-16 items-center gap-3 px-4 py-2 hover:bg-slate-50">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-lg" aria-hidden="true">
                {chat.kind === 'team' ? '🏐' : '💬'}
              </span>
              <span className="min-w-0 flex-1">
                <span className="flex items-baseline justify-between gap-2">
                  <span className="truncate font-medium">{chat.name}</span>
                  {chat.last_message && <span className="shrink-0 text-xs text-slate-500">{formatWhen(chat.last_message.created_at)}</span>}
                </span>
                <span className="flex items-center justify-between gap-2">
                  <span className="truncate text-sm text-slate-600">
                    {chat.last_message
                      ? `${chat.last_message.author ?? ''}: ${chat.last_message.body ?? t('chat.deleted')}`
                      : t('chat.empty')}
                  </span>
                  {chat.unread > 0 && (
                    <span className="shrink-0 rounded-full bg-brand-700 px-2 text-xs font-semibold leading-5 text-white" aria-label={t('chat.unread', { count: chat.unread })}>
                      {chat.unread}
                    </span>
                  )}
                </span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

function formatWhen(iso: string): string {
  const date = new Date(iso);
  return date.toDateString() === new Date().toDateString() ? formatTime(iso) : formatDay(iso);
}

/** Admins: a chat with freely chosen members (e.g. the board). */
function CreateChat({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const members = useQuery({ queryKey: ['members', slug], queryFn: async () => (await api<{ data: MemberRow[] }>(tp('members'))).data });
  const [name, setName] = useState('');
  const [mode, setMode] = useState<'flat' | 'topics'>('flat');
  const [chosen, setChosen] = useState<string[]>([]);
  const [filter, setFilter] = useState('');

  const create = useMutation({
    mutationFn: () => post<{ data: ChatDetail }>(tp('chats'), { name, mode, member_ids: chosen }),
    onSuccess: async ({ data }) => {
      await queryClient.invalidateQueries({ queryKey: ['chats', slug] });
      onDone();
      navigate(`/${slug}/chat/${data.id}`);
    },
  });

  const label = (m: MemberRow) => m.persons.find((p) => p.relation === 'self')?.name ?? m.persons[0]?.name ?? m.email;
  const list = (members.data ?? [])
    .filter((m) => m.status === 'active')
    .filter((m) => !filter || label(m).toLowerCase().includes(filter.toLowerCase()))
    .sort((a, b) => label(a).localeCompare(label(b)));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    create.mutate();
  };

  return (
    <form onSubmit={submit}>
      <Card title={t('chat.create')}>
        <div className="space-y-3">
          <Field label={t('chat.name')} required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} placeholder={t('chat.namePlaceholder')} />
          <Select label={t('chat.mode')} value={mode} onChange={(e) => setMode(e.target.value as 'flat' | 'topics')}>
            <option value="flat">{t('chat.modes.flat')}</option>
            <option value="topics">{t('chat.modes.topics')}</option>
          </Select>
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
                      onChange={(e) => setChosen((c) => (e.target.checked ? [...c, m.id] : c.filter((x) => x !== m.id)))}
                    />
                    {label(m)}
                  </label>
                </li>
              ))}
            </ul>
          </fieldset>
          {create.isError && <Notice tone="error">{errorText(create.error, t('errors.generic'))}</Notice>}
          <div className="flex gap-2">
            <Button type="submit" disabled={create.isPending || chosen.length === 0}>{t('common.create')}</Button>
            <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
          </div>
        </div>
      </Card>
    </form>
  );
}
