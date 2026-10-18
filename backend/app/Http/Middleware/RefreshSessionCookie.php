<?php

namespace App\Http\Middleware;

use App\Support\Auth\SessionManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sliding login: when the server extended the session, the browser cookie
 * gets the new expiry too – otherwise it would still vanish 90 days after
 * the first login.
 */
class RefreshSessionCookie
{
    public function __construct(private SessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $token = $request->attributes->get(SessionManager::REFRESH_ATTRIBUTE);
        $loggedOut = collect($response->headers->getCookies())
            ->contains(fn ($cookie) => $cookie->getName() === config('security.session.cookie'));

        if (is_string($token) && ! $loggedOut) {
            $response->headers->setCookie($this->sessions->cookie($token));
        }

        return $response;
    }
}
