/// <reference lib="webworker" />
/**
 * Service worker: offline app shell (precache from the build) and push
 * messages. Push payload from the server: { title, body, url, tag }.
 */
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';

declare const self: ServiceWorkerGlobalScope;

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();
registerRoute(new NavigationRoute(createHandlerBoundToURL('/app.html'), { denylist: [/^\/api\//, /^\/cron\//, /^\/install/, /^\/up$/] }));

// New versions take over right away (registerType "autoUpdate").
self.addEventListener('install', () => void self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

interface PushPayload {
  title: string;
  body?: string;
  url?: string;
  tag?: string;
}

self.addEventListener('push', (event) => {
  let payload: PushPayload;
  try {
    payload = event.data?.json() as PushPayload;
  } catch {
    payload = { title: event.data?.text() ?? 'VolleyOrg' };
  }

  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      tag: payload.tag,
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      data: { url: payload.url ?? '/' },
    }),
  );
});

// Tapping the message opens the event in an open app window or a new one.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data as { url?: string } | null)?.url ?? '/', self.location.origin);
  if (target.origin !== self.location.origin) return;

  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      const open = windows.find((client) => new URL(client.url).origin === target.origin);
      if (open) {
        await open.focus();
        await open.navigate(target.href).catch(() => undefined);
        return;
      }
      await self.clients.openWindow(target.href);
    })(),
  );
});

// The browser renewed the subscription: tell the server about the new one.
self.addEventListener('pushsubscriptionchange', (event) => {
  const change = event as Event & { waitUntil(p: Promise<unknown>): void };
  change.waitUntil(
    (async () => {
      const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
      const response = await fetch('/api/v1/push/key', { credentials: 'same-origin', headers });
      if (!response.ok) return;
      const { data } = (await response.json()) as { data: { public_key: string } };
      const subscription = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64UrlToBytes(data.public_key) });
      await fetch('/api/v1/push/subscriptions', { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(subscription.toJSON()) });
    })(),
  );
});

function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
}
