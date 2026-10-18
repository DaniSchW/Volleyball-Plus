<?php

use App\Console\Commands\GenerateSeries;
use App\Console\Commands\ReleaseAdults;
use App\Support\Security\SslCheck;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Triggered every minute by the hosting cron – on ALL-INKL and similar
| hosters as a web call of /cron/{CRON_TOKEN}, otherwise via
| `php artisan schedule:run`. Queued mails and pushes are processed in the
| same run, so no long-running worker process is needed.
|
| Rules for this file:
| - Tasks run in-process (Schedule::call + Artisan::call), never as
|   Schedule::command(): that spawns a child process, which web-triggered
|   cron calls on shared hosting cannot do (exec/proc_open disabled, and
|   PHP_BINARY is the FPM binary there).
| - Recurring work must not depend on hitting an exact minute – the cron
|   may run less often (use everyMinute() with a "due" check).
| - Overlap locks must expire quickly, so a run killed by the hoster's
|   time limit does not block the next runs.
*/

$task = fn (string $command) => Schedule::call(fn () => Artisan::call($command))
    ->name($command)
    ->everyMinute()
    ->withoutOverlapping(5);

// Applies database changes after an update was uploaded via FTP.
$task('app:migrate-pending');

$task('queue:work --stop-when-empty --max-time=25');

$task('security:check-ssl')->when(fn () => SslCheck::isDue());

// Children are released from their parents' accounts on their 18th birthday.
$task('app:release-adults')->when(fn () => ReleaseAdults::isDue());

// Keeps about six months of training dates ahead for every series.
$task('app:generate-series')->when(fn () => GenerateSeries::isDue());
