<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Support\Notifications\Vapid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Devices of the logged-in account that receive push messages.
 */
class PushController extends Controller
{
    public function key(): JsonResponse
    {
        return response()->json(['data' => ['public_key' => Vapid::publicKey()]]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        if (! $this->allowedHost($data['endpoint'])) {
            throw ValidationException::withMessages(['endpoint' => __('notifications.push.endpoint_invalid')]);
        }

        // A device belongs to whoever is logged in on it now.
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                'account_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        );

        return response()->json(['message' => 'ok'], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        PushSubscription::query()
            ->where('account_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->json(['message' => 'ok']);
    }

    private function allowedHost(string $endpoint): bool
    {
        $host = mb_strtolower((string) parse_url($endpoint, PHP_URL_HOST));

        foreach (config('security.push_hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
