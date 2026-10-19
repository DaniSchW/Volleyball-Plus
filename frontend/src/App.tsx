import { createBrowserRouter, RouterProvider, type RouteObject } from 'react-router';
import { TenantLayout } from './components/TenantLayout';
import { EventFormPage } from './pages/EventFormPage';
import { EventPage } from './pages/EventPage';
import { EventsPage } from './pages/EventsPage';
import { HomePage } from './pages/HomePage';
import { JoinPage } from './pages/JoinPage';
import { LoginConfirmPage } from './pages/LoginConfirmPage';
import { LoginPage } from './pages/LoginPage';
import { LeaguesPage } from './pages/LeaguesPage';
import { MatchImportPage } from './pages/MatchImportPage';
import { CashBookPage } from './pages/CashBookPage';
import { CashPage } from './pages/CashPage';
import { ChatListPage } from './pages/ChatListPage';
import { HallsPage } from './pages/HallsPage';
import { ChatPage } from './pages/ChatPage';
import { MembersPage } from './pages/MembersPage';
import { PollsPage } from './pages/PollsPage';
import { StatsPage } from './pages/StatsPage';
import { TasksPage } from './pages/TasksPage';
import { NoTenantPage } from './pages/NoTenantPage';
import { PersonPage } from './pages/PersonPage';
import { ProfilePage } from './pages/ProfilePage';
import { TeamPage } from './pages/TeamPage';
import { TeamsPage } from './pages/TeamsPage';

export const routes: RouteObject[] = [
  { path: '/', element: <NoTenantPage /> },
  // Public pages of a department.
  { path: '/:tenant/login', element: <LoginPage /> },
  { path: '/:tenant/login/confirm', element: <LoginConfirmPage /> },
  { path: '/:tenant/join/:token', element: <JoinPage /> },
  {
    // Every department lives under its own path: https://<domain>/<slug>/…
    path: '/:tenant',
    element: <TenantLayout />,
    children: [
      { index: true, element: <HomePage /> },
      { path: 'events', element: <EventsPage /> },
      { path: 'events/new', element: <EventFormPage /> },
      { path: 'events/:eventId', element: <EventPage /> },
      { path: 'events/:eventId/edit', element: <EventFormPage /> },
      { path: 'teams', element: <TeamsPage /> },
      { path: 'teams/:teamId', element: <TeamPage /> },
      { path: 'teams/:teamId/import', element: <MatchImportPage /> },
      { path: 'persons/:personId', element: <PersonPage /> },
      { path: 'profile', element: <ProfilePage /> },
      { path: 'members', element: <MembersPage /> },
      { path: 'leagues', element: <LeaguesPage /> },
      { path: 'chat', element: <ChatListPage /> },
      { path: 'chat/:chatId', element: <ChatPage /> },
      { path: 'polls', element: <PollsPage /> },
      { path: 'tasks', element: <TasksPage /> },
      { path: 'stats', element: <StatsPage /> },
      { path: 'cash', element: <CashPage /> },
      { path: 'cash/:bookId', element: <CashBookPage /> },
      { path: 'halls', element: <HallsPage /> },
    ],
  },
];

const router = createBrowserRouter(routes);

export function App() {
  return <RouterProvider router={router} />;
}
