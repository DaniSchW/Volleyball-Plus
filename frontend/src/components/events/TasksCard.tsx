import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, del, errorText, post, type EventDetail, type EventTask, type TaskTemplateList } from '../../lib/api';
import { useTenantPath, useTenantSlug } from '../../lib/queries';
import { Button, Card, Field, Notice, Select } from '../ui';

/**
 * Tasks of an event ("Wer fährt?", "Kuchen") – invited people take places;
 * whoever manages the event adds tasks and can put anybody invited on them.
 */
export function TasksCard({ event }: { event: EventDetail }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const done = ({ data }: { data: EventDetail }) => {
    queryClient.setQueryData(['event', event.id], data);
    void queryClient.invalidateQueries({ queryKey: ['events'] });
    void queryClient.invalidateQueries({ queryKey: ['my-tasks'] });
  };
  const take = useMutation({ mutationFn: ({ task, person }: { task: string; person: string }) => post<{ data: EventDetail }>(tp(`tasks/${task}/assignments`), { person_id: person }), onSuccess: done });
  const release = useMutation({ mutationFn: ({ task, person }: { task: string; person: string }) => del<{ data: EventDetail }>(tp(`tasks/${task}/assignments/${person}`)), onSuccess: done });
  const remove = useMutation({ mutationFn: (task: string) => del<{ data: EventDetail }>(tp(`tasks/${task}`)), onSuccess: done });
  const error = take.error ?? release.error ?? remove.error;

  if (event.tasks.length === 0 && !event.can_manage) return null;

  return (
    <Card title={t('tasks.title')}>
      {event.tasks.length === 0 && <p className="text-sm text-slate-500">{t('tasks.none')}</p>}
      <ul className="divide-y divide-slate-100">
        {event.tasks.map((task) => (
          <TaskLine
            key={task.id}
            task={task}
            canManage={event.can_manage}
            busy={take.isPending || release.isPending}
            onTake={(person) => take.mutate({ task: task.id, person })}
            onRelease={(person) => release.mutate({ task: task.id, person })}
            onRemove={() => window.confirm(t('tasks.removeConfirm', { title: task.title })) && remove.mutate(task.id)}
          />
        ))}
      </ul>
      {error && <Notice tone="error">{errorText(error, t('errors.generic'))}</Notice>}
      {event.can_manage && <AddTask event={event} onDone={done} />}
      {event.can_manage && <TemplateBar event={event} onApplied={() => void queryClient.invalidateQueries({ queryKey: ['event', event.id] })} />}
    </Card>
  );
}

