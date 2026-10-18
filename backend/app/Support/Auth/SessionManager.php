<?php

namespace App\Support\Auth;

use App\Models\Account;
use App\Models\AccountSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Long-lived login sessions: a random token in an HttpOnly cookie, only its
 * hash in the database. Each use extends the session (sliding 90 days).
 */
class SessionManager
{
    public const REQUEST_ATTRIBUTE = 'vp.session';

    /**
     * @return array{0: AccountSession, 1: string}
     */
    public function start(Account $account, Request $request): array
    {
        $token = Str::random(64);
        $now = Carbon::now();

        $session = AccountSession::create([
            'account_id' => $account->id,
            'token_hash' => hash('sha256', $token),
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addDays(config('security.session.lifetime_days')),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'ip' => $request->ip(),
        ]);

        $account->forceFill(['last_login_at' => $now])->save();

        return [$session, $token];
    }

    /**
     * Finds the valid session for the request's cookie and extends it.
     */
    public function resolve(Request $request): ?AccountSession
    {
        $token = $request->cookie(config('security.session.cookie'));
        if (! is_string($token) || $token === '') {
            return null;
        }

        $session = AccountSession::query()
            ->with('account')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($session === null) {
            return null;
        }

        $now = Carbon::now();
        if ($session->last_seen_at->diffInMinutes($now) >= config('security.session.touch_after_minutes')) {
            $session->forceFill([
                'last_seen_at' => $now,
                'expires_at' => $now->copy()->addDays(config('security.session.lifetime_days')),
            ])->save();
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $session);

        return $session;
    }

    public function current(Request $request): ?AccountSession
    {
        return $request->attributes->get(self::REQUEST_ATTRIBUTE);
    }

    public function revoke(AccountSession $session): void
    {
        $session->forceFill(['revoked_at' => Carbon::now()])->save();
    }

    public function cookie(string $token): Cookie
    {
        return Cookie::create(
            name: config('security.session.cookie'),
            value: $token,
            expire: Carbon::now()->addDays(config('security.session.lifetime_days')),
            path: '/',
            domain: null,
            secure: config('security.session.secure'),
            httpOnly: true,
            raw: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function forgetCookie(): Cookie
    {
        return Cookie::create(
            name: config('security.session.cookie'),
            value: '',
            expire: 1,
            path: '/',
            secure: config('security.session.secure'),
            httpOnly: true,
            raw: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
