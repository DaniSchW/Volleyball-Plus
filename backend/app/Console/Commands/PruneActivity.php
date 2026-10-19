<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Keeps the activity log to the last 7 days (all clubs at once).
 */
class PruneActivity extends Command
{
    protected $signature = 'app:prune-activity';

    protected $description = 'Delete activity log entries older than 7 days';

    public const LAST_RUN = 'app.prune_activity.last_run';

    public static function isDue(?CarbonImmutable $now = null): bool
    {
        $last = Setting::read(self::LAST_RUN);

        return $last === null || CarbonImmutable::parse($last)->addHour()->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    public function handle(): int
    {
        $removed = ActivityLog::withoutGlobalScopes()
            ->where('created_at', '<', CarbonImmutable::now()->subDays(ActivityLog::KEEP_DAYS))
            ->delete();

        Setting::write(self::LAST_RUN, CarbonImmutable::now()->toIso8601String());
        $this->info("Removed: {$removed}");

        return self::SUCCESS;
    }
}
