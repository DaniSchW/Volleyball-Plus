<?php

namespace App\Support\Platform;

use App\Enums\AuthTokenPurpose;
use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Enums\TenantStatus;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Auth\MagicLinks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Clubs register their volleyball department themselves; a platform admin
 * approves them. Until then the club does not exist for anybody else
 * (no login, no invitation links).
 */
class ClubRegistration
{
    public function __construct(private TenantContext $tenants, private MagicLinks $links) {}

    /**
     * @param  array<string, mixed>  $data  validated registration form
     */
    public function register(array $data): Tenant
    {
        $email = mb_strtolower(trim($data['email']));

        $tenant = DB::transaction(function () use ($data, $email) {
            $tenant = Tenant::create([
                'slug' => $data['slug'],
                'name' => trim($data['club_name']),
                'mail_from_name' => trim($data['club_name']),
                'city' => $data['city'] ?? null,
                'status' => TenantStatus::Pending,
                'contact_name' => trim($data['first_name'].' '.$data['last_name']),
                'contact_email' => $email,
                'registration_note' => $data['note'] ?? null,
            ]);

            // The person registering becomes the club's first admin.
            $this->tenants->run($tenant, function () use ($data, $email) {
                $account = Account::query()->firstOrCreate(['email' => $email]);
                $member = TenantMember::create(['account_id' => $account->id, 'is_admin' => true, 'status' => MemberStatus::Active]);
                $person = Person::create([
                    'first_name' => trim($data['first_name']),
                    'last_name' => trim($data['last_name']),
                    'gender' => $data['gender'],
                    'email' => $email,
                ]);
                PersonAccount::create(['person_id' => $person->id, 'tenant_member_id' => $member->id, 'relation' => PersonRelation::Own]);
            });

            return $tenant;
        });

        $platformUrl = rtrim((string) config('app.url'), '/').'/platform';
        foreach (Account::query()->where('is_platform_admin', true)->get() as $admin) {
            Mail::to($admin->email)->locale($admin->locale)->send(new NotificationMail(
                null,
                __('platform.mail.new.title', ['name' => $tenant->name], $admin->locale),
                __('platform.mail.new.body', ['name' => $tenant->name, 'city' => $tenant->city ?? '–', 'contact' => $tenant->contact_name, 'email' => $email, 'note' => $tenant->registration_note ?? '–'], $admin->locale),
                $platformUrl,
                false,
            ));
        }
        Mail::to($email)->send(new NotificationMail(
            null,
            __('platform.mail.received.title', ['name' => $tenant->name]),
            __('platform.mail.received.body', ['name' => $tenant->name]),
            null,
            false,
        ));

        return $tenant;
    }

    /** Activates the club and sends its admin a login link. */
    public function approve(Tenant $tenant, Request $request): void
    {
        $tenant->forceFill(['status' => TenantStatus::Active, 'approved_at' => Carbon::now()])->save();

        if ($tenant->contact_email) {
            $this->tenants->run($tenant, fn () => $this->links->send($tenant, $tenant->contact_email, AuthTokenPurpose::Login, null, $request));
            Mail::to($tenant->contact_email)->send(new NotificationMail(
                $tenant,
                __('platform.mail.approved.title', ['name' => $tenant->name]),
                __('platform.mail.approved.body', ['name' => $tenant->name, 'url' => rtrim((string) config('app.url'), '/').'/'.$tenant->slug]),
                null,
                false,
            ));
        }
    }

    /**
     * Removes a club that was never approved, with its first admin's data.
     * Accounts stay (they may belong to other clubs) unless orphaned.
     */
    public function reject(Tenant $tenant): void
    {
        $accountIds = TenantMember::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('account_id');

        DB::transaction(function () use ($tenant) {
            // A pending club only has its first admin; the rest cascades with the tenant.
            foreach (['person_accounts', 'persons', 'tenant_members'] as $table) {
                DB::table($table)->where('tenant_id', $tenant->id)->delete();
            }
            $tenant->delete();
        });

        $stillMember = TenantMember::withoutGlobalScopes()->whereIn('account_id', $accountIds)->pluck('account_id');
        Account::query()->whereIn('id', $accountIds->diff($stillMember))->where('is_platform_admin', false)->delete();
    }

    /**
     * Removes a (suspended) club completely: every row of the department,
     * its files, and the logins that belong to no other club.
     */
    public function destroy(Tenant $tenant): void
    {
        $accountIds = TenantMember::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('account_id');
        $tables = collect(Schema::getTables())->pluck('name')
            ->filter(fn (string $table) => $table !== 'tenants' && Schema::hasColumn($table, 'tenant_id'))->values();

        // References inside the department may block a certain order – repeat until all are gone.
        for ($pass = 0; $tables->isNotEmpty() && $pass < 20; $pass++) {
            $tables = $tables->reject(function (string $table) use ($tenant) {
                try {
                    DB::table($table)->where('tenant_id', $tenant->id)->delete();

                    return true;
                } catch (QueryException) {
                    return false;
                }
            })->values();
        }
        $tenant->delete();
        Storage::disk('local')->deleteDirectory('tenants/'.$tenant->id);

        $stillMember = TenantMember::withoutGlobalScopes()->whereIn('account_id', $accountIds)->pluck('account_id');
        Account::query()->whereIn('id', $accountIds->diff($stillMember))->where('is_platform_admin', false)->delete();
    }
}
