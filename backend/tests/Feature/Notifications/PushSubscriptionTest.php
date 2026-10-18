<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\TenantMember;
use App\Support\Notifications\PushSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private TenantMember $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->member($this->tenant(), 'paul@example.org');
    }

    /** A real P-256 browser key, so the payload can be encrypted. */
    private function keys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return ['p256dh' => $b64("\x04".$ec['x'].$ec['y']), 'auth' => $b64(random_bytes(16))];
    }

    public function test_server_key_is_created_once_and_private_key_stored_encrypted(): void
    {
        $key = $this->loginAs($this->member)->getJson('/api/v1/push/key')->assertOk()->json('data.public_key');

        $this->assertSame($key, $this->loginAs($this->member)->getJson('/api/v1/push/key')->json('data.public_key'));
        $this->assertSame(87, strlen($key));
        $stored = Setting::read('push.vapid');
        $this->assertStringNotContainsString($stored['private'], json_encode(Setting::read('push.vapid')['public']));
    }

    public function test_devices_subscribe_and_unsubscribe(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $this->loginAs($this->member)->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => $this->keys()])->assertCreated();
        $this->loginAs($this->member)->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => $this->keys()])->assertCreated();
        $this->assertSame(1, PushSubscription::count());

        // Only the push services of the browsers.
        $this->loginAs($this->member)->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://evil.example.org/fcm.googleapis.com', 'keys' => $this->keys()])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        $this->loginAs($this->member)->postJson('/api/v1/push/subscriptions', ['endpoint' => 'http://fcm.googleapis.com/x', 'keys' => $this->keys()])
            ->assertUnprocessable();

        $this->loginAs($this->member)->deleteJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_messages_are_encrypted_and_gone_devices_removed(): void
    {
        $keys = $this->keys();
        foreach (['ok', 'gone'] as $name) {
            PushSubscription::create([
                'account_id' => $this->member->account_id, 'endpoint' => "https://fcm.googleapis.com/fcm/send/{$name}",
                'endpoint_hash' => PushSubscription::hashEndpoint("https://fcm.googleapis.com/fcm/send/{$name}"),
                'public_key' => $keys['p256dh'], 'auth_token' => $keys['auth'],
            ]);
        }
        $requests = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(410)]));
        $stack->push(Middleware::history($requests));

        (new PushSender(new Client(['handler' => $stack])))->send(PushSubscription::query()->orderBy('endpoint')->get()->reverse()->values(), ['title' => 'Hallo']);

        $this->assertCount(2, $requests);
        $this->assertSame('aes128gcm', $requests[0]['request']->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid t=', $requests[0]['request']->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('Hallo', (string) $requests[0]['request']->getBody());
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/ok'], PushSubscription::pluck('endpoint')->all());
        $this->assertNotNull(PushSubscription::first()->last_success_at);
    }
}
