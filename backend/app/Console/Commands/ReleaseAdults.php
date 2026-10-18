<?php

namespace App\Console\Commands;

use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Mail\NotificationMail;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * On their 18th birthday children are released from their parents'
 * accounts: the guardian links end and the child gets full control over
 * its profile.
 */
class ReleaseAdults extends Command
{
    protected $signature = 'app:release-adults';

    protected $description = 'End parent links of persons who turned 18';

    public const LAST_RUN = 'app.release_adults.last_run';

    public static function isDue(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        return Setting::read(self::LAST_RUN) !== $today->toDateString();
    }

    public function handle(TenantContext $tenants): int
    {
        $today = CarbonImmutable::today();
        $released = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $released += $tenants->run($tenant, function () use ($today) {
                $adultIds = Person::query()
                    ->whereNotNull('birthday')
                    ->whereDate('birthday', '<=', $today->subYears(18)->toDateString())
                    ->whereHas('accountLinks', fn ($q) => $q->where('relation', PersonRelation::Guardian))
                    ->pluck('id');

                if ($adultIds->isEmpty()) {
                    return 0;
                }

                $this->announce($adultIds);

                PersonAccount::query()
                    ->whereIn('person_id', $adultIds)
                    ->where('relation', PersonRelation::Guardian)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => $today]);
                MinorPermission::query()->whereIn('person_id', $adultIds)->delete();

                return $adultIds->count();
            });
        }

        Setting::write(self::LAST_RUN, $today->toDateString());

        if ($released > 0) {
            Log::info("Released {$released} person(s) from parent accounts.");
        }
        $this->info("Released: {$released}");

        return self::SUCCESS;
    }

    /**
     * Tells parents and the person (if it has its own login) by mail.
     *
     * @param  Collection<int, string>  $personIds
     */
    private function announce(Collection $personIds): void
    {
        $links = PersonAccount::query()
            ->whereIn('person_id', $personIds)
            ->whereNull('ended_at')
            ->with(['person', 'member.account', 'member.tenant'])
            ->get();

        foreach ($links as $link) {
            $member = $link->member;
            if ($member === null || $member->status !== MemberStatus::Active) {
                continue;
            }

            $tenant = $member->tenant;
            $locale = $member->account->locale ?: $tenant->default_locale;
            $who = $link->relation === PersonRelation::Guardian ? 'guardian' : 'self';
            $params = ['name' => $link->person->first_name, 'tenant' => $tenant->name];

            Mail::to($member->account->email)->locale($locale)->queue(new NotificationMail(
                $tenant,
                __("notifications.adult.{$who}_title", $params, $locale),
                __("notifications.adult.{$who}_body", $params, $locale),
                rtrim((string) config('app.url'), '/').'/'.$tenant->slug.'/persons/'.$link->person_id,
                settingsHint: false,
            ));
        }
    }
}
