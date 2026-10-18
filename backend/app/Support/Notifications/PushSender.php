<?php

namespace App\Support\Notifications;

use App\Models\PushSubscription;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Delivers one message to devices. Subscriptions the push service reports
 * as gone (app uninstalled, permission withdrawn) are removed.
 */
class PushSender
{
    public function __construct(private ?ClientInterface $client = null) {}

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     */
    public function send(Collection $subscriptions, array $payload): void
    {
        if ($subscriptions->isEmpty()) {
            return;
        }

        // Short timeout: delivery runs inside the time limit of a cron call.
        $client = $this->client ?? new Client(['timeout' => 10, 'connect_timeout' => 5]);
        $webPush = new WebPush(['VAPID' => Vapid::keys()], ['TTL' => 86400, 'urgency' => 'normal'], $client, logger: $this->logger());
        $byEndpoint = $subscriptions->keyBy('endpoint');

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => 'aes128gcm',
            ]), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        foreach ($webPush->flush() as $report) {
            $subscription = $byEndpoint->get($report->getEndpoint());
            if ($subscription === null) {
                continue;
            }
            if ($report->isSuccess()) {
                $subscription->forceFill(['last_success_at' => Carbon::now()])->save();
            } elseif ($report->isSubscriptionExpired()) {
                $subscription->delete();
            }
        }
    }

    /**
     * The library reports missing optional extensions (GMP/BCMath) as a
     * PHP notice, which Laravel would turn into an exception. Only real
     * problems are logged.
     */
    private function logger(): LoggerInterface
    {
        return new class extends AbstractLogger
        {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if (! in_array($level, [LogLevel::NOTICE, LogLevel::INFO, LogLevel::DEBUG], true)) {
                    Log::log($level, (string) $message, $context);
                }
            }
        };
    }
}
