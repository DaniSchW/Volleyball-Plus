import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useParams, useSearchParams } from 'react-router';
import { api, del, errorText, patch, post, upload, type ChatAttachment, type ChatDetail, type ChatMessage, type ChatMessagesPage } from '../lib/api';
import { Attachments } from '../components/chat/Attachments';
import { formatDay, formatTime } from '../lib/dates';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Loading, Notice } from '../components/ui';

/** How often an open chat asks for new messages (no websockets on shared hosting). */
const POLL_MS = 4000;

export function ChatPage() {
  const { t } = useTranslation();
  const { chatId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const chat = useQuery({ queryKey: ['chat', chatId], queryFn: async () => (await api<{ data: ChatDetail }>(tp(`chats/${chatId}`))).data });
  const [settings, setSettings] = useState(false);

  if (chat.isPending) return <Loading />;
  if (chat.isError) return <Notice tone="error">{errorText(chat.error, t('errors.generic'))}</Notice>;

  const c = chat.data;
  const topicId = c.mode === 'topics' ? (params.get('topic') ?? c.topics[0]?.id ?? null) : null;

  return (
    <div className="-my-4 flex h-[calc(100dvh-3.5rem-env(safe-area-inset-top))] flex-col">
      <div className="flex items-center justify-between gap-2 border-b border-slate-200 py-2">
        <Link to={`/${slug}/chat`} className="shrink-0 rounded p-2 text-slate-600" aria-label={t('chat.back')}>‹</Link>
        <h1 className="min-w-0 flex-1 truncate text-base font-semibold">{c.name}</h1>
        {c.can_moderate && (
          <button type="button" className="rounded p-2 text-sm text-brand-700" onClick={() => setSettings((s) => !s)} aria-expanded={settings}>
            {t('chat.settings')}
          </button>
        )}
      </div>
      {settings && <ChatSettings chat={c} />}
      {c.mode === 'topics' && (
        <div role="tablist" aria-label={t('chat.topics')} className="-mx-4 flex gap-2 overflow-x-auto px-4 py-2">
          {c.topics.map((topic) => (
            <button
              key={topic.id}
              type="button"
              role="tab"
              aria-selected={topic.id === topicId}
              onClick={() => setParams({ topic: topic.id }, { replace: true })}
              className={`min-h-9 shrink-0 rounded-full border px-3 text-sm ${topic.id === topicId ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white'}`}
            >
              # {topic.name}
              {topic.unread > 0 && topic.id !== topicId && <span className="ml-1 font-semibold">({topic.unread})</span>}
            </button>
          ))}
        </div>
      )}
      {(c.mode === 'flat' || topicId) && <Conversation key={topicId ?? 'flat'} chatId={c.id} topicId={topicId} uploadInfo={c.upload} />}
    </div>
  );
}

function Conversation({ chatId, topicId, uploadInfo }: { chatId: string; topicId: string | null; uploadInfo: ChatDetail['upload'] }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [hasMore, setHasMore] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  const since = useRef<string | null>(null);
  const scroller = useRef<HTMLDivElement>(null);
  const stickToBottom = useRef(true);
  const [reply, setReply] = useState<ChatMessage | null>(null);
  const [editing, setEditing] = useState<ChatMessage | null>(null);
  const [active, setActive] = useState<string | null>(null);

  const base = `chats/${chatId}/messages${topicId ? `?topic=${topicId}` : ''}`;
  const firstUrl = tp(base);
  const withParams = (extra: string) => tp(`${base}${base.includes('?') ? '&' : '?'}${extra}`);

  const merge = useCallback((incoming: ChatMessage[], changed: ChatMessage[] = []) => {
    setMessages((current) => {
      const byId = new Map(current.map((m) => [m.id, m]));
      [...incoming, ...changed].forEach((m) => byId.set(m.id, m));
      return [...byId.values()].sort((a, b) => (a.id < b.id ? -1 : 1));
    });
  }, []);

  // First page.
  useEffect(() => {
    let cancelled = false;
    api<ChatMessagesPage>(firstUrl).then((page) => {
      if (cancelled) return;
      setMessages(page.data);
      setHasMore(page.has_more);
      since.current = page.server_time;
      setLoaded(true);
      void queryClient.invalidateQueries({ queryKey: ['chats'] });
    }, () => setFailed(true));
    return () => { cancelled = true; };
  }, [firstUrl, queryClient]);

  // Polling while the page is visible.
  const lastId = messages[messages.length - 1]?.id;
  useEffect(() => {
    if (!loaded) return;
    const tick = async () => {
      if (document.visibilityState !== 'visible') return;
      try {
        const query = [lastId && `after=${lastId}`, since.current && `since=${encodeURIComponent(since.current)}`].filter(Boolean).join('&');
        const page = await api<ChatMessagesPage>(lastId ? withParams(query) : firstUrl);
        since.current = page.server_time;
        if (page.data.length || page.changed.length) merge(page.data, page.changed);
      } catch {
        // Next tick tries again.
      }
    };
    const timer = window.setInterval(() => void tick(), POLL_MS);
    return () => window.clearInterval(timer);
  });

  // Keep the view at the newest message unless the user scrolled up.
  useEffect(() => {
    const el = scroller.current;
    if (el && stickToBottom.current) el.scrollTop = el.scrollHeight;
  }, [messages]);

  const older = useMutation({
    mutationFn: () => api<ChatMessagesPage>(withParams(`before=${messages[0].id}`)),
    onSuccess: (page) => {
      stickToBottom.current = false;
      merge(page.data);
      setHasMore(page.has_more);
    },
  });
  const remove = useMutation({
    mutationFn: (id: string) => del<{ data: ChatMessage }>(tp(`chat-messages/${id}`)),
    onSuccess: ({ data }) => merge([], [data]),
  });

  if (failed) return <Notice tone="error">{t('errors.generic')}</Notice>;

  return (
    <>
      <div
        ref={scroller}
        className="min-h-0 flex-1 overflow-y-auto py-3"
        onScroll={(e) => {
          const el = e.currentTarget;
          stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        }}
      >
        {!loaded && <Loading />}
        {hasMore && (
          <div className="mb-3 text-center">
            <button type="button" className="text-sm text-brand-700 underline" onClick={() => older.mutate()} disabled={older.isPending}>{t('chat.older')}</button>
          </div>
        )}
        {loaded && messages.length === 0 && <p className="py-8 text-center text-sm text-slate-500">{t('chat.empty')}</p>}
        <ol className="space-y-2">
          {messages.map((m, i) => {
            const newDay = i === 0 || new Date(messages[i - 1].created_at).toDateString() !== new Date(m.created_at).toDateString();
            const sameAuthor = !newDay && i > 0 && messages[i - 1].author.id === m.author.id;
            return (
              <li key={m.id}>
                {newDay && <p className="my-3 text-center text-xs text-slate-500">{formatDay(m.created_at)}</p>}
                <Bubble
                  message={m}
                  showAuthor={!m.is_mine && !sameAuthor}
                  active={active === m.id}
                  onToggle={() => setActive(active === m.id ? null : m.id)}
                  onReply={() => { setReply(m); setEditing(null); setActive(null); }}
                  onEdit={() => { setEditing(m); setReply(null); setActive(null); }}
                  onDelete={() => { setActive(null); if (window.confirm(t('chat.deleteConfirm'))) remove.mutate(m.id); }}
                />
              </li>
            );
          })}
        </ol>
      </div>
      <Composer
        chatId={chatId}
        topicId={topicId}
        uploadInfo={uploadInfo}
        reply={reply}
        editing={editing}
        onCancel={() => { setReply(null); setEditing(null); }}
        onSent={(m) => { stickToBottom.current = true; merge([m]); setReply(null); setEditing(null); }}
      />
    </>
  );
}

function Bubble({ message: m, showAuthor, active, onToggle, onReply, onEdit, onDelete }: {
  message: ChatMessage;
  showAuthor: boolean;
  active: boolean;
  onToggle: () => void;
  onReply: () => void;
  onEdit: () => void;
  onDelete: () => void;
}) {
  const { t } = useTranslation();

  return (
    <div className={`flex flex-col ${m.is_mine ? 'items-end' : 'items-start'}`}>
      {showAuthor && <span className="mb-0.5 px-1 text-xs font-medium text-slate-600">{m.author.name}</span>}
      <button
        type="button"
        onClick={onToggle}
        disabled={m.deleted}
        className={`${m.attachments.length > 0 ? 'w-[85%] sm:w-80' : 'max-w-[85%]'} whitespace-pre-wrap break-words rounded-2xl px-3 py-2 text-left text-sm ${
          m.deleted ? 'border border-dashed border-slate-300 italic text-slate-500' : m.is_mine ? 'bg-brand-700 text-white' : 'border border-slate-200 bg-white'
        }`}
      >
        {m.reply_to && (
          <span className={`mb-1 block border-l-2 pl-2 text-xs ${m.is_mine ? 'border-white/60 text-white/80' : 'border-slate-300 text-slate-500'}`}>
            <span className="font-medium">{m.reply_to.author}</span>: {m.reply_to.body ?? t('chat.deleted')}
          </span>
        )}
        {!m.deleted && m.attachments.length > 0 && <div className={m.body ? 'mb-1' : ''}><Attachments items={m.attachments} mine={m.is_mine} /></div>}
        {m.deleted ? t('chat.deleted') : m.body}
        <span className={`mt-0.5 block text-right text-[11px] ${m.is_mine && !m.deleted ? 'text-white/70' : 'text-slate-400'}`}>
          {m.edited && !m.deleted && `${t('chat.edited')} · `}{formatTime(m.created_at)}
        </span>
      </button>
      {active && !m.deleted && (
        <div className="mt-1 flex gap-3 px-1 text-xs">
          <button type="button" className="text-brand-700" onClick={onReply}>{t('chat.reply')}</button>
          {m.is_mine && <button type="button" className="text-brand-700" onClick={onEdit}>{t('chat.edit')}</button>}
          {m.can_delete && <button type="button" className="text-red-700" onClick={onDelete}>{t('chat.delete')}</button>}
        </div>
      )}
    </div>
  );
}

interface PendingUpload {
  key: string;
  name: string;
  progress: number;
  attachment?: ChatAttachment;
  error?: string;
}

function Composer({ chatId, topicId, uploadInfo, reply, editing, onCancel, onSent }: {
  chatId: string;
  topicId: string | null;
  uploadInfo: ChatDetail['upload'];
  reply: ChatMessage | null;
  editing: ChatMessage | null;
  onCancel: () => void;
  onSent: (message: ChatMessage) => void;
}) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [body, setBody] = useState('');
  const [uploads, setUploads] = useState<PendingUpload[]>([]);
  const fileInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (editing) setBody(editing.body ?? '');
  }, [editing]);

  const ready = uploads.filter((u) => u.attachment).map((u) => u.attachment!.id);
  const uploading = uploads.some((u) => !u.attachment && !u.error);

  const send = useMutation({
    mutationFn: () => editing
      ? patch<{ data: ChatMessage }>(tp(`chat-messages/${editing.id}`), { body })
      : post<{ data: ChatMessage }>(tp(`chats/${chatId}/messages`), { body, topic_id: topicId, reply_to_id: reply?.id ?? null, attachment_ids: ready }),
    onSuccess: ({ data }) => {
      setBody('');
      setUploads([]);
      onSent(data);
    },
  });

  const addFiles = (files: FileList | null) => {
    if (!files) return;
    const room = uploadInfo.per_message - uploads.length;
    [...files].slice(0, Math.max(0, room)).forEach((file) => {
      const key = `${file.name}-${file.size}-${Math.random()}`;
      const update = (patchValue: Partial<PendingUpload>) => setUploads((list) => list.map((u) => (u.key === key ? { ...u, ...patchValue } : u)));
      if (file.size > uploadInfo.max_bytes) {
        setUploads((list) => [...list, { key, name: file.name, progress: 0, error: t('chat.tooBig', { mb: Math.floor(uploadInfo.max_bytes / 1048576) }) }]);
        return;
      }
      setUploads((list) => [...list, { key, name: file.name, progress: 0 }]);
      upload<{ data: ChatAttachment }>(tp(`chats/${chatId}/attachments`), file, (progress) => update({ progress }))
        .then(({ data }) => update({ attachment: data, progress: 1 }))
        .catch((error) => update({ error: errorText(error, t('errors.generic')) }));
    });
    if (fileInput.current) fileInput.current.value = '';
  };

  const submit = (event?: FormEvent) => {
    event?.preventDefault();
    if ((body.trim() || (ready.length > 0 && !editing)) && !uploading && !send.isPending) send.mutate();
  };

  return (
    <form onSubmit={submit} className="border-t border-slate-200 bg-slate-50 pb-[calc(0.5rem+env(safe-area-inset-bottom))] pt-2">
      {(reply || editing) && (
        <div className="mb-2 flex items-center justify-between gap-2 rounded-lg bg-white px-3 py-1 text-xs text-slate-600">
          <span className="min-w-0 truncate">
            {editing ? t('chat.editing') : t('chat.replyingTo', { name: reply!.author.name })}
            {reply && `: ${reply.body ?? ''}`}
          </span>
          <button type="button" className="shrink-0 text-slate-500" aria-label={t('common.cancel')} onClick={() => { setBody(''); onCancel(); }}>✕</button>
        </div>
      )}
      {send.isError && <div className="mb-2"><Notice tone="error">{errorText(send.error, t('errors.generic'))}</Notice></div>}
      {uploads.length > 0 && (
        <ul className="mb-2 flex flex-wrap gap-2">
          {uploads.map((u) => (
            <li key={u.key} className={`flex max-w-full items-center gap-2 rounded-full px-3 py-1 text-xs ${u.error ? 'bg-red-50 text-red-800' : 'bg-white'}`}>
              <span className="min-w-0 truncate">{u.name}</span>
              {u.error ? <span>{u.error}</span> : !u.attachment && <span className="tabular-nums text-slate-500">{Math.round(u.progress * 100)} %</span>}
              <button type="button" className="text-slate-500" aria-label={t('chat.removeFile', { name: u.name })} onClick={() => setUploads((list) => list.filter((x) => x.key !== u.key))}>✕</button>
            </li>
          ))}
        </ul>
      )}
      <div className="flex items-end gap-2">
        {!editing && (
          <>
            <button
              type="button"
              className="flex size-11 shrink-0 items-center justify-center rounded-full text-xl text-slate-600 hover:bg-slate-200 disabled:opacity-40"
              aria-label={t('chat.attach')}
              disabled={uploads.length >= uploadInfo.per_message}
              onClick={() => fileInput.current?.click()}
            >
              📎
            </button>
            <input
              ref={fileInput}
              type="file"
              multiple
              className="sr-only"
              tabIndex={-1}
              aria-hidden="true"
              accept={['image/*', 'video/*', ...uploadInfo.extensions.map((e) => `.${e}`)].join(',')}
              onChange={(e) => addFiles(e.target.files)}
            />
          </>
        )}
        <textarea
          rows={Math.min(4, Math.max(1, body.split('\n').length))}
          className="min-h-11 min-w-0 flex-1 resize-none rounded-2xl border border-slate-300 px-3 py-2 text-base"
          placeholder={t('chat.placeholder')}
          aria-label={t('chat.placeholder')}
          maxLength={4000}
          value={body}
          onChange={(e) => setBody(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) submit();
          }}
        />
        <Button type="submit" disabled={(!body.trim() && (editing !== null || ready.length === 0)) || uploading || send.isPending}>{editing ? t('common.save') : t('chat.send')}</Button>
      </div>
    </form>
  );
}

