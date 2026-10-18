<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Support\Notifications\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push message to all devices of one login; processed by the cron run.
 */
class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $payload  title, body, url, tag
     */
    public function __construct(public string $accountId, public array $payload) {}

    public function handle(PushSender $sender): void
    {
        $sender->send(PushSubscription::query()->where('account_id', $this->accountId)->get(), $this->payload);
    }
}
