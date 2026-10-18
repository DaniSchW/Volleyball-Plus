import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { routes } from './App';
import { changeLanguage } from './i18n';

type Handler = { status?: number; body: unknown } | ((init: RequestInit) => { status?: number; body: unknown });

const TENANT = { data: { slug: 'ssv-ulm', name: 'SSV Ulm Volleyball', default_locale: 'de' } };
const ACCOUNT = { data: { email: 'max@example.org', locale: 'de', tenants: [{ slug: 'ssv-ulm', name: 'SSV Ulm Volleyball' }] } };
const ME = (isAdmin = false) => ({ data: { member_id: 'm1', is_admin: isAdmin, persons: [] } });

/** Fake API: answers by "METHOD path". */
function mockApi(handlers: Record<string, Handler>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const path = String(input).replace('/api/v1', '');
    const key = `${init.method ?? 'GET'} ${path}`;
    const handler = handlers[key];
    const result = typeof handler === 'function' ? handler(init) : handler ?? { status: 404, body: { message: 'not mocked: ' + key } };
    return new Response(JSON.stringify(result.body), { status: result.status ?? 200, headers: { 'Content-Type': 'application/json' } });
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

function renderAt(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  changeLanguage('de');
});

describe('app shell', () => {
  it('shows the department and the start page to members', async () => {
    mockApi({ 'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() } });

    renderAt('/ssv-ulm');

    expect(await screen.findByText('SSV Ulm Volleyball')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Anstehende Termine' })).toBeInTheDocument();
  });

  it('sends visitors without login to the login page', async () => {
    mockApi({ 'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { status: 401, body: { message: 'Bitte melde dich an.' } } });

    const router = renderAt('/ssv-ulm/teams');

    expect(await screen.findByRole('button', { name: 'Anmeldelink schicken' })).toBeInTheDocument();
    expect(router.state.location.pathname).toBe('/ssv-ulm/login');
  });

  it('opens the menu at the top right; admins also see the members page', async () => {
    mockApi({ 'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME(true) }, 'PATCH /me': { body: ACCOUNT } });
    renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: 'Menü öffnen' }));
    expect(screen.getByRole('link', { name: 'Teams' })).toHaveAttribute('href', '/ssv-ulm/teams');
    expect(screen.getByRole('link', { name: 'Mitglieder & Admins' })).toBeInTheDocument();

    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'en' } });
    expect(await screen.findByRole('link', { name: 'Cash box' })).toBeInTheDocument();
  });

  it('closes the menu with Escape', async () => {
    mockApi({ 'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() } });
    renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: 'Menü öffnen' }));
    fireEvent.keyDown(document, { key: 'Escape' });

    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('reports an unknown department', async () => {
    mockApi({ 'GET /me': { status: 401, body: {} }, 'GET /t/gibt-es-nicht': { status: 404, body: { message: 'Not found' } } });

    renderAt('/gibt-es-nicht');

    expect(await screen.findByText('Diese Abteilung gibt es nicht.')).toBeInTheDocument();
  });
});

const EVENT = {
  id: 'e1', type: 'training', response_mode: 'rsvp', scope: 'team', status: 'scheduled', title: 'Hallentraining',
  starts_at: '2026-10-20T17:30:00Z', ends_at: '2026-10-20T19:30:00Z', meet_at: null, rsvp_deadline: null, deadline_passed: false,
  location_name: 'Kuhberghalle', address: null, directions_url: null, teams: [{ id: 't1', name: 'Herren 1', color: null }],
  opponent: null, is_home: null, own_logo_url: null, opponent_logo_url: null, counts: { yes: 3, no: 1, maybe: 0, open: 6 },
  max_participants: null, is_full: false, min_participants: 6, below_minimum: true, can_manage: false,
  reason_required: { no: true, maybe: false },
  my: [{ person_id: 'p1', name: 'Max', is_self: true, status: null, reason: null, can_respond: true }],
};

describe('start page', () => {
  it('shows upcoming events and asks for a reason when declining', async () => {
    let sent: Record<string, unknown> = {};
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/notices': { body: { data: [{ id: 'n1', scope: 'team', team: { id: 't1', name: 'Herren 1' }, title: 'Halle zu in den Ferien', body: null, visible_from: '2026-10-19T00:00:00Z', visible_until: '2026-10-31T00:00:00Z', can_delete: false }] } },
      'GET /t/ssv-ulm/events?': { body: { data: [EVENT], next_cursor: null } },
      'POST /t/ssv-ulm/events/e1/responses': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: { ...EVENT, my: [{ ...EVENT.my[0], status: 'no', reason: 'Arbeit' }], responses: null, names_visible: false, description: null, series_id: null, series: null, team_ids: ['t1'] } } };
      },
    });
    renderAt('/ssv-ulm');

    expect(await screen.findByText('Halle zu in den Ferien')).toBeInTheDocument();
    const card = await screen.findByRole('article', { name: 'Hallentraining' });
    expect(card).toHaveTextContent('Mindestens 6 Zusagen nötig');
    expect(card.className).toContain('amber');

    fireEvent.click(screen.getByRole('button', { name: /Absage/ }));
    const reason = screen.getByLabelText('Grund');
    expect(reason).toBeRequired();
    fireEvent.change(reason, { target: { value: 'Arbeit' } });
    fireEvent.click(screen.getByRole('button', { name: 'Senden' }));

    await waitFor(() => expect(sent).toEqual({ person_id: 'p1', status: 'no', reason: 'Arbeit' }));
  });

  it('accepts with one tap', async () => {
    let sent: Record<string, unknown> = {};
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/notices': { body: { data: [] } },
      'GET /t/ssv-ulm/events?': { body: { data: [EVENT], next_cursor: null } },
      'POST /t/ssv-ulm/events/e1/responses': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: { ...EVENT, responses: null } } };
      },
    });
    renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: /Zusage/ }));
    await waitFor(() => expect(sent).toEqual({ person_id: 'p1', status: 'yes' }));
  });
});

