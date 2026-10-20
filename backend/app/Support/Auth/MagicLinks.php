<?php

namespace App\Support\Auth;

use App\Enums\AuthTokenPurpose;
use App\Enums\MemberStatus;
use App\Mail\MagicLinkMail;
use App\Models\Account;
use App\Models\AuthToken;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates, mails and redeems one-time login links. Accounts are only ever
 * created through invitations, so a login link is only sent to addresses
 * that are active members of the department – without telling the caller
 * whether that was the case.
 */
class MagicLinks
{
    public function __construct(private TenantContext $tenants) {}

    public function sendLogin(string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        $tenant = $this->tenants->get();

        $account = Account::query()->where('email', $email)->first();
        $isMember = $account !== null && TenantMember::query()
            ->where('account_id', $account->id)
            ->where('status', MemberStatus::Active)
            ->exists();

        if (! $isMember) {
            return;
        }

        $this->send($tenant, $email, AuthTokenPurpose::Login, null, $request, $account->locale);
    }

    /**
     * Login without a club in the URL (start page): one link for the whole
     * account – afterwards the start page lists the account's clubs. Only
     * for members of an active club and platform admins.
     */
    public function sendGlobalLogin(string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        $account = Account::query()->where('email', $email)->first();
        // Superadmins may log in before their account exists.
        if ($account === null && in_array($email, config('platform.superadmins'), true)) {
            $account = Account::create(['email' => $email]);
        }
        if ($account === null || (! $account->isPlatformAdmin() && ! $this->hasActiveClub($account))) {
            return;
        }

        $this->send(null, $email, AuthTokenPurpose::Login, null, $request, $account->locale);
    }

    public function hasActiveClub(Account $account): bool
    {
        return TenantMember::withoutGlobalScopes()
            ->join('tenants', 'tenants.id', '=', 'tenant_members.tenant_id')
            ->where('tenant_members.account_id', $account->id)
            ->where('tenant_members.status', MemberStatus::Active)
            ->where('tenants.status', 'active')
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function send(?Tenant $tenant, string $email, AuthTokenPurpose $purpose, ?array $payload, Request $request, ?string $locale = null): void
    {
        $token = Str::random(48);

        AuthToken::create([
            'email' => mb_strtolower(trim($email)),
            'token_hash' => hash('sha256', $token),
            'purpose' => $purpose,
            'tenant_id' => $tenant?->id,
            'payload' => $payload,
            'expires_at' => Carbon::now()->addMinutes(config('security.magic_link_minutes')),
            'requested_ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        $url = rtrim((string) config('app.url'), '/').($tenant ? '/'.$tenant->slug : '').'/login/confirm?token='.$token;

        // Sent right away (not queued): the person is waiting for it.
        Mail::to($email)->locale($locale ?? app()->getLocale())->send(new MagicLinkMail($tenant, $url, $purpose));
    }

    /**
     * Redeems a token exactly once; null if unknown, expired or used.
     */
    public function consume(string $token): ?AuthToken
    {
        $authToken = AuthToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($authToken === null) {
            return null;
        }

        // Guard against two simultaneous clicks redeeming the same link.
        $claimed = DB::table('auth_tokens')
            ->where('id', $authToken->id)
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);

        return $claimed === 1 ? $authToken : null;
    }
}
