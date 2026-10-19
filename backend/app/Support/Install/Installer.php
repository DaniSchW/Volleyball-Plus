<?php

namespace App\Support\Install;

use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Models\Account;
use App\Models\Person;
use App\Models\PersonAccount;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Web installer for hosting without SSH: checks the database, creates the
 * tables and the first department, then writes .env (application key,
 * database, mail, cron token). .env is written last – once it exists the
 * app counts as installed and the installer is gone.
 */
class Installer
{
    /**
     * Separate connection for the entered credentials, so a failed attempt
     * never changes the connection the rest of the app uses.
     */
    private const CONNECTION = 'install';

    /**
     * @param  array<string, mixed>  $input  validated installer form
     * @return array{env_written: bool, env: string, cron_command: string, cron_url: string, tenant_url: string}
     */
    public function install(array $input): array
    {
        $this->useDatabase($input);
        $this->migrate();

        $tenant = Tenant::on(self::CONNECTION)->firstOrCreate(
            ['slug' => $input['tenant_slug']],
            ['name' => $input['tenant_name'], 'mail_from_name' => $input['tenant_name'], 'approved_at' => now()],
        );
        $this->createAdmin($tenant, $input);

        $cronToken = Str::random(48);
        $env = EnvFile::render($this->envValues($input, $cronToken));
        $written = Requirements::envWritable()
            && @file_put_contents(app()->environmentFilePath(), $env, LOCK_EX) !== false;

        if ($written) {
            SetupCode::forget();
        }

        $appUrl = rtrim($input['app_url'], '/');

        return [
            'env_written' => $written,
            'env' => $env,
            'cron_command' => PHP_BINARY.' '.base_path('artisan').' schedule:run',
            'cron_url' => $appUrl.'/cron/'.$cronToken,
            'tenant_url' => $appUrl.'/'.$tenant->slug.'/login',
        ];
    }

    /**
     * First admin of the department, with an own profile. Logs in afterwards
     * with a magic link like everybody else.
     *
     * @param  array<string, mixed>  $input
     */
    private function createAdmin(Tenant $tenant, array $input): void
    {
        app(TenantContext::class)->run($tenant, function () use ($input) {
            $account = Account::on(self::CONNECTION)->firstOrCreate(['email' => mb_strtolower(trim($input['admin_email']))]);
            // Whoever installs runs the platform: approves clubs that register.
            $account->forceFill(['is_platform_admin' => true])->save();

            $member = TenantMember::on(self::CONNECTION)->firstOrCreate(['account_id' => $account->id]);
            $member->forceFill(['is_admin' => true, 'status' => MemberStatus::Active])->save();

            $hasOwnProfile = PersonAccount::on(self::CONNECTION)
                ->where('tenant_member_id', $member->id)
                ->where('relation', PersonRelation::Own)
                ->exists();

            if (! $hasOwnProfile) {
                $person = Person::on(self::CONNECTION)->create([
                    'first_name' => $input['admin_first_name'],
                    'last_name' => $input['admin_last_name'],
                    'gender' => $input['admin_gender'],
                    'email' => $account->email,
                ]);
                PersonAccount::on(self::CONNECTION)->create([
                    'person_id' => $person->id,
                    'tenant_member_id' => $member->id,
                    'relation' => PersonRelation::Own,
                ]);
            }
        });
    }

    /**
     * Sets up the installer connection for the entered MySQL database and
     * checks that it is reachable and recent enough.
     *
     * @param  array<string, mixed>  $input
     */
    public function useDatabase(array $input): void
    {
        config(['database.connections.'.self::CONNECTION => array_merge(config('database.connections.mysql'), [
            'host' => $input['db_host'],
            'port' => (string) $input['db_port'],
            'database' => $input['db_database'],
            'username' => $input['db_username'],
            'password' => (string) ($input['db_password'] ?? ''),
            'collation' => 'utf8mb4_unicode_ci',
        ])]);
        DB::purge(self::CONNECTION);

        try {
            $version = (string) DB::connection(self::CONNECTION)->scalar('select version()');
        } catch (Throwable $e) {
            throw new InstallException(__('install.errors.db_connection', ['message' => $e->getMessage()]), previous: $e);
        }

        if (! str_contains(strtolower($version), 'mariadb') && version_compare($version, '8.0.0', '<')) {
            throw new InstallException(__('install.errors.db_version', ['version' => $version]));
        }
    }

    private function migrate(): void
    {
        try {
            $exitCode = Artisan::call('migrate', ['--force' => true, '--database' => self::CONNECTION]);
        } catch (Throwable $e) {
            throw new InstallException(__('install.errors.migration', ['message' => $e->getMessage()]), previous: $e);
        }

        if ($exitCode !== 0) {
            throw new InstallException(__('install.errors.migration', ['message' => trim(Artisan::output())]));
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|bool|null>
     */
    private function envValues(array $input, string $cronToken): array
    {
        $smtp = $input['mail_mailer'] === 'smtp';

        return [
            'APP_NAME' => 'VolleyOrga',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => false,
            'APP_URL' => rtrim($input['app_url'], '/'),
            'APP_LOCALE' => 'de',
            'APP_FALLBACK_LOCALE' => 'en',
            'APP_FAKER_LOCALE' => 'de_DE',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'LOG_CHANNEL' => 'daily',
            'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $input['db_host'],
            'DB_PORT' => (int) $input['db_port'],
            'DB_DATABASE' => $input['db_database'],
            'DB_USERNAME' => $input['db_username'],
            'DB_PASSWORD' => $input['db_password'] ?? '',
            'DB_COLLATION' => 'utf8mb4_unicode_ci',
            'FORCE_HTTPS' => true,
            'TRUSTED_PROXIES' => env('TRUSTED_PROXIES') ?: null,
            'CRON_TOKEN' => $cronToken,
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'FILESYSTEM_DISK' => 'local',
            'MAIL_MAILER' => $smtp ? 'smtp' : 'phpmail',
            'MAIL_SCHEME' => $smtp ? $input['smtp_scheme'] : null,
            'MAIL_HOST' => $smtp ? $input['smtp_host'] : null,
            'MAIL_PORT' => $smtp ? (int) $input['smtp_port'] : null,
            'MAIL_USERNAME' => $smtp ? ($input['smtp_username'] ?? null) : null,
            'MAIL_PASSWORD' => $smtp ? ($input['smtp_password'] ?? null) : null,
            'MAIL_FROM_ADDRESS' => $input['mail_from_address'],
            'MAIL_FROM_NAME' => 'VolleyOrga',
        ];
    }
}
