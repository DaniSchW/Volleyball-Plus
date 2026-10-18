<?php

use Illuminate\Support\Facades\Schedule;

/*
| The hosting cron calls `php artisan schedule:run` every minute (or, if no
| cron is available, an external cron service calls /cron/{CRON_TOKEN}).
| Queued mails and pushes are processed in the same run, so no
| long-running worker process is needed on shared hosting.
*/

Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('security:check-ssl')->dailyAt('04:10');
