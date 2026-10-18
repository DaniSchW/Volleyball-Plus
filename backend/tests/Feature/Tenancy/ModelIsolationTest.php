<?php

namespace Tests\Feature\Tenancy;

use App\Enums\InvitationKind;
use App\Enums\TeamRole;
use App\Models\CalendarFeed;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\EventResponseLog;
use App\Models\EventSeries;
use App\Models\EventTeam;
use App\Models\Invitation;
use App\Models\InvitationUse;
use App\Models\MinorPermission;
use App\Models\Notice;
use App\Models\NotificationPreference;
use App\Models\Opponent;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\StoredFile;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every department model: rows of department B are invisible in A.
 */
class ModelIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** All models with BelongsToTenant – extend when adding one. */
    private const MODELS = [
        Team::class, TenantMember::class, Person::class, PersonAccount::class, MinorPermission::class,
        TeamMembership::class, Invitation::class, InvitationUse::class, StoredFile::class,
        Event::class, EventSeries::class, EventTeam::class, EventInvitee::class, EventResponse::class,
        EventResponseLog::class, Opponent::class, Notice::class, CalendarFeed::class,
        NotificationPreference::class,
    ];

    public function test_every_tenant_model_is_listed_here(): void
    {
        $found = collect(glob(app_path('Models/*.php')))
            ->map(fn (string $file) => 'App\\Models\\'.basename($file, '.php'))
            ->filter(fn (string $class) => in_array(BelongsToTenant::class, class_uses_recursive($class), true))
            ->values()->all();

        $this->assertEqualsCanonicalizing(self::MODELS, $found);
    }

    public function test_rows_of_another_department_are_invisible(): void
    {
        $a = $this->tenant('abteilung-a', 'A');
        $b = $this->tenant('abteilung-b', 'B');
        $this->member($a, 'a@example.org');

        $this->inTenant($b, function () use ($b) {
            $member = $this->member($b, 'b@example.org');
            $person = $member->ownPerson();
            $team = Team::create(['name' => 'B-Team', 'gender' => 'mixed']);
            MinorPermission::create(['person_id' => $person->id]);
            TeamMembership::create(['team_id' => $team->id, 'person_id' => $person->id, 'role' => TeamRole::Player]);
            $invitation = Invitation::create([
                'kind' => InvitationKind::Team, 'team_id' => $team->id, 'role' => TeamRole::Player,
                'token_hash' => hash('sha256', 'x'), 'token_encrypted' => 'x',
            ]);
            InvitationUse::create(['invitation_id' => $invitation->id, 'tenant_member_id' => $member->id, 'person_id' => $person->id]);
            StoredFile::create(['storage_key' => 'k', 'mime_type' => 'image/webp', 'size_bytes' => 1, 'purpose' => 'avatar']);
            $series = EventSeries::create([
                'team_id' => $team->id, 'title' => 'Training', 'weekdays' => [2], 'starts_on' => '2026-11-03',
                'start_time' => '19:30:00', 'end_time' => '21:30:00',
            ]);
            $event = Event::create([
                'type' => 'training', 'title' => 'Training', 'starts_at' => '2026-11-03 18:30:00', 'ends_at' => '2026-11-03 20:30:00',
                'series_id' => $series->id,
            ]);
            EventTeam::create(['event_id' => $event->id, 'team_id' => $team->id]);
            EventInvitee::query()->firstOrCreate(['event_id' => $event->id, 'person_id' => $person->id]);
            EventResponse::create(['event_id' => $event->id, 'person_id' => $person->id, 'status' => 'yes', 'responded_at' => now()]);
            EventResponseLog::create(['event_id' => $event->id, 'person_id' => $person->id, 'new_status' => 'yes']);
            Opponent::create(['name' => 'TSV Gegner']);
            Notice::create(['scope' => 'tenant', 'title' => 'Info', 'visible_from' => now(), 'visible_until' => now()->addDay()]);
            CalendarFeed::create(['tenant_member_id' => $member->id, 'token_hash' => hash('sha256', 'c'), 'token_encrypted' => 'c']);
            NotificationPreference::create(['tenant_member_id' => $member->id, 'category' => 'notice', 'push' => false, 'email' => false]);
        });

        $this->inTenant($a, function () {
            foreach (self::MODELS as $model) {
                $own = in_array($model, [TenantMember::class, Person::class, PersonAccount::class], true) ? 1 : 0;
                $this->assertSame($own, $model::query()->count(), $model);
            }
        });

        // Nor can B's rows be loaded by id while A is active.
        $foreignPerson = $this->inTenant($b, fn () => Person::first());
        $this->assertNull($this->inTenant($a, fn () => Person::find($foreignPerson->id)));
    }
}
