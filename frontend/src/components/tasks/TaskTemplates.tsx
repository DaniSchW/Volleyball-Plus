import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, patch, post, type EventPage, type TaskTemplate, type TaskTemplateItem, type TaskTemplateList } from '../../lib/api';
import { formatDay, formatTime } from '../../lib/dates';
import { useTenantPath, useTenantSlug } from '../../lib/queries';
import { Button, Card, Field, Loading, Notice, SaveBar, Select } from '../ui';
import { TypeIcon } from '../events/EventCard';

/**
 * Task templates for trainers, organizers and admins: create, edit and take
 * over into several upcoming events at once (e.g. all home matches).
 */
export function TaskTemplates() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const list = useQuery({ queryKey: ['task-templates', slug], queryFn: async () => (await api<{ data: TaskTemplateList }>(tp('task-templates'))).data });
  const [editing, setEditing] = useState<TaskTemplate | 'new' | null>(null);
  const [applying, setApplying] = useState<TaskTemplate | null>(null);

  if (list.isPending) return <Loading />;
  if (!list.data || (list.data.teams.length === 0 && !list.data.can_create_for_all)) return null;

  return (
    <Card title={t('tasks.templates.title')}>
      <p className="mb-3 text-xs text-slate-500">{t('tasks.templates.hint')}</p>
      {list.data.templates.length === 0 && <p className="text-sm text-slate-500">{t('tasks.templates.none')}</p>}
      <ul className="divide-y divide-slate-100">
        {list.data.templates.map((tpl) => (
          <li key={tpl.id} className="py-3">
            {editing !== null && editing !== 'new' && editing.id === tpl.id ? (
              <TemplateForm template={tpl} options={list.data} onDone={() => setEditing(null)} />
            ) : applying?.id === tpl.id ? (
              <ApplyTemplate template={tpl} onDone={() => setApplying(null)} />
            ) : (
              <div className="space-y-2">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-medium">📋 {tpl.name}</p>
                    <p className="text-xs text-slate-500">{tpl.team?.name ?? t('tasks.templates.forAll')}</p>
                  </div>
                  {tpl.can_change && <button type="button" className="shrink-0 text-sm text-brand-700" onClick={() => setEditing(tpl)}>{t('tasks.templates.edit')}</button>}
                </div>
                <ul className="flex flex-wrap gap-1.5">
                  {tpl.items.map((item, i) => (
                    <li key={i} className="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs text-amber-900 ring-1 ring-amber-200">
                      {item.title}{item.slots ? ` ×${item.slots}` : ''}
                    </li>
                  ))}
                </ul>
                <Button variant="secondary" onClick={() => setApplying(tpl)}>{t('tasks.templates.applyToEvents')}</Button>
              </div>
            )}
          </li>
        ))}
      </ul>
      {editing === 'new' ? (
        <div className="border-t border-slate-100 pt-3"><TemplateForm options={list.data} onDone={() => setEditing(null)} /></div>
      ) : (
        <Button className="mt-2" onClick={() => setEditing('new')}>{t('tasks.templates.new')}</Button>
      )}
    </Card>
  );
}


function TemplateForm({ template, options, onDone }: { template?: TaskTemplate; options: TaskTemplateList; onDone: () => void }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [name, setName] = useState(template?.name ?? '');
  const [teamId, setTeamId] = useState(template ? template.team?.id ?? '' : options.teams[0]?.id ?? '');
  const [rows, setRows] = useState<TaskRow[]>(template ? template.items.map((i) => ({ title: i.title, slots: i.slots ? String(i.slots) : '' })) : [{ title: '', slots: '' }]);
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['task-templates', slug] });

  const save = useMutation({
    mutationFn: () => {
      const items: Partial<TaskTemplateItem>[] = rows.filter((r) => r.title.trim()).map((r) => ({ title: r.title.trim(), slots: r.slots ? Number(r.slots) : null }));
      const body = { name, team_id: teamId || null, items };
      return template ? patch(tp(`task-templates/${template.id}`), body) : post(tp('task-templates'), body);
    },
    onSuccess: () => {
      refresh();
      onDone();
    },
  });
  const remove = useMutation({ mutationFn: () => del(tp(`task-templates/${template!.id}`)), onSuccess: () => { refresh(); onDone(); } });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    save.mutate();
  };

  return (
    <form onSubmit={submit} className="space-y-3">
      <Field label={t('tasks.templates.name')} required maxLength={120} placeholder={t('tasks.templates.namePlaceholder')} value={name} onChange={(e) => setName(e.target.value)} />
      <Select label={t('tasks.templates.for')} value={teamId} onChange={(e) => setTeamId(e.target.value)}>
        {options.can_create_for_all && <option value="">{t('tasks.templates.forAll')}</option>}
        {options.teams.map((team) => <option key={team.id} value={team.id}>{team.name}</option>)}
      </Select>
      <fieldset className="min-w-0 space-y-2">
        <legend className="mb-1 text-sm font-medium text-slate-700">{t('tasks.templates.items')}</legend>
        <TaskRows rows={rows} onChange={setRows} />
        <p className="text-xs text-slate-500">{t('tasks.templates.slotsHint')}</p>
      </fieldset>
      {(save.isError || remove.isError) && <Notice tone="error">{errorText(save.error ?? remove.error, t('errors.generic'))}</Notice>}
      <SaveBar>
        <Button type="submit" disabled={save.isPending}>{t('common.save')}</Button>
        <Button variant="secondary" onClick={onDone}>{t('common.cancel')}</Button>
        {template && <Button variant="danger" onClick={() => window.confirm(t('tasks.templates.deleteConfirm', { name: template.name })) && remove.mutate()}>{t('tasks.templates.delete')}</Button>}
      </SaveBar>
    </form>
  );
}

