import { useEffect, useState } from 'react';
import { isIos, isStandalone } from './push';

/**
 * "Install as app": browsers fire beforeinstallprompt once the app is
 * installable (Chrome/Edge/Samsung on Android and desktop). It is caught as
 * early as possible and kept until the member taps "Install". iOS has no
 * such event – there it is "Share → Add to Home Screen".
 */
interface InstallPromptEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

let deferred: InstallPromptEvent | null = null;
const listeners = new Set<() => void>();
const notify = () => listeners.forEach((l) => l());

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferred = event as InstallPromptEvent;
    notify();
  });
  window.addEventListener('appinstalled', () => {
    deferred = null;
    notify();
  });
}

export type InstallMode = 'prompt' | 'ios' | null;

/** 'prompt' = our button opens the browser dialog, 'ios' = show the steps, null = installed or not possible. */
export function useInstall(): { mode: InstallMode; install: () => Promise<boolean> } {
  const [, setTick] = useState(0);
  useEffect(() => {
    const listener = () => setTick((n) => n + 1);
    listeners.add(listener);
    return () => void listeners.delete(listener);
  }, []);

  const mode: InstallMode = isStandalone() ? null : deferred ? 'prompt' : isIos() ? 'ios' : null;
  const install = async () => {
    if (!deferred) return false;
    const event = deferred;
    await event.prompt();
    const { outcome } = await event.userChoice;
    deferred = null;
    notify();
    return outcome === 'accepted';
  };

  return { mode, install };
}
