import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';
import { api, errorText, post, type ChatDetail, type ChatSummary } from '../lib/api';
import { MemberPicker } from '../components/chat/MemberPicker';
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
  const [name, setName] = useState('');
  const [mode, setMode] = useState<'flat' | 'topics'>('flat');
  const [chosen, setChosen] = useState<string[]>([]);

  const create = useMutation({
    mutationFn: () => post<{ data: ChatDetail }>(tp('chats'), { name, mode, member_ids: chosen }),
    onSuccess: async ({ data }) => {
      await queryClient.invalidateQueries({ queryKey: ['chats', slug] });
      onDone();
      navigate(`/${slug}/chat/${data.id}`);
    },
  });

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
          <MemberPicker chosen={chosen} onChange={setChosen} />
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
