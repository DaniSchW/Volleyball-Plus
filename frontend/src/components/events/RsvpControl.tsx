import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { errorText, post, type EventDetail, type EventSummary, type MyReply, type Position, type RsvpStatus } from '../../lib/api';
import { useTenantPath } from '../../lib/queries';
import { Notice } from '../ui';

const STATUSES: RsvpStatus[] = ['yes', 'maybe', 'no'];

const STYLE: Record<RsvpStatus, { active: string; icon: string }> = {
  yes: { active: 'bg-green-600 text-white border-green-600', icon: '✓' },
  maybe: { active: 'bg-amber-500 text-white border-amber-500', icon: '?' },
  no: { active: 'bg-red-600 text-white border-red-600', icon: '✕' },
};

/**
 * Accept / maybe / decline for one person (oneself or a child). For "no"
 * and "maybe" a reason can be entered – required if the team says so.
 */
export function RsvpControl({ event, reply, showName }: { event: EventSummary; reply: MyReply; showName: boolean }) {
  const { t } = useTranslation();
  const tp = useTenantPath();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState<RsvpStatus | null>(null);
  const [reason, setReason] = useState(reply.reason ?? '');
  const limited = !!event.position_limits && Object.keys(event.position_limits).length > 0;
  // With a Positions-Soll players with several positions may choose; otherwise the first free one counts.
  const [position, setPosition] = useState<Position | ''>(reply.as_position ?? '');

  const send = useMutation({
    mutationFn: (body: { status: RsvpStatus; reason?: string; position?: Position | null }) =>
      post<{ data: EventDetail }>(tp(`events/${event.id}/responses`), { person_id: reply.person_id, ...body }),
    onSuccess: ({ data }) => {
      setPending(null);
      queryClient.setQueryData(['event', event.id], data);
      void queryClient.invalidateQueries({ queryKey: ['events'] });
    },
  });

  const choose = (status: RsvpStatus) => {
    if (status === 'yes') {
      send.mutate({ status, ...(limited ? { position: position || null } : {}) });
    } else {
      setPending(status);
    }
  };

  const required = pending !== null && event.reason_required[pending as 'no' | 'maybe'];
  const disabled = !reply.can_respond || send.isPending;

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        {showName && <span className="w-16 shrink-0 truncate text-sm font-medium">{reply.name}</span>}
        <div className="flex flex-1 gap-2" role="group" aria-label={t('events.replyFor', { name: reply.name })}>
          {STATUSES.map((status) => {
            const active = (pending ?? reply.status) === status;
            const full = status === 'yes' && reply.status !== 'yes' && (event.is_full || !reply.can_accept);
            return (
              <button
                key={status}
                type="button"
                disabled={disabled || full}
                aria-pressed={active}
                onClick={() => choose(status)}
                className={`flex min-h-11 flex-1 items-center justify-center gap-1 rounded-lg border text-sm font-medium disabled:opacity-40 ${
                  active ? STYLE[status].active : 'border-slate-300 bg-surface text-slate-700'
                }`}
              >
                <span aria-hidden="true">{STYLE[status].icon}</span>
                {t(`events.status.${status}`)}
              </button>
            );
          })}
        </div>
      </div>

      {limited && reply.positions.length > 1 && reply.can_respond && (
        <label className="flex items-center gap-2 text-xs text-slate-600">
          {t('events.acceptAs')}
          <select
            className="min-h-9 rounded-md border border-slate-300 bg-surface px-2 text-sm"
            value={position}
            onChange={(e) => {
              const value = e.target.value as Position | '';
              setPosition(value);
              if (reply.status === 'yes' && value) send.mutate({ status: 'yes', position: value });
            }}
          >
            <option value="">{t('events.acceptAsAuto')}</option>
            {reply.positions.map((p) => <option key={p} value={p}>{t(`positions.${p}`)}</option>)}
          </select>
        </label>
      )}
      {reply.status === 'yes' && reply.as_position && limited && (
        <p className="text-xs text-slate-500">{t('events.acceptedAs', { position: t(`positions.${reply.as_position}`) })}</p>
      )}

      {pending && (
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            send.mutate({ status: pending, reason });
          }}
        >
          <input
            className="min-h-11 min-w-0 flex-1 rounded-lg border border-slate-300 px-3 text-sm"
            placeholder={required ? t('events.reasonRequired') : t('events.reasonOptional')}
            aria-label={t('events.reason')}
            required={required}
            maxLength={500}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            autoFocus
          />
          <button type="submit" className="min-h-11 rounded-lg bg-brand-solid px-4 text-sm font-medium text-white" disabled={send.isPending}>
            {t('events.send')}
          </button>
        </form>
      )}

      {reply.is_guest && !reply.can_accept && reply.can_respond && (
        <p className="text-xs text-slate-500">{t('events.guestsNotAllowed')}</p>
      )}
      {!reply.can_respond && reply.status === null && event.response_mode === 'rsvp' && event.status === 'scheduled' && (
        <p className="text-xs text-slate-500">{event.deadline_passed ? t('events.deadlinePassed') : t('events.askParents')}</p>
      )}
      {send.isError && <Notice tone="error">{errorText(send.error, t('errors.generic'))}</Notice>}
    </div>
  );
}