describe('login', () => {
  it('requests a link with the CSRF header', async () => {
    const fetchMock = mockApi({
      'GET /t/ssv-ulm': { body: TENANT },
      'POST /t/ssv-ulm/auth/login': { status: 202, body: { message: 'ok' } },
    });
    renderAt('/ssv-ulm/login');

    fireEvent.change(await screen.findByLabelText('E-Mail-Adresse'), { target: { value: 'max@example.org' } });
    fireEvent.click(screen.getByRole('button', { name: 'Anmeldelink schicken' }));

    expect(await screen.findByText(/ist ein Anmeldelink unterwegs/)).toBeInTheDocument();
    const [, init] = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/login'))!;
    expect((init as RequestInit).headers).toMatchObject({ 'X-Requested-With': 'XMLHttpRequest' });
    expect(JSON.parse(String((init as RequestInit).body))).toEqual({ email: 'max@example.org' });
  });

  it('only logs in when the button on the link page is pressed', async () => {
    const fetchMock = mockApi({
      'GET /t/ssv-ulm': { body: TENANT },
      'POST /auth/consume': { body: { data: { tenant: 'ssv-ulm' } } },
      'GET /me': { body: ACCOUNT },
      'GET /t/ssv-ulm/me': { body: ME() },
    });
    const router = renderAt('/ssv-ulm/login/confirm?token=abc');

    const button = await screen.findByRole('button', { name: 'Jetzt anmelden' });
    expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/auth/consume'))).toBe(false);

    fireEvent.click(button);
    await waitFor(() => expect(router.state.location.pathname).toBe('/ssv-ulm'));
    const [, init] = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/consume'))!;
    expect(JSON.parse(String((init as RequestInit).body))).toEqual({ token: 'abc' });
  });
});

describe('joining', () => {
  it('lets parents register a child', async () => {
    let sent: Record<string, unknown> = {};
    mockApi({
      'GET /me': { status: 401, body: {} },
      'GET /t/ssv-ulm/invitations/tok': {
        body: { data: { tenant: 'SSV Ulm', kind: 'team', team: 'U16 männlich', role: 'player', relation: null, person: null, allows_child: true } },
      },
      'POST /t/ssv-ulm/invitations/tok/accept': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 202, body: { data: { status: 'confirm' }, message: 'Bitte bestätige deine Adresse.' } };
      },
    });
    renderAt('/ssv-ulm/join/tok');

    expect(await screen.findByRole('heading', { name: 'Einladung zu U16 männlich als Spieler/in' })).toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('Ich melde mein Kind an (unter 18)'));
    fireEvent.change(screen.getByLabelText('Vorname des Kindes'), { target: { value: 'Kim' } });
    fireEvent.change(screen.getByLabelText('Nachname'), { target: { value: 'Klein' } });
    fireEvent.change(screen.getByLabelText('Geburtstag'), { target: { value: '2013-04-01' } });
    fireEvent.change(screen.getByLabelText('Deine E-Mail-Adresse (Elternteil)'), { target: { value: 'eltern@example.org' } });
    fireEvent.click(screen.getByRole('button', { name: 'Beitreten' }));

    expect(await screen.findByText('Bitte bestätige deine Adresse.')).toBeInTheDocument();
    expect(sent).toEqual({
      email: 'eltern@example.org', mode: 'child',
      child_first_name: 'Kim', child_last_name: 'Klein', child_gender: 'female', child_birthday: '2013-04-01',
    });
  });
});
