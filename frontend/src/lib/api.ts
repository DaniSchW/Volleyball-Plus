export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

type Body = Record<string, unknown> | FormData;

interface Options extends Omit<RequestInit, 'body'> {
  body?: Body;
}

/**
 * JSON request against the Laravel API (/api/v1/…), same origin only. The
 * login session travels as HttpOnly cookie; X-Requested-With is the CSRF
 * protection the server requires on every changing request.
 */
export async function api<T>(path: string, { body, ...init }: Options = {}): Promise<T> {
  const isForm = body instanceof FormData;
  const response = await fetch(`/api/v1${path}`, {
    credentials: 'same-origin',
    ...init,
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    headers: {
      Accept: 'application/json',
      'Accept-Language': document.documentElement.lang || 'de',
      'X-Requested-With': 'XMLHttpRequest',
      ...(body !== undefined && !isForm ? { 'Content-Type': 'application/json' } : {}),
      ...init.headers,
    },
  });

  if (!response.ok) {
    const data = (await response.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
    throw new ApiError(response.status, data?.message ?? response.statusText, data?.errors ?? {});
  }

  return (await response.json()) as T;
}

export const post = <T>(path: string, body: Body = {}) => api<T>(path, { method: 'POST', body });
export const patch = <T>(path: string, body: Body) => api<T>(path, { method: 'PATCH', body });
export const put = <T>(path: string, body: Body) => api<T>(path, { method: 'PUT', body });
export const del = <T>(path: string) => api<T>(path, { method: 'DELETE' });

/** First validation message per field, or the general message. */
export function errorText(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.errors)[0]?.[0];
    return first ?? error.message ?? fallback;
  }
  return fallback;
}

// ---- API types ----

export interface TenantInfo {
  slug: string;
  name: string;
  logo_url: string | null;
  default_locale: string;
}

export interface Account {
  email: string;
  locale: string;
  tenants: { slug: string; name: string }[];
}

export type TeamRole = 'player' | 'trainer' | 'organizer';

export interface MyPerson {
  id: string;
  relation: 'self' | 'guardian';
  first_name: string;
  last_name: string;
  teams: { id: string; name: string; role: TeamRole }[];
}

export interface TenantMe {
  member_id: string;
  is_admin: boolean;
  persons: MyPerson[];
}

export interface TeamSettings {
  min_participants: number | null;
  default_max_participants: number | null;
  cross_nomination_mode: 'immediately' | 'hours_before_start' | 'after_rsvp_deadline';
  cross_nomination_hours: number | null;
  decline_reason_required: boolean;
  maybe_reason_required: boolean;
  reasons_visible_to_teammates: boolean;
  rsvp_visibility: 'names' | 'counts';
}

export interface Team {
  id: string;
  name: string;
  gender: 'men' | 'women' | 'mixed';
  is_youth: boolean;
  color: string | null;
  can_manage: boolean;
  can_invite: boolean;
  can_see: boolean;
  rank_in_league?: number;
  logo_url?: string | null;
  settings?: TeamSettings;
}

export interface Address {
  street: string | null;
  postal_code: string | null;
  city: string | null;
  country: string | null;
}

export type Position = 'setter' | 'opposite' | 'middle' | 'outside' | 'libero' | 'universal';
export const POSITIONS: Position[] = ['setter', 'opposite', 'middle', 'outside', 'libero', 'universal'];
export const DETAIL_FIELDS = ['phone', 'email', 'address', 'birthday'] as const;

export interface Person {
  id: string;
  first_name: string;
  last_name: string;
  jersey_number: number | null;
  gender: 'male' | 'female';
  primary_position: Position | null;
  secondary_positions: Position[];
  height_cm: number | null;
  reach_height_cm: number | null;
  jump_reach_cm: number | null;
  avatar_url: string | null;
  phone: string | null;
  email: string | null;
  address: Address | null;
  birthday: string | null;
  is_minor: boolean;
}

export interface MinorPermissions {
  own_login: boolean;
  can_rsvp: boolean;
  can_chat: boolean;
  can_take_tasks: boolean;
  can_vote_polls: boolean;
  editable_profile_fields: string[];
}

export interface PersonDetail extends Person {
  shared_fields: string[] | null;
  editable_fields: string[];
  can_manage_profile: boolean;
  can_manage_minor_permissions: boolean;
  can_invite_guardian: boolean;
  can_invite_own: boolean;
  minor_permissions?: MinorPermissions;
  teams: { id: string; name: string; role: TeamRole }[];
}

export interface RosterRow {
  person: Person;
  roles: { id: string; role: TeamRole }[];
}

export interface Invitation {
  id: string;
  kind: 'team' | 'person';
  role: TeamRole | null;
  relation: 'self' | 'guardian' | null;
  url: string;
  use_count: number;
  max_uses: number | null;
  expires_at: string | null;
}

export interface PublicInvitation {
  tenant: string;
  kind: 'team' | 'person';
  team: string | null;
  role: TeamRole | null;
  relation: 'self' | 'guardian' | null;
  person: string | null;
  allows_child: boolean;
}

export interface SessionInfo {
  id: string;
  user_agent: string | null;
  last_seen_at: string;
  created_at: string;
  current: boolean;
}

export interface MemberRow {
  id: string;
  email: string;
  is_admin: boolean;
  status: 'active' | 'disabled';
  persons: { id: string; name: string; relation: 'self' | 'guardian' }[];
}

// ---- events ----

export type EventType = 'training' | 'match' | 'extra';
export type RsvpStatus = 'yes' | 'no' | 'maybe';

export interface MyReply {
  person_id: string;
  name: string;
  is_self: boolean;
  status: RsvpStatus | null;
  reason: string | null;
  can_respond: boolean;
}

export interface EventSummary {
  id: string;
  type: EventType;
  response_mode: 'rsvp' | 'info';
  scope: 'team' | 'tenant';
  status: 'scheduled' | 'cancelled';
  title: string;
  starts_at: string;
  ends_at: string;
  meet_at: string | null;
  rsvp_deadline: string | null;
  deadline_passed: boolean;
  location_name: string | null;
  address: string | null;
  directions_url: string | null;
  teams: { id: string; name: string; color: string | null }[];
  opponent: { id: string; name: string } | null;
  is_home: boolean | null;
  own_logo_url: string | null;
  opponent_logo_url: string | null;
  counts: { yes: number; no: number; maybe: number; open: number };
  max_participants: number | null;
  is_full: boolean;
  min_participants: number | null;
  below_minimum: boolean;
  can_manage: boolean;
  reason_required: { no: boolean; maybe: boolean };
  my: MyReply[];
}

export interface ResponseRow {
  person: { id: string; name: string; avatar_url: string | null; primary_position: Position | null; role: TeamRole };
  status: RsvpStatus | null;
  reason: string | null;
  responded_at: string | null;
}

export interface EventDetail extends EventSummary {
  description: string | null;
  names_visible: boolean;
  series_id: string | null;
  series: { weekdays: number[]; interval_weeks: number; ends_on: string | null } | null;
  team_ids: string[];
  responses: ResponseRow[] | null;
}

export interface EventPage {
  data: EventSummary[];
  next_cursor: string | null;
}

export interface Notice {
  id: string;
  scope: 'team' | 'tenant';
  team: { id: string; name: string } | null;
  title: string;
  body: string | null;
  visible_from: string;
  visible_until: string;
  can_delete: boolean;
}

export interface Opponent {
  id: string;
  name: string;
  logo_url: string | null;
}
