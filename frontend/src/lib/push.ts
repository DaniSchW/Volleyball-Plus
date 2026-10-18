import { api, post } from './api';

/**
 * Push messages on this device. iPhones/iPads only allow them for apps
 * added to the home screen (iOS 16.4+).
 */
export type PushState = 'unsupported' | 'needs-install' | 'denied' | 'enabled' | 'disabled';

const supported = () => typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

export function isIos(): boolean {
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

export function isStandalone(): boolean {
  return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

async function currentSubscription(): Promise<PushSubscription | null> {
  const registration = await navigator.serviceWorker.getRegistration();
  return (await registration?.pushManager.getSubscription()) ?? null;
}

export async function pushState(): Promise<PushState> {
  if (isIos() && !isStandalone()) return 'needs-install';
  if (!supported()) return 'unsupported';
  if (Notification.permission === 'denied') return 'denied';
  if (Notification.permission !== 'granted') return 'disabled';
  return (await currentSubscription()) ? 'enabled' : 'disabled';
}

export async function enablePush(): Promise<PushState> {
  if (!supported()) return 'unsupported';
  // Must follow a tap directly – browsers block permission prompts otherwise.
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'disabled';

  const registration = await navigator.serviceWorker.ready;
  const { data } = await api<{ data: { public_key: string } }>('/push/key');
  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToBytes(data.public_key) }));
  await post('/push/subscriptions', subscription.toJSON() as Record<string, unknown>);

  return 'enabled';
}

export async function disablePush(): Promise<PushState> {
  if (!supported()) return 'unsupported';
  const subscription = await currentSubscription();
  if (subscription) {
    await api('/push/subscriptions', { method: 'DELETE', body: { endpoint: subscription.endpoint } }).catch(() => undefined);
    await subscription.unsubscribe();
  }
  return 'disabled';
}

/** Before logging out: this device should no longer get the account's messages. */
export async function forgetPushOnLogout(): Promise<void> {
  if (supported()) await disablePush().catch(() => undefined);
}

export function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
}

