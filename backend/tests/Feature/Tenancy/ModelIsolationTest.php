<?php

namespace Tests\Feature\Tenancy;

use App\Enums\InvitationKind;
use App\Enums\TeamRole;
use App\Models\ActivityLog;
use App\Models\CalendarFeed;
use App\Models\CashBook;
use App\Models\CashEntry;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\ChatReadState;
use App\Models\ChatTopic;
use App\Models\CrossNomination;
use App\Models\Document;
use App\Models\Event;
use App\Models\EventInvitee;
use App\Models\EventResponse;
use App\Models\EventResponseLog;
use App\Models\EventSeries;
use App\Models\EventTeam;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\HallSlot;
use App\Models\Invitation;
use App\Models\InvitationUse;
use App\Models\League;
use App\Models\MinorPermission;
use App\Models\Notice;
use App\Models\NotificationPreference;
use App\Models\Opponent;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollTeam;
use App\Models\PollVote;
use App\Models\RefereeLicense;
use App\Models\RsvpRule;
use App\Models\StoredFile;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\TenantMember;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMember;
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
        NotificationPreference::class, League::class, CrossNomination::class, TrainingGroup::class, TrainingGroupMember::class,
        RefereeLicense::class,
        RsvpRule::class, Document::class, TaskTemplate::class, Task::class, TaskAssignment::class, Poll::class, PollTeam::class, PollOption::class, PollVote::class,
        Chat::class, ChatAttachment::class, ChatMember::class, ChatTopic::class, ChatMessage::class, ChatReadState::class,
        ActivityLog::class, CashBook::class, CashEntry::class, Hall::class, HallSlot::class, HallBooking::class,
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
            $league = League::create(['name' => 'Landesliga', 'level' => 7]);
            $lower = Team::create(['name' => 'B-Team 2', 'gender' => 'mixed', 'league_id' => $league->id]);
            TeamMembership::create(['team_id' => $lower->id, 'person_id' => $person->id, 'role' => TeamRole::Player]);
            CrossNomination::create(['host_team_id' => $team->id, 'person_id' => $person->id, 'home_team_id' => $lower->id]);
            $group = TrainingGroup::create(['team_id' => $team->id, 'name' => 'Zuspiel']);
            TrainingGroupMember::create(['training_group_id' => $group->id, 'person_id' => $person->id]);
            RefereeLicense::create(['person_id' => $person->id, 'level' => 'D', 'valid_until' => '2027-06-30']);
            $task = Task::create(['event_id' => $event->id, 'title' => 'Fahrer', 'slots' => 2]);
            TaskAssignment::create(['task_id' => $task->id, 'person_id' => $person->id]);
            $poll = Poll::create(['title' => 'Grillfest?']);
            PollTeam::create(['poll_id' => $poll->id, 'team_id' => $team->id]);
            $option = PollOption::create(['poll_id' => $poll->id, 'label' => 'Ja']);
            PollVote::create(['poll_id' => $poll->id, 'option_id' => $option->id, 'person_id' => $person->id]);
            $chat = Chat::create(['kind' => 'custom', 'name' => 'Vorstand']);
            ChatMember::create(['chat_id' => $chat->id, 'tenant_member_id' => $member->id]);
            $topic = ChatTopic::create(['chat_id' => $chat->id, 'name' => 'Allgemein']);
            $message = ChatMessage::create(['chat_id' => $chat->id, 'topic_id' => $topic->id, 'author_id' => $member->id, 'body' => 'Hallo']);
            $stored = StoredFile::create(['storage_key' => 'c', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'purpose' => 'chat_attachment']);
            ChatAttachment::create(['chat_id' => $chat->id, 'message_id' => $message->id, 'file_id' => $stored->id, 'kind' => 'file', 'name' => 'plan.pdf']);
            ChatReadState::create(['chat_id' => $chat->id, 'tenant_member_id' => $member->id, 'last_read_message_id' => $message->id]);
            $book = CashBook::create(['kind' => 'team', 'team_id' => $team->id, 'book_key' => $team->id]);
            CashEntry::create(['cash_book_id' => $book->id, 'booked_on' => '2026-10-01', 'amount_cents' => 500, 'description' => 'Strafe', 'person_id' => $person->id]);
            $hall = Hall::create(['name' => 'Halle B']);
            HallSlot::create(['hall_id' => $hall->id, 'weekday' => 2, 'start_time' => '18:00:00', 'end_time' => '22:00:00']);
            HallBooking::create(['hall_id' => $hall->id, 'team_id' => $team->id, 'weekday' => 2, 'start_time' => '19:30:00', 'end_time' => '21:30:00']);
            NotificationPreference::create(['tenant_member_id' => $member->id, 'category' => 'notice', 'push' => false, 'email' => false]);
            $docFile = StoredFile::create(['storage_key' => 'd', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'purpose' => 'document']);
            Document::create(['team_id' => $team->id, 'title' => 'Aufbau', 'file_id' => $docFile->id, 'name' => 'aufbau.pdf', 'uploaded_by' => $member->id]);
            TaskTemplate::create(['team_id' => $team->id, 'name' => 'Spieltag', 'items' => [['title' => 'Kasse', 'description' => null, 'slots' => 2]], 'created_by' => $member->id]);
            RsvpRule::create(['person_id' => $person->id, 'team_id' => $team->id, 'event_type' => 'training', 'weekdays' => [3], 'status' => 'yes', 'created_by' => $member->id]);
        });

        $this->inTenant($a, function () use ($a) {
            foreach (self::MODELS as $model) {
                if ($model === ActivityLog::class) {
                    // A's own member and person were logged in A; nothing of B's many changes.
                    $this->assertSame(ActivityLog::withoutGlobalScopes()->where('tenant_id', $a->id)->count(), ActivityLog::query()->count());
                    $this->assertSame(0, ActivityLog::query()->where('label', 'like', '%B-Team%')->count());

                    continue;
                }
                $own = in_array($model, [TenantMember::class, Person::class, PersonAccount::class], true) ? 1 : 0;
                $this->assertSame($own, $model::query()->count(), $model);
            }
        });

        // Nor can B's rows be loaded by id while A is active.
        $foreignPerson = $this->inTenant($b, fn () => Person::first());
        $this->assertNull($this->inTenant($a, fn () => Person::find($foreignPerson->id)));
    }
}
