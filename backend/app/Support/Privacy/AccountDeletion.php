<?php

namespace App\Support\Privacy;

use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Models\Account;
use App\Models\AccountSession;
use App\Models\AuthToken;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A person deletes their whole login: in every club the own profile is
 * deleted (like PersonData::delete), links to children end, the login is
 * disabled; the account itself loses its e-mail address, devices and
 * sessions. Refused while the person is the last admin of a club or the
 * last platform admin.
 */
class AccountDeletion
{
    public function __construct(private TenantContext $tenants, private PersonData $persons) {}

    public function delete(Account $account): void
    {
        $members = TenantMember::withoutGlobalScopes()->where('account_id', $account->id)->where('status', MemberStatus::Active)->get();
        $clubs = Tenant::query()->whereIn('id', $members->pluck('tenant_id'))->get()->keyBy('id');

        foreach ($members->where('is_admin', true) as $member) {
            $admins = TenantMember::withoutGlobalScopes()->where('tenant_id', $member->tenant_id)
                ->where('is_admin', true)->where('status', MemberStatus::Active)->count();
            if ($admins <= 1) {
                throw ValidationException::withMessages(['confirm' => __('account.last_admin', ['club' => $clubs[$member->tenant_id]?->name])]);
            }
        }
        if ($account->isSuperadmin()) {
            throw ValidationException::withMessages(['confirm' => __('account.superadmin')]);
        }
        if ($account->is_platform_admin && config('platform.superadmins') === [] && Account::query()->where('is_platform_admin', true)->count() <= 1) {
            throw ValidationException::withMessages(['confirm' => __('account.last_platform_admin')]);
        }

        foreach ($members as $member) {
            $tenant = $clubs[$member->tenant_id] ?? null;
            if ($tenant === null) {
                continue;
            }
            $this->tenants->run($tenant, function () use ($member) {
                $own = PersonAccount::query()->where('tenant_member_id', $member->id)->where('relation', PersonRelation::Own)->whereNull('ended_at')->first();
                $person = $own ? Person::query()->find($own->person_id) : null;
                if ($person !== null) {
                    $this->persons->delete($person);
                }
                PersonAccount::query()->where('tenant_member_id', $member->id)->whereNull('ended_at')->update(['ended_at' => Carbon::now()]);
                $member->forceFill(['status' => MemberStatus::Disabled, 'is_admin' => false])->save();
            });
        }

        AuthToken::query()->where('email', $account->email)->delete();
        AccountSession::query()->where('account_id', $account->id)->delete();
        PushSubscription::query()->where('account_id', $account->id)->delete();
        $account->forceFill([
            'email' => 'deleted-'.mb_strtolower($account->id).'@invalid',
            'is_platform_admin' => false,
            'last_login_at' => null,
        ])->save();
    }
}
