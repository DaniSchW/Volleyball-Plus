import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { routes } from './App';
import { changeLanguage } from './i18n';

function renderAt(path: string) {
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

function mockFetch(status: number, body: unknown) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })),
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  changeLanguage('de');
});

describe('app shell', () => {
  it('shows the department and the start page', async () => {
    mockFetch(200, { data: { slug: 'ssv-ulm', name: 'SSV Ulm Volleyball', default_locale: 'de' } });

    renderAt('/ssv-ulm');

    expect(await screen.findByText('SSV Ulm Volleyball')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Anstehende Termine' })).toBeInTheDocument();
    expect(fetch).toHaveBeenCalledWith('/api/v1/t/ssv-ulm', expect.anything());
  });

  it('opens the menu at the top right and switches the language', async () => {
    mockFetch(200, { data: { slug: 'ssv-ulm', name: 'SSV Ulm Volleyball', default_locale: 'de' } });
    renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: 'Menü öffnen' }));
    expect(screen.getByRole('link', { name: 'Kasse' })).toHaveAttribute('href', '/ssv-ulm/cash');

    fireEvent.change(screen.getByRole('combobox'), { target: { value: 'en' } });
    expect(await screen.findByRole('link', { name: 'Cash box' })).toBeInTheDocument();
  });

  it('closes the menu with Escape', async () => {
    mockFetch(200, { data: { slug: 'ssv-ulm', name: 'SSV Ulm Volleyball', default_locale: 'de' } });
    renderAt('/ssv-ulm');

    fireEvent.click(await screen.findByRole('button', { name: 'Menü öffnen' }));
    fireEvent.keyDown(document, { key: 'Escape' });

    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('reports an unknown department', async () => {
    mockFetch(404, { message: 'Not found' });

    renderAt('/gibt-es-nicht');

    expect(await screen.findByText('Diese Abteilung gibt es nicht.')).toBeInTheDocument();
  });
});
