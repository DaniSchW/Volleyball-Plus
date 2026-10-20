<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuthTokenPurpose;
use App\Enums\MemberStatus;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\TenantMember;
use App\Support\Auth\MagicLinks;
use App\Support\Auth\Permissions;
use App\Support\Auth\SessionManager;
use App\Support\Invitations\JoinService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(
        private MagicLinks $links,
        private SessionManager $sessions,
        private TenantContext $tenants,
    ) {}

    /**
     * Sends a login link. The answer is the same whether or not the address
     * belongs to a member.
     */
    public function requestLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));

        $limited = ! RateLimiter::attempt('login-email:'.sha1($email), 5, fn () => true, 15 * 60)
            || ! RateLimiter::attempt('login-ip:'.$request->ip(), 20, fn () => true, 15 * 60);
        abort_if($limited, 429, __('auth.too_many_requests'));

        $this->links->sendLogin($email, $request);

        return response()->json(['message' => __('auth.link_sent')], 202);
    }

    /** Login from the start page (no club in the URL); same answer either way. */
    public function requestGlobalLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));

        $limited = ! RateLimiter::attempt('login-email:'.sha1($email), 5, fn () => true, 15 * 60)
            || ! RateLimiter::attempt('login-ip:'.$request->ip(), 20, fn () => true, 15 * 60);
        abort_if($limited, 429, __('auth.too_many_requests'));

        $this->links->sendGlobalLogin($email, $request);

        return response()->json(['message' => __('auth.link_sent')], 202);
    }

    /**
     * Redeems a magic link (login or invitation confirmation) and starts the
     * session.
     */
    public function consume(Request $request, JoinService $joins, Permissions $permissions): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);

        $token = $this->links->consume($data['token']);
        abort_if($token === null, 422, __('auth.link_invalid'));

        $tenant = $token->tenant;

        if ($tenant === null) {
            $account = Account::query()->where('email', $token->email)->first();
            abort_if($account === null || (! $account->isPlatformAdmin() && ! $this->links->hasActiveClub($account)), 422, __('auth.link_invalid'));
            [, $plain] = $this->sessions->start($account, $request);

            return response()->json(['data' => ['tenant' => null]])->withCookie($this->sessions->cookie($plain));
        }
        abort_if($tenant->status !== TenantStatus::Active, 422, __('auth.link_invalid'));

        $account = $this->tenants->run($tenant, function () use ($token, $joins, $permissions) {
            if ($token->purpose === AuthTokenPurpose::Join) {
                return $joins->completeFromToken($token);
            }

            $account = Account::query()->where('email', $token->email)->first();
            $member = $account === null ? null : TenantMember::query()
                ->where('account_id', $account->id)
                ->where('status', MemberStatus::Active)
                ->first();

            abort_if($member === null || ! $permissions->mayUseApp($member), 422, __('auth.link_invalid'));

            return $account;
        });

        [, $plain] = $this->sessions->start($account, $request);

        return response()
            ->json(['data' => ['tenant' => $tenant->slug]])
            ->withCookie($this->sessions->cookie($plain));
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $this->sessions->current($request);
        if ($session !== null) {
            $this->sessions->revoke($session);
        }

        return response()->json(['message' => 'ok'])->withCookie($this->sessions->forgetCookie());
    }
}
