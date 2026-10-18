import { createBrowserRouter, RouterProvider, type RouteObject } from 'react-router';
import { MENU_SECTIONS } from './components/AppHeader';
import { TenantLayout } from './components/TenantLayout';
import { HomePage } from './pages/HomePage';
import { NoTenantPage } from './pages/NoTenantPage';
import { PlaceholderPage } from './pages/PlaceholderPage';

export const routes: RouteObject[] = [
  { path: '/', element: <NoTenantPage /> },
  {
    // Every department lives under its own path: https://<domain>/<slug>/…
    path: '/:tenant',
    element: <TenantLayout />,
    children: [
      { index: true, element: <HomePage /> },
      ...MENU_SECTIONS.map((section) => ({ path: section, element: <PlaceholderPage section={section} /> })),
    ],
  },
];

const router = createBrowserRouter(routes);

export function App() {
  return <RouterProvider router={router} />;
}
