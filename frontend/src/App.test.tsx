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
  reason_required: { no: true, maybe: false }, training_group: null,
  my: [{ person_id: 'p1', name: 'Max', is_self: true, status: null, reason: null, is_guest: false, can_accept: true, guests_open_at: null, can_respond: true }],
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

describe('notifications', () => {
  const PREFS = [
    { category: 'event_new', push: true, email: false },
    { category: 'event_changed', push: true, email: true },
    { category: 'reminder', push: true, email: false },
    { category: 'notice', push: true, email: false },
  ];
  const profileApi = (extra: Record<string, Handler> = {}) => ({
    'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
    'GET /me/sessions': { body: { data: [] } },
    'GET /t/ssv-ulm/calendar': { body: { data: { url: 'https://vb.example.org/api/v1/t/ssv-ulm/calendar/x.ics' } } },
    'GET /t/ssv-ulm/notification-preferences': { body: { data: PREFS } },
    ...extra,
  });

  it('lets members choose push and e-mail per category', async () => {
    let sent: Record<string, unknown> = {};
    mockApi(profileApi({
      'PUT /t/ssv-ulm/notification-preferences': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: (sent as { preferences: unknown[] }).preferences } };
      },
    }));
    renderAt('/ssv-ulm/profile');

    const box = await screen.findByLabelText('Neue Termine: E-Mail');
    expect(box).not.toBeChecked();
    expect(screen.getByText(/unterstützt keine Push-Nachrichten/)).toBeInTheDocument();
    fireEvent.click(box);

    await waitFor(() => expect(sent).toEqual({ preferences: [{ ...PREFS[0], email: true }, ...PREFS.slice(1)] }));
    expect(screen.getByLabelText('Neue Termine: E-Mail')).toBeChecked();
  });

  it('explains on iPhones that the app must be on the home screen', async () => {
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Safari/604.1');
    mockApi(profileApi());
    renderAt('/ssv-ulm/profile');

    expect(await screen.findByText(/Zum Home-Bildschirm/)).toBeInTheDocument();
    vi.restoreAllMocks();
  });
});

describe('cross-nominated guests', () => {
  it('can decline but accept only once places open for guests', async () => {
    const guestEvent = { ...EVENT, my: [{ ...EVENT.my[0], is_guest: true, can_accept: false, guests_open_at: '2026-10-21T17:30:00Z' }] };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/notices': { body: { data: [] } },
      'GET /t/ssv-ulm/events?': { body: { data: [guestEvent], next_cursor: null } },
    });
    renderAt('/ssv-ulm');

    const card = await screen.findByRole('article', { name: 'Hallentraining' });
    expect(card).toHaveTextContent('als Gast');
    expect(card).toHaveTextContent(/Als Gast kannst du ab 21\.10\., \d\d:30 zusagen\./);
    expect(screen.getByRole('button', { name: /Zusage/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: /Absage/ })).toBeEnabled();
  });
});

describe('league tree', () => {
  it('lets admins add a sub-league', async () => {
    let sent: Record<string, unknown> = {};
    const tree = [
      { id: 'l4', parent_id: null, name: 'Regionalliga', level: 4, depth: 0, team_count: 1 },
      { id: 'l5', parent_id: null, name: 'Oberliga', level: 5, depth: 0, team_count: 0 },
    ];
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME(true) },
      'GET /t/ssv-ulm/leagues': { body: { data: tree } },
      'POST /t/ssv-ulm/leagues': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { data: [tree[0], { id: 'l4s', parent_id: 'l4', name: 'Regionalliga Süd', level: 4, depth: 1, team_count: 0 }, tree[1]] } };
      },
    });
    renderAt('/ssv-ulm/leagues');

    expect(await screen.findByText('Oberliga', { selector: 'span' })).toBeInTheDocument();
    // Leagues in use cannot be deleted.
    expect(screen.queryByRole('button', { name: 'Regionalliga löschen' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Oberliga löschen' })).toBeInTheDocument();

    fireEvent.change(screen.getByPlaceholderText('z. B. Regionalliga Süd'), { target: { value: 'Regionalliga Süd' } });
    fireEvent.change(screen.getByLabelText('Unter'), { target: { value: 'l4' } });
    fireEvent.click(screen.getByRole('button', { name: 'Hinzufügen' }));

    expect(await screen.findByText(/^Regionalliga Süd/, { selector: 'span' })).toBeInTheDocument();
    expect(sent).toEqual({ name: 'Regionalliga Süd', parent_id: 'l4' });
  });
});

