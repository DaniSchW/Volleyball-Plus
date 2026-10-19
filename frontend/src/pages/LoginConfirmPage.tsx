import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { errorText, post } from '../lib/api';
import { useTenant, useTenantSlug } from '../lib/queries';
import { Button, Notice } from '../components/ui';

/**
 * Landing page of the magic link. Login happens only on the button click
 * (POST), so mail scanners that open links in advance do not use it up.
 */
export function LoginConfirmPage() {
  const { t } = useTranslation();
  const slug = useTenantSlug();
  const tenant = useTenant();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const consume = useMutation({
    mutationFn: () => post<{ data: { tenant: string | null } }>('/auth/consume', { token: params.get('token') ?? '' }),
    onSuccess: async ({ data }) => {
      await queryClient.invalidateQueries();
      // Without a club (login from the start page) the start page lists the clubs.
      navigate(data.tenant ? `/${data.tenant}` : '/', { replace: true });
    },
  });

  return (
    <div className="mx-auto flex min-h-dvh max-w-sm flex-col justify-center gap-6 p-4 text-center">
      <h1 className="text-2xl font-semibold">{slug ? tenant.data?.name ?? '…' : t('app.name')}</h1>
      <Button onClick={() => consume.mutate()} disabled={consume.isPending || consume.isSuccess}>
        {t('login.confirm')}
      </Button>
      {consume.isError && (
        <>
          <Notice tone="error">{errorText(consume.error, t('errors.generic'))}</Notice>
          <Link to={slug ? `/${slug}/login` : '/'} className="text-sm text-brand-700 underline">
            {t('login.again')}
          </Link>
        </>
      )}
    </div>
  );
}
