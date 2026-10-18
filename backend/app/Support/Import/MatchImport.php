<?php

namespace App\Support\Import;

use App\Enums\EventScope;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\EventTeam;
use App\Models\Opponent;
use App\Models\Team;
use App\Models\TenantMember;
use App\Support\Events\EventService;
use App\Support\Events\InviteeSync;
use App\Support\Notifications\EventNotifications;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a parsed schedule into the team's match days: which entries are
 * the team's (its name in the file), home or away, new or changed. Matches
 * the team only referees become "Schiedsgericht" dates.
 *
 * Known matches are found by their key from the file, else by the same
 * start and opponent (e.g. first imported from .ics, now from CSV).
 */
class MatchImport
{
    /** Length of a match day if the file has no end time. */
    public const DEFAULT_MINUTES = 120;

    public function __construct(
        private TenantContext $tenants,
        private InviteeSync $invitees,
        private EventService $events,
        private EventNotifications $notifications,
    ) {}

    /**
     * @param  list<ParsedMatch>  $matches
     * @return array{names: list<array{name: string, count: int}>, own_name: ?string, rows: list<array<string, mixed>>, missing: list<array<string, mixed>>, skipped: int}
     */
    public function preview(Team $team, array $matches, ?string $ownName = null): array
    {
        $names = $this->teamNames($matches);
        $own = $this->ownName($team, $names, $ownName);
        $rows = $this->rows($team, $matches, $own);
        $linked = collect($rows)->pluck('existing_id')->filter()->flip();

        $missing = $this->teamEvents($team)
            ->filter(fn (Event $e) => $e->import_key !== null && ! $linked->has($e->id) && $e->starts_at->isFuture())
            ->map(fn (Event $e) => ['id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at->toIso8601ZuluString()])
            ->values()->all();

        return [
            'names' => $names,
            'own_name' => $own,
            'rows' => array_map(fn (array $row) => array_diff_key($row, ['attributes' => true, 'event' => true]), $rows),
            'missing' => $missing,
            'skipped' => count($matches) - count($rows),
        ];
    }

    /**
     * @param  list<ParsedMatch>  $matches
     * @param  list<string>  $keys  the rows chosen in the preview
     * @return array{created: int, updated: int}
     */
    public function apply(Team $team, array $matches, string $ownName, array $keys, TenantMember $actor): array
    {
        $chosen = array_flip($keys);
        $rows = collect($this->rows($team, $matches, $ownName))->filter(fn ($row) => isset($chosen[$row['key']]));
        $created = collect();
        $updated = 0;

        DB::transaction(function () use ($team, $rows, $actor, $ownName, &$created, &$updated) {
            foreach ($rows as $row) {
                $attributes = $row['attributes'];
                $attributes['opponent_id'] = $this->opponentId($row['opponent']);

                if ($row['event'] === null) {
                    $event = Event::create($attributes + ['scope' => EventScope::Team, 'created_by' => $actor->id, 'import_key' => $row['key']]);
                    EventTeam::create(['event_id' => $event->id, 'team_id' => $team->id]);
                    $this->invitees->syncEvent($event);
                    $created->push($event);

                    continue;
                }

                /** @var Event $event */
                $event = $row['event'];
                $event->forceFill(['import_key' => $row['key']])->save();
                if ($row['status'] === 'update') {
                    $tz = $this->tenants->get()->timezone;
                    $changes = array_intersect_key($attributes, array_flip($row['changes']));
                    foreach (['starts_at', 'ends_at'] as $field) {
                        if (isset($changes[$field])) {
                            $changes[$field] = $changes[$field]->setTimezone($tz)->format('Y-m-d H:i:s');
                        }
                    }
                    // A title that was only "Spiel gegen <old opponent>" follows the new opponent.
                    if (isset($changes['opponent_id']) && $event->title === __('events.default_title.match_vs', ['opponent' => $event->opponent?->name])) {
                        $changes['title'] = null;
                    }
                    $this->events->update($event, $changes, $actor);
                    $updated++;
                }
            }

            $team->forceFill(['import_name' => $ownName])->save();
        });

        // One message for the whole schedule instead of one per match.
        if ($created->isNotEmpty()) {
            $this->notifications->imported($team, $created, $actor);
        }

        return ['created' => $created->count(), 'updated' => $updated];
    }

    /**
     * @param  list<ParsedMatch>  $matches
     * @return list<array<string, mixed>>
     */
    private function rows(Team $team, array $matches, ?string $own): array
    {
        $existing = $this->teamEvents($team);
        $byKey = $existing->filter(fn (Event $e) => $e->import_key !== null)->keyBy('import_key');
        $tz = $this->tenants->get()->timezone;
        $rows = [];

        foreach ($matches as $match) {
            $role = match (true) {
                $match->home === null => 'unknown',
                $own !== null && $match->home === $own => 'home',
                $own !== null && $match->away === $own => 'away',
                $own !== null && $match->referee === $own => 'referee',
                default => null,
            };
            if ($role === null) {
                continue;
            }

            $opponent = match ($role) {
                'home' => $match->away,
                'away' => $match->home,
                default => null,
            };
            $attributes = $this->attributes($match, $role, $opponent);
            $event = $byKey->get($match->key) ?? $existing->first(fn (Event $e) => $e->import_key !== $match->key
                && $e->starts_at->equalTo($match->startsAt)
                && ($opponent === null || mb_strtolower((string) $e->opponent?->name) === mb_strtolower($opponent)));

            $changes = $event ? $this->changes($event, $attributes, $opponent) : [];
            $status = match (true) {
                $event === null => 'new',
                $event->status === EventStatus::Cancelled => 'cancelled',
                $changes !== [] => 'update',
                default => 'same',
            };
            $past = $match->startsAt->isPast();

            $rows[] = [
                'key' => $match->key,
                'role' => $role,
                'title' => $attributes['title'] ?? __('events.default_title.match_vs', ['opponent' => $opponent ?? '?']),
                'opponent' => $opponent,
                'starts_at' => $match->startsAt->toIso8601ZuluString(),
                'ends_at' => $attributes['ends_at']->toIso8601ZuluString(),
                'location_name' => $attributes['location_name'],
                'address' => $attributes['address'],
                'details' => $attributes['description'],
                'past' => $past,
                'status' => $status,
                'changes' => $changes,
                'existing_id' => $event?->id,
                'selected' => ! $past && in_array($status, ['new', 'update'], true),
                'attributes' => $attributes,
                'event' => $event,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ParsedMatch $match, string $role, ?string $opponent): array
    {
        $details = array_filter([
            implode(' · ', array_filter([$match->matchday, $match->round])) ?: null,
            $role !== 'referee' && $match->referee ? __('import.referee_team', ['team' => $match->referee]) : null,
            $match->result ? __('import.result', ['result' => $match->result]) : null,
        ]);
        $end = $match->endsAt !== null && $match->endsAt->greaterThan($match->startsAt)
            ? $match->endsAt
            : $match->startsAt->addMinutes(self::DEFAULT_MINUTES);

        return [
            'type' => $role === 'referee' ? EventType::Extra : EventType::Match,
            'response_mode' => 'rsvp',
            'title' => match ($role) {
                'referee' => __('import.referee_title', ['home' => $match->home, 'away' => $match->away]),
                'unknown' => $match->summary ?? __('events.default_title.match'),
                default => __('events.default_title.match_vs', ['opponent' => $opponent]),
            },
            'description' => $details !== [] ? implode("\n", $details) : null,
            'starts_at' => $match->startsAt,
            'ends_at' => $end,
            'location_name' => $match->locationName,
            'address' => $match->address,
            'is_home' => match ($role) {
                'home' => true,
                'away' => false,
                default => null,
            },
        ];
    }

    /**
     * What a new import would change on a known match. Text the team edited
     * (title, description) stays; the place only counts when the hall changed.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function changes(Event $event, array $attributes, ?string $opponent): array
    {
        $changes = [];
        if (! $event->starts_at->equalTo($attributes['starts_at'])) {
            $changes[] = 'starts_at';
            $changes[] = 'ends_at';
        }
        if ($attributes['location_name'] !== null && mb_strtolower((string) $event->location_name) !== mb_strtolower($attributes['location_name'])) {
            $changes[] = 'location_name';
            $changes[] = 'address';
        }
        if ($attributes['is_home'] !== null && $event->is_home !== $attributes['is_home']) {
            $changes[] = 'is_home';
        }
        if ($opponent !== null && mb_strtolower((string) $event->opponent?->name) !== mb_strtolower($opponent)) {
            $changes[] = 'opponent_id';
        }

        return $changes;
    }

    /**
     * Team names in the file (playing or refereeing), most frequent first –
     * the own team is in every entry of a team export.
     *
     * @param  list<ParsedMatch>  $matches
     * @return list<array{name: string, count: int}>
     */
    private function teamNames(array $matches): array
    {
        return collect($matches)->flatMap(fn (ParsedMatch $m) => array_filter([$m->home, $m->away, $m->referee]))
            ->countBy()->sortDesc()
            ->map(fn ($count, $name) => ['name' => (string) $name, 'count' => $count])
            ->values()->all();
    }

    /**
     * @param  list<array{name: string, count: int}>  $names
     */
    private function ownName(Team $team, array $names, ?string $chosen): ?string
    {
        $available = array_column($names, 'name');
        foreach ([$chosen, $team->import_name] as $candidate) {
            if ($candidate !== null && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return $available[0] ?? null;
    }

    /**
     * @return Collection<int, Event>
     */
    private function teamEvents(Team $team): Collection
    {
        return Event::query()->with('opponent')
            ->whereIn('id', EventTeam::query()->where('team_id', $team->id)->select('event_id'))
            ->whereIn('type', [EventType::Match, EventType::Extra])
            ->where('starts_at', '>=', Carbon::now()->subYear())
            ->get();
    }

    private function opponentId(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return (Opponent::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Opponent::create(['name' => $name]))->id;
    }
}
