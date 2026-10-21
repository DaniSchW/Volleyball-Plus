import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { api, errorText, post, type ImportPreview, type ImportRow, type Team } from '../lib/api';
import { formatDay, formatTime } from '../lib/dates';
import { useTenantPath, useTenantSlug } from '../lib/queries';
import { Button, Card, Loading, Notice, PageTitle, Select } from '../components/ui';

/**
 * Match schedule from a file (.ics/.ical or the SAMS CSV): preview, choose,
 * import. A new import of the updated file changes known matches.
 */
export function MatchImportPage() {
  const { t } = useTranslation();
  const { teamId = '' } = useParams();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const team = useQuery({ queryKey: ['team', teamId], queryFn: async () => (await api<{ data: Team }>(tp(`teams/${teamId}`))).data });

  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const form = (extra: Record<string, string | string[]>) => {
    const body = new FormData();
    body.append('file', file!);
    Object.entries(extra).forEach(([key, value]) =>
      Array.isArray(value) ? value.forEach((v) => body.append(`${key}[]`, v)) : body.append(key, value));
    return body;
  };

  const load = useMutation({
    mutationFn: ({ f, ownName }: { f: File; ownName?: string }) => {
      const body = new FormData();
      body.append('file', f);
      if (ownName) body.append('own_name', ownName);
      return post<{ data: ImportPreview }>(tp(`teams/${teamId}/match-import/preview`), body);
    },
    onSuccess: ({ data }) => {
      setPreview(data);
      setSelected(new Set(data.rows.filter((r) => r.selected).map((r) => r.key)));
    },
  });
  const importNow = useMutation({
    mutationFn: () => post<{ data: { created: number; updated: number } }>(tp(`teams/${teamId}/match-import`), form({ own_name: preview!.own_name!, keys: [...selected] })),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['events'] });
      await queryClient.invalidateQueries({ queryKey: ['opponents', slug] });
      if (file) load.mutate({ f: file, ownName: preview?.own_name ?? undefined });
    },
  });

  if (team.isPending) return <Loading />;

  const choose = (f: File | undefined) => {
    if (!f) return;
    setFile(f);
    setPreview(null);
    importNow.reset();
    load.mutate({ f });
  };
  const toggle = (key: string, on: boolean) => setSelected((s) => {
    const next = new Set(s);
    if (on) next.add(key);
    else next.delete(key);
    return next;
  });

  return (
    <div className="space-y-4">
      <PageTitle>{t('import.title', { team: team.data?.name })}</PageTitle>
      <p className="text-sm text-slate-600">{t('import.hint')}</p>

      <Card>
        <label className="inline-flex min-h-11 cursor-pointer items-center rounded-lg bg-brand-solid px-4 text-sm font-medium text-white">
          {file ? t('import.otherFile') : t('import.chooseFile')}
          <input type="file" accept=".ics,.ical,.csv,text/calendar,text/csv" className="sr-only" onChange={(e) => choose(e.target.files?.[0])} />
        </label>
        {file && <p className="mt-2 truncate text-xs text-slate-500">{file.name}</p>}
        {load.isPending && <Loading />}
        {load.isError && <div className="mt-3"><Notice tone="error">{errorText(load.error, t('errors.generic'))}</Notice></div>}
      </Card>

      {preview && (
        <>
          {preview.names.length > 1 && (
            <Card>
              <Select
                label={t('import.ownName')}
                value={preview.own_name ?? ''}
                onChange={(e) => file && load.mutate({ f: file, ownName: e.target.value })}
              >
                {preview.names.map((n) => <option key={n.name} value={n.name}>{n.name} ({n.count})</option>)}
              </Select>
              <p className="mt-1 text-xs text-slate-500">{t('import.ownNameHint')}</p>
            </Card>
          )}

          <Card title={t('import.found', { count: preview.rows.length })}>
            {preview.skipped > 0 && <p className="mb-2 text-xs text-slate-500">{t('import.skipped', { count: preview.skipped })}</p>}
            <ul className="divide-y divide-slate-100">
              {preview.rows.map((row) => (
                <ImportLine key={row.key} row={row} checked={selected.has(row.key)} onChange={(on) => toggle(row.key, on)} />
              ))}
            </ul>
          </Card>

          {preview.missing.length > 0 && (
            <Card title={t('import.missing')}>
              <p className="mb-2 text-xs text-slate-500">{t('import.missingHint')}</p>
              <ul className="space-y-1 text-sm">
                {preview.missing.map((m) => (
                  <li key={m.id}>
                    <Link className="text-brand-700 underline" to={`/${slug}/events/${m.id}`}>{formatDay(m.starts_at)} {formatTime(m.starts_at)} · {m.title}</Link>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {importNow.isSuccess && (
            <Notice tone="success">{t('import.done', { created: importNow.data.data.created, updated: importNow.data.data.updated })}</Notice>
          )}
          {importNow.isError && <Notice tone="error">{errorText(importNow.error, t('errors.generic'))}</Notice>}
          <div className="flex flex-wrap gap-2">
            <Button disabled={selected.size === 0 || importNow.isPending || !preview.own_name} onClick={() => importNow.mutate()}>
              {t('import.submit', { count: selected.size })}
            </Button>
            <Link to={`/${slug}/teams/${teamId}`} className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium">
              {t('import.backToTeam')}
            </Link>
          </div>
        </>
      )}
    </div>
  );
}

function ImportLine({ row, checked, onChange }: { row: ImportRow; checked: boolean; onChange: (on: boolean) => void }) {
  const { t } = useTranslation();
  const disabled = row.status === 'same' || row.status === 'cancelled';
  const status = row.status === 'update'
    ? t('import.status.update', { what: [...new Set(row.changes.map((c) => t(`import.fields.${c}`)))].join(', ') })
    : t(`import.status.${row.status}`);
  const tone = { new: 'text-green-700', update: 'text-amber-700', same: 'text-slate-500', cancelled: 'text-red-700' }[row.status];

  return (
    <li>
      <label className={`flex gap-3 py-2 ${disabled ? 'opacity-60' : ''}`}>
        <input type="checkbox" className="mt-1 size-5 shrink-0 accent-brand-solid" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
        <span className="min-w-0 flex-1 text-sm">
          <span className="block text-xs text-slate-500">
            {formatDay(row.starts_at)} · {formatTime(row.starts_at)}
            {row.role === 'home' && ` · ${t('events.home')}`}
            {row.role === 'away' && ` · ${t('events.away')}`}
            {row.past && ` · ${t('import.past')}`}
          </span>
          <span className="block font-medium">{row.role === 'referee' ? '🟨 ' : '🏆 '}{row.title}</span>
          {row.location_name && <span className="block truncate text-xs text-slate-600">{row.location_name}</span>}
          <span className={`block text-xs ${tone}`}>{status}</span>
        </span>
      </label>
    </li>
  );
}
