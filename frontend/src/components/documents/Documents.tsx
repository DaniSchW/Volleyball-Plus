import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, patch, post, type DocumentItem, type DocumentList } from '../../lib/api';
import { formatDate, toLocalInput } from '../../lib/dates';
import { useTenantPath, useTenantSlug } from '../../lib/queries';
import { Button, Card, Field, Loading, Notice, SaveBar, Select } from '../ui';

const ICON = (mime: string | null) =>
  mime === 'application/pdf' ? '📕' : mime?.startsWith('image/') ? '🖼️' : mime?.startsWith('video/') ? '🎬'
    : mime?.includes('sheet') || mime?.includes('excel') || mime === 'text/csv' ? '📊'
      : mime?.includes('presentation') || mime?.includes('powerpoint') ? '📽️' : '📄';

const size = (bytes: number) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

/**
 * Document storage: "for everybody" and one section per team the member
 * sees (or one team only). Inside a section sorted into folders.
 */
export function Documents({ teamId }: { teamId?: string }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const docs = useQuery({
    queryKey: ['documents', slug, teamId ?? 'all'],
    queryFn: async () => (await api<{ data: DocumentList }>(tp(`documents${teamId ? `?team=${teamId}` : ''}`))).data,
  });

  if (docs.isPending) return <Loading />;
  if (docs.isError) return <Notice tone="error">{errorText(docs.error, t('errors.generic'))}</Notice>;

  const { documents, teams } = docs.data;
  const sections = teamId
    ? [{ key: teamId, title: teams.find((x) => x.id === teamId)?.name ?? '', list: documents }]
    : [
        { key: 'all', title: t('documents.forEverybody'), list: documents.filter((d) => d.team === null) },
        ...teams.map((team) => ({ key: team.id, title: team.name, list: documents.filter((d) => d.team?.id === team.id) })).filter((s) => s.list.length > 0),
      ];
  const targets = [
    ...(docs.data.can_upload && !teamId ? [{ id: '', name: t('documents.forEverybody') }] : []),
    ...teams.filter((x) => x.can_upload && (!teamId || x.id === teamId)),
  ];
  const folders = [...new Set(documents.map((d) => d.folder).filter((f): f is string => !!f))].sort();

  return (
    <div className="space-y-4">
      {sections.map((section) => (
        <Card key={section.key} title={<>{section.key === 'all' ? '🏛️' : '👥'} {section.title}</>}>
          {section.list.length === 0 ? <p className="text-sm text-slate-500">{t('documents.none')}</p> : <Folders documents={section.list} targets={targets} folders={folders} />}
        </Card>
      ))}
      {targets.length > 0 && <UploadForm targets={targets} folders={folders} maxMb={docs.data.max_mb} defaultTarget={teamId ?? ''} />}
    </div>
  );
}

function Folders({ documents, targets, folders }: { documents: DocumentItem[]; targets: { id: string; name: string }[]; folders: string[] }) {
  const { t } = useTranslation();
  const groups = [...new Set(documents.map((d) => d.folder ?? ''))].sort((a, b) => (a === '' ? -1 : b === '' ? 1 : a.localeCompare(b)));

  return (
    <div className="space-y-3">
      {groups.map((folder) => (
        <div key={folder || '-'}>
          {folder && <h3 className="mb-1 text-sm font-semibold text-slate-600">📂 {folder}</h3>}
          <ul className="divide-y divide-slate-100">
            {documents.filter((d) => (d.folder ?? '') === folder).map((d) => <DocumentRow key={d.id} document={d} targets={targets} folders={folders} />)}
          </ul>
        </div>
      ))}
      {groups.length === 0 && <p className="text-sm text-slate-500">{t('documents.none')}</p>}
    </div>
  );
}

