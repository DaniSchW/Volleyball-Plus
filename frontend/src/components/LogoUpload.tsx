import { useMutation } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { errorText, post } from '../lib/api';
import { Card, Notice } from './ui';

/** Picture upload for logos (department, team). */
export function LogoUpload({ title, path, currentUrl, onUploaded }: { title: string; path: string; currentUrl: string | null; onUploaded: () => void }) {
  const { t } = useTranslation();
  const upload = useMutation({
    mutationFn: (file: File) => {
      const body = new FormData();
      body.append('image', file);
      return post(path, body);
    },
    onSuccess: onUploaded,
  });

  return (
    <Card title={title}>
      <div className="flex items-center gap-3">
        {currentUrl && <img src={currentUrl} alt="" className="vo-logo size-14 object-contain" />}
        <label className="inline-flex min-h-11 cursor-pointer items-center rounded-lg border border-slate-300 px-4 text-sm font-medium hover:bg-slate-50">
          {currentUrl ? t('events.changeLogo') : t('events.uploadLogo')}
          <input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={(e) => e.target.files?.[0] && upload.mutate(e.target.files[0])} />
        </label>
      </div>
      {upload.isError && <div className="mt-2"><Notice tone="error">{errorText(upload.error, t('errors.generic'))}</Notice></div>}
    </Card>
  );
}
