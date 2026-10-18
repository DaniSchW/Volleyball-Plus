<?php

namespace App\Console\Commands;

use App\Models\EventSeries;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\Events\SeriesGenerator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Extends all running series so that there are always about six months of
 * training dates ahead.
 */
class GenerateSeries extends Command
{
    protected $signature = 'app:generate-series';

    protected $description = 'Create upcoming events of recurring series';

    public const LAST_RUN = 'app.generate_series.last_run';

    public static function isDue(?CarbonImmutable $today = null): bool
    {
        return Setting::read(self::LAST_RUN) !== ($today ?? CarbonImmutable::today())->toDateString();
    }

    public function handle(TenantContext $tenants, SeriesGenerator $generator): int
    {
        $created = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $created += $tenants->run($tenant, fn () => EventSeries::query()
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', CarbonImmutable::today()->toDateString()))
                ->get()
                ->sum(fn (EventSeries $series) => $generator->generate($series)));
        }

        Setting::write(self::LAST_RUN, CarbonImmutable::today()->toDateString());
        $this->info("Created: {$created}");

        return self::SUCCESS;
    }
}
