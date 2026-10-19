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

/**
 * File upload with progress (fetch cannot report upload progress). Same
 * headers and error handling as api().
 */
export function upload<T>(path: string, file: File, onProgress: (share: number) => void): Promise<T> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `/api/v1${path}`);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('Accept-Language', document.documentElement.lang || 'de');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.onprogress = (e) => e.lengthComputable && onProgress(e.loaded / e.total);
    xhr.onload = () => {
      const data = (() => { try { return JSON.parse(xhr.responseText); } catch { return null; } })();
      if (xhr.status >= 200 && xhr.status < 300) resolve(data as T);
      else reject(new ApiError(xhr.status, data?.message ?? xhr.statusText, data?.errors ?? {}));
    };
    xhr.onerror = () => reject(new ApiError(0, 'network'));
    const body = new FormData();
    body.append('file', file);
    xhr.send(body);
  });
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
  league_name: string | null;
  color: string | null;
  can_manage: boolean;
  can_invite: boolean;
  can_see: boolean;
  rank_in_league?: number;
  league?: { id: string; name: string; level: number } | null;
  in_hierarchy?: boolean;
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
  referee_license: RefereeLicense | null;
}

export interface RefereeLicense {
  level: string;
  license_number: string | null;
  valid_until: string;
  expired: boolean;
  expires_soon: boolean;
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
  /** DSGVO export and deletion. */
  can_manage_personal_data: boolean;
  can_manage_minor_permissions: boolean;
  can_edit_referee_license: boolean;
  can_invite_guardian: boolean;
  can_invite_own: boolean;
  minor_permissions?: MinorPermissions;
  teams: { id: string; name: string; role: TeamRole }[];
}

export interface RosterRow {
  person: Person;
  roles: { id: string; role: TeamRole }[];
  /** Trains with this team but plays matches for this higher team. */
  plays_higher_in: string | null;
}

