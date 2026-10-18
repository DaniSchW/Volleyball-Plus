<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Support\Security\SslCheck;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SslCheckScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_is_due_when_it_never_ran(): void
    {
        $this->assertTrue(SslCheck::isDue());
    }

    public function test_check_is_due_again_after_24_hours(): void
    {
        $now = CarbonImmutable::parse('2026-10-18 12:00:00');
        Setting::write('security.ssl_check', ['checked_at' => $now->subHours(23)->toIso8601String()]);

        $this->assertFalse(SslCheck::isDue($now));
        $this->assertTrue(SslCheck::isDue($now->addHour()));
    }

    public function test_cron_tasks_run_in_process_and_do_not_depend_on_an_exact_minute(): void
    {
        // The hoster's cron may run less often than every minute.
        foreach (app(Schedule::class)->events() as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event, 'tasks must run in-process: '.$event->getSummaryForDisplay());
            $this->assertSame('* * * * *', $event->expression, $event->getSummaryForDisplay());
            $this->assertLessThanOrEqual(5, $event->expiresAt, 'overlap lock must expire quickly: '.$event->getSummaryForDisplay());
        }
    }
}
