import { useTranslation } from 'react-i18next';
import type { ChatAttachment } from '../../lib/api';

export function formatSize(bytes: number | null): string {
  if (bytes === null) return '';
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}

const FILE_ICON: Record<string, string> = {
  pdf: '📕', doc: '📝', docx: '📝', odt: '📝', rtf: '📝', txt: '📝',
  xls: '📊', xlsx: '📊', ods: '📊', csv: '📊',
  ppt: '📽️', pptx: '📽️', odp: '📽️', odg: '🖼️', heic: '🖼️',
};

/** Pictures and videos inline, documents as cards to open or download. */
export function Attachments({ items, mine }: { items: ChatAttachment[]; mine: boolean }) {
  const { t } = useTranslation();
  const images = items.filter((a) => a.kind === 'image');
  const others = items.filter((a) => a.kind !== 'image');

  return (
    <div className="space-y-1">
      {images.length > 0 && (
        <div className={`grid gap-1 ${images.length > 1 ? 'grid-cols-2' : 'grid-cols-1'}`}>
          {images.map((a) => (
            <a key={a.id} href={a.url} target="_blank" rel="noopener" onClick={(e) => e.stopPropagation()}>
              <img
                src={a.url}
                alt={a.name}
                loading="lazy"
                width={a.width ?? undefined}
                height={a.height ?? undefined}
                className="max-h-72 w-full rounded-xl object-cover"
              />
            </a>
          ))}
        </div>
      )}
      {others.map((a) =>
        a.kind === 'video' ? (
          <div key={a.id}>
            <video src={a.url} controls playsInline preload="metadata" className="max-h-80 w-full rounded-xl bg-black" onClick={(e) => e.stopPropagation()} />
            {/* Not every browser plays every format (e.g. iPhone HEVC videos elsewhere). */}
            <a href={a.url} download={a.name} onClick={(e) => e.stopPropagation()} className={`mt-0.5 block text-right text-xs underline ${mine ? 'text-white/80' : 'text-slate-500'}`}>
              {t('chat.downloadVideo', { size: formatSize(a.size) })}
            </a>
          </div>
        ) : (
          <a
            key={a.id}
            href={a.url}
            target={a.mime_type === 'application/pdf' ? '_blank' : undefined}
            rel="noopener"
            download={a.mime_type === 'application/pdf' ? undefined : a.name}
            onClick={(e) => e.stopPropagation()}
            className={`flex items-center gap-3 rounded-xl px-3 py-2 ${mine ? 'bg-white/15' : 'bg-slate-100'}`}
          >
            <span className="text-2xl" aria-hidden="true">{FILE_ICON[a.name.split('.').pop()?.toLowerCase() ?? ''] ?? '📄'}</span>
            <span className="min-w-0">
              <span className="block truncate text-sm font-medium">{a.name}</span>
              <span className={`block text-xs ${mine ? 'text-white/70' : 'text-slate-500'}`}>{formatSize(a.size)} · {t('chat.open')}</span>
            </span>
          </a>
        ),
      )}
    </div>
  );
}
