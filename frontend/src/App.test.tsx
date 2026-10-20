import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
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
  max_participants: null, position_limits: null, is_full: false, min_participants: 6, below_minimum: true, can_manage: false,
  reason_required: { no: true, maybe: false }, training_group: null,
  my: [{ person_id: 'p1', name: 'Max', is_self: true, status: null, reason: null, as_position: null, positions: [], is_guest: false, can_accept: true, guests_open_at: null, can_respond: true }],
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
  it('guests can decline but not accept when the training does not allow guests', async () => {
    const guestEvent = { ...EVENT, my: [{ ...EVENT.my[0], is_guest: true, can_accept: false, guests_open_at: '2026-10-21T17:30:00Z' }] };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/notices': { body: { data: [] } },
      'GET /t/ssv-ulm/events?': { body: { data: [guestEvent], next_cursor: null } },
    });
    renderAt('/ssv-ulm');

    const card = await screen.findByRole('article', { name: 'Hallentraining' });
    expect(card).toHaveTextContent('als Gast');
    expect(card).toHaveTextContent('Gäste können für dieses Training nicht zusagen.');
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

  it('lets the trainer remind those who have not voted by push and e-mail', async () => {
    let sent: Record<string, unknown> = {};
    const poll = {
      id: 'q1', title: 'Wann grillen wir?', description: null, scope: 'team', teams: [{ id: 't1', name: 'Herren 1' }], multiple_choice: false,
      anonymous: false, allow_free_text: false, closes_at: null, is_closed: false, created_at: '2026-10-19T10:00:00Z', can_manage: true,
      results_visible: true, voter_count: 1, eligible_count: 5,
      options: [{ id: 'o1', label: 'Samstag', count: 1, voters: ['Max Muster'] }], free_texts: [], my: [],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/teams': { body: { data: [] } },
      'GET /t/ssv-ulm/polls': { body: { data: [poll] } },
      'POST /t/ssv-ulm/polls/q1/remind': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: { push: 3, email: 1 }, message: 'Benachrichtigt: 3 per Push, 1 per E-Mail.' } };
      },
    });
    renderAt('/ssv-ulm/polls');

    fireEvent.click(await screen.findByRole('button', { name: '🔔 Erinnern' }));
    expect(screen.getByRole('checkbox', { name: 'Nur an die, die noch nicht abgestimmt haben' })).toBeChecked();
    fireEvent.click(screen.getByRole('button', { name: 'Jetzt senden' }));
    await waitFor(() => expect(sent).toEqual({ push: true, email: 'without_push', only_open: true }));
    expect(await screen.findByText('Benachrichtigt: 3 per Push, 1 per E-Mail.')).toBeInTheDocument();
  });
});

describe('task templates', () => {
  it('creates a template with optional numbers and takes it over into home matches', async () => {
    let created: Record<string, unknown> = {};
    let applied: Record<string, unknown> = {};
    const template = { id: 'tt1', name: 'Spieltag Damen 1', team: { id: 't1', name: 'Damen 1' }, items: [{ title: 'Kasse', description: null, slots: 2 }, { title: 'Aufräumen', description: null, slots: null }], can_change: true };
    const match = { ...EVENT, id: 'e7', type: 'match', title: 'Spiel gegen TSV', is_home: true, status: 'scheduled' };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/tasks': { body: { data: [] } },
      'GET /t/ssv-ulm/task-templates': { body: { data: { templates: [template], teams: [{ id: 't1', name: 'Damen 1' }], can_create_for_all: false } } },
      'POST /t/ssv-ulm/task-templates': (init) => {
        created = JSON.parse(String(init.body));
        return { status: 201, body: { data: template } };
      },
      'GET /t/ssv-ulm/events?team=t1': { body: { data: [match], next_cursor: null } },
      'POST /t/ssv-ulm/task-templates/tt1/apply': (init) => {
        applied = JSON.parse(String(init.body));
        return { body: { data: { events: 1, tasks: 2 }, message: '2 Aufgaben in 1 Termine übernommen.' } };
      },
    });
    renderAt('/ssv-ulm/tasks');

    expect(await screen.findByText('📋 Spieltag Damen 1')).toBeInTheDocument();
    expect(screen.getByText('Kasse ×2')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Neue Vorlage' }));
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Heimspieltag' } });
    fireEvent.change(screen.getByLabelText('Aufgabe 1'), { target: { value: 'Linienrichter' } });
    fireEvent.change(screen.getByLabelText('Anzahl für Aufgabe 1'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: '+ Aufgabe hinzufügen' }));
    fireEvent.change(screen.getByLabelText('Aufgabe 2'), { target: { value: 'Kuchen' } });
    fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));
    await waitFor(() => expect(created).toEqual({ name: 'Heimspieltag', team_id: 't1', items: [{ title: 'Linienrichter', slots: 2 }, { title: 'Kuchen', slots: null }] }));

    fireEvent.click(screen.getByRole('button', { name: 'Auf Termine übertragen' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Das Heimspiel auswählen' }));
    fireEvent.click(screen.getByRole('button', { name: 'Auf 1 Termin übertragen' }));
    await waitFor(() => expect(applied).toEqual({ event_ids: ['e7'] }));
    expect(await screen.findByText('2 Aufgaben in 1 Termine übernommen.')).toBeInTheDocument();
  });
});