describe('statistics', () => {
  it('shows the rate per player and switches the period', async () => {
    const team = { id: 't1', name: 'Herren 1', gender: 'men', is_youth: false, league_name: null, color: null, can_manage: true, can_invite: true, can_see: true };
    const stats = (from: string) => ({
      period: { from, to: '2026-10-25' },
      presets: { last_4_weeks: { from: '2026-09-27', to: '2026-10-25' }, season: { from: '2026-07-01', to: '2026-10-25' } },
      types: ['training', 'match', 'extra'], can_see_all: true, event_count: 3,
      rows: [
        { person: { id: 'p1', name: 'Paul Spieler', primary_position: null }, is_guest: false, is_current: true, invited: 3, yes: 3, maybe: 0, no: 0, open: 0, late: 0, rate: 100 },
        { person: { id: 'p2', name: 'Mia Mit', primary_position: null }, is_guest: false, is_current: true, invited: 3, yes: 1, maybe: 1, no: 1, open: 0, late: 1, rate: 33 },
      ],
    });
    const fetchMock = mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/teams/t1': { body: { data: team } },
      'GET /t/ssv-ulm/teams/t1/statistics?types%5B%5D=training&types%5B%5D=match&types%5B%5D=extra': { body: { data: stats('2026-07-01') } },
      'GET /t/ssv-ulm/teams/t1/statistics?from=2026-09-27&to=2026-10-25&types%5B%5D=training&types%5B%5D=match&types%5B%5D=extra': { body: { data: stats('2026-09-27') } },
    });
    renderAt('/ssv-ulm/teams/t1');

    fireEvent.click(await screen.findByRole('tab', { name: 'Statistik' }));
    expect(await screen.findByText('Zusagequote aller Spieler')).toBeInTheDocument();
    expect(screen.getByText('33 %')).toBeInTheDocument();
    expect(screen.getByText(/1 von 3 zugesagt · \? 1 · ✕ 1 · 1× nach Frist/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Letzte 4 Wochen' }));
    await waitFor(() => expect(screen.getByText(/27\.09\.2026 – 25\.10\.2026/)).toBeInTheDocument());
    expect(fetchMock.mock.calls.filter(([url]) => /\/statistics\?/.test(String(url))).length).toBe(2);
  });
});

describe('roster', () => {
  it('lets trainers take players of other teams into the team', async () => {
    let sent: Record<string, unknown> = {};
    const team = { id: 't2', name: 'Herren 2', gender: 'men', is_youth: false, league_name: null, color: null, can_manage: true, can_invite: true, can_see: true };
    const person = (id: string, first: string) => ({
      id, first_name: first, last_name: 'Test', jersey_number: null, gender: 'male', primary_position: null, secondary_positions: [],
      height_cm: null, reach_height_cm: null, jump_reach_cm: null, avatar_url: null, phone: null, email: null, address: null,
      birthday: null, is_minor: false, referee_license: null,
    });
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/teams/t2': { body: { data: team } },
      'GET /t/ssv-ulm/teams/t2/members': { body: { data: [{ person: person('p1', 'Paul'), roles: [{ id: 'm1', role: 'player' }], plays_higher_in: 'Herren 1' }] } },
      'GET /t/ssv-ulm/persons?q=Ma': { body: { data: [{ ...person('p2', 'Max'), teams: [{ id: 't1', name: 'Herren 1', role: 'player' }] }] } },
      'POST /t/ssv-ulm/teams/t2/members': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { data: { id: 'm2' } } };
      },
    });
    renderAt('/ssv-ulm/teams/t2');

    expect(await screen.findByText('Spielt in Herren 1 – trainiert hier mit, aber keine Spieltage')).toBeInTheDocument();
    fireEvent.change(screen.getByRole('searchbox', { name: 'Name suchen' }), { target: { value: 'Ma' } });
    expect(await screen.findByText('Herren 1 (Spieler/in)')).toBeInTheDocument();
    fireEvent.click(screen.getAllByRole('button', { name: 'Hinzufügen' })[0]);

    await waitFor(() => expect(sent).toEqual({ person_id: 'p2', role: 'player' }));
  });
});