function TaskLine({ task, canManage, busy, onTake, onRelease, onRemove }: {
  task: EventTask;
  canManage: boolean;
  busy: boolean;
  onTake: (person: string) => void;
  onRelease: (person: string) => void;
  onRemove: () => void;
}) {
  const { t } = useTranslation();
  const taken = task.assignees.length;
  const full = task.slots !== null && taken >= task.slots;
  const takers = task.can_take_for.filter((p) => !task.assignees.some((a) => a.person_id === p.person_id));

  return (
    <li className="space-y-2 py-3">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-medium">{task.title}</p>
          {task.description && <p className="text-xs text-slate-500">{task.description}</p>}
        </div>
        <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${full ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-700'}`}>
          {task.slots === null ? t('tasks.joined', { count: taken }) : t('tasks.places', { taken, slots: task.slots })}
        </span>
      </div>
      {task.assignees.length > 0 && (
        <ul className="flex flex-wrap gap-2">
          {task.assignees.map((a) => (
            <li key={a.person_id} className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-sm">
              {a.name}
              {(a.mine || canManage) && (
                <button type="button" className="ml-1 text-slate-500" aria-label={t('tasks.release', { name: a.name, title: task.title })} disabled={busy} onClick={() => onRelease(a.person_id)}>✕</button>
              )}
            </li>
          ))}
        </ul>
      )}
      <div className="flex flex-wrap gap-2">
        {!full && takers.map((p) => (
          <Button key={p.person_id} variant="secondary" disabled={busy} onClick={() => onTake(p.person_id)}>
            {takers.length > 1 || task.can_take_for.length > 1 ? t('tasks.takeFor', { name: p.name }) : t('tasks.take')}
          </Button>
        ))}
        {canManage && (
          <button type="button" className="text-xs text-red-700 underline" onClick={onRemove}>{t('tasks.remove')}</button>
        )}
      </div>
    </li>
  );
}

function AddTask({ event, onDone }: { event: EventDetail; onDone: (result: { data: EventDetail }) => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [title, setTitle] = useState('');
  const [slots, setSlots] = useState('');
  const add = useMutation({
    mutationFn: () => post<{ data: EventDetail }>(tp(`events/${event.id}/tasks`), { title, slots: slots ? Number(slots) : null }),
    onSuccess: (result) => {
      setTitle('');
      setSlots('');
      onDone(result);
    },
  });

  return (
    <form
      className="mt-3 grid grid-cols-[1fr_5rem_auto] items-end gap-2 border-t border-slate-100 pt-3"
      onSubmit={(e) => {
        e.preventDefault();
        add.mutate();
      }}
    >
      <Field label={t('tasks.newTask')} required maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t('tasks.placeholder')} />
      <Field label={t('tasks.slots')} type="number" min={1} max={99} inputMode="numeric" placeholder={t('tasks.anyNumber')} value={slots} onChange={(e) => setSlots(e.target.value)} />
      <Button type="submit" disabled={add.isPending}>{t('common.add')}</Button>
      {add.isError && <div className="col-span-3"><Notice tone="error">{errorText(add.error, t('errors.generic'))}</Notice></div>}
    </form>
  );
}

/** Take over a template, or save this event's tasks as one ("Spieltag Damen 1"). */
function TemplateBar({ event, onApplied }: { event: EventDetail; onApplied: () => void }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [choice, setChoice] = useState('');
  const [message, setMessage] = useState<string | null>(null);
  const templates = useQuery({ queryKey: ['task-templates', slug], queryFn: async () => (await api<{ data: TaskTemplateList }>(tp('task-templates'))).data });

  const apply = useMutation({
    mutationFn: () => post<{ message: string }>(tp(`task-templates/${choice}/apply`), { event_ids: [event.id] }),
    onSuccess: (result) => {
      setMessage(result.message);
      setChoice('');
      onApplied();
    },
  });
  const save = useMutation({
    mutationFn: (name: string) => post(tp('task-templates'), { name, team_id: event.teams.length === 1 ? event.teams[0].id : null, event_id: event.id }),
    onSuccess: () => {
      setMessage(t('tasks.templateSaved'));
      void queryClient.invalidateQueries({ queryKey: ['task-templates', slug] });
    },
  });

  const list = templates.data?.templates ?? [];
  return (
    <div className="mt-3 space-y-2 border-t border-slate-100 pt-3">
      {list.length > 0 && (
        <div className="flex items-end gap-2">
          <div className="min-w-0 flex-1">
            <Select label={t('tasks.fromTemplate')} value={choice} onChange={(e) => setChoice(e.target.value)}>
              <option value="">{t('tasks.chooseTemplate')}</option>
              {list.map((tpl) => <option key={tpl.id} value={tpl.id}>{tpl.name} ({tpl.items.length})</option>)}
            </Select>
          </div>
          <Button variant="secondary" disabled={!choice || apply.isPending} onClick={() => apply.mutate()}>{t('tasks.applyTemplate')}</Button>
        </div>
      )}
      {event.tasks.length > 0 && (
        <button
          type="button"
          className="text-sm text-brand-700 underline"
          disabled={save.isPending}
          onClick={() => {
            const name = window.prompt(t('tasks.templateName'), event.title);
            if (name?.trim()) save.mutate(name.trim());
          }}
        >
          💾 {t('tasks.saveAsTemplate')}
        </button>
      )}
      {message && <p className="text-xs text-emerald-700">{message}</p>}
      {(apply.isError || save.isError) && <Notice tone="error">{errorText(apply.error ?? save.error, t('errors.generic'))}</Notice>}
    </div>
  );
}