describe('opponent logo', () => {
  it('uploads the opponent logo at the match; the list shows logos only once the opponent has one', async () => {
    let uploaded: FormData | null = null;
    const match = {
      ...EVENT, id: 'e9', type: 'match', title: 'Spiel gegen SG Ailingen', opponent: { id: 'op1', name: 'SG Ailingen' }, is_home: false,
      own_logo_url: '/logo.png', opponent_logo_url: null,
    };
    const detail = {
      ...match, description: null, names_visible: false, series_id: null, series: null, team_ids: ['t1'], cross_nomination_opens_at: null,
      guests_open_at: null, responses: null, positions: null, tasks: [], task_summary: null, position_limits: null, position_taken: null,
      guest_position_needs: null, can_manage: true, can_record_attendance: false, attendance_recorded_at: null,
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/notices': { body: { data: [] } }, 'GET /t/ssv-ulm/polls': { body: { data: [] } },
      'GET /t/ssv-ulm/events?': { body: { data: [match], next_cursor: null } },
      'GET /t/ssv-ulm/events/e9': { body: { data: detail } },
      'GET /t/ssv-ulm/task-templates': { body: { data: { templates: [], teams: [], can_create_for_all: false } } },
      'POST /t/ssv-ulm/opponents/op1/logo': (init) => {
        uploaded = init.body as FormData;
        return { body: { data: { logo_url: '/x.png' } } };
      },
    });
    const router = renderAt('/ssv-ulm');

    expect(await screen.findByText('Spiel gegen SG Ailingen')).toBeInTheDocument();
    expect(screen.queryByText('gegen')).not.toBeInTheDocument();

    await act(async () => { await router.navigate('/ssv-ulm/events/e9'); });
    const input = (await screen.findByText('Logo des Gegners hochladen')).parentElement!.querySelector('input')!;
    fireEvent.change(input, { target: { files: [new File(['png'], 'ailingen.png', { type: 'image/png' })] } });
    await waitFor(() => expect(uploaded?.get('image')).toBeInstanceOf(File));
  });
});

describe('replies entered by the trainer', () => {
  it('sets a player to accepted right in the list', async () => {
    let sent: Record<string, unknown> = {};
    const row = { person: { id: 'p2', name: 'Paul Spieler', avatar_url: null, primary_position: null, positions: [], role: 'player', is_guest: false }, status: null, as_position: null, reason: null, responded_at: null, attended: null };
    const detail = {
      ...EVENT, description: null, names_visible: true, series_id: null, series: null, team_ids: ['t1'], cross_nomination_opens_at: null,
      guests_open_at: null, positions: null, tasks: [], task_summary: null, position_limits: null, position_taken: null, guest_position_needs: null,
      can_manage: true, can_record_attendance: false, attendance_recorded_at: null, deadline_passed: false, my: [], responses: [row],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/events/e1': { body: { data: detail } },
      'GET /t/ssv-ulm/task-templates': { body: { data: { templates: [], teams: [], can_create_for_all: false } } },
      'POST /t/ssv-ulm/events/e1/responses': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: { ...detail, responses: [{ ...row, status: 'yes' }] } } };
      },
    });
    renderAt('/ssv-ulm/events/e1');

    fireEvent.click(await screen.findByRole('button', { name: 'Paul Spieler: Zusage eintragen' }));
    await waitFor(() => expect(sent).toEqual({ person_id: 'p2', status: 'yes' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Paul Spieler: Zusage eintragen' })).toHaveAttribute('aria-pressed', 'true'));
  });
});

describe('chat', () => {
  it('shows messages and sends a reply', async () => {
    let sent: Record<string, unknown> = {};
    const msg = (id: string, body: string, mine = false) => ({
      id, topic_id: null, author: { id: mine ? 'm1' : 'm2', name: mine ? 'Max Muster' : 'Tom Trainer' }, is_mine: mine, body,
      deleted: false, edited: false, reply_to: null, attachments: [], can_delete: mine, created_at: '2026-10-19T08:00:00Z',
    });
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/chats/c1': { body: { data: { id: 'c1', kind: 'team', name: 'Herren 1', team_id: 't1', mode: 'flat', can_moderate: false, topics: [], members: [], upload: { max_bytes: 52428800, per_message: 10, extensions: ['pdf', 'jpg'] } } } },
      'GET /t/ssv-ulm/chats/c1/messages': { body: { data: [msg('01a', 'Training fällt aus')], changed: [], has_more: false, server_time: '2026-10-19T10:00:00Z' } },
      'GET /t/ssv-ulm/chats': { body: { data: [] } },
      'POST /t/ssv-ulm/chats/c1/messages': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { data: { ...msg('01b', 'Schade!', true), reply_to: { id: '01a', author: 'Tom Trainer', body: 'Training fällt aus' } } } };
      },
    });
    renderAt('/ssv-ulm/chat/c1');

    fireEvent.click(await screen.findByRole('button', { name: /^Training fällt aus/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Antworten' }));
    expect(screen.getByText('Antwort an Tom Trainer: Training fällt aus')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Nachricht schreiben'), { target: { value: 'Schade!' } });
    fireEvent.click(screen.getByRole('button', { name: 'Senden' }));

    await waitFor(() => expect(sent).toEqual({ body: 'Schade!', topic_id: null, reply_to_id: '01a', attachment_ids: [] }));
    expect(await screen.findByRole('button', { name: /Schade!/ })).toBeInTheDocument();
  });
});

