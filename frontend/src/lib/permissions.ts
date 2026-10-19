import type { TenantMe } from './api';

/** Mirrors the server rules – only to decide what to show; the server decides. */
export function canCreateEvents(me: TenantMe | undefined): boolean {
  return !!me && (me.is_admin || ownTeamRoles(me).some((r) => r.role === 'trainer' || r.role === 'organizer'));
}

export function trainerTeamIds(me: TenantMe | undefined): string[] {
  return ownTeamRoles(me).filter((r) => r.role === 'trainer').map((r) => r.id);
}

function ownTeamRoles(me: TenantMe | undefined) {
  return me?.persons.find((p) => p.relation === 'self')?.teams ?? [];
}

/** Cash books: admins, trainers and organizers. */
export function canUseCash(me: TenantMe | undefined): boolean {
  return canCreateEvents(me);
}
