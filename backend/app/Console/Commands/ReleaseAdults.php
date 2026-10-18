<?php

namespace App\Console\Commands;

use App\Enums\PersonRelation;
use App\Models\MinorPermission;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
}