describe('chat attachments', () => {
  it('shows pictures, videos and documents of a message', async () => {
    const att = (id: string, kind: string, name: string, mime: string) => ({ id, kind, name, mime_type: mime, size: 245760, width: null, height: null, url: `/api/v1/t/ssv-ulm/chat-attachments/${id}` });
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/chats': { body: { data: [] } },
      'GET /t/ssv-ulm/chats/c1': { body: { data: { id: 'c1', kind: 'team', name: 'Herren 1', team_id: 't1', mode: 'flat', can_moderate: false, topics: [], members: [], upload: { max_bytes: 1000, per_message: 10, extensions: ['pdf'] } } } },
      'GET /t/ssv-ulm/chats/c1/messages': { body: { data: [{
        id: '01a', topic_id: null, author: { id: 'm2', name: 'Tom Trainer' }, is_mine: false, body: 'Infos zum Turnier', deleted: false, edited: false,
        reply_to: null, can_delete: false, created_at: '2026-10-19T08:00:00Z',
        attachments: [att('a1', 'image', 'Halle.webp', 'image/webp'), att('a2', 'video', 'Aufschlag.mp4', 'video/mp4'), att('a3', 'file', 'Spielplan.ods', 'application/vnd.oasis.opendocument.spreadsheet')],
      }], changed: [], has_more: false, server_time: '2026-10-19T10:00:00Z' } },
    });
    renderAt('/ssv-ulm/chat/c1');

    expect(await screen.findByRole('img', { name: 'Halle.webp' })).toHaveAttribute('src', '/api/v1/t/ssv-ulm/chat-attachments/a1');
    expect(document.querySelector('video')).toHaveAttribute('src', '/api/v1/t/ssv-ulm/chat-attachments/a2');
    expect(screen.getByRole('link', { name: /Spielplan\.ods/ })).toHaveAttribute('download', 'Spielplan.ods');
    expect(screen.getAllByText(/240 KB/)).toHaveLength(2);

    // Too large files are refused before uploading.
    const input = document.querySelector('input[type=file]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'.repeat(2000)], 'gross.pdf', { type: 'application/pdf' })] } });
    expect(await screen.findByText('zu groß (max. 0 MB)')).toBeInTheDocument();
  });
});

describe('cash', () => {
  const book = (entries: unknown[], balance: number) => ({
    data: {
      id: 'b1', kind: 'team', team_id: 't1', name: 'Herren 1', archived: false, opening_balance_cents: 0,
      summary: { opening: 0, carried: 0, income: 2000, expenses: balance - 2000, closing: balance, balance },
      entries, categories: ['Getränke', 'Strafe'], persons: [{ id: 'p1', name: 'Paul Spieler' }],
    },
  });
  const fine = {
    id: 'e1', booked_on: '2026-10-01', amount_cents: 2000, category: 'Strafe', description: 'Zu spät', person: { id: 'p1', name: 'Paul Spieler' },
    created_by: 'Tom Trainer', created_at: null, cancelled_at: null, cancelled_by: null, balance_after: 2000,
  };

  it('books an expense and cancels a booking', async () => {
    let sent: Record<string, unknown> = {};
    let cancelled = false;
    let current = book([fine], 2000);
    vi.stubGlobal('confirm', () => true);
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/cash-books/b1': () => ({ body: current }),
      'POST /t/ssv-ulm/cash-books/b1/entries': (init) => {
        sent = JSON.parse(String(init.body));
        current = book([{ ...fine, id: 'e2', amount_cents: -1250, category: 'Getränke', description: 'Wasser', person: null, balance_after: 750 }, fine], 750);
        return { status: 201, body: { data: {} } };
      },
      'POST /t/ssv-ulm/cash-entries/e1/cancel': () => {
        cancelled = true;
        return { body: { message: 'ok' } };
      },
    });
    renderAt('/ssv-ulm/cash/b1');

    expect(await screen.findByText('Zu spät')).toBeInTheDocument();
    expect(screen.getByText('Gebucht von Tom Trainer')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('radio', { name: 'Ausgabe' }));
    fireEvent.change(screen.getByLabelText('Betrag (€)', { selector: 'input[placeholder]' }), { target: { value: '12,50' } });
    fireEvent.change(screen.getByLabelText('Beschreibung'), { target: { value: 'Wasser' } });
    fireEvent.change(screen.getByLabelText('Kategorie', { selector: 'input' }), { target: { value: 'Getränke' } });
    fireEvent.click(screen.getByRole('button', { name: 'Buchen' }));

    await waitFor(() => expect(sent).toMatchObject({ amount_cents: -1250, description: 'Wasser', category: 'Getränke', person_id: null }));
    expect(await screen.findByText('Wasser')).toBeInTheDocument();
    expect(screen.getByText(/7,50/, { selector: 'p.text-2xl' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Buchung „Zu spät“ stornieren' }));
    await waitFor(() => expect(cancelled).toBe(true));
  });

  it('hides the cash in the menu of players, halls stay visible', async () => {
    mockApi({ 'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() } });
    renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: 'Menü öffnen' }));
    expect(screen.getByRole('link', { name: 'Chat' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Kasse' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Hallen' })).toBeInTheDocument();
  });
});

