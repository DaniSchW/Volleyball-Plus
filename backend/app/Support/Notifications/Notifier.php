<?php

namespace App\Support\Notifications;

use App\Enums\MemberStatus;
use App\Enums\NotificationCategory;
use App\Enums\PersonRelation;
use App\Jobs\SendPush;
use App\Mail\NotificationMail;
use App\Models\NotificationPreference;
use App\Models\PersonAccount;
use App\Models\PushSubscription;
use App\Models\TenantMember;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a notification to the logins behind persons: a person's own login
 * (if it may use the app) and the parents of a child. Each login gets it
 * once, in its language, on the channels it chose for the category.
 * Delivery is queued and happens in the next cron run.
 */
class Notifier
{
    public function __construct(private TenantContext $tenants) {}

    /**
     * @param  iterable<string>  $personIds
     * @param  Closure(string $locale): array{title: string, body: string}  $message
     */
    public function toPersons(NotificationCategory $category, iterable $personIds, Closure $message, string $path, ?TenantMember $except = null): int
    {
        return $this->toMembers($category, $this->membersFor(collect($personIds)), $message, $path, $except);
    }

    /**
     * @param  Collection<int, TenantMember>  $members
     * @param  Closure(string $locale): array{title: string, body: string}  $message
     */
    public function toMembers(NotificationCategory $category, Collection $members, Closure $message, string $path, ?TenantMember $except = null): int
    {
        $members = $members->filter(fn (TenantMember $m) => $m->status === MemberStatus::Active && $m->id !== $except?->id)->unique('id');
        if ($members->isEmpty()) {
            return 0;
        }

        $tenant = $this->tenants->get();
        $url = rtrim((string) config('app.url'), '/').'/'.$tenant->slug.$path;
        $preferences = NotificationPreference::query()
            ->whereIn('tenant_member_id', $members->pluck('id'))
            ->where('category', $category)
            ->get()->keyBy('tenant_member_id');
        $members->loadMissing('account');

        foreach ($members as $member) {
            $preference = $preferences->get($member->id);
            $push = $preference?->push ?? $category->defaultPush();
            $email = $preference?->email ?? $category->defaultEmail();
            if (! $push && ! $email) {
                continue;
            }

            $locale = $member->account->locale ?: $tenant->default_locale;
            ['title' => $title, 'body' => $body] = $message($locale);

            if ($push) {
                SendPush::dispatch($member->account_id, [
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                    'tag' => $category->value.':'.$path,
                ]);
            }
            if ($email) {
                Mail::to($member->account->email)->locale($locale)->queue(new NotificationMail($tenant, $title, $body, $url));
            }
        }

        return $members->count();
    }

    /**
     * Sent on purpose by an organiser (e.g. a poll or a reminder for it): push
     * as the recipients set it up, e-mail as chosen – "without_push" reaches
     * exactly those who get no push (no device or push switched off).
     *
     * @param  iterable<string>  $personIds
     * @param  Closure(string $locale): array{title: string, body: string}  $message
     * @param  'none'|'without_push'|'all'  $email
     * @return array{push: int, email: int}
     */
    public function toPersonsDirect(NotificationCategory $category, iterable $personIds, Closure $message, string $path, bool $push, string $email, ?TenantMember $except = null): array
    {
        $members = $this->membersFor(collect($personIds))
            ->filter(fn (TenantMember $m) => $m->status === MemberStatus::Active && $m->id !== $except?->id)->unique('id');
        $sent = ['push' => 0, 'email' => 0];
        if ($members->isEmpty() || (! $push && $email === 'none')) {
            return $sent;
        }

        $tenant = $this->tenants->get();
        $url = rtrim((string) config('app.url'), '/').'/'.$tenant->slug.$path;
        $preferences = NotificationPreference::query()->whereIn('tenant_member_id', $members->pluck('id'))
            ->where('category', $category)->get()->keyBy('tenant_member_id');
        $devices = PushSubscription::query()->whereIn('account_id', $members->pluck('account_id'))->distinct()->pluck('account_id')->flip();
        $members->loadMissing('account');

        foreach ($members as $member) {
            $byPush = $push && $devices->has($member->account_id) && ($preferences->get($member->id)?->push ?? $category->defaultPush());
            $byEmail = $email === 'all' || ($email === 'without_push' && ! $byPush);
            if (! $byPush && ! $byEmail) {
                continue;
            }
            $locale = $member->account->locale ?: $tenant->default_locale;
            ['title' => $title, 'body' => $body] = $message($locale);

            if ($byPush) {
                SendPush::dispatch($member->account_id, ['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $category->value.':'.$path]);
                $sent['push']++;
            }
            if ($byEmail) {
                Mail::to($member->account->email)->locale($locale)->queue(new NotificationMail($tenant, $title, $body, $url));
                $sent['email']++;
            }
        }

        return $sent;
    }

    /** Date and time in the department's time zone, formatted for the recipient. */
    public function when(CarbonInterface $at, string $locale): string
    {
        return $at->copy()->setTimezone($this->tenants->get()->timezone)->locale($locale)
            ->isoFormat(__('notifications.date_format', [], $locale));
    }

    /**
     * Logins acting for the persons. A child's own login only counts when
     * the parents allowed it.
     *
     * @param  Collection<int, string>  $personIds
     * @return Collection<int, TenantMember>
     */
    private function membersFor(Collection $personIds): Collection
    {
        if ($personIds->isEmpty()) {
            return collect();
        }

        $links = PersonAccount::query()
            ->whereIn('person_id', $personIds->unique()->values())
            ->whereNull('ended_at')
            ->with(['member', 'person.minorPermission'])
            ->get();
        $childIds = $links->where('relation', PersonRelation::Guardian)->pluck('person_id')->flip();

        return $links
            ->filter(fn (PersonAccount $link) => $link->relation === PersonRelation::Guardian
                || ! $childIds->has($link->person_id)
                || (bool) $link->person?->minorPermission?->own_login)
            ->map(fn (PersonAccount $link) => $link->member)
            ->filter()
            ->unique('id')
            ->values();
    }
}
