<?php

namespace App\Console\Commands;

use App\Enums\EventScope;
use App\Enums\EventStatus;
use App\Enums\NotificationCategory;
use App\Enums\ResponseMode;
use App\Models\Event;
use App\Models\Notice;
use App\Models\Person;
use App\Models\TeamMembership;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Notifications\EventNotifications;
use App\Support\Notifications\Notifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Time-driven notifications, run with every cron call:
 * - reminder 24 hours before the reply deadline (or the start) to everyone
 *   who has not answered, once per event;
 * - notices as soon as they become visible.
 * Works by "not yet sent" markers, so a late or skipped cron run only
 * delays messages and never sends them twice.
 */
class SendNotifications extends Command
{
    public const REMINDER_HOURS = 24;

    protected $signature = 'app:send-notifications';

    protected $description = 'Send due reminders and notice announcements';

    public function handle(TenantContext $tenants, EventNotifications $events, Notifier $notifier): int
    {
        $reminders = 0;
        $notices = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $tenants->run($tenant, function () use ($events, $notifier, &$reminders, &$notices) {
                $reminders += $this->reminders($events);
                $notices += $this->notices($notifier);
            });
        }

        $this->info("Reminders: {$reminders}, notices: {$notices}");

        return self::SUCCESS;
    }

    private function reminders(EventNotifications $events): int
    {
        $now = Carbon::now();
        $horizon = $now->copy()->addHours(self::REMINDER_HOURS);
        $sent = 0;

        $due = Event::query()
            ->whereNull('reminder_sent_at')
            ->where('status', EventStatus::Scheduled)
            ->where('response_mode', ResponseMode::Rsvp)
            ->where('starts_at', '>', $now)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('rsvp_deadline')->where('rsvp_deadline', '>', $now)->where('rsvp_deadline', '<=', $horizon))
                ->orWhere(fn ($q) => $q->whereNull('rsvp_deadline')->where('starts_at', '<=', $horizon)))
            ->get();

        foreach ($due as $event) {
            // Created only shortly before: the "new event" message was enough.
            $reference = $event->rsvp_deadline ?? $event->starts_at;
            if ($event->created_at->greaterThan($reference->copy()->subHours(self::REMINDER_HOURS))) {
                $event->forceFill(['reminder_sent_at' => $now])->save();

                continue;
            }

            $events->remind($event);
            $event->forceFill(['reminder_sent_at' => $now])->save();
            $sent++;
        }

        return $sent;
    }

    private function notices(Notifier $notifier): int
    {
        $now = Carbon::now();
        $due = Notice::query()
            ->whereNull('notified_at')
            ->where('visible_from', '<=', $now)
            ->where('visible_until', '>', $now)
            ->get();

        foreach ($due as $notice) {
            $notice->forceFill(['notified_at' => $now])->save();

            $persons = $notice->scope === EventScope::Tenant
                ? Person::query()->pluck('id')
                : TeamMembership::query()->where('team_id', $notice->team_id)->pluck('person_id');
            $author = $notice->created_by ? TenantMember::query()->find($notice->created_by) : null;

            $notifier->toPersons(NotificationCategory::Notice, $persons, fn (string $locale) => [
                'title' => __('notifications.notice.title', ['title' => $notice->title], $locale),
                'body' => Str::limit((string) $notice->body, 140),
            ], '?notice='.$notice->id, $author);
        }

        return $due->count();
    }
}