describe('halls', () => {
  it('assigns a team and warns about an overlap', async () => {
    let sent: Record<string, unknown> = {};
    const hall = { id: 'h1', name: 'Kuhberghalle', address: null, courts: 3, contact: null, notes: null, slots: [], bookings: [] as unknown[] };
    const booking = {
      id: 'k1', team: { id: 't2', name: 'Damen 1', color: null, archived: false }, weekday: 2, start_time: '20:00', end_time: '22:00',
      courts: [1, 2], valid_from: null, valid_until: null, notes: null, outside_slots: false,
      conflicts: [{ id: 'k0', team: 'Herren 1', start_time: '19:30', end_time: '21:30', courts: null }],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME(true) },
      'GET /t/ssv-ulm/halls': { body: { data: [hall], booking: null } },
      'GET /t/ssv-ulm/teams': { body: { data: [{ id: 't2', name: 'Damen 1' }] } },
      'POST /t/ssv-ulm/halls/h1/bookings': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { data: [{ ...hall, bookings: [booking] }], booking } };
      },
    });
    renderAt('/ssv-ulm/halls');

    fireEvent.click(await screen.findByRole('button', { name: 'Mannschaft zuordnen' }));
    await screen.findByRole('option', { name: 'Damen 1' });
    fireEvent.change(screen.getByLabelText('Mannschaft'), { target: { value: 't2' } });
    fireEvent.change(screen.getByLabelText('Tag'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Von'), { target: { value: '20:00' } });
    fireEvent.change(screen.getByLabelText('Bis'), { target: { value: '22:00' } });
    fireEvent.click(screen.getByLabelText('Teil 2'));
    fireEvent.click(screen.getByLabelText('Teil 1'));
    expect(screen.getByText('Teile 1 + 2 von 3')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

    await waitFor(() => expect(sent).toMatchObject({ team_id: 't2', weekday: 2, start_time: '20:00', end_time: '22:00', courts: [1, 2] }));
    expect(await screen.findByText('Gespeichert – bitte prüfen:')).toBeInTheDocument();
    expect(screen.getAllByText('Überschneidung mit Herren 1 (19:30–21:30)', { exact: false })).toHaveLength(2);
    expect(screen.getByText('1 Belegung überschneidet sich mit einer anderen.')).toBeInTheDocument();
  });
});

describe('positions', () => {
  it('shows taken places and accepts for a chosen position', async () => {
    let sent: Record<string, unknown> = {};
    const detail = {
      ...EVENT, description: null, names_visible: false, series_id: null, series: null, team_ids: ['t1'], cross_nomination_opens_at: null,
      guests_open_at: null, responses: null, positions: null, tasks: [], task_summary: null,
      position_limits: { setter: 2, outside: 3 }, position_taken: { setter: 2, outside: 1 },
      my: [{ ...EVENT.my[0], positions: ['setter', 'outside'] }],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/events/e1': { body: { data: detail } },
      'POST /t/ssv-ulm/events/e1/responses': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: { ...detail, position_taken: { setter: 2, outside: 2 }, my: [{ ...detail.my[0], status: 'yes', as_position: 'outside' }] } } };
      },
    });
    renderAt('/ssv-ulm/events/e1');

    expect(await screen.findByText('2/2')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Zusagen als'), { target: { value: 'outside' } });
    fireEvent.click(screen.getByRole('button', { name: /Zusage/ }));

    await waitFor(() => expect(sent).toEqual({ person_id: 'p1', status: 'yes', position: 'outside' }));
    expect(await screen.findByText('Zugesagt als Außenangriff')).toBeInTheDocument();
    expect(screen.getByText('2/3')).toBeInTheDocument();
  });
});

