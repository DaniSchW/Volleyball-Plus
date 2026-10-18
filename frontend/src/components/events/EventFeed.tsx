import { useEffect, useRef } from 'react';
import { useInfiniteQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api, type EventPage } from '../../lib/api';
import { useTenantPath, useTenantSlug } from '../../lib/queries';
import { Loading, Notice } from '../ui';
import { EventCard } from './EventCard';

/**
 * Event list with real infinite scrolling: the next page loads as soon as
 * the end of the list scrolls into view – no "more" button.
 */
export function EventFeed({ past = false, teamId }: { past?: boolean; teamId?: string }) {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tp = useTenantPath();
  const sentinel = useRef<HTMLDivElement>(null);

  const query = useInfiniteQuery({
    queryKey: ['events', slug, { past, teamId }],
    initialPageParam: '',
    queryFn: ({ pageParam }) => {
      const params = new URLSearchParams();
      if (pageParam) params.set('cursor', pageParam);
      if (past) params.set('past', '1');
      if (teamId) params.set('team', teamId);
      return api<EventPage>(`${tp('events')}?${params.toString()}`);
    },
    getNextPageParam: (last) => last.next_cursor ?? undefined,
  });

  const { hasNextPage, isFetchingNextPage, fetchNextPage } = query;

  useEffect(() => {
    const node = sentinel.current;
    if (!node || !hasNextPage || typeof IntersectionObserver === 'undefined') return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting) && !isFetchingNextPage) void fetchNextPage();
      },
      { rootMargin: '400px' },
    );
    observer.observe(node);
    return () => observer.disconnect();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage]);

  if (query.isPending) return <Loading />;
  if (query.isError) return <Notice tone="error">{t('errors.generic')}</Notice>;

  const events = query.data.pages.flatMap((page) => page.data);

  return (
    <div className="space-y-3">
      {events.length === 0 && (
        <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
          {past ? t('events.noPast') : t('home.noEvents')}
        </p>
      )}
      {events.map((event) => (
        <EventCard key={event.id} event={event} />
      ))}
      <div ref={sentinel} aria-hidden="true" />
      {isFetchingNextPage && <Loading />}
    </div>
  );
}
