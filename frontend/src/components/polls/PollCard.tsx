import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { del, errorText, post, type Poll } from '../../lib/api';
import { formatDateTime } from '../../lib/dates';
import { useTenantPath } from '../../lib/queries';
import { Button, Card, Notice, Toggle } from '../ui';
import { NotifyChoice, type EmailMode } from './NotifyChoice';

/** One poll: vote (for oneself or a child), results as bars once visible. */
export function PollCard({ poll }: { poll: Poll }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const done = () => void queryClient.invalidateQueries({ queryKey: ['polls'] });
  const close = useMutation({ mutationFn: () => post(tp(`polls/${poll.id}/close`)), onSuccess: done });
  const remove = useMutation({ mutationFn: () => del(tp(`polls/${poll.id}`)), onSuccess: done });
  const [reminding, setReminding] = useState(false);
  const [reminded, setReminded] = useState<string | null>(null);

  const meta = [
    poll.scope === 'tenant' ? t('polls.allTeams') : poll.teams.map((team) => team.name).join(', '),
    poll.multiple_choice && t('polls.multiple'),
    poll.anonymous && t('polls.anonymous'),
    poll.is_closed ? t('polls.closed') : poll.closes_at && t('polls.until', { date: formatDateTime(poll.closes_at) }),
  ].filter(Boolean).join(' · ');

  return (
    <Card title={poll.title}>
      <div className="space-y-4">
        <p className="-mt-2 text-xs text-slate-500">{meta}</p>
        {poll.description && <p className="whitespace-pre-line text-sm text-slate-700">{poll.description}</p>}
        {poll.my.map((voter) => <Ballot key={voter.person_id} poll={poll} voter={voter} showName={poll.my.length > 1 || !voter.is_self} />)}
        {poll.results_visible ? <Results poll={poll} /> : <p className="text-xs text-slate-500">{t('polls.resultsAfterVote')}</p>}
        {reminded && <Notice tone="success">{reminded}</Notice>}
        {poll.can_manage && reminding && <RemindForm poll={poll} onDone={(message) => { setReminding(false); setReminded(message); }} />}
        {poll.can_manage && !reminding && (
          <div className="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
            {!poll.is_closed && <Button variant="secondary" onClick={() => setReminding(true)}>🔔 {t('polls.remind.button')}</Button>}
            {!poll.is_closed && <Button variant="secondary" onClick={() => close.mutate()}>{t('polls.close')}</Button>}
            <Button variant="danger" onClick={() => window.confirm(t('polls.deleteConfirm')) && remove.mutate()}>{t('polls.delete')}</Button>
          </div>
        )}
      </div>
    </Card>
  );
}

/** Notify again: by default only who has not voted yet. */
function RemindForm({ poll, onDone }: { poll: Poll; onDone: (message: string | null) => void }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const [push, setPush] = useState(true);
  const [email, setEmail] = useState<EmailMode>('without_push');
  const [onlyOpen, setOnlyOpen] = useState(true);
  const send = useMutation({
    mutationFn: () => post<{ message: string }>(tp(`polls/${poll.id}/remind`), { push, email, only_open: onlyOpen }),
    onSuccess: (result) => onDone(result.message),
  });

  return (
    <div className="space-y-3 border-t border-slate-100 pt-3">
      <h3 className="text-sm font-semibold">{t('polls.remind.title')}</h3>
      <Toggle label={t('polls.remind.onlyOpen')} checked={onlyOpen} onChange={setOnlyOpen} />
      <NotifyChoice push={push} email={email} onPush={setPush} onEmail={setEmail} />
      {send.isError && <Notice tone="error">{errorText(send.error, t('errors.generic'))}</Notice>}
      <div className="flex flex-wrap gap-2">
        <Button disabled={send.isPending || (!push && email === 'none')} onClick={() => send.mutate()}>{t('polls.remind.send')}</Button>
        <Button variant="secondary" onClick={() => onDone(null)}>{t('common.cancel')}</Button>
      </div>
    </div>
  );
}