describe('positions in the replies', () => {
  it('shows short position badges, accepted players sorted by position', async () => {
    const row = (id: string, name: string, status: string | null, primary: string | null, positions: string[], asPosition: string | null = null) => ({
      person: { id, name, avatar_url: null, primary_position: primary, positions, role: 'player', is_guest: false },
      status, as_position: asPosition, reason: null, responded_at: null,
    });
    const detail = {
      ...EVENT, description: null, names_visible: true, series_id: null, series: null, team_ids: ['t1'], cross_nomination_opens_at: null,
      guests_open_at: null, positions: null, tasks: [], task_summary: null, position_limits: null, position_taken: null, guest_position_needs: null,
      responses: [
        row('p1', 'Anna Berg', 'yes', 'outside', ['outside']),
        row('p2', 'Zoe Zuspiel', 'yes', 'setter', ['setter', 'opposite']),
        row('p3', 'Mia Mitte', 'yes', 'outside', ['outside', 'middle'], 'middle'),
        row('p4', 'Lea Libero', null, 'libero', ['libero']),
      ],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/events/e1': { body: { data: detail } },
    });
    renderAt('/ssv-ulm/events/e1');

    expect(await screen.findByText('Zoe Zuspiel')).toBeInTheDocument();
    // Counted position bold first (Mia accepted as middle blocker), further positions next to it.
    expect(screen.getAllByText('ZU')).toHaveLength(1);
    expect(screen.getAllByText('MB')).toHaveLength(1);
    expect(screen.getAllByText('AA')).toHaveLength(2);
    expect(screen.getByText('L')).toHaveAttribute('title', 'Libero');
    const names = screen.getAllByText(/^(Anna Berg|Zoe Zuspiel|Mia Mitte)$/).map((el) => el.textContent);
    expect(names).toEqual(['Zoe Zuspiel', 'Mia Mitte', 'Anna Berg']);
  });
});

describe('standing replies', () => {
  it('answers all Wednesday trainings at once', async () => {
    let sent: Record<string, unknown> = {};
    const options = {
      rules: [], season_end: '2027-06-30',
      persons: [{ id: 'p1', name: 'Max Muster', teams: [{ id: 't1', name: 'Herren 1', series: [{ id: 's1', title: 'Training', weekdays: [3, 5], start_time: '19:30', end_time: '21:30' }] }] }],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/rsvp-rules': { body: { data: options } },
      'POST /t/ssv-ulm/rsvp-rules': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { data: { id: 'r1' }, applied: 8, skipped: [] } };
      },
    });
    renderAt('/ssv-ulm/replies');

    fireEvent.click(await screen.findByRole('button', { name: 'Mi' }));
    fireEvent.change(screen.getByLabelText('Gilt bis'), { target: { value: 'season' } });
    fireEvent.click(screen.getByRole('button', { name: 'Für alle passenden Termine eintragen' }));

    await waitFor(() => expect(sent).toMatchObject({ person_id: 'p1', status: 'yes', team_id: 't1', event_type: 'training', weekdays: [3], overwrite: true, series_id: null, valid_until: '2027-06-30' }));
    expect(await screen.findByText('8 Termine beantwortet.')).toBeInTheDocument();
  });
});

describe('after the event', () => {
  it('marks missing reactions and lets trainers record attendance', async () => {
    let sent: Record<string, unknown> = {};
    const row = (id: string, name: string, status: string | null, attended: boolean) => ({
      person: { id, name, avatar_url: null, primary_position: null, positions: [], role: 'player', is_guest: false },
      status, as_position: null, reason: null, responded_at: null, attended,
    });
    const detail = {
      ...EVENT, description: null, names_visible: true, series_id: null, series: null, team_ids: ['t1'], cross_nomination_opens_at: null,
      guests_open_at: null, positions: null, tasks: [], task_summary: null, position_limits: null, position_taken: null, guest_position_needs: null,
      can_record_attendance: true, attendance_recorded_at: null, deadline_passed: true, my: [],
      responses: [row('p1', 'Anna Berg', 'yes', true), row('p2', 'Ole Ohne', null, false)],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/events/e1': { body: { data: detail } },
      'PUT /t/ssv-ulm/events/e1/attendance': (init) => {
        sent = JSON.parse(String(init.body));
        return { body: { data: { ...detail, attendance_recorded_at: '2026-10-20T20:00:00Z' } } };
      },
    });
    renderAt('/ssv-ulm/events/e1');

    expect(await screen.findByText('⚠️ Nicht reagiert (1)')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Nachtragen' }));
    fireEvent.click(screen.getByLabelText('Ole Ohne'));
    fireEvent.click(screen.getByLabelText('Anna Berg'));
    fireEvent.click(screen.getByRole('button', { name: 'Anwesenheit speichern' }));
    await waitFor(() => expect(sent).toEqual({ present: ['p2'] }));
  });
});