/** Moderators: group chat or topics, new topics. */
function ChatSettings({ chat }: { chat: ChatDetail }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [topic, setTopic] = useState('');
  const done = ({ data }: { data: ChatDetail }) => queryClient.setQueryData(['chat', chat.id], data);
  const mode = useMutation({ mutationFn: (value: 'flat' | 'topics') => patch<{ data: ChatDetail }>(tp(`chats/${chat.id}`), { mode: value }), onSuccess: done });
  const add = useMutation({
    mutationFn: () => post<{ data: ChatDetail }>(tp(`chats/${chat.id}/topics`), { name: topic }),
    onSuccess: (result) => { setTopic(''); done(result); },
  });

  return (
    <div className="space-y-3 border-b border-slate-200 py-3 text-sm">
      <div role="group" aria-label={t('chat.mode')} className="flex gap-2">
        {(['flat', 'topics'] as const).map((value) => (
          <button
            key={value}
            type="button"
            aria-pressed={chat.mode === value}
            onClick={() => chat.mode !== value && mode.mutate(value)}
            className={`min-h-9 rounded-full border px-3 ${chat.mode === value ? 'border-brand-700 bg-brand-700 text-white' : 'border-slate-300 bg-white'}`}
          >
            {t(`chat.modes.${value}`)}
          </button>
        ))}
      </div>
      {chat.mode === 'topics' && (
        <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); add.mutate(); }}>
          <input
            className="min-h-11 min-w-0 flex-1 rounded-lg border border-slate-300 px-3 text-base"
            placeholder={t('chat.newTopic')}
            aria-label={t('chat.newTopic')}
            maxLength={60}
            value={topic}
            onChange={(e) => setTopic(e.target.value)}
          />
          <Button type="submit" variant="secondary" disabled={!topic.trim() || add.isPending}>{t('common.add')}</Button>
        </form>
      )}
      <p className="text-xs text-slate-500">{t('chat.membersList', { names: chat.members.map((m) => m.name).join(', ') })}</p>
    </div>
  );
}