function Ballot({ poll, voter, showName }: { poll: Poll; voter: Poll['my'][number]; showName: boolean }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [choice, setChoice] = useState<string[]>(voter.option_ids);
  const [text, setText] = useState(voter.free_text ?? '');
  const voted = voter.option_ids.length > 0 || !!voter.free_text;
  const [editing, setEditing] = useState(!voted);

  const send = useMutation({
    mutationFn: () => post<{ data: Poll }>(tp(`polls/${poll.id}/votes`), { person_id: voter.person_id, option_ids: choice, free_text: text || null }),
    onSuccess: () => {
      setEditing(false);
      void queryClient.invalidateQueries({ queryKey: ['polls'] });
    },
  });
  const pick = (id: string, on: boolean) => setChoice((c) => (poll.multiple_choice ? (on ? [...c, id] : c.filter((x) => x !== id)) : on ? [id] : []));

  if (!voter.can_vote && !voted) {
    return <p className="text-xs text-slate-500">{poll.is_closed ? t('polls.closed') : t('polls.askParents', { name: voter.name })}</p>;
  }
  if (!editing) {
    return (
      <p className="text-sm">
        {showName && <span className="font-medium">{voter.name}: </span>}
        {t('polls.youVoted', { answer: [...poll.options.filter((o) => voter.option_ids.includes(o.id)).map((o) => o.label), voter.free_text].filter(Boolean).join(', ') })}
        {voter.can_vote && <button type="button" className="ml-2 text-brand-700 underline" onClick={() => setEditing(true)}>{t('polls.change')}</button>}
      </p>
    );
  }

  return (
    <form
      className="space-y-2"
      onSubmit={(e) => {
        e.preventDefault();
        send.mutate();
      }}
    >
      <fieldset>
        <legend className="mb-1 text-sm font-medium">{showName ? t('polls.voteFor', { name: voter.name }) : t('polls.yourVote')}</legend>
        {poll.options.map((option) => (
          <label key={option.id} className="flex min-h-11 items-center gap-3 text-sm">
            <input
              type={poll.multiple_choice ? 'checkbox' : 'radio'}
              name={`poll-${poll.id}-${voter.person_id}`}
              className="size-5 accent-brand-solid"
              checked={choice.includes(option.id)}
              onChange={(e) => pick(option.id, e.target.checked)}
            />
            {option.label}
          </label>
        ))}
      </fieldset>
      {poll.allow_free_text && (
        <input
          className="min-h-11 w-full rounded-lg border border-slate-300 px-3 text-base"
          placeholder={t('polls.freeText')}
          aria-label={t('polls.freeText')}
          maxLength={500}
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
      )}
      {send.isError && <Notice tone="error">{errorText(send.error, t('errors.generic'))}</Notice>}
      <Button type="submit" disabled={send.isPending || (choice.length === 0 && !text.trim())}>{t('polls.send')}</Button>
    </form>
  );
}

/** Share of voters per option; the numbers (and names, unless anonymous) are written out. */
function Results({ poll }: { poll: Poll }) {
  const { t } = useTranslation();
  const voters = poll.voter_count ?? 0;

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">{t('polls.participation', { count: voters, total: poll.eligible_count })}</p>
      {poll.options.map((option) => {
        const share = voters > 0 ? Math.round(((option.count ?? 0) * 100) / voters) : 0;
        return (
          <div key={option.id} title={option.voters?.join(', ')}>
            <div className="flex items-baseline justify-between gap-2 text-sm">
              <span className="min-w-0">{option.label}</span>
              <span className="shrink-0 tabular-nums text-slate-700">{option.count} · {share} %</span>
            </div>
            <div className="mt-1 h-3 w-full overflow-hidden rounded bg-slate-100" aria-hidden="true">
              <div className="h-full rounded bg-brand-600" style={{ width: `${share}%` }} />
            </div>
            {option.voters && option.voters.length > 0 && <p className="mt-0.5 text-xs text-slate-500">{option.voters.join(', ')}</p>}
          </div>
        );
      })}
      {poll.free_texts.length > 0 && (
        <div>
          <p className="text-sm font-medium">{t('polls.freeTexts')}</p>
          <ul className="mt-1 space-y-1 text-sm text-slate-700">
            {poll.free_texts.map((f, i) => <li key={i}>„{f.text}“{f.name && <span className="text-xs text-slate-500"> – {f.name}</span>}</li>)}
          </ul>
        </div>
      )}
    </div>
  );
}