describe('documents', () => {
  it('lists documents for everybody and per team and uploads one', async () => {
    let sent: FormData | null = null;
    const doc = (id: string, title: string, team: { id: string; name: string } | null, folder: string | null = null) => ({
      id, title, description: null, folder, team, name: `${id}.pdf`, mime_type: 'application/pdf', size_bytes: 120000,
      uploaded_at: '2026-10-20T08:00:00Z', url: `/api/v1/t/ssv-ulm/documents/${id}/file`, can_manage: false,
    });
    const list = {
      can_upload: false, max_mb: 50,
      teams: [{ id: 't1', name: 'Herren 1', can_upload: true }],
      documents: [doc('d1', 'Vereinsmeldung 2026', null, 'Verband'), doc('d2', 'Aufbauanleitung Spieltag', { id: 't1', name: 'Herren 1' })],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/documents': { body: { data: list } },
      'POST /t/ssv-ulm/documents': (init) => {
        sent = init.body as FormData;
        return { status: 201, body: { data: doc('d3', 'Hallenordnung', { id: 't1', name: 'Herren 1' }) } };
      },
    });
    renderAt('/ssv-ulm/documents');

    expect(await screen.findByRole('link', { name: /Vereinsmeldung 2026/ })).toHaveAttribute('href', '/api/v1/t/ssv-ulm/documents/d1/file');
    expect(screen.getByText('📂 Verband')).toBeInTheDocument();
    expect(screen.getByText(/Aufbauanleitung Spieltag/)).toBeInTheDocument();
    // A trainer of Herren 1 uploads only there (not "for everybody").
    expect(screen.queryByLabelText('Sichtbar für')).not.toBeInTheDocument();
    const file = new File(['%PDF-1.4'], 'hallenordnung.pdf', { type: 'application/pdf' });
    fireEvent.change(screen.getByLabelText('Datei'), { target: { files: [file] } });
    expect(screen.getByLabelText('Titel')).toHaveValue('hallenordnung');
    fireEvent.click(screen.getByRole('button', { name: 'Hochladen' }));

    await waitFor(() => expect(sent?.get('team_id')).toBe('t1'));
    expect(sent!.get('title')).toBe('hallenordnung');
  });
});

describe('notice expiry', () => {
  it('cannot publish an info without an expiry date', async () => {
    let sent: Record<string, unknown> = {};
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME(true) },
      'GET /t/ssv-ulm/notices': { body: { data: [] } },
      'GET /t/ssv-ulm/polls': { body: { data: [] } },
      'GET /t/ssv-ulm/events': { body: { data: [], next_cursor: null } },
      'GET /t/ssv-ulm/teams': { body: { data: [] } },
      'POST /t/ssv-ulm/notices': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { data: {} } };
      },
    });
    renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: 'Info hinzufügen' }));
    fireEvent.change(screen.getByLabelText('Überschrift'), { target: { value: 'Halle zu' } });
    expect(screen.getByLabelText('Verfällt am (Pflicht)')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Veröffentlichen' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: '1 Woche' }));
    expect(screen.getByLabelText('Verfällt am (Pflicht)')).not.toHaveValue('');
    expect(screen.getByRole('checkbox', { name: 'Push-Nachricht senden' })).toBeChecked();
    fireEvent.click(screen.getByRole('button', { name: 'Veröffentlichen' }));
    await waitFor(() => expect(String(sent.visible_until)).toMatch(/T23:59$/));
    expect(sent.notify).toBe(true);
  });
});

describe('legal pages', () => {
  const legal = {
    name: 'Erika Muster', company: null, street: 'Musterweg 1', postal_code: '89073', city: 'Ulm', country: 'Deutschland',
    email: 'service@example.org', phone: null, vat_id: null, updated_on: '2026-10-20', complete: true,
  };

  it('shows the imprint with the operator details and links to the other texts', async () => {
    mockApi({ 'GET /legal': { body: { data: legal } } });
    renderAt('/impressum');
    expect(await screen.findByRole('heading', { name: 'Impressum' })).toBeInTheDocument();
    expect((await screen.findAllByText(/Musterweg 1/)).length).toBeGreaterThan(0);
    expect(screen.getByRole('link', { name: 'service@example.org' })).toHaveAttribute('href', 'mailto:service@example.org');
    expect(screen.getByRole('link', { name: 'AGB' })).toHaveAttribute('href', '/agb');
  });

  it('marks missing details and explains promotional e-mails in the terms', async () => {
    mockApi({ 'GET /legal': { body: { data: { ...legal, name: null, street: null, complete: false } } } });
    renderAt('/agb');
    expect(await screen.findByText('Die Angaben zum Betreiber sind noch nicht vollständig eingetragen.')).toBeInTheDocument();
    expect(screen.getByText('§ 8 Werbung und E-Mails')).toBeInTheDocument();
    expect(screen.getByText(/Name des Betreibers – noch nicht eingetragen/)).toBeInTheDocument();
  });
});