/** Upcoming events of the template's team (or all) – home matches preselectable. */
function ApplyTemplate({ template, onDone }: { template: TaskTemplate; onDone: () => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [selected, setSelected] = useState<string[]>([]);
  const [message, setMessage] = useState<string | null>(null);
  const events = useQuery({
    queryKey: ['template-events', template.team?.id ?? 'all'],
    queryFn: async () => (await api<EventPage>(`${tp('events')}${template.team ? `?team=${template.team.id}` : ''}`)).data.filter((e) => e.status === 'scheduled'),
  });
  const apply = useMutation({
    mutationFn: () => post<{ message: string }>(tp(`task-templates/${template.id}/apply`), { event_ids: selected }),
    onSuccess: (result) => {
      setMessage(result.message);
      setSelected([]);
      void queryClient.invalidateQueries({ queryKey: ['events'] });
      void queryClient.invalidateQueries({ queryKey: ['event'] });
    },
  });
  const homeMatches = (events.data ?? []).filter((e) => e.type === 'match' && e.is_home).map((e) => e.id);

  return (
    <div className="space-y-3">
      <p className="font-medium">📋 {template.name} – {t('tasks.templates.applyToEvents')}</p>
      {events.isPending && <Loading />}
      {homeMatches.length > 0 && (
        <button type="button" className="text-sm text-brand-700 underline" onClick={() => setSelected(homeMatches)}>{t('tasks.templates.allHomeMatches', { count: homeMatches.length })}</button>
      )}
      <ul className="max-h-80 space-y-1 overflow-y-auto">
        {events.data?.map((e) => (
          <li key={e.id}>
            <label className="flex min-h-11 items-center gap-3 rounded-lg px-2 text-sm hover:bg-slate-50">
              <input
                type="checkbox"
                className="size-5 accent-brand-solid"
                checked={selected.includes(e.id)}
                onChange={(ev) => setSelected(ev.target.checked ? [...selected, e.id] : selected.filter((x) => x !== e.id))}
              />
              <TypeIcon type={e.type} />
              <span className="min-w-0 flex-1 truncate">{e.title}</span>
              <span className="shrink-0 text-xs text-slate-500">{formatDay(e.starts_at)} {formatTime(e.starts_at)}</span>
            </label>
          </li>
        ))}
      </ul>
      {message && <Notice tone="success">{message}</Notice>}
      {apply.isError && <Notice tone="error">{errorText(apply.error, t('errors.generic'))}</Notice>}
      <div className="flex flex-wrap gap-2">
        <Button disabled={selected.length === 0 || apply.isPending} onClick={() => apply.mutate()}>{t('tasks.templates.applyN', { count: selected.length })}</Button>
        <Button variant="secondary" onClick={onDone}>{t('common.close')}</Button>
      </div>
    </div>
  );
}

export interface TaskRow {
  title: string;
  slots: string;
}

/** Editable list of tasks (title + optional number of people) – for templates and new events. */
export function TaskRows({ rows, onChange }: { rows: TaskRow[]; onChange: (rows: TaskRow[]) => void }) {
  const { t } = useTranslation();
  return (
    <>
      {rows.map((row, i) => (
        <div key={i} className="flex items-center gap-2">
          <input
            aria-label={t('tasks.templates.itemN', { n: i + 1 })}
            placeholder={t('tasks.placeholder')}
            maxLength={120}
            className="min-h-11 min-w-0 flex-1 rounded-lg border border-slate-300 px-3 text-base"
            value={row.title}
            onChange={(e) => onChange(rows.map((r, j) => (j === i ? { ...r, title: e.target.value } : r)))}
          />
          <input
            aria-label={t('tasks.templates.slotsN', { n: i + 1 })}
            type="number"
            min={1}
            max={99}
            inputMode="numeric"
            placeholder={t('tasks.anyNumberShort')}
            className="min-h-11 w-20 rounded-lg border border-slate-300 px-2 text-base"
            value={row.slots}
            onChange={(e) => onChange(rows.map((r, j) => (j === i ? { ...r, slots: e.target.value } : r)))}
          />
          <button type="button" className="min-h-11 px-2 text-red-700" aria-label={t('tasks.templates.removeItem', { n: i + 1 })} onClick={() => onChange(rows.filter((_, j) => j !== i))}>✕</button>
        </div>
      ))}
      <button type="button" className="text-sm text-brand-700 underline" onClick={() => onChange([...rows, { title: '', slots: '' }])}>+ {t('tasks.templates.addItem')}</button>
    </>
  );
}
