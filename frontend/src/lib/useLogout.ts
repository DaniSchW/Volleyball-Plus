import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router';
import { post } from './api';
import { forgetPushOnLogout } from './push';

/** Logs out on this device (push of this device off) and goes to the given page. */
export function useLogout(to = '/') {
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  return useMutation({
    mutationFn: async () => {
      await forgetPushOnLogout();
      return post('/auth/logout');
    },
    onSuccess: () => {
      queryClient.clear();
      navigate(to, { replace: true });
    },
  });
}