describe('hall form', () => {
  it('saves address fields and weekly training times', async () => {
    let sent: Record<string, unknown> = {};
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME(true) },
      'GET /t/ssv-ulm/halls': { body: { data: [], booking: null } },
      'POST /t/ssv-ulm/halls': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { data: [], booking: null } };
      },
    });
    renderAt('/ssv-ulm/halls');

    fireEvent.change((await screen.findAllByLabelText('Name'))[0], { target: { value: 'Kuhberghalle' } });
    fireEvent.change(screen.getByLabelText('Straße'), { target: { value: 'Kuhbergstraße' } });
    fireEvent.change(screen.getByLabelText('PLZ'), { target: { value: '89077' } });
    fireEvent.click(screen.getByRole('button', { name: 'Zeit am Montag hinzufügen' }));
    fireEvent.change(screen.getByLabelText('Montag bis'), { target: { value: '21:30' } });
    fireEvent.click(screen.getByRole('button', { name: 'Halle anlegen' }));

    await waitFor(() => expect(sent.opening_hours).toEqual([{ weekday: 1, start_time: '18:00', end_time: '21:30', courts: [] }]));
    expect(sent).toMatchObject({ street: 'Kuhbergstraße', postal_code: '89077', house_number: null, city: null });
  });
});

describe('halls for players', () => {
  it('shows times, parts and the contact without edit buttons', async () => {
    const hall = {
      id: 'h1', name: 'Kuhberghalle', address: null, courts: 3, notes: null,
      contact: { name: 'Herr Maier', phone: '0731 123456', email: null },
      slots: [{ id: 's1', weekday: 2, start_time: '18:00', end_time: '22:00', courts: [1, 2], valid_from: null, valid_until: null }],
      bookings: [],
    };
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/halls': { body: { data: [hall], booking: null } },
    });
    renderAt('/ssv-ulm/halls');

    expect(await screen.findByText(/18:00–22:00 · Teile 1 \+ 2 von 3/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: '0731 123456' })).toHaveAttribute('href', 'tel:0731123456');
    expect(screen.getByText(/Herr Maier/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Mannschaft zuordnen' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Bearbeiten' })).not.toBeInTheDocument();
  });
});

describe('platform', () => {
  it('logs in from the start page', async () => {
    let sent: Record<string, unknown> = {};
    mockApi({
      'GET /me': { status: 401, body: { message: 'Unauthenticated.' } },
      'POST /auth/login': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 202, body: { message: 'ok' } };
      },
    });
    renderAt('/');

    fireEvent.change(await screen.findByLabelText('E-Mail-Adresse'), { target: { value: 'max@example.org' } });
    fireEvent.click(screen.getByRole('button', { name: 'Anmeldelink schicken' }));
    await waitFor(() => expect(sent).toEqual({ email: 'max@example.org' }));
    expect(screen.getByRole('link', { name: 'Verein registrieren' })).toHaveAttribute('href', '/register');
  });

  it('registers a club with a suggested short name', async () => {
    let sent: Record<string, unknown> = {};
    mockApi({
      'POST /clubs/register': (init) => {
        sent = JSON.parse(String(init.body));
        return { status: 201, body: { message: 'Danke! Wir prüfen die Anmeldung.' } };
      },
    });
    renderAt('/register');

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'TSV Blaubeuren Volleyball' } });
    expect(screen.getByLabelText('Kürzel für eure Adresse')).toHaveValue('tsv-blaubeuren-volleyball');
    fireEvent.change(screen.getByLabelText('Vorname'), { target: { value: 'Bea' } });
    fireEvent.change(screen.getByLabelText('Nachname'), { target: { value: 'Blau' } });
    fireEvent.change(screen.getByLabelText('E-Mail-Adresse'), { target: { value: 'bea@example.org' } });
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Registrierung absenden' }));

    await waitFor(() => expect(sent).toMatchObject({ club_name: 'TSV Blaubeuren Volleyball', slug: 'tsv-blaubeuren-volleyball', first_name: 'Bea', accept: true }));
    expect(await screen.findByText('Danke! Wir prüfen die Anmeldung.')).toBeInTheDocument();
  });

  it('lets platform admins approve a club', async () => {
    let approved = false;
    const club = {
      id: 'c1', slug: 'tsv-blaubeuren', name: 'TSV Blaubeuren Volleyball', city: 'Blaubeuren', status: 'pending', contact_name: 'Bea Blau',
      contact_email: 'bea@example.org', note: null, created_at: '2026-10-19T10:00:00Z', approved_at: null, members: 1, teams: 0,
    };
    mockApi({
      'GET /me': { body: { data: { ...ACCOUNT.data, is_platform_admin: true } } },
      'GET /platform/clubs': { body: { data: [club] } },
      'POST /platform/clubs/c1/approve': () => {
        approved = true;
        return { body: { data: [{ ...club, status: 'active' }] } };
      },
    });
    renderAt('/platform');

    expect(await screen.findByText('Warten auf Freigabe (1)')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Freigeben' }));
    await waitFor(() => expect(approved).toBe(true));
    expect(await screen.findByText('Aktiv (1)')).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Konten' })).not.toBeInTheDocument();
  });

  it('lets the superadmin see and delete accounts', async () => {
    let deleted: Record<string, unknown> = {};
    const row = (id: string, email: string, extra = {}) => ({
      id, email, created_at: '2024-01-01T10:00:00Z', last_active_at: '2026-10-01T10:00:00Z', deletion_warned_at: null, deletion_due_at: null,
      is_platform_admin: false, is_superadmin: false, clubs: [{ name: 'SSV Ulm Volleyball', slug: 'ssv-ulm', is_admin: false, active: true }], ...extra,
    });
    mockApi({
      'GET /me': { body: { data: { ...ACCOUNT.data, email: 'service@example.org', is_platform_admin: true, is_superadmin: true } } },
      'GET /platform/clubs': { body: { data: [] } },
      'GET /platform/accounts?filter=all&page=1': { body: { data: [
        row('a1', 'alt@example.org', { deletion_due_at: '2026-11-03T10:00:00Z' }),
        row('a2', 'service@example.org', { is_superadmin: true, clubs: [] }),
      ], meta: { total: 2, page: 1, last_page: 1 } } },
      'POST /platform/accounts/a1/delete': (init) => {
        deleted = JSON.parse(String(init.body));
        return { body: { message: 'Das Konto wurde gelöscht.' } };
      },
    });
    vi.spyOn(window, 'prompt').mockReturnValue('alt@example.org');
    renderAt('/platform');

    fireEvent.click(await screen.findByRole('tab', { name: 'Konten' }));
    expect(await screen.findByText('2 Konten')).toBeInTheDocument();
    expect(screen.getByText(/Löschung angekündigt – fällig am/)).toBeInTheDocument();
    // The own (superadmin) account has no delete button.
    expect(screen.getAllByRole('button', { name: 'Löschen' })).toHaveLength(1);
    fireEvent.click(screen.getByRole('button', { name: 'Löschen' }));
    await waitFor(() => expect(deleted).toEqual({ confirm: 'alt@example.org' }));
    expect(await screen.findByText('Das Konto wurde gelöscht.')).toBeInTheDocument();
  });
});