export interface PersonSearchResult extends Person {
  teams: { id: string; name: string; role: TeamRole }[];
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

export type EventType = 'training' | 'match' | 'extra' | 'helper';
export type RsvpStatus = 'yes' | 'no' | 'maybe';

export interface MyReply {
  person_id: string;
  name: string;
  is_self: boolean;
  status: RsvpStatus | null;
  reason: string | null;
  /** Position the acceptance counts for (Positions-Soll). */
  as_position: Position | null;
  /** Main and secondary positions from the profile. */
  positions: Position[];
  is_guest: boolean;
  can_accept: boolean;
  guests_open_at: string | null;
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
  /** Positions-Soll: at most n accepted per position. */
  position_limits: Partial<Record<Position, number>> | null;
  is_full: boolean;
  min_participants: number | null;
  below_minimum: boolean;
  can_manage: boolean;
  reason_required: { no: boolean; maybe: boolean };
  training_group: { id: string; name: string } | null;
  task_summary: { count: number; slots: number; taken: number } | null;
  my: MyReply[];
}

export interface ResponseRow {
  person: { id: string; name: string; avatar_url: string | null; primary_position: Position | null; role: TeamRole; is_guest: boolean };
  status: RsvpStatus | null;
  as_position: Position | null;
  reason: string | null;
  responded_at: string | null;
}

export interface EventDetail extends EventSummary {
  description: string | null;
  names_visible: boolean;
  series_id: string | null;
  series: { weekdays: number[]; interval_weeks: number; ends_on: string | null } | null;
  team_ids: string[];
  cross_nomination_opens_at: string | null;
  guests_open_at: string | null;
  responses: ResponseRow[] | null;
  tasks: EventTask[];
  /** Accepted players per position – only for people managing the event. */
  positions: Partial<Record<Position | 'unknown', number>> | null;
  /** Accepted per limited position (numbers only). */
  position_taken: Partial<Record<Position, number>> | null;
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

export type NotificationCategory = 'event_new' | 'event_changed' | 'reminder' | 'notice';

export interface NotificationPreference {
  category: NotificationCategory;
  push: boolean;
  email: boolean;
}

// ---- training structure ----

export interface League {
  id: string;
  parent_id: string | null;
  name: string;
  level: number;
  depth: number;
  team_count: number;
}

export interface CrossNomination {
  id: string;
  active: boolean;
  person: Person;
  home_team: { id: string; name: string };
}

export interface CrossCandidate {
  person: Person;
  home_team: { id: string; name: string };
}

export interface TrainingGroup {
  id: string;
  team_id: string;
  name: string;
  person_ids: string[];
}

// ---- statistics ----

export interface Period {
  from: string;
  to: string;
}

export interface StatisticsRow {
  person: { id: string; name: string; primary_position: Position | null };
  is_guest: boolean;
  is_current: boolean;
  invited: number;
  yes: number;
  maybe: number;
  no: number;
  open: number;
  late: number;
  rate: number;
}

export interface TeamStatistics {
  period: Period;
  presets: { last_4_weeks: Period; season: Period };
  types: EventType[];
  can_see_all: boolean;
  event_count: number;
  rows: StatisticsRow[];
}

export interface HistoryRow {
  event: { id: string; title: string; type: EventType; starts_at: string };
  status: RsvpStatus | null;
  responded_at: string | null;
  after_deadline: boolean;
}

// ---- schedule import ----

export interface ImportRow {
  key: string;
  role: 'home' | 'away' | 'referee' | 'unknown';
  title: string;
  opponent: string | null;
  starts_at: string;
  ends_at: string;
  location_name: string | null;
  address: string | null;
  details: string | null;
  past: boolean;
  status: 'new' | 'update' | 'same' | 'cancelled';
  changes: string[];
  existing_id: string | null;
  selected: boolean;
}

export interface ImportPreview {
  names: { name: string; count: number }[];
  own_name: string | null;
  rows: ImportRow[];
  missing: { id: string; title: string; starts_at: string }[];
  skipped: number;
}

// ---- tasks & polls ----

export interface EventTask {
  id: string;
  title: string;
  description: string | null;
  slots: number;
  assignees: { person_id: string; name: string; mine: boolean }[];
  can_take_for: { person_id: string; name: string }[];
}

export interface MyTasksRow {
  event: { id: string; type: EventType; title: string; starts_at: string; teams: string[] };
  mine: { id: string; title: string; slots: number; taken: number; mine: string[] }[];
  open_slots: number;
}

export interface TaskStatsRow {
  person: { id: string; name: string };
  helper: number;
  other: number;
  total: number;
}

export interface Poll {
  id: string;
  title: string;
  description: string | null;
  scope: 'team' | 'tenant';
  teams: { id: string; name: string }[];
  multiple_choice: boolean;
  anonymous: boolean;
  allow_free_text: boolean;
  closes_at: string | null;
  is_closed: boolean;
  created_at: string;
  can_manage: boolean;
  results_visible: boolean;
  voter_count: number | null;
  eligible_count: number;
  options: { id: string; label: string; count: number | null; voters: string[] | null }[];
  free_texts: { text: string; name: string | null }[];
  my: { person_id: string; name: string; is_self: boolean; option_ids: string[]; free_text: string | null; can_vote: boolean }[];
}

// ---- chat ----

export interface ChatSummary {
  id: string;
  kind: 'team' | 'custom';
  name: string;
  team_id: string | null;
  mode: 'flat' | 'topics';
  can_moderate: boolean;
  unread: number;
  last_message: { author: string | null; body: string | null; created_at: string } | null;
}

export interface ChatDetail extends Omit<ChatSummary, 'unread' | 'last_message'> {
  topics: { id: string; name: string; unread: number }[];
  members: { id: string; name: string }[];
  /** Chosen members of a custom chat; null for team chats. */
  member_ids: string[] | null;
  upload: { max_bytes: number; per_message: number; extensions: string[] };
}

export interface ChatAttachment {
  id: string;
  kind: 'image' | 'video' | 'file';
  name: string;
  mime_type: string | null;
  size: number | null;
  width: number | null;
  height: number | null;
  url: string;
}

export interface ChatMessage {
  id: string;
  topic_id: string | null;
  author: { id: string | null; name: string };
  is_mine: boolean;
  body: string | null;
  deleted: boolean;
  edited: boolean;
  reply_to: { id: string; author: string; body: string | null } | null;
  attachments: ChatAttachment[];
  can_delete: boolean;
  created_at: string;
}

export interface ChatMessagesPage {
  data: ChatMessage[];
  changed: ChatMessage[];
  has_more: boolean;
  server_time: string;
}

export interface CashSummary {
  opening: number;
  carried: number;
  income: number;
  expenses: number;
  closing: number;
  balance: number;
}

export interface CashBookInfo {
  id: string;
  kind: 'team' | 'department';
  team_id: string | null;
  name: string;
  archived: boolean;
  opening_balance_cents: number;
  summary: CashSummary;
}

export interface CashOverview {
  books: CashBookInfo[];
  teams_total: number | null;
  total: number | null;
}

export interface CashEntry {
  id: string;
  booked_on: string;
  amount_cents: number;
  category: string | null;
  description: string;
  person: { id: string; name: string } | null;
  created_by: string | null;
  created_at: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
  balance_after: number | null;
}

export interface CashBookDetail extends CashBookInfo {
  entries: CashEntry[];
  categories: string[];
  persons: { id: string; name: string }[];
}

export interface HallSlot {
  id: string;
  weekday: number;
  start_time: string;
  end_time: string;
  valid_from: string | null;
  valid_until: string | null;
}

export interface HallBooking extends HallSlot {
  team: { id: string; name: string; color: string | null; archived: boolean };
  /** Parts of the hall, null = whole hall. */
  courts: number[] | null;
  notes: string | null;
  conflicts: { id: string; team: string; start_time: string; end_time: string; courts: number[] | null }[];
  outside_slots: boolean;
}

export interface Hall {
  id: string;
  name: string;
  address: string | null;
  courts: number;
  notes: string | null;
  slots: HallSlot[];
  bookings: HallBooking[];
}

export interface TeamHallTime {
  id: string;
  hall: { id: string; name: string; address: string | null };
  weekday: number;
  start_time: string;
  end_time: string;
  courts: number[] | null;
  valid_from: string | null;
  valid_until: string | null;
}
