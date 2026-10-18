import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { api, ApiError, type Account, type TenantInfo, type TenantMe } from './api';

export function useTenantSlug(): string {
  return useParams().tenant ?? '';
}

/** Public facts about the department in the URL. */
export function useTenant() {
  const tenant = useTenantSlug();

  return useQuery({
    queryKey: ['tenant', tenant],
    queryFn: async () => (await api<{ data: TenantInfo }>(`/t/${encodeURIComponent(tenant)}`)).data,
    enabled: tenant !== '',
    retry: false,
  });
}

/** The logged-in account; null when not logged in. */
export function useAccount() {
  return useQuery({
    queryKey: ['account'],
    queryFn: async () => {
      try {
        return (await api<{ data: Account }>('/me')).data;
      } catch (error) {
        if (error instanceof ApiError && error.status === 401) return null;
        throw error;
      }
    },
    retry: false,
  });
}

/** The logged-in member in this department (roles, own profile, children). */
export function useTenantMe() {
  const tenant = useTenantSlug();

  return useQuery({
    queryKey: ['tenant-me', tenant],
    queryFn: async () => (await api<{ data: TenantMe }>(`/t/${tenant}/me`)).data,
    enabled: tenant !== '',
    retry: false,
  });
}

/** Builds a department API path: tp('teams') → /t/<slug>/teams. */
export function useTenantPath(): (path: string) => string {
  const tenant = useTenantSlug();
  return (path: string) => `/t/${tenant}/${path}`;
}