function DocumentRow({ document, targets, folders }: { document: DocumentItem; targets: { id: string; name: string }[]; folders: string[] }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const slug = useTenantSlug();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({ title: document.title, folder: document.folder ?? '', description: document.description ?? '', team_id: document.team?.id ?? '' });
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['documents', slug] });

  const save = useMutation({
    mutationFn: () => patch(tp(`documents/${document.id}`), { ...form, team_id: form.team_id || null, folder: form.folder || null, description: form.description || null }),
    onSuccess: () => {
      setEditing(false);
      void refresh();
    },
  });
  const remove = useMutation({ mutationFn: () => del(tp(`documents/${document.id}`)), onSuccess: refresh });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  if (editing) {
    // Moving is possible within the places this member manages (current place always listed).
    const places = targets.some((x) => x.id === (document.team?.id ?? '')) ? targets : [{ id: document.team?.id ?? '', name: document.team?.name ?? t('documents.forEverybody') }, ...targets];
    return (
      <li className="py-2">
        <form onSubmit={submit} className="space-y-2 rounded-lg border border-slate-200 p-3">
          <Field label={t('documents.titleLabel')} required maxLength={160} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <FolderField value={form.folder} onChange={(folder) => setForm({ ...form, folder })} folders={folders} />
          <Field label={t('documents.description')} maxLength={1000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          {places.length > 1 && (
            <Select label={t('documents.place')} value={form.team_id} onChange={(e) => setForm({ ...form, team_id: e.target.value })}>
              {places.map((x) => <option key={x.id || 'all'} value={x.id}>{x.name}</option>)}
            </Select>
          )}
          {save.isError && <Notice tone="error">{errorText(save.error, t('errors.generic'))}</Notice>}
          <SaveBar>
            <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
            <Button variant="secondary" onClick={() => setEditing(false)}>{t('common.cancel')}</Button>
            <Button variant="danger" onClick={() => window.confirm(t('documents.deleteConfirm', { title: document.title })) && remove.mutate()}>{t('documents.delete')}</Button>
          </SaveBar>
        </form>
      </li>
    );
  }

  return (
    <li className="flex items-center gap-3 py-2">
      <span aria-hidden="true" className="text-xl">{ICON(document.mime_type)}</span>
      <a href={document.url} target="_blank" rel="noreferrer" className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium text-brand-700 underline-offset-2 hover:underline">{document.title}</span>
        <span className="block truncate text-xs text-slate-500">
          {[document.description, `${document.name.split('.').pop()?.toUpperCase()} · ${size(document.size_bytes)}`, formatDate(toLocalInput(document.uploaded_at).slice(0, 10))].filter(Boolean).join(' · ')}
        </span>
      </a>
      {document.can_manage && (
        <button type="button" className="shrink-0 rounded px-2 py-1 text-brand-700" aria-label={t('documents.edit', { title: document.title })} onClick={() => setEditing(true)}>✎</button>
      )}
    </li>
  );
}

function FolderField({ value, onChange, folders }: { value: string; onChange: (value: string) => void; folders: string[] }) {
  const { t } = useTranslation();
  return (
    <>
      <Field label={t('documents.folder')} hint={t('documents.folderHint')} maxLength={80} list="document-folders" value={value} onChange={(e) => onChange(e.target.value)} />
      <datalist id="document-folders">{folders.map((f) => <option key={f} value={f} />)}</datalist>
    </>
  );
}

function UploadForm({ targets, folders, maxMb, defaultTarget }: { targets: { id: string; name: string }[]; folders: string[]; maxMb: number; defaultTarget: string }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const slug = useTenantSlug();
  const queryClient = useQueryClient();
  const [file, setFile] = useState<File | null>(null);
  const [inputKey, setInputKey] = useState(0);
  const [form, setForm] = useState({ title: '', folder: '', description: '', team_id: targets.some((x) => x.id === defaultTarget) ? defaultTarget : targets[0].id });

  const upload = useMutation({
    mutationFn: () => {
      const body = new FormData();
      body.append('file', file!);
      Object.entries(form).forEach(([key, value]) => value && body.append(key, value));
      return post(tp('documents'), body);
    },
    onSuccess: () => {
      setFile(null);
      setInputKey((k) => k + 1);
      setForm((f) => ({ ...f, title: '', description: '' }));
      void queryClient.invalidateQueries({ queryKey: ['documents', slug] });
    },
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (file) upload.mutate();
  };

  return (
    <Card title={t('documents.upload')}>
      <form onSubmit={submit} className="space-y-3">
        <div>
          <label htmlFor="document-file" className="mb-1 block text-sm font-medium text-slate-700">{t('documents.file')}</label>
          <input key={inputKey} id="document-file" type="file" className="block w-full text-sm" onChange={(e) => {
            const chosen = e.target.files?.[0] ?? null;
            setFile(chosen);
            if (chosen && !form.title) setForm((f) => ({ ...f, title: chosen.name.replace(/\.[^.]+$/, '') }));
          }} />
          <p className="mt-1 text-xs text-slate-500">{t('documents.fileHint', { mb: maxMb })}</p>
        </div>
        <Field label={t('documents.titleLabel')} maxLength={160} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        {targets.length > 1 && (
          <Select label={t('documents.place')} value={form.team_id} onChange={(e) => setForm({ ...form, team_id: e.target.value })}>
            {targets.map((x) => <option key={x.id || 'all'} value={x.id}>{x.name}</option>)}
          </Select>
        )}
        <FolderField value={form.folder} onChange={(folder) => setForm({ ...form, folder })} folders={folders} />
        <Field label={t('documents.description')} maxLength={1000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <p className="text-xs text-slate-500">{form.team_id ? t('documents.visibleTeam') : t('documents.visibleAll')}</p>
        {upload.isError && <Notice tone="error">{errorText(upload.error, t('errors.generic'))}</Notice>}
        <SaveBar><Button type="submit" disabled={!file || upload.isPending}>{upload.isPending ? t('documents.uploading') : t('documents.uploadButton')}</Button></SaveBar>
      </form>
    </Card>
  );
}