describe('schedule import', () => {
  it('previews a file and imports the chosen matches', async () => {
    let sent: FormData | null = null;
    const team = { id: 't1', name: 'Herren 1', gender: 'men', is_youth: false, league_name: null, color: null, can_manage: true, can_invite: true, can_see: true };
    const row = (key: string, extra: Record<string, unknown>) => ({
      key, role: 'away', title: 'Spiel gegen TG Biberach', opponent: 'TG Biberach', starts_at: '2026-11-22T13:00:00Z', ends_at: '2026-11-22T15:00:00Z',
      location_name: 'Wilhelm-Leger Halle', address: null, details: null, past: false, status: 'new', changes: [], existing_id: null, selected: true, ...extra,
    });
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/teams/t1': { body: { data: team } },
      'POST /t/ssv-ulm/teams/t1/match-import/preview': {
        body: { data: {
          names: [{ name: 'SSV Ulm 1846', count: 16 }, { name: 'TG Biberach', count: 2 }], own_name: 'SSV Ulm 1846', skipped: 0, missing: [],
          rows: [
            row('uid:a', {}),
            row('uid:b', { title: 'Spiel gegen SC Göggingen', status: 'update', changes: ['starts_at', 'ends_at'] }),
            row('uid:c', { title: 'Spiel gegen VSG Illertal', status: 'same', selected: false }),
          ],
        } },
      },
      'POST /t/ssv-ulm/teams/t1/match-import': (init) => {
        sent = init.body as FormData;
        return { body: { data: { created: 1, updated: 0 } } };
      },
    });
    renderAt('/ssv-ulm/teams/t1/import');

    const input = (await screen.findByText('Datei auswählen')).querySelector('input')!;
    fireEvent.change(input, { target: { files: [new File(['BEGIN:VCALENDAR'], 'plan.ics', { type: 'text/calendar' })] } });

    expect(await screen.findByText('geändert: Uhrzeit')).toBeInTheDocument();
    expect(screen.getByText('schon vorhanden, unverändert')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('checkbox', { name: /SC Göggingen/ }));
    fireEvent.click(screen.getByRole('button', { name: '1 Termin übernehmen' }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent!.getAll('keys[]')).toEqual(['uid:a']);
    expect(sent!.get('own_name')).toBe('SSV Ulm 1846');
    expect(await screen.findByText('Fertig: 1 neu, 0 aktualisiert.')).toBeInTheDocument();
  });
});

describe('tasks and polls', () => {
  it('lets invited people take a task of an event', async () => {
    let sent: Record<string, unknown> = {};
    const detail = {
      ...EVENT, type: 'helper', response_mode: 'info', title: 'Vereinsfest', description: null, names_visible: true, series_id: null, series: null,
      team_ids: ['t1'], cross_nomination_opens_at: null, guests_open_at: null, responses: [], positions: null, my: [], task_summary: { count: 1, slots: 2, taken: 1 },
      tasks: [{ id: 'k1', title: 'Kasse', description: null, slots: 2, assignees: [{ person_id: 'p9', name: 'Mia Mit', mine: false }], can_take_for: [{ person_id: 'p1', name: 'Max' }] }],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/events/e1': { body: { data: detail } },
      'POST /t/ssv-ulm/tasks/k1/assignments': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: { ...detail, tasks: [{ ...detail.tasks[0], assignees: [...detail.tasks[0].assignees, { person_id: 'p1', name: 'Max Muster', mine: true }] }] } } };
      },
    });
    renderAt('/ssv-ulm/events/e1');

    expect(await screen.findByText('1/2 besetzt')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Übernehmen' }));
    await waitFor(() => expect(sent).toEqual({ person_id: 'p1' }));
    expect(await screen.findByText('2/2 besetzt')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Max Muster von „Kasse“ austragen' })).toBeInTheDocument();
  });

  it('votes in a poll and shows the result afterwards', async () => {
    let sent: Record<string, unknown> = {};
    const poll = {
      id: 'q1', title: 'Wann grillen wir?', description: null, scope: 'team', teams: [{ id: 't1', name: 'Herren 1' }], multiple_choice: false,
      anonymous: false, allow_free_text: false, closes_at: null, is_closed: false, created_at: '2026-10-19T10:00:00Z', can_manage: false,
      results_visible: false, voter_count: null, eligible_count: 5,
      options: [{ id: 'o1', label: 'Samstag', count: null, voters: null }, { id: 'o2', label: 'Sonntag', count: null, voters: null }],
      free_texts: [], my: [{ person_id: 'p1', name: 'Max', is_self: true, option_ids: [], free_text: null, can_vote: true }],
    };
    const voted = { ...poll, results_visible: true, voter_count: 1,
      options: [{ id: 'o1', label: 'Samstag', count: 0, voters: [] }, { id: 'o2', label: 'Sonntag', count: 1, voters: ['Max Muster'] }],
      my: [{ ...poll.my[0], option_ids: ['o2'] }] };
    let current: Record<string, unknown> = poll;
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/teams': { body: { data: [] } },
      'GET /t/ssv-ulm/polls': () => ({ body: { data: [current] } }),
      'POST /t/ssv-ulm/polls/q1/votes': (init) => {
        sent = JSON.parse(String(init.body));
        current = voted;
        return { body: { data: voted } };
      },
    });
    renderAt('/ssv-ulm/polls');

    expect(await screen.findByText('Das Ergebnis siehst du, sobald du abgestimmt hast.')).toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('Sonntag'));
    fireEvent.click(screen.getByRole('button', { name: 'Abstimmen' }));

    await waitFor(() => expect(sent).toEqual({ person_id: 'p1', option_ids: ['o2'], free_text: null }));
    expect(await screen.findByText('Deine Antwort: Sonntag')).toBeInTheDocument();
    expect(screen.getByText('1 von 5 haben abgestimmt')).toBeInTheDocument();
    expect(screen.getByText('Max Muster')).toBeInTheDocument();
  });
});