describe('platform access', () => {
  it('tells a club admin that the platform needs the operator account instead of loading forever', async () => {
    let loggedOut = false;
    mockApi({
      'GET /me': { body: ACCOUNT },
      'POST /auth/logout': () => {
        loggedOut = true;
        return { body: { message: 'ok' } };
      },
    });
    renderAt('/platform');

    expect(await screen.findByText(/Du bist als max@example.org angemeldet/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Abmelden und mit anderem Konto anmelden' }));
    await waitFor(() => expect(loggedOut).toBe(true));
  });

  it('sends visitors who are not logged in to the login', async () => {
    mockApi({ 'GET /me': { status: 401, body: { message: 'Unauthenticated.' } } });
    renderAt('/platform');
    expect(await screen.findByRole('link', { name: 'Zur Anmeldung' })).toHaveAttribute('href', '/');
  });
});

describe('logout from the menu', () => {
  it('logs out from every page via the menu', async () => {
    let loggedOut = false;
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME() },
      'GET /t/ssv-ulm/notices': { body: { data: [] } }, 'GET /t/ssv-ulm/polls': { body: { data: [] } },
      'GET /t/ssv-ulm/events': { body: { data: [], next_cursor: null } },
      'POST /auth/logout': () => {
        loggedOut = true;
        return { body: { message: 'ok' } };
      },
    });
    const router = renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: 'Menü öffnen' }));
    fireEvent.click(screen.getByRole('button', { name: /Abmelden/ }));
    await waitFor(() => expect(loggedOut).toBe(true));
    await waitFor(() => expect(router.state.location.pathname).toBe('/ssv-ulm/login'));
  });
});

describe('activity log', () => {
  it('shows admins what changed and by whom', async () => {
    mockApi({
      'GET /t/ssv-ulm': { body: TENANT }, 'GET /me': { body: ACCOUNT }, 'GET /t/ssv-ulm/me': { body: ME(true) },
      'GET /t/ssv-ulm/activity': { body: { data: [
        { id: 'a1', action: 'updated', type: 'event', label: 'Techniktraining (20.10.2026 19:30)', actor: 'Tom Trainer', by_system: false, created_at: '2026-10-19T08:00:00Z' },
      ] } },
    });
    renderAt('/ssv-ulm/activity');

    expect(await screen.findByText('Techniktraining (20.10.2026 19:30)')).toBeInTheDocument();
    expect(screen.getByText('geändert')).toBeInTheDocument();
    expect(screen.getByText('von Tom Trainer')).toBeInTheDocument();
  });
});
