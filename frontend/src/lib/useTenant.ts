import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { api, type TenantInfo } from './api';

export function useTenant() {
  const { tenant = '' } = useParams();

  return useQuery({
    queryKey: ['tenant', tenant],
    queryFn: async () => (await api<{ data: TenantInfo }>(`/t/${encodeURIComponent(tenant)}`)).data,
    enabled: tenant !== '',
    retry: false,
  });
}
